<?php

namespace App\Services\Ingesta;

use App\Models\Cuenta;
use InvalidArgumentException;
use RuntimeException;

/**
 * AppsFlyer desde BigQuery (Data Locker de la cuenta en el proyecto de datos
 * de EPA, 2026-11-05) en vez del Master API: no necesita el token de
 * AppsFlyer de la cuenta, solo que la cuenta de servicio del dashboard pueda
 * leer el dataset. Devuelve EXACTAMENTE la misma forma que
 * EnriquecedorAppsFlyerApi::enriquecer(), así el resto del pipeline (cruce
 * con costos, venta real, creativos) no cambia.
 *
 * Reglas, copiadas del script de Openbank (InstalacionesAPI.gs / TDC):
 * - Instalaciones: adquisición (UA) + retargeting SOLO re-atribución; los
 *   re-engagements no cuentan.
 * - Eventos (principal → NC, secundario → orders): solo atribución primaria,
 *   y en retargeting solo re-atribución. El nombre del evento sin distinguir
 *   mayúsculas.
 * - Fechas en la zona horaria de la cuenta (AppsFlyer guarda en UTC).
 *
 * Las tablas y la zona se configuran por cuenta (credencial de AppsFlyer,
 * origen "bigquery"); las de retargeting son opcionales.
 */
class EnriquecedorAppsFlyerBigQuery
{
    /** Columnas que se leen de cada tabla (Data Locker estándar). */
    public const COLUMNAS = [
        'installs' => ['app_id', 'install_time', 'af_ad_id', 'af_ad', 'af_adset', 'campaign'],
        'eventos' => ['app_id', 'event_time', 'event_name', 'af_ad_id', 'af_ad', 'af_adset', 'campaign', 'is_primary_attribution'],
    ];

    public const TABLAS_DEFAULT = [
        'installs' => 'installs',
        'installs_rt' => 'installsretargeting',
        'eventos' => 'inapps',
        'eventos_rt' => 'inappsretargeting',
    ];

    public function __construct(
        private readonly BigQueryClient $bq,
        private readonly array $ajustes,
        private readonly string $eventoPrincipal,
        private readonly ?string $eventoSecundario = null,
    ) {}

    public static function paraCuenta(Cuenta $cuenta, ?BigQueryClient $bq = null): self
    {
        $ajustes = $cuenta->credencial('appsflyer')?->ajustes ?? [];
        if (($ajustes['origen'] ?? 'api') !== 'bigquery' || blank($ajustes['bq_proyecto'] ?? null) || blank($ajustes['bq_dataset'] ?? null)) {
            throw new RuntimeException("{$cuenta->nombre} no tiene configurado AppsFlyer desde BigQuery (proyecto y dataset) -- Cuentas → editar → Medición.");
        }
        if (blank($ajustes['evento_principal'] ?? null)) {
            throw new RuntimeException("{$cuenta->nombre} no tiene evento principal de AppsFlyer -- agrégalo en Cuentas → editar → Medición.");
        }

        return new self($bq ?? app(BigQueryClient::class), $ajustes, $ajustes['evento_principal'], ($ajustes['evento_secundario'] ?? null) ?: null);
    }

    /**
     * Revisa, sin traer datos, que el dataset y las tablas existan y tengan
     * las columnas que se van a leer. Para "Probar conexión" del formulario.
     *
     * @return array{ok: bool, tablas: list<array{clave: string, tabla: string, existe: bool, faltan: list<string>, opcional: bool}>, disponibles: list<string>}
     */
    public static function probar(BigQueryClient $bq, array $ajustes): array
    {
        $proyecto = self::identificador($ajustes['bq_proyecto'] ?? '', 'el proyecto');
        $dataset = self::identificador($ajustes['bq_dataset'] ?? '', 'el dataset');
        $existentes = $bq->tablas($proyecto, $dataset);

        $resultado = [];
        foreach (self::tablas($ajustes) as $clave => $tabla) {
            $opcional = str_ends_with($clave, '_rt');
            if ($tabla === null) {
                continue;
            }
            $existe = in_array($tabla, $existentes, true);
            $necesarias = [...self::COLUMNAS[self::tipo($clave)], ...($opcional ? ['retargeting_conversion_type'] : [])];
            $faltan = $existe ? array_values(array_diff($necesarias, $bq->columnas($proyecto, $dataset, $tabla))) : [];
            $resultado[] = ['clave' => $clave, 'tabla' => $tabla, 'existe' => $existe, 'faltan' => $faltan, 'opcional' => $opcional];
        }

        $ok = collect($resultado)->every(fn ($t) => ($t['existe'] && ! $t['faltan']) || ($t['opcional'] && ! $t['existe']));

        return ['ok' => $ok, 'tablas' => $resultado, 'disponibles' => $existentes];
    }

    /**
     * @param  list<string>  $appIds
     * @return array{limpias: list<array<string, mixed>>, problemas: list<array<string, mixed>>, subtotales: int, organico: list<array<string, mixed>>, columnaNC: ?string, columnaOrders: ?string}
     */
    public function enriquecer(array $appIds, string $desde, string $hasta): array
    {
        $filas = $this->bq->consultar($this->sql(), [
            'apps' => array_values($appIds),
            'desde' => $desde,
            'hasta' => $hasta,
            'zona' => $this->ajustes['zona_horaria'] ?? 'America/Mexico_City',
            'principal' => $this->eventoPrincipal,
            'secundario' => $this->eventoSecundario ?? '',
        ]);

        $normalizadas = [];
        $problemas = [];
        foreach ($filas as $f) {
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $f['fecha'], $m)) {
                $problemas[] = ['fuente' => 'AppsFlyer BigQuery', 'motivo' => 'Fecha no reconocida: "'.$f['fecha'].'"', 'fila' => $f];

                continue;
            }
            $adId = trim((string) ($f['af_ad_id'] ?? ''));
            $esOrganico = $adId === '' || strcasecmp($adId, 'None') === 0;
            $normalizadas[] = [
                'adId' => $esOrganico ? null : $adId,
                'esOrganico' => $esOrganico,
                'campaign' => (string) ($f['campaign'] ?? ''),
                'adRaw' => (string) ($f['ad'] ?? ''),
                'conjunto' => ($f['conjunto'] ?? '') ?: null,
                'fecha' => "{$m[3]}-{$m[2]}-{$m[1]}",
                'installs' => (int) $f['installs'],
                'orders' => (int) $f['orders'],
                'newCustomers' => (int) $f['nc'],
                'filaOriginal' => $f,
            ];
        }

        $agregado = AppsFlyerCsvParser::agregarPorAdId($normalizadas, sumarDuplicados: true);

        return [
            'limpias' => $agregado['limpias'],
            'problemas' => [...$problemas, ...$agregado['problemasDuplicado']],
            'subtotales' => 0,
            'organico' => $agregado['organico'],
            'columnaNC' => 'AppsFlyer BigQuery: '.$this->eventoPrincipal,
            'columnaOrders' => $this->eventoSecundario ? 'AppsFlyer BigQuery: '.$this->eventoSecundario : null,
        ];
    }

    /** SQL parametrizado; los nombres de tabla se validan antes de entrar. */
    private function sql(): string
    {
        $proyecto = self::identificador($this->ajustes['bq_proyecto'], 'el proyecto');
        $dataset = self::identificador($this->ajustes['bq_dataset'], 'el dataset');
        $t = fn (string $tabla) => "`{$proyecto}.{$dataset}.{$tabla}`";
        $tablas = self::tablas($this->ajustes);

        $installs = fn (string $tabla, bool $rt) => <<<SQL
            SELECT DATE(SAFE_CAST(install_time AS TIMESTAMP), @zona) AS fecha, af_ad_id,
                   ANY_VALUE(campaign) AS campaign, ANY_VALUE(af_ad) AS ad, ANY_VALUE(af_adset) AS conjunto,
                   COUNT(*) AS installs, 0 AS nc, 0 AS orders
            FROM {$t($tabla)}
            WHERE app_id IN UNNEST(@apps)
              AND DATE(SAFE_CAST(install_time AS TIMESTAMP), @zona) BETWEEN CAST(@desde AS DATE) AND CAST(@hasta AS DATE)
            SQL.($rt ? "\n  AND LOWER(CAST(retargeting_conversion_type AS STRING)) = 're-attribution'" : '')."\n  GROUP BY fecha, af_ad_id";

        $eventos = fn (string $tabla, bool $rt) => <<<SQL
            SELECT DATE(SAFE_CAST(event_time AS TIMESTAMP), @zona) AS fecha, af_ad_id,
                   ANY_VALUE(campaign) AS campaign, ANY_VALUE(af_ad) AS ad, ANY_VALUE(af_adset) AS conjunto,
                   0 AS installs,
                   COUNTIF(LOWER(event_name) = LOWER(@principal)) AS nc,
                   COUNTIF(@secundario != '' AND LOWER(event_name) = LOWER(@secundario)) AS orders
            FROM {$t($tabla)}
            WHERE app_id IN UNNEST(@apps)
              AND DATE(SAFE_CAST(event_time AS TIMESTAMP), @zona) BETWEEN CAST(@desde AS DATE) AND CAST(@hasta AS DATE)
              AND LOWER(event_name) IN (LOWER(@principal), LOWER(@secundario))
              AND LOWER(IFNULL(CAST(is_primary_attribution AS STRING), 'true')) IN ('true', '1')
            SQL.($rt ? "\n  AND LOWER(CAST(retargeting_conversion_type AS STRING)) = 're-attribution'" : '')."\n  GROUP BY fecha, af_ad_id";

        $partes = [$installs($tablas['installs'], false), $eventos($tablas['eventos'], false)];
        if ($tablas['installs_rt']) {
            $partes[] = $installs($tablas['installs_rt'], true);
        }
        if ($tablas['eventos_rt']) {
            $partes[] = $eventos($tablas['eventos_rt'], true);
        }
        $union = implode("\nUNION ALL\n", $partes);

        return <<<SQL
            SELECT CAST(fecha AS STRING) AS fecha, af_ad_id,
                   ANY_VALUE(campaign) AS campaign, ANY_VALUE(ad) AS ad, ANY_VALUE(conjunto) AS conjunto,
                   SUM(installs) AS installs, SUM(nc) AS nc, SUM(orders) AS orders
            FROM ({$union})
            GROUP BY fecha, af_ad_id
            SQL;
    }

    /** @return array{installs: string, eventos: string, installs_rt: ?string, eventos_rt: ?string} */
    private static function tablas(array $ajustes): array
    {
        $config = $ajustes['bq_tablas'] ?? [];
        $resultado = [];
        foreach (self::TABLAS_DEFAULT as $clave => $default) {
            // Las de retargeting se pueden apagar dejándolas vacías.
            $valor = array_key_exists($clave, $config) ? trim((string) $config[$clave]) : $default;
            $resultado[$clave] = $valor === '' ? null : self::identificador($valor, "la tabla {$clave}");
        }
        if (! $resultado['installs'] || ! $resultado['eventos']) {
            throw new InvalidArgumentException('Faltan las tablas de instalaciones o de eventos.');
        }

        return $resultado;
    }

    private static function tipo(string $clave): string
    {
        return str_starts_with($clave, 'installs') ? 'installs' : 'eventos';
    }

    /** Solo letras, números, guion y guion bajo: nunca SQL libre en un nombre. */
    private static function identificador(string $valor, string $que): string
    {
        $valor = trim($valor);
        if (! preg_match('/^[A-Za-z0-9_\-]{1,1024}$/', $valor)) {
            throw new InvalidArgumentException("Revisa {$que}: solo letras, números, guion y guion bajo.");
        }

        return $valor;
    }
}
