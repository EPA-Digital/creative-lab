<?php

namespace App\Services\Fuentes;

use App\Models\CuentaPublicitaria;
use App\Services\Divisas\Divisas;
use App\Services\Ingesta\GoogleAdsApiClient;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Costos desde la API (2026-11-26, pedido explícito): trae de Meta, TikTok
 * y Google Ads el gasto, impresiones y clicks por anuncio y día de cada cuenta
 * publicitaria activa del país, y los guarda en:
 *
 * - plataforma_anuncios_diario: el detalle campaña → conjunto → anuncio
 *   (vista En vivo), con lo que había en la corrida anterior.
 * - medios_diario (origen 'api'): por campaña y día, lo que leen Diario,
 *   Daily, Weekly, Control de gasto, Channel y SO. En los días que trae, la
 *   API reemplaza las filas del CSV de data.medios de esas mismas campañas
 *   (por ID de campaña o, si el CSV no lo trae, por nombre); lo que solo
 *   viene en el CSV (otros medios, otras cuentas) se queda.
 *
 * Clicks: en Meta, clicks al enlace (inline_link_clicks, como Creativos);
 * en TikTok y Google, clicks. Costo en USD con el tipo de cambio del día (Divisas).
 */
class SincronizadorCostosApi
{
    /** TikTok no deja pedir más de 30 días por reporte. */
    private const DIAS_POR_REPORTE_TIKTOK = 30;

    public const PLATAFORMAS = ['meta', 'tiktok', 'google'];

    private const NOMBRES = ['meta' => 'Meta', 'tiktok' => 'TikTok', 'google' => 'Google Ads'];

    public function __construct(
        private readonly ?MetaApiClient $meta,
        private readonly ?TiktokApiClient $tiktok,
        private readonly Divisas $divisas,
        private readonly ?GoogleAdsApiClient $google = null,
    ) {}

    /**
     * @return array{cuentas: list<array{cuenta: string, plataforma: string, anuncios: int, costo: float}>, errores: list<string>}
     */
    public function sincronizar(int $paisId, string $desde, string $hasta): array
    {
        $resultado = ['cuentas' => [], 'errores' => []];
        $cuentas = CuentaPublicitaria::where('pais_id', $paisId)
            ->where('activa', true)->whereIn('plataforma', self::PLATAFORMAS)->orderBy('id')->get();

        foreach ($cuentas as $cp) {
            $cliente = match ($cp->plataforma) {
                'meta' => $this->meta,
                'tiktok' => $this->tiktok,
                default => $this->google,
            };
            if (! $cliente) {
                $resultado['errores'][] = "{$cp->nombre}: no hay credenciales de ".self::NOMBRES[$cp->plataforma].' en este servidor.';

                continue;
            }
            try {
                $filas = match ($cp->plataforma) {
                    'meta' => $this->traerMeta($cp->id_externo, $desde, $hasta),
                    'tiktok' => $this->traerTiktok($cp->id_externo, $desde, $hasta),
                    default => $this->traerGoogle($cp->id_externo, $desde, $hasta),
                };
                $moneda = $cp->moneda ?: $this->moneda($cp, $desde, $hasta);
                $this->guardar($cp, $filas, $moneda, $desde, $hasta);
                $resultado['cuentas'][] = [
                    'cuenta' => $cp->nombre, 'plataforma' => $cp->plataforma,
                    'anuncios' => count(array_unique(array_column($filas, 'anuncio_id'))),
                    'costo' => round(array_sum(array_map(fn ($f) => $this->aUsd($f['costo'], $moneda, $f['fecha']), $filas)), 2),
                ];
            } catch (Throwable $e) {
                $resultado['errores'][] = "{$cp->nombre}: {$e->getMessage()}";
            }
        }

        return $resultado;
    }

    /** @return list<array{fecha: string, campania_id: string, campania: string, conjunto_id: ?string, conjunto: ?string, anuncio_id: string, anuncio: ?string, costo: float, impresiones: int, clicks: int}> */
    public function traerMeta(string $adAccountId, string $desde, string $hasta): array
    {
        $filas = [];
        $after = null;
        do {
            $params = [
                'level' => 'ad',
                'fields' => 'campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,spend,impressions,inline_link_clicks',
                'time_range' => json_encode(['since' => $desde, 'until' => $hasta]),
                'time_increment' => '1',
                'limit' => '500',
            ];
            if ($after) {
                $params['after'] = $after;
            }
            $data = $this->meta->get("act_{$adAccountId}/insights", $params);
            foreach ($data['data'] ?? [] as $r) {
                $filas[] = [
                    'fecha' => (string) $r['date_start'],
                    'campania_id' => (string) ($r['campaign_id'] ?? ''),
                    'campania' => (string) ($r['campaign_name'] ?? ''),
                    'conjunto_id' => ($r['adset_id'] ?? null) ?: null,
                    'conjunto' => ($r['adset_name'] ?? null) ?: null,
                    'anuncio_id' => (string) $r['ad_id'],
                    'anuncio' => ($r['ad_name'] ?? null) ?: null,
                    'costo' => (float) ($r['spend'] ?? 0),
                    'impresiones' => (int) ($r['impressions'] ?? 0),
                    'clicks' => (int) ($r['inline_link_clicks'] ?? 0),
                ];
            }
            $after = ! empty($data['paging']['next']) ? ($data['paging']['cursors']['after'] ?? null) : null;
        } while ($after);

        return $filas;
    }

    /** @return list<array<string, mixed>> misma forma que traerMeta() */
    public function traerTiktok(string $advertiserId, string $desde, string $hasta): array
    {
        $filas = [];
        $inicio = CarbonImmutable::parse($desde);
        $fin = CarbonImmutable::parse($hasta);
        while ($inicio <= $fin) {
            $corte = $inicio->addDays(self::DIAS_POR_REPORTE_TIKTOK - 1)->min($fin);
            $page = 1;
            do {
                $data = $this->tiktok->get('/report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type' => 'BASIC',
                    'data_level' => 'AUCTION_AD',
                    'dimensions' => ['ad_id', 'stat_time_day'],
                    'metrics' => ['spend', 'impressions', 'clicks', 'campaign_id', 'campaign_name', 'adgroup_id', 'adgroup_name', 'ad_name'],
                    'start_date' => $inicio->toDateString(),
                    'end_date' => $corte->toDateString(),
                    // Sin esto TikTok omite el gasto de los ads eliminados (ver EnriquecedorCostosTiktok).
                    'filtering' => [['field_name' => 'ad_status', 'filter_type' => 'IN', 'filter_value' => json_encode(['STATUS_ALL'])]],
                    'page' => $page,
                    'page_size' => 1000,
                ]);
                foreach ($data['list'] ?? [] as $r) {
                    $m = $r['metrics'] ?? [];
                    $f = [
                        'fecha' => substr((string) ($r['dimensions']['stat_time_day'] ?? ''), 0, 10),
                        'campania_id' => (string) ($m['campaign_id'] ?? ''),
                        'campania' => (string) ($m['campaign_name'] ?? ''),
                        'conjunto_id' => ($m['adgroup_id'] ?? null) ?: null,
                        'conjunto' => ($m['adgroup_name'] ?? null) ?: null,
                        'anuncio_id' => (string) $r['dimensions']['ad_id'],
                        'anuncio' => ($m['ad_name'] ?? null) ?: null,
                        'costo' => (float) ($m['spend'] ?? 0),
                        'impresiones' => (int) ($m['impressions'] ?? 0),
                        'clicks' => (int) ($m['clicks'] ?? 0),
                    ];
                    // El reporte trae también los días en 0 de cada ad.
                    if ($f['fecha'] !== '' && ($f['costo'] > 0 || $f['impresiones'] > 0 || $f['clicks'] > 0)) {
                        $filas[] = $f;
                    }
                }
                $totalPaginas = (int) ($data['page_info']['total_page'] ?? 1);
                $page++;
            } while ($page <= $totalPaginas);
            $inicio = $corte->addDay();
        }

        return $filas;
    }

    /**
     * Google por anuncio (ad_group_ad). Performance Max y otras campañas sin
     * anuncios en ad_group_ad no aparecen ahí: lo que el total de la campaña
     * tenga de más se agrega como un renglón "Sin detalle por anuncio", así
     * el costo por campaña siempre cuadra con Google.
     *
     * @return list<array<string, mixed>> misma forma que traerMeta()
     */
    public function traerGoogle(string $customerId, string $desde, string $hasta): array
    {
        $rango = "segments.date BETWEEN '{$desde}' AND '{$hasta}'";
        $filas = [];
        $porCampania = [];
        foreach ($this->google->buscar($customerId, 'SELECT segments.date, campaign.id, campaign.name, ad_group.id, ad_group.name, ad_group_ad.ad.id, ad_group_ad.ad.name, '
            ."metrics.cost_micros, metrics.impressions, metrics.clicks FROM ad_group_ad WHERE {$rango}") as $r) {
            $f = [
                'fecha' => (string) $r['segments']['date'],
                'campania_id' => (string) ($r['campaign']['id'] ?? ''),
                'campania' => (string) ($r['campaign']['name'] ?? ''),
                'conjunto_id' => isset($r['adGroup']['id']) ? (string) $r['adGroup']['id'] : null,
                'conjunto' => ($r['adGroup']['name'] ?? null) ?: null,
                'anuncio_id' => (string) ($r['adGroupAd']['ad']['id'] ?? ''),
                'anuncio' => ($r['adGroupAd']['ad']['name'] ?? null) ?: null,
                'costo' => ((int) ($r['metrics']['costMicros'] ?? 0)) / 1_000_000,
                'impresiones' => (int) ($r['metrics']['impressions'] ?? 0),
                'clicks' => (int) ($r['metrics']['clicks'] ?? 0),
            ];
            if ($f['anuncio_id'] === '' || ($f['costo'] <= 0 && $f['impresiones'] === 0 && $f['clicks'] === 0)) {
                continue;
            }
            $f['anuncio'] ??= 'Anuncio '.$f['anuncio_id'];
            $filas[] = $f;
            $k = $f['fecha'].'|'.$f['campania_id'];
            $porCampania[$k] ??= [0.0, 0, 0];
            $porCampania[$k][0] += $f['costo'];
            $porCampania[$k][1] += $f['impresiones'];
            $porCampania[$k][2] += $f['clicks'];
        }

        foreach ($this->google->buscar($customerId, "SELECT segments.date, campaign.id, campaign.name, metrics.cost_micros, metrics.impressions, metrics.clicks FROM campaign WHERE {$rango}") as $r) {
            $fecha = (string) $r['segments']['date'];
            $id = (string) ($r['campaign']['id'] ?? '');
            [$costo, $impresiones, $clicks] = $porCampania["{$fecha}|{$id}"] ?? [0.0, 0, 0];
            $resto = [
                round(((int) ($r['metrics']['costMicros'] ?? 0)) / 1_000_000 - $costo, 6),
                max(0, (int) ($r['metrics']['impressions'] ?? 0) - $impresiones),
                max(0, (int) ($r['metrics']['clicks'] ?? 0) - $clicks),
            ];
            if ($resto[0] > 0.005 || $resto[1] > 0 || $resto[2] > 0) {
                $filas[] = [
                    'fecha' => $fecha, 'campania_id' => $id, 'campania' => (string) ($r['campaign']['name'] ?? ''),
                    'conjunto_id' => null, 'conjunto' => null,
                    'anuncio_id' => "campania-{$id}", 'anuncio' => 'Sin detalle por anuncio',
                    'costo' => max(0, $resto[0]), 'impresiones' => $resto[1], 'clicks' => $resto[2],
                ];
            }
        }

        return $filas;
    }

    private function moneda(CuentaPublicitaria $cp, string $desde, string $hasta): ?string
    {
        try {
            if ($cp->plataforma === 'google') {
                return strtoupper((string) ($this->google->buscar($cp->id_externo, 'SELECT customer.currency_code FROM customer')[0]['customer']['currencyCode'] ?? '')) ?: null;
            }
            if ($cp->plataforma === 'meta') {
                return strtoupper((string) ($this->meta->get("act_{$cp->id_externo}", ['fields' => 'currency'])['currency'] ?? '')) ?: null;
            }
            $data = $this->tiktok->get('/report/integrated/get/', [
                'advertiser_id' => $cp->id_externo, 'report_type' => 'BASIC', 'data_level' => 'AUCTION_ADVERTISER',
                'dimensions' => ['advertiser_id'], 'metrics' => ['currency'], 'start_date' => $desde, 'end_date' => $hasta,
            ]);

            return strtoupper((string) ($data['list'][0]['metrics']['currency'] ?? '')) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function aUsd(float $monto, ?string $moneda, string $fecha): float
    {
        return $moneda ? $this->divisas->aUsd($monto, $moneda, $fecha) : $monto;
    }

    /** @param  list<array<string, mixed>>  $filas */
    private function guardar(CuentaPublicitaria $cp, array $filas, ?string $moneda, string $desde, string $hasta): void
    {
        $ahora = now();
        $cuentaId = CuentaActual::id();
        // Convertir antes de abrir la transacción: si falta el tipo de cambio, no se borra nada.
        $filas = array_map(fn ($f) => [...$f, 'costo_usd' => round($this->aUsd($f['costo'], $moneda, $f['fecha']), 2)], $filas);

        DB::transaction(function () use ($cp, $filas, $moneda, $desde, $hasta, $ahora, $cuentaId) {
            // --- Detalle por anuncio, con lo de la corrida anterior ---------------
            $previos = DB::table('plataforma_anuncios_diario')->where('cuenta_publicitaria_id', $cp->id)
                ->whereBetween('fecha', [$desde, $hasta])->get(['fecha', 'anuncio_id', 'costo', 'impresiones', 'clicks'])
                ->keyBy(fn ($p) => substr((string) $p->fecha, 0, 10).'|'.$p->anuncio_id);
            DB::table('plataforma_anuncios_diario')->where('cuenta_publicitaria_id', $cp->id)->whereBetween('fecha', [$desde, $hasta])->delete();
            $detalle = array_map(function ($f) use ($cp, $moneda, $ahora, $cuentaId, $previos) {
                $p = $previos[$f['fecha'].'|'.$f['anuncio_id']] ?? null;

                return [
                    'cuenta_id' => $cuentaId, 'pais_id' => $cp->pais_id, 'cuenta_publicitaria_id' => $cp->id, 'plataforma' => $cp->plataforma,
                    'fecha' => $f['fecha'], 'campania_id' => $f['campania_id'], 'campania' => mb_substr($f['campania'], 0, 300),
                    'conjunto_id' => $f['conjunto_id'], 'conjunto' => $f['conjunto'] !== null ? mb_substr($f['conjunto'], 0, 300) : null,
                    'anuncio_id' => $f['anuncio_id'], 'anuncio' => $f['anuncio'] !== null ? mb_substr($f['anuncio'], 0, 400) : null,
                    'moneda' => $moneda, 'costo_original' => round($f['costo'], 2), 'costo' => $f['costo_usd'],
                    'impresiones' => $f['impresiones'], 'clicks' => $f['clicks'],
                    'costo_previo' => $p?->costo, 'impresiones_previo' => $p?->impresiones, 'clicks_previo' => $p?->clicks,
                    'actualizado_en' => $ahora,
                ];
            }, $filas);
            foreach (array_chunk($detalle, 500) as $tanda) {
                DB::table('plataforma_anuncios_diario')->insert($tanda);
            }

            // --- medios_diario por campaña y día ---------------------------------
            $porCampania = [];
            foreach ($filas as $f) {
                $k = $f['fecha'].'|'.$f['campania_id'];
                $porCampania[$k] ??= ['fecha' => $f['fecha'], 'campania_id' => $f['campania_id'], 'campania' => $f['campania'], 'costo' => 0.0, 'clicks' => 0, 'impresiones' => 0];
                $porCampania[$k]['costo'] += $f['costo_usd'];
                $porCampania[$k]['clicks'] += $f['clicks'];
                $porCampania[$k]['impresiones'] += $f['impresiones'];
            }
            DB::table('medios_diario')->where('cuenta_publicitaria_id', $cp->id)->where('origen', 'api')->whereBetween('fecha', [$desde, $hasta])->delete();
            // Filas del CSV de esas mismas campañas en esos días: la API manda.
            foreach (collect($porCampania)->groupBy('fecha') as $fecha => $camps) {
                DB::table('medios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $cp->pais_id)->where('fecha', $fecha)
                    ->where('origen', 'csv')->where('medio', $cp->plataforma)
                    ->where(fn ($q) => $q->whereIn('campania_id', $camps->pluck('campania_id')->filter()->all())
                        ->orWhereIn(DB::raw('LOWER(TRIM(campania))'), $camps->map(fn ($c) => mb_strtolower(trim($c['campania'])))->all()))
                    ->delete();
            }
            $medio = array_map(fn ($c) => [
                'cuenta_id' => $cuentaId, 'pais_id' => $cp->pais_id, 'fecha' => $c['fecha'], 'medio' => $cp->plataforma,
                'medio_original' => $cp->plataforma, 'origen' => 'api', 'cuenta_publicitaria_id' => $cp->id,
                'campania' => mb_substr($c['campania'], 0, 300), 'campania_id' => $c['campania_id'] ?: null,
                'costo' => round($c['costo'], 2), 'clicks' => $c['clicks'], 'impresiones' => $c['impresiones'],
                'views' => 0, 'views_completas' => 0, 'creado_en' => $ahora,
            ], array_values($porCampania));
            foreach (array_chunk($medio, 500) as $tanda) {
                DB::table('medios_diario')->insert($tanda);
            }
        });
    }

    /**
     * Última corrida terminada de la cuenta y cuándo toca la siguiente: el
     * scheduler corre cada `intervalo_minutos` empezando a las 00:`minuto`
     * (hora de CDMX), así que la próxima es la siguiente de esas marcas.
     *
     * @return array{ultima: ?string, proxima: string, estado: ?string, intervalo: int}
     */
    public static function estado(?CarbonImmutable $ahora = null): array
    {
        $intervalo = max(1, (int) config('costos_api.intervalo_minutos', 60));
        $ahora ??= CarbonImmutable::now('America/Mexico_City');
        $marca = $ahora->startOfDay()->addMinutes((int) config('costos_api.minuto', 10));
        while ($marca <= $ahora) {
            $marca = $marca->addMinutes($intervalo);
        }
        $u = DB::table('sincronizaciones_costos')->where('cuenta_id', CuentaActual::id())->whereNotNull('terminado_en')
            ->orderByDesc('terminado_en')->first(['terminado_en', 'estado']);

        return [
            'ultima' => $u ? CarbonImmutable::parse($u->terminado_en)->toIso8601String() : null,
            'proxima' => $marca->toIso8601String(),
            'estado' => $u?->estado,
            'intervalo' => $intervalo,
        ];
    }
}
