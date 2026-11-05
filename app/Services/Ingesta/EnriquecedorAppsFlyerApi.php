<?php

namespace App\Services\Ingesta;

use App\Models\Cuenta;
use RuntimeException;

/**
 * Reemplaza el CSV manual de AppsFlyer por un pull en vivo al Master API,
 * para el mismo shape de datos (Campaign/Campaign ID/Ad/Ad ID/Install Day/
 * Installs/NC/Orders) que hoy produce AppsFlyerCsvParser::parse() sobre el
 * archivo subido a mano -- ver AppsFlyerApiClient para el detalle de
 * endpoint/auth/KPIs verificados en vivo.
 *
 * Un país puede tener más de un appsflyer_app_id (iOS + Android trackeados
 * por separado en AppsFlyer, aunque comparten los mismos Ad ID de Meta/
 * TikTok) -- se pide UN pull por app y se combinan con
 * AppsFlyerCsvParser::agregarPorAdId(..., sumarDuplicados: true), porque un
 * mismo Ad ID+fecha apareciendo en ambos pulls es tráfico real y distinto
 * (instalaciones de dispositivos iOS vs Android para el mismo anuncio), no
 * una fila duplicada a descartar como sí lo es dentro de un mismo CSV.
 */
class EnriquecedorAppsFlyerApi
{
    // af_adset: nombre del conjunto, para las reglas de clasificación de
    // cada cuenta. Un ad vive en un solo conjunto, así que no parte filas.
    private const GROUPINGS = 'c,af_c_id,af_adset,af_ad,af_ad_id,install_time';

    /**
     * Los eventos son por cuenta (credencial de AppsFlyer, ajustes): nombres
     * REALES de evento del SDK de la app, no los headers relabeled del CSV
     * manual -- ver nota de verificación en vivo en AppsFlyerApiClient. TaDa:
     * "First Order Placed" / "Order Placed"; Openbank: onb_success_n2 /
     * onb_success_tdc. El principal llena newCustomers (NC) y el secundario
     * orders; sin secundario, orders queda en 0.
     */
    public function __construct(
        private readonly AppsFlyerApiClient $appsflyer,
        private readonly string $eventoPrincipal,
        private readonly ?string $eventoSecundario = null,
    ) {}

    public static function paraCuenta(Cuenta $cuenta): self
    {
        $cliente = AppsFlyerApiClient::paraCuenta($cuenta);
        $ajustes = $cuenta->credencial('appsflyer')?->ajustes ?? [];
        if (blank($ajustes['evento_principal'] ?? null)) {
            throw new RuntimeException("{$cuenta->nombre} no tiene evento principal de AppsFlyer -- agrégalo en Cuentas → editar → Credenciales.");
        }

        return new self($cliente, $ajustes['evento_principal'], ($ajustes['evento_secundario'] ?? null) ?: null);
    }

    /**
     * @param  list<string>  $appIds
     * @return array{limpias: list<array{adId: string, campaign: string, adRaw: string, serie: list<array{fecha: string, installs: int|float, orders: int|float, newCustomers: int|float}>}>, problemas: list<array{fuente: string, motivo: string, fila: mixed}>, subtotales: int, organico: list<array{fecha: string, installs: int|float, orders: int|float, newCustomers: int|float}>, columnaNC: ?string, columnaOrders: ?string}
     */
    public function enriquecer(array $appIds, string $desde, string $hasta): array
    {
        $filasNormalizadas = [];
        $problemas = [];

        foreach ($appIds as $appId) {
            $csvCrudo = $this->appsflyer->get($appId, [
                'from' => $desde,
                'to' => $hasta,
                'groupings' => self::GROUPINGS,
                'kpis' => implode(',', ['installs', ...array_map(fn (string $e) => "event_counter_{$e}", $this->eventos())]),
            ]);

            [$filasApp, $problemasApp] = $this->parsearCsv($csvCrudo, $appId);
            $filasNormalizadas = [...$filasNormalizadas, ...$filasApp];
            $problemas = [...$problemas, ...$problemasApp];
        }

        $agregado = AppsFlyerCsvParser::agregarPorAdId($filasNormalizadas, sumarDuplicados: true);

        return [
            'limpias' => $agregado['limpias'],
            'problemas' => [...$problemas, ...$agregado['problemasDuplicado']],
            'subtotales' => 0,
            'organico' => $agregado['organico'],
            'columnaNC' => 'AppsFlyer API: '.$this->eventoPrincipal,
            'columnaOrders' => $this->eventoSecundario ? 'AppsFlyer API: '.$this->eventoSecundario : null,
        ];
    }

    /**
     * @return array{0: list<array{adId: ?string, esOrganico: bool, campaign: string, adRaw: string, fecha: string, installs: int|float, orders: int|float, newCustomers: int|float, filaOriginal: mixed}>, 1: list<array{fuente: string, motivo: string, fila: mixed}>}
     */
    private function parsearCsv(string $csvCrudo, string $appId): array
    {
        $texto = str_replace("\r", '', $csvCrudo);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        $lineas = array_filter(explode("\n", trim($texto)), fn (string $l) => $l !== '');

        $headers = str_getcsv(array_shift($lineas) ?? '', escape: '');
        $idxCampaign = array_search('Campaign', $headers, true);
        $idxCampaignId = array_search('Campaign ID', $headers, true);
        $idxAd = array_search('Ad', $headers, true);
        $idxAdId = array_search('Ad ID', $headers, true);
        $idxAdset = array_search('Adset', $headers, true);
        $idxInstallTime = array_search('Install Time', $headers, true);
        $idxInstalls = array_search('Installs', $headers, true);
        $idxNc = array_search('Event Counter - '.$this->eventoPrincipal, $headers, true);
        $idxOrders = $this->eventoSecundario ? array_search('Event Counter - '.$this->eventoSecundario, $headers, true) : false;

        $filas = [];
        $problemas = [];

        foreach ($lineas as $linea) {
            $campos = str_getcsv($linea, escape: '');

            if (count($campos) !== count($headers)) {
                $problemas[] = [
                    'fuente' => 'AppsFlyer API',
                    'motivo' => "Fila con {$appId} no matchea la cantidad de columnas esperada, se descarta",
                    'fila' => $linea,
                ];

                continue;
            }

            $adIdRaw = $campos[$idxAdId] ?? 'None';
            $esOrganico = $adIdRaw === 'None' || $adIdRaw === '';
            $fechaIso = $campos[$idxInstallTime] ?? null;
            $fecha = $fechaIso ? self::normalizarFecha($fechaIso) : null;

            if ($fecha === null) {
                $problemas[] = [
                    'fuente' => 'AppsFlyer API',
                    'motivo' => "Install Time no reconocido en {$appId}: \"{$fechaIso}\"",
                    'fila' => $campos,
                ];

                continue;
            }

            $filas[] = [
                'adId' => $esOrganico ? null : $adIdRaw,
                'esOrganico' => $esOrganico,
                'campaign' => $campos[$idxCampaign] ?? '',
                'adRaw' => ($campos[$idxAd] ?? '') === 'None' ? '' : ($campos[$idxAd] ?? ''),
                'conjunto' => $idxAdset === false || in_array($campos[$idxAdset] ?? '', ['', 'None'], true) ? null : $campos[$idxAdset],
                'fecha' => $fecha,
                'installs' => self::numero($campos[$idxInstalls] ?? null),
                'orders' => $idxOrders === false ? 0 : self::numero($campos[$idxOrders] ?? null),
                'newCustomers' => $idxNc === false ? 0 : self::numero($campos[$idxNc] ?? null),
                'filaOriginal' => ['appId' => $appId, 'campaignId' => $campos[$idxCampaignId] ?? null, ...array_combine($headers, $campos)],
            ];
        }

        return [$filas, $problemas];
    }

    /** @return list<string> */
    private function eventos(): array
    {
        return array_values(array_filter([$this->eventoPrincipal, $this->eventoSecundario]));
    }

    private static function numero(?string $raw): int|float
    {
        return ($raw !== null && is_numeric($raw)) ? $raw + 0 : 0;
    }

    /**
     * install_time de AppsFlyer viene YYYY-MM-DD -- se invierte a DD-MM-YYYY
     * para calzar con el formato interno que usa el resto del pipeline
     * (AppsFlyerCsvParser::limpiarFecha produce ese mismo formato desde el
     * texto "Jul 02, 2026" del CSV manual).
     */
    private static function normalizarFecha(string $iso): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return null;
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
}
