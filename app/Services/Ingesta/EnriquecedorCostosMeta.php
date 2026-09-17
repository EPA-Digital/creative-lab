<?php

namespace App\Services\Ingesta;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puerto de enriquecerCostosMeta (pipeline.js, proyecto Node, referencia).
 * Trae costo/impresiones/clicks de TODOS los ads con actividad en el rango
 * (activos o no -- filosofía A, 2026-07-31), más status y la imagen del
 * creativo en un segundo paso best-effort. Incluye los 3 fixes reales
 * encontrados con datos reales durante la migración:
 *   1. filtering=[{field:"ad.id",operator:"IN",...}] en vez de paginar toda
 *      la cuenta -- ~3 llamadas para 111 ads en vez de 34 para 1695.
 *   2. Resolución de imagen vía asset_customization_rules por prioridad
 *      (Story/Feed reales), nunca images[0] a ciegas -- un creative
 *      Advantage+ puede traer varias imágenes candidatas que NO son
 *      recortes del mismo arte.
 *   3. limit=50 explícito en /adimages -- pagina con default de 25 sin
 *      avisar (sin este limit, hashes más allá del puesto 25 del chunk
 *      quedaban sin resolver por error, no por decisión).
 */
class EnriquecedorCostosMeta
{
    public function __construct(
        private readonly MetaApiClient $meta,
        private readonly ImagenCacheService $imagenes,
    ) {}

    /**
     * @return list<array{adId: string, adName: string, campaignName: string, cost: float, impressions: int, clicks: int, status: string, imageUrl: string}>
     */
    public function enriquecer(string $adAccountId, string $desde, string $hasta): array
    {
        $filas = $this->traerCostos($adAccountId, $desde, $hasta);
        [$statusPorAdId, $imagenRemotaPorAdId, $copyPorAdId] = $this->traerStatusEImagen($adAccountId, $filas);

        $imagenLocalPorAdId = $this->imagenes->cachearVarias(
            $imagenRemotaPorAdId,
            fn (string $adId) => "meta-costo-{$adId}"
        );

        foreach ($filas as &$fila) {
            $fila['status'] = $statusPorAdId[$fila['adId']] ?? '';
            $fila['imageUrl'] = $imagenLocalPorAdId[$fila['adId']] ?? '';
            $fila['copy'] = $copyPorAdId[$fila['adId']] ?? null;
        }
        unset($fila);

        return $filas;
    }

    /**
     * @return list<array{adId: string, adName: string, campaignName: string, cost: float, impressions: int, clicks: int}>
     */
    private function traerCostos(string $adAccountId, string $desde, string $hasta): array
    {
        $filas = [];
        $after = null;

        for (;;) {
            $params = [
                'level' => 'ad',
                // ad_name/campaign_name -- hay ads con gasto real en el
                // rango que NUNCA tienen fila en el CSV de AppsFlyer (gasto
                // sin ninguna conversión atribuida todavía) -- sin el
                // nombre acá, esos ads no tienen nada de qué sacar
                // nombre común/funnel/tipo de cuenta del lado del import.
                'fields' => 'ad_id,ad_name,campaign_name,spend,impressions,inline_link_clicks',
                'time_range' => json_encode(['since' => $desde, 'until' => $hasta]),
                'limit' => '500',
            ];
            if ($after) {
                $params['after'] = $after;
            }

            $data = $this->meta->get("act_{$adAccountId}/insights", $params);
            foreach ($data['data'] ?? [] as $row) {
                $filas[] = [
                    'adId' => $row['ad_id'],
                    'adName' => $row['ad_name'] ?? '',
                    'campaignName' => $row['campaign_name'] ?? '',
                    'cost' => (float) ($row['spend'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'clicks' => (int) ($row['inline_link_clicks'] ?? 0),
                ];
            }

            $after = ! empty($data['paging']['next']) ? ($data['paging']['cursors']['after'] ?? null) : null;
            if (! $after) {
                break;
            }
        }

        return $filas;
    }

    /**
     * Segunda pasada, liviana y best-effort. filtering ad.id IN en tandas de
     * 50, cada una en su propio try/catch -- una tanda que pegue rate limit
     * no tira las demás, y el costo (que ya se guardó) nunca se pierde.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, array{titulo: ?string, texto: ?string}>}  [statusPorAdId, imagenRemotaPorAdId, copyPorAdId]
     */
    private function traerStatusEImagen(string $adAccountId, array $filas): array
    {
        $adIdsEnRango = array_values(array_unique(array_column($filas, 'adId')));

        return $this->traerStatusEImagenParaIds($adAccountId, $adIdsEnRango);
    }

    /**
     * Wrapper público sobre traerStatusEImagen para el comando de reparación
     * (2026-08-12) -- acepta una lista de ad_id directa (no derivada de
     * filas de costo), para poder re-consultar solo los creativos que ya
     * están en la base con imagen_url/copy faltante, sin tener que volver a
     * traer costos. A diferencia de traerStatusEImagenParaIds (uso interno,
     * devuelve la URL REMOTA sin cachear -- enriquecer() hace el cacheo
     * aparte), este método SÍ cachea localmente antes de devolver, para que
     * el comando de reparación reciba directo la ruta local final.
     *
     * @param  list<string>  $adIds
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, array{titulo: ?string, texto: ?string}>}  [statusPorAdId, imagenLocalPorAdId, copyPorAdId]
     */
    public function reintentarImagenYCopy(string $adAccountId, array $adIds): array
    {
        [$statusPorAdId, $imagenRemotaPorAdId, $copyPorAdId] = $this->traerStatusEImagenParaIds($adAccountId, array_values(array_unique($adIds)));

        $imagenLocalPorAdId = $this->imagenes->cachearVarias(
            $imagenRemotaPorAdId,
            fn (string $adId) => "meta-costo-{$adId}"
        );

        return [$statusPorAdId, $imagenLocalPorAdId, $copyPorAdId];
    }

    /**
     * @param  list<string>  $adIds
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, array{titulo: ?string, texto: ?string}>}
     */
    private function traerStatusEImagenParaIds(string $adAccountId, array $adIds): array
    {
        $statusPorAdId = [];
        $imagenRemotaPorAdId = [];
        $copyPorAdId = [];
        $hashPorAdId = [];
        $thumbnailPorAdId = [];
        $hashesUnicos = [];

        foreach (array_chunk($adIds, 50) as $chunk) {
            $r = $this->procesarTandaAds($adAccountId, $chunk);
            $statusPorAdId += $r['status'];
            $imagenRemotaPorAdId += $r['imagenRemota'];
            $copyPorAdId += $r['copy'];
            $hashPorAdId += $r['hashPorAdId'];
            $thumbnailPorAdId += $r['thumbnailPorAdId'];
            foreach ($r['hashPorAdId'] as $hash) {
                $hashesUnicos[$hash] = true;
            }
        }

        $urlPorHash = $this->resolverHashesAImagenes($adAccountId, array_keys($hashesUnicos));

        foreach ($hashPorAdId as $adId => $hash) {
            $url = $urlPorHash[$hash] ?? ($thumbnailPorAdId[$adId] ?? null);
            if ($url) {
                $imagenRemotaPorAdId[$adId] = $url;
            }
        }

        return [$statusPorAdId, $imagenRemotaPorAdId, $copyPorAdId];
    }

    /**
     * Trae status/imagen/copy de UNA tanda -- si falla, la PARTE A LA MITAD
     * y reintenta cada mitad por separado (recursivo, piso de 5) en vez de
     * perder la tanda de 50 completa (2026-08-12, fix real: confirmado en
     * logs que Meta a veces rechaza una tanda de 50 con
     * "500: Please reduce the amount of data you're asking for" -- el
     * `fields` anidado, asset_feed_spec completo, es pesado para ciertas
     * combinaciones de 50 ads; partiendo la tanda, la mitad "liviana" se
     * recupera aunque la otra siga fallando).
     *
     * @param  list<string>  $chunk
     * @return array{status: array<string, string>, imagenRemota: array<string, string>, copy: array<string, array{titulo: ?string, texto: ?string}>, hashPorAdId: array<string, string>, thumbnailPorAdId: array<string, string>}
     */
    private function procesarTandaAds(string $adAccountId, array $chunk, int $tamanioMinimo = 5): array
    {
        try {
            // body/title del creative viajan GRATIS en esta misma llamada --
            // ya se pedía status/imagen por acá, no hace falta una segunda
            // pasada solo para el copy (confirmado 2026-08-04:
            // creative{body,title} funciona en el mismo campo singular, no
            // el edge adcreatives{}). Para ads Advantage+
            // (asset_feed_spec.ad_formats=AUTOMATIC_FORMAT, ~70% de esta
            // cuenta) creative.body/title vienen null -- el texto real vive
            // en asset_feed_spec.bodies[0].text/titles[0].text, mismo objeto
            // que ya se pedía para la imagen (verificado 2026-08-04 contra
            // la API real).
            $params = [
                'fields' => 'id,effective_status,creative{body,title,image_url,thumbnail_url,asset_feed_spec{bodies,titles,images{hash,adlabels},asset_customization_rules{priority,image_label}}}',
                'filtering' => json_encode([['field' => 'ad.id', 'operator' => 'IN', 'value' => $chunk]]),
                'limit' => '50',
            ];
            $data = $this->meta->get("act_{$adAccountId}/ads", $params);

            $statusPorAdId = [];
            $imagenRemotaPorAdId = [];
            $copyPorAdId = [];
            $hashPorAdId = [];
            $thumbnailPorAdId = [];

            foreach ($data['data'] ?? [] as $ad) {
                $statusPorAdId[$ad['id']] = $ad['effective_status'] ?? '';
                $titulo = $ad['creative']['title'] ?? ($ad['creative']['asset_feed_spec']['titles'][0]['text'] ?? null);
                $texto = $ad['creative']['body'] ?? ($ad['creative']['asset_feed_spec']['bodies'][0]['text'] ?? null);
                if ($titulo || $texto) {
                    $copyPorAdId[$ad['id']] = ['titulo' => $titulo, 'texto' => $texto];
                }
                $imagenRemota = $ad['creative']['image_url'] ?? null;
                if ($imagenRemota) {
                    $imagenRemotaPorAdId[$ad['id']] = $imagenRemota;

                    continue;
                }
                $hash = self::elegirHashAssetFeedSpec($ad['creative']['asset_feed_spec'] ?? null);
                if ($hash) {
                    $hashPorAdId[$ad['id']] = $hash;
                    if (! empty($ad['creative']['thumbnail_url'])) {
                        $thumbnailPorAdId[$ad['id']] = $ad['creative']['thumbnail_url'];
                    }
                } elseif (! empty($ad['creative']['thumbnail_url'])) {
                    $imagenRemotaPorAdId[$ad['id']] = $ad['creative']['thumbnail_url'];
                }
            }

            return [
                'status' => $statusPorAdId,
                'imagenRemota' => $imagenRemotaPorAdId,
                'copy' => $copyPorAdId,
                'hashPorAdId' => $hashPorAdId,
                'thumbnailPorAdId' => $thumbnailPorAdId,
            ];
        } catch (Throwable $e) {
            if (count($chunk) > $tamanioMinimo) {
                $mitad = (int) ceil(count($chunk) / 2);
                $a = $this->procesarTandaAds($adAccountId, array_slice($chunk, 0, $mitad), $tamanioMinimo);
                $b = $this->procesarTandaAds($adAccountId, array_slice($chunk, $mitad), $tamanioMinimo);

                return [
                    'status' => $a['status'] + $b['status'],
                    'imagenRemota' => $a['imagenRemota'] + $b['imagenRemota'],
                    'copy' => $a['copy'] + $b['copy'],
                    'hashPorAdId' => $a['hashPorAdId'] + $b['hashPorAdId'],
                    'thumbnailPorAdId' => $a['thumbnailPorAdId'] + $b['thumbnailPorAdId'],
                ];
            }

            Log::warning('No se pudo traer status/imagen para una tanda de '.count($chunk)." ad(s) tras partir al mínimo (rate limit u otro error de la API): {$e->getMessage()}");

            return ['status' => [], 'imagenRemota' => [], 'copy' => [], 'hashPorAdId' => [], 'thumbnailPorAdId' => []];
        }
    }

    /**
     * Detecta ads SIN actividad real (impressions=0 y spend=0) en una
     * ventana larga hacia atrás (4 meses por defecto) -- pedido explícito
     * del negocio (2026-08-04): Meta nunca confirma "eliminado" como sí
     * hace TikTok (/ad/get/ vacío, ver EnriquecedorCostosTiktok), pero un
     * ad pausado hace 4+ meses sin ningún gasto/impresión ya no debe
     * mostrarse clasificado como DTC/BRD solo porque el nombre trae ese
     * segmento -- el negocio ya no puede confiar en esa clasificación si
     * el ad lleva tanto tiempo muerto. Solo se llama para ads que YA
     * vinieron sin costo en el rango recién importado (tieneMeta=false) --
     * no es una segunda pasada sobre todos los ads.
     *
     * Best-effort igual que traerStatusEImagen: una tanda que falle NO se
     * marca como inactiva (mejor mantener la clasificación del nombre que
     * reclasificar mal por un error transitorio de la API).
     *
     * @param  list<string>  $adIds
     * @return list<string>  subconjunto de $adIds SIN actividad en la ventana
     */
    public function detectarSinActividadReciente(string $adAccountId, array $adIds, string $hasta, int $meses = 4): array
    {
        if ($adIds === []) {
            return [];
        }

        $desdeLargo = date('Y-m-d', strtotime("{$hasta} -{$meses} months"));
        $conActividad = [];

        foreach (array_chunk($adIds, 50) as $chunk) {
            try {
                $data = $this->meta->get("act_{$adAccountId}/insights", [
                    'level' => 'ad',
                    'fields' => 'ad_id,impressions,spend',
                    'time_range' => json_encode(['since' => $desdeLargo, 'until' => $hasta]),
                    'filtering' => json_encode([['field' => 'ad.id', 'operator' => 'IN', 'value' => $chunk]]),
                    'limit' => '50',
                ]);
                foreach ($data['data'] ?? [] as $row) {
                    if (((float) ($row['spend'] ?? 0)) > 0 || ((int) ($row['impressions'] ?? 0)) > 0) {
                        $conActividad[$row['ad_id']] = true;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo revisar actividad histórica de una tanda de '.count($chunk)." ad(s) (rate limit u otro error de la API) -- no se reclasifica ninguno de esta tanda: {$e->getMessage()}");
                foreach ($chunk as $id) {
                    $conActividad[$id] = true;
                }
            }
        }

        return array_values(array_diff($adIds, array_keys($conActividad)));
    }

    /**
     * Resuelve en lote (no por ad) los hashes de asset_feed_spec contra
     * adimages.url -- la imagen original tal como se subió, sin recortar.
     * limit=50 explícito -- /adimages pagina con default de 25 SIN avisar
     * (no rechaza, solo devuelve menos de lo pedido); sin este limit, los
     * hashes más allá del puesto 25 del chunk quedaban sin resolver por
     * error, no por decisión. Cada trozo en su propio try/catch.
     *
     * @param  list<string>  $hashes
     * @return array<string, string>  hash => url
     */
    private function resolverHashesAImagenes(string $adAccountId, array $hashes): array
    {
        $urlPorHash = [];
        foreach (array_chunk($hashes, 50) as $chunk) {
            try {
                $data = $this->meta->get("act_{$adAccountId}/adimages", [
                    'hashes' => json_encode($chunk),
                    'fields' => 'hash,url',
                    'limit' => '50',
                ]);
                foreach ($data['data'] ?? [] as $img) {
                    if (! empty($img['hash']) && ! empty($img['url'])) {
                        $urlPorHash[$img['hash']] = $img['url'];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo resolver un lote de '.count($chunk)." hash(es) de imagen contra adimages: {$e->getMessage()}");
            }
        }

        return $urlPorHash;
    }

    /**
     * Un creative Advantage+ puede traer VARIAS imágenes candidatas en
     * asset_feed_spec.images -- NO son recortes del mismo arte, a veces son
     * creativos totalmente distintos (caso real: 3 hashes, Story/Reels y
     * Feed correctos mostrando un six-pack, el tercero -- el que
     * "images[0]" agarraba a ciegas -- un asset viejo/reusado de OTRA
     * promo). asset_customization_rules mapea cada imagen a su regla de
     * placement con un `priority` -- se usa la de prioridad MÁS ALTA
     * (número más bajo), nunca la regla catch-all sin restricción de
     * plataforma/posición (la de prioridad más baja/genérica), que es
     * justo la que traía el asset viejo en el caso real. Si el creative no
     * trae asset_customization_rules (no es Advantage+), cae a images[0].
     *
     * @param  ?array<string, mixed>  $assetFeedSpec
     */
    private static function elegirHashAssetFeedSpec(?array $assetFeedSpec): ?string
    {
        $images = $assetFeedSpec['images'] ?? [];
        if ($images === []) {
            return null;
        }

        $reglas = $assetFeedSpec['asset_customization_rules'] ?? [];
        if ($reglas !== []) {
            usort($reglas, fn ($a, $b) => ($a['priority'] ?? PHP_INT_MAX) <=> ($b['priority'] ?? PHP_INT_MAX));
            $labelId = $reglas[0]['image_label']['id'] ?? null;
            if ($labelId !== null) {
                foreach ($images as $img) {
                    foreach ($img['adlabels'] ?? [] as $label) {
                        if (($label['id'] ?? null) === $labelId) {
                            return $img['hash'] ?? null;
                        }
                    }
                }
            }
        }

        return $images[0]['hash'] ?? null;
    }
}
