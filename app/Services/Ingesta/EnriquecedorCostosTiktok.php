<?php

namespace App\Services\Ingesta;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puerto de enriquecerCostosTikTok (pipeline.js, proyecto Node, referencia).
 * Trae costo/impresiones/clicks de TODOS los ads con actividad en el rango
 * (activos o no -- filosofía A, 2026-07-31), más nombre y thumbnail de
 * video en un segundo paso best-effort. /ad/get/ SIN filtro de
 * operation_status (a diferencia de listarAdsActivosTikTok, que sí filtra a
 * ENABLE) -- trae cualquier ad de la cuenta en pocas llamadas paginadas, no
 * un loop por ad.
 */
class EnriquecedorCostosTiktok
{
    public function __construct(
        private readonly TiktokApiClient $tiktok,
        private readonly ImagenCacheService $imagenes,
    ) {}

    /**
     * @return list<array{adId: string, adName: string, cost: float, impressions: int, clicks: int, imageUrl: string}>
     */
    public function enriquecer(string $advertiserId, string $desde, string $hasta): array
    {
        $filas = $this->traerCostos($advertiserId, $desde, $hasta);

        [$nombrePorAdId, $videoIdPorAdId, $campaignNombrePorAdId, $copyPorAdId] = $this->traerNombreYVideoId($advertiserId);
        $imagenRemotaPorVideoId = $this->traerThumbnailsDeVideo($advertiserId, array_values(array_unique($videoIdPorAdId)));

        // Se cachea por Ad ID (no por video_id) porque 2 ads distintos
        // pueden compartir el mismo video reusado -- cada uno necesita su
        // propio archivo cacheado con su propio Ad ID en el nombre.
        $urlPorAdId = [];
        foreach ($videoIdPorAdId as $adId => $videoId) {
            $remota = $imagenRemotaPorVideoId[$videoId] ?? null;
            if ($remota) {
                $urlPorAdId[$adId] = $remota;
            }
        }
        $imagenLocalPorAdId = $this->imagenes->cachearVarias(
            $urlPorAdId,
            fn (string $adId) => "tiktok-costo-{$adId}"
        );

        foreach ($filas as &$fila) {
            $fila['adName'] = $nombrePorAdId[$fila['adId']] ?? '';
            $fila['imageUrl'] = $imagenLocalPorAdId[$fila['adId']] ?? '';
            $fila['campaignName'] = $campaignNombrePorAdId[$fila['adId']] ?? '';
            $fila['copy'] = $copyPorAdId[$fila['adId']] ?? null;
        }
        unset($fila);

        return $filas;
    }

    /**
     * Reparación dirigida por Ad ID (2026-08-12, comando
     * `reparar:creativos-incompletos`) -- a diferencia de
     * traerNombreYVideoId (que pagina la cuenta COMPLETA), esto solo pide
     * los ad_id puntuales que ya están en la base con imagen_url/copy
     * faltante: mucho más chico y rápido, usa `filtering.ad_ids` sobre
     * /ad/get/ (mismo patrón ya probado en detectarEliminados) en tandas de
     * 100, en vez de re-paginar toda la cuenta para reparar unos cientos de
     * ads.
     *
     * @param  list<string>  $adIds
     * @return array<string, array{imagenUrl: ?string, campaignName: ?string, copy: ?array{titulo: ?string, texto: ?string}}>
     */
    public function reintentarNombreImagenYCopy(string $advertiserId, array $adIds): array
    {
        if ($adIds === []) {
            return [];
        }

        $videoIdPorAdId = [];
        $campaignNombrePorAdId = [];
        $copyPorAdId = [];

        foreach (array_chunk($adIds, 100) as $chunk) {
            try {
                $data = $this->tiktok->get('/ad/get/', [
                    'advertiser_id' => $advertiserId,
                    'filtering' => ['ad_ids' => array_map('strval', $chunk)],
                    'page_size' => 100,
                ]);
                foreach ($data['list'] ?? [] as $ad) {
                    if (! empty($ad['video_id'])) {
                        $videoIdPorAdId[$ad['ad_id']] = $ad['video_id'];
                    }
                    if (! empty($ad['campaign_name'])) {
                        $campaignNombrePorAdId[$ad['ad_id']] = $ad['campaign_name'];
                    }
                    if (! empty($ad['ad_text'])) {
                        $copyPorAdId[$ad['ad_id']] = ['titulo' => null, 'texto' => $ad['ad_text']];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo reparar nombre/video para una tanda de '.count($chunk)." ad(s) (rate limit u otro error de la API): {$e->getMessage()}");
            }
        }

        $imagenRemotaPorVideoId = $this->traerThumbnailsDeVideo($advertiserId, array_values(array_unique($videoIdPorAdId)));
        $urlPorAdId = [];
        foreach ($videoIdPorAdId as $adId => $videoId) {
            $remota = $imagenRemotaPorVideoId[$videoId] ?? null;
            if ($remota) {
                $urlPorAdId[$adId] = $remota;
            }
        }
        $imagenLocalPorAdId = $this->imagenes->cachearVarias($urlPorAdId, fn (string $adId) => "tiktok-costo-{$adId}");

        $resultado = [];
        foreach ($adIds as $adId) {
            $resultado[$adId] = [
                'imagenUrl' => $imagenLocalPorAdId[$adId] ?? null,
                'campaignName' => $campaignNombrePorAdId[$adId] ?? null,
                'copy' => $copyPorAdId[$adId] ?? null,
            ];
        }

        return $resultado;
    }

    /**
     * data_level=AUCTION_AD sin filtrar por campaign_automation_type SÍ
     * cubre el spend de ads Smart+/UPGRADED_SMART_PLUS_CREATIVE -- verificado
     * 2026-08-11 contra la cuenta real de Ecuador (5 ad_ids Smart+ conocidos
     * de una campaña activa, todos con spend/impressions/clicks reales y no
     * nulos en esta misma llamada). El único gap real de Smart+ es el texto
     * (ver comentario de traerNombreYVideoId más abajo), no el costo -- no
     * hace falta ningún endpoint distinto para Smart+.
     *
     * @return list<array{adId: string, cost: float, impressions: int, clicks: int}>
     */
    private function traerCostos(string $advertiserId, string $desde, string $hasta): array
    {
        $filas = [];
        $page = 1;

        for (; ;) {
            $data = $this->tiktok->get('/report/integrated/get/', [
                'advertiser_id' => $advertiserId,
                'report_type' => 'BASIC',
                'data_level' => 'AUCTION_AD',
                'dimensions' => ['ad_id'],
                'metrics' => ['spend', 'impressions', 'clicks'],
                'start_date' => $desde,
                'end_date' => $hasta,
                'page' => $page,
                'page_size' => 100,
            ]);

            foreach ($data['list'] ?? [] as $row) {
                $filas[] = [
                    'adId' => $row['dimensions']['ad_id'],
                    'cost' => (float) ($row['metrics']['spend'] ?? 0),
                    'impressions' => (int) ($row['metrics']['impressions'] ?? 0),
                    'clicks' => (int) ($row['metrics']['clicks'] ?? 0),
                ];
            }

            $totalPage = $data['page_info']['total_page'] ?? 1;
            if ($page >= $totalPage) {
                break;
            }
            $page++;
        }

        return $filas;
    }

    /**
     * Cada PÁGINA en su propio try/catch (2026-08-12, fix real: antes el
     * try/catch envolvía el loop de paginación COMPLETO -- para una cuenta
     * grande con 10-20+ páginas, una sola página fallando de forma
     * transitoria perdía nombre/video_id/campaña/copy de la cuenta ENTERA,
     * no solo esa página. Confirmado con datos reales: México, con muchas
     * más páginas que Ecuador, tenía 464 ads con formato=null contra 18 de
     * Ecuador -- desproporción que solo se explica por este blast radius).
     * Si falla la PRIMERA página, no se conoce `total_page` todavía y no hay
     * forma segura de seguir paginando -- ahí sí se corta. Una vez conocido
     * `total_page` de una página exitosa, una falla posterior solo pierde
     * esa página, nunca corta el loop.
     *
     * campaign_name y ad_text viajan GRATIS en esta misma respuesta (sin
     * `fields` explícito, /ad/get/ ya devuelve el objeto completo por
     * defecto) -- ad_text viene vacío para los ads "Smart+ automatizados"
     * (campaign_automation_type=UPGRADED_SMART_PLUS_CREATIVE, ~42% de los
     * ads reales verificado 2026-08-04): TikTok no expone ahí el texto que
     * genera/rota automáticamente, solo lo tienen los ads MANUAL/SMART_PLUS.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>, 3: array<string, array{titulo: ?string, texto: ?string}>} [nombrePorAdId, videoIdPorAdId, campaignNombrePorAdId, copyPorAdId]
     */
    private function traerNombreYVideoId(string $advertiserId): array
    {
        $nombrePorAdId = [];
        $videoIdPorAdId = [];
        $campaignNombrePorAdId = [];
        $copyPorAdId = [];

        $page = 1;
        $totalPage = null;
        for (; ;) {
            try {
                $data = $this->tiktok->get('/ad/get/', [
                    'advertiser_id' => $advertiserId,
                    'page' => $page,
                    'page_size' => 100,
                ]);

                foreach ($data['list'] ?? [] as $ad) {
                    if (! empty($ad['ad_name'])) {
                        $nombrePorAdId[$ad['ad_id']] = $ad['ad_name'];
                    }
                    if (! empty($ad['video_id'])) {
                        $videoIdPorAdId[$ad['ad_id']] = $ad['video_id'];
                    }
                    if (! empty($ad['campaign_name'])) {
                        $campaignNombrePorAdId[$ad['ad_id']] = $ad['campaign_name'];
                    }
                    if (! empty($ad['ad_text'])) {
                        $copyPorAdId[$ad['ad_id']] = ['titulo' => null, 'texto' => $ad['ad_text']];
                    }
                }

                $totalPage = $data['page_info']['total_page'] ?? 1;
            } catch (Throwable $e) {
                Log::warning("No se pudo traer nombre/video de la página {$page} de ads (de ".($totalPage ?? '?').") -- el costo se guarda igual, esta página queda sin nombre/imagen, las demás siguen: {$e->getMessage()}");
                if ($totalPage === null) {
                    break;
                }
            }

            if ($totalPage !== null && $page >= $totalPage) {
                break;
            }
            $page++;
        }

        return [$nombrePorAdId, $videoIdPorAdId, $campaignNombrePorAdId, $copyPorAdId];
    }

    /**
     * /file/video/ad/info/ acepta un array de video_ids en una sola
     * llamada -- se trocea de a 60 ids por llamada, la API rechaza con code
     * 40002 "maximum number of items is 60" si se manda más. Cada tanda en
     * su propio try/catch -- si una sola falla, las demás igual se
     * guardan.
     *
     * @param  list<string>  $videoIds
     * @return array<string, string> video_id => video_cover_url
     */
    private function traerThumbnailsDeVideo(string $advertiserId, array $videoIds): array
    {
        $imagenRemotaPorVideoId = [];

        foreach (array_chunk($videoIds, 60) as $lote) {
            try {
                $data = $this->tiktok->get('/file/video/ad/info/', [
                    'advertiser_id' => $advertiserId,
                    'video_ids' => $lote,
                ]);
                foreach ($data['list'] ?? [] as $v) {
                    if (! empty($v['video_id']) && ! empty($v['video_cover_url'])) {
                        $imagenRemotaPorVideoId[$v['video_id']] = $v['video_cover_url'];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo traer thumbnails de video para una tanda de '.count($lote)." (rate limit u otro error de la API): {$e->getMessage()}");
            }
        }

        return $imagenRemotaPorVideoId;
    }

    /**
     * Detecta ads que YA NO EXISTEN en TikTok -- confirmado con datos
     * reales (2026-08-04): /ad/get/ filtrado por un ad_id que TikTok
     * eliminó de Ads Manager (al terminar su flight) devuelve `list: []`,
     * a diferencia de Meta, que nunca deja de devolver el ad aunque esté
     * pausado hace meses (ver EnriquecedorCostosMeta::
     * detectarSinActividadReciente, la señal equivalente del lado Meta).
     * Solo se llama para ads que YA vinieron sin costo en el rango recién
     * importado (tieneMeta=false).
     *
     * Best-effort: una tanda que falle NO se marca como eliminada (mejor
     * mantener la clasificación del nombre que reclasificar mal por un
     * error transitorio de la API).
     *
     * @param  list<string>  $adIds
     * @return list<string> subconjunto de $adIds que /ad/get/ NO devolvió
     */
    public function detectarEliminados(string $advertiserId, array $adIds): array
    {
        if ($adIds === []) {
            return [];
        }

        $encontrados = [];

        foreach (array_chunk($adIds, 100) as $chunk) {
            try {
                $data = $this->tiktok->get('/ad/get/', [
                    'advertiser_id' => $advertiserId,
                    // ad_ids DEBE viajar como strings en el JSON -- TikTok
                    // rechaza con code 40002 "Not a valid string" si algún
                    // id llega como número (confirmado 2026-08-04, ver
                    // storage/logs/laravel.log).
                    'filtering' => ['ad_ids' => array_map('strval', $chunk)],
                    'fields' => ['ad_id'],
                    'page_size' => 100,
                ]);
                foreach ($data['list'] ?? [] as $ad) {
                    $encontrados[$ad['ad_id']] = true;
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo confirmar si una tanda de '.count($chunk)." ad(s) sigue existiendo en TikTok (rate limit u otro error de la API) -- no se reclasifica ninguno de esta tanda: {$e->getMessage()}");
                foreach ($chunk as $id) {
                    $encontrados[$id] = true;
                }
            }
        }

        return array_values(array_diff($adIds, array_keys($encontrados)));
    }
}
