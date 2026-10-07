<?php

namespace App\Services\Reportes;

use App\Models\CuentaPublicitaria;
use App\Services\ImagenFirmadaService;
use App\Services\Ingesta\EnriquecedorCostosMeta;
use App\Services\Ingesta\EnriquecedorCostosTiktok;
use App\Services\Ingesta\ImagenCacheService;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reporte de creativos de un mes en Excel/PDF (pedido explícito
 * 2026-10-07): lo mismo que muestra "Datos por mes" (por arte o por
 * anuncio, con los mismos filtros) más imagen, campaña, copy, formato y
 * cuenta. Ver ExportadorExcelCreativos / ExportadorPdfCreativos.
 *
 * Solo lectura: si un creativo no tiene la imagen en el bucket, se le pide
 * la URL a Meta/TikTok y se descarga para el reporte, sin guardar nada.
 */
class ReporteCreativosService
{
    private const ANCHO_MINIATURA = 160;

    private const ALTO_MAXIMO_MINIATURA = 200;

    public function __construct(private readonly ImagenFirmadaService $firmas) {}

    /**
     * @param  array{plataforma?: ?string, funnel?: ?string, q?: ?string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function filas(int $paisId, string $mes, string $agrupar, array $filtros = []): array
    {
        $anuncios = DB::table('resultados')
            ->join('creativos', 'creativos.id', '=', 'resultados.creativo_id')
            ->leftJoin('cuentas_publicitarias', 'cuentas_publicitarias.id', '=', 'creativos.cuenta_publicitaria_id')
            ->leftJoin('correcciones_nombres', 'correcciones_nombres.ad_id', '=', 'creativos.ad_id')
            ->where('creativos.pais_id', $paisId)
            ->where('resultados.mes', $mes)
            ->get([
                'creativos.id', 'creativos.ad_id', 'creativos.nombre_comun', 'creativos.nombre_completo',
                'creativos.nombre_campania', 'creativos.copy', 'creativos.imagen_url', 'creativos.plataforma',
                'creativos.formato', 'creativos.funnel', 'creativos.tipo_cuenta',
                'cuentas_publicitarias.nombre as cuenta', 'correcciones_nombres.nombre_corregido as nombre_amigable',
                'resultados.cost', 'resultados.impressions', 'resultados.clicks', 'resultados.installs',
                'resultados.nc', 'resultados.orders',
            ])
            ->map(fn ($a) => [
                'creativoId' => $a->id,
                'adId' => $a->ad_id,
                'nombre' => $a->nombre_comun ?: $a->nombre_completo,
                'nombreAmigable' => $a->nombre_amigable,
                'plataforma' => $a->plataforma,
                'funnel' => $a->funnel,
                'tipoCuenta' => $a->tipo_cuenta,
                'formato' => $a->formato,
                'cuenta' => $a->cuenta,
                'campania' => $a->nombre_campania,
                'copy' => is_string($a->copy) ? json_decode($a->copy, true) : $a->copy,
                'imagenUrl' => $a->imagen_url,
                'cost' => (float) $a->cost,
                'impressions' => (int) $a->impressions,
                'clicks' => (int) $a->clicks,
                'installs' => (int) $a->installs,
                'nc' => $a->nc === null ? null : (int) $a->nc,
                'orders' => $a->orders === null ? null : (int) $a->orders,
            ])
            ->filter(fn (array $a) => self::pasaFiltros($a, $filtros));

        $filas = $agrupar === 'anuncio'
            ? $anuncios->map(fn (array $a) => self::fila([$a]))
            : $anuncios->groupBy(fn (array $a) => implode('|', [$a['nombre'], $a['funnel'], $a['plataforma']]))
                ->map(fn ($grupo) => self::fila($grupo->all()));

        return $filas->sortByDesc('cost')->values()->all();
    }

    /**
     * Miniatura JPEG (bytes) de cada fila, en el mismo orden -- null si no
     * hay imagen en ningún lado. Primero el bucket; lo que falte, a Meta/
     * TikTok (URL remota, sin guardar).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<int, ?string>
     */
    public function miniaturas(int $paisId, array $filas): array
    {
        $urlPorFila = [];
        foreach ($filas as $i => $fila) {
            if (ImagenCacheService::esImagenEnBucket($fila['imagenUrl']) && ($firmada = $this->firmarSinFallar($fila['imagenUrl']))) {
                $urlPorFila[$i] = $firmada;
            }
        }

        $faltan = array_diff_key($filas, $urlPorFila);
        foreach ($this->urlsRemotas($paisId, $faltan) as $i => $url) {
            $urlPorFila[$i] = $url;
        }

        $miniaturas = array_fill_keys(array_keys($filas), null);
        foreach (array_chunk($urlPorFila, 25, preserve_keys: true) as $tanda) {
            $respuestas = Http::pool(fn (Pool $pool) => array_map(
                fn ($i, $url) => $pool->as((string) $i)->timeout(30)->get($url),
                array_keys($tanda),
                $tanda,
            ));
            foreach (array_keys($tanda) as $i) {
                $r = $respuestas[(string) $i] ?? null;
                if ($r instanceof Response && $r->successful()) {
                    $miniaturas[$i] = self::miniatura($r->body());
                }
            }
        }

        return $miniaturas;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    public static function totales(array $filas): array
    {
        $sumar = fn (string $campo) => array_sum(array_map(fn ($f) => $f[$campo] ?? 0, $filas));
        $hay = fn (string $campo) => count(array_filter($filas, fn ($f) => $f[$campo] !== null)) > 0;

        return self::conEficiencias([
            'cost' => $sumar('cost'),
            'impressions' => $sumar('impressions'),
            'clicks' => $sumar('clicks'),
            'installs' => $sumar('installs'),
            'nc' => $hay('nc') ? $sumar('nc') : null,
            'orders' => $hay('orders') ? $sumar('orders') : null,
            'anuncios' => $sumar('anuncios'),
        ]);
    }

    /**
     * Una fila del reporte a partir de uno o varios anuncios (mismo arte):
     * métricas sumadas, ratios recalculados desde las sumas, y para
     * imagen/copy/nombre amigable el anuncio de mayor gasto.
     *
     * @param  list<array<string, mixed>>  $anuncios
     * @return array<string, mixed>
     */
    private static function fila(array $anuncios): array
    {
        usort($anuncios, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        $rep = $anuncios[0];
        $sumar = fn (string $campo) => array_sum(array_column($anuncios, $campo));
        $hay = fn (string $campo) => count(array_filter(array_column($anuncios, $campo), fn ($v) => $v !== null)) > 0;

        return self::conEficiencias([
            'creativoId' => $rep['creativoId'],
            'adId' => $rep['adId'],
            'adIds' => array_values(array_unique(array_column($anuncios, 'adId'))),
            'nombre' => $rep['nombre'],
            'nombreAmigable' => $rep['nombreAmigable'],
            'plataforma' => $rep['plataforma'],
            'funnel' => $rep['funnel'],
            'tipoCuenta' => $rep['tipoCuenta'],
            'formato' => $rep['formato'],
            'cuenta' => $rep['cuenta'],
            'campanias' => array_values(array_unique(array_filter(array_column($anuncios, 'campania')))),
            'copyTitulo' => $rep['copy']['titulo'] ?? null,
            'copyTexto' => $rep['copy']['texto'] ?? null,
            'imagenUrl' => $rep['imagenUrl'],
            'anuncios' => count($anuncios),
            'cost' => $sumar('cost'),
            'impressions' => $sumar('impressions'),
            'clicks' => $sumar('clicks'),
            'installs' => $sumar('installs'),
            'nc' => $hay('nc') ? $sumar('nc') : null,
            'orders' => $hay('orders') ? $sumar('orders') : null,
        ]);
    }

    /**
     * Mismas fórmulas que DatosMensualesController::conEficiencias.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private static function conEficiencias(array $f): array
    {
        $cost = (float) $f['cost'];

        return [...$f,
            'ctr' => $f['impressions'] > 0 ? $f['clicks'] / $f['impressions'] * 100 : null,
            'cpm' => $f['impressions'] > 0 ? $cost / $f['impressions'] * 1000 : null,
            'cpi' => $f['installs'] > 0 ? $cost / $f['installs'] : null,
            'cac' => $f['nc'] ? $cost / $f['nc'] : null,
            'cpo' => $f['orders'] ? $cost / $f['orders'] : null,
        ];
    }

    /**
     * Mismos filtros que la tabla de "Datos por mes" (DatosMensuales.vue).
     *
     * @param  array<string, mixed>  $a
     * @param  array{plataforma?: ?string, funnel?: ?string, q?: ?string}  $filtros
     */
    private static function pasaFiltros(array $a, array $filtros): bool
    {
        $plataforma = $filtros['plataforma'] ?? null;
        if ($plataforma && $plataforma !== 'TODAS' && $a['plataforma'] !== $plataforma) {
            return false;
        }
        $funnel = $filtros['funnel'] ?? null;
        if ($funnel && $funnel !== 'TODOS' && ($a['funnel'] ?? 'SIN') !== $funnel) {
            return false;
        }
        $q = mb_strtolower(trim((string) ($filtros['q'] ?? '')));

        return $q === '' || collect([$a['nombre'], $a['adId'], $a['campania'], $a['cuenta']])
            ->contains(fn ($v) => str_contains(mb_strtolower((string) $v), $q));
    }

    /**
     * URL remota (Meta/TikTok) de las filas sin imagen en el bucket,
     * probando cada cuenta activa del país.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, string>
     */
    private function urlsRemotas(int $paisId, array $filas): array
    {
        $urls = [];
        foreach (['meta', 'tiktok'] as $plataforma) {
            $filaPorAdId = [];
            foreach ($filas as $i => $f) {
                if ($f['plataforma'] === $plataforma) {
                    $filaPorAdId[$f['adId']] = $i;
                }
            }
            foreach (CuentaPublicitaria::activasPara($paisId, $plataforma)->pluck('cuenta_id') as $cuentaId) {
                if ($filaPorAdId === []) {
                    break;
                }
                try {
                    $remotas = $plataforma === 'meta'
                        ? (new EnriquecedorCostosMeta(MetaApiClient::fromConfig(), new ImagenCacheService))->imagenesRemotas($cuentaId, array_keys($filaPorAdId))
                        : (new EnriquecedorCostosTiktok(TiktokApiClient::fromConfig(), new ImagenCacheService))->portadasRemotas($cuentaId, array_map('strval', array_keys($filaPorAdId)));
                } catch (Throwable $e) {
                    Log::warning("Reporte de creativos: no se pudieron traer imágenes de {$plataforma} (cuenta {$cuentaId}): {$e->getMessage()}");

                    continue;
                }
                foreach ($remotas as $adId => $url) {
                    if (isset($filaPorAdId[$adId])) {
                        $urls[$filaPorAdId[$adId]] = $url;
                        unset($filaPorAdId[$adId]);
                    }
                }
            }
        }

        return $urls;
    }

    private function firmarSinFallar(string $url): ?string
    {
        try {
            return $this->firmas->firmar($url);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * JPEG chico (160 px de ancho, máx. 200 de alto) para que el Excel/PDF
     * no pese decenas de MB. null si la imagen no se puede leer.
     */
    public static function miniatura(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }
        $ancho = imagesx($img);
        $alto = imagesy($img);
        $escala = min(self::ANCHO_MINIATURA / $ancho, self::ALTO_MAXIMO_MINIATURA / $alto, 1);
        $chica = imagescale($img, max(1, (int) round($ancho * $escala)), max(1, (int) round($alto * $escala)));
        ob_start();
        imagejpeg($chica ?: $img, null, 78);

        return ob_get_clean() ?: null;
    }
}
