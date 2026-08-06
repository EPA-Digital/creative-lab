<?php

namespace App\Services\Ingesta;

/**
 * Puerto de clasificarYAgruparAppsFlyer + filtrarAfPorRango + cruzarYAgrupar
 * (dashboard/shared/motor.js, proyecto Node, referencia). Clasifica cada Ad
 * ID del CSV de AppsFlyer por plataforma, acota su serie diaria al rango
 * pedido, y cruza por Ad ID contra los costos de la API -- el resultado son
 * "cards" listas para calcularVentaReal y persistir en creativos/resultados.
 *
 * A diferencia de Node, unifica cruzarYAgrupar (Meta) y
 * cruzarTikTokFaseUnoYDos (TikTok) en una sola función parametrizada por
 * plataforma -- las dos hacen el mismo cruce por Ad ID para lo que importa
 * acá (costo/impresiones/clicks/installs/NC/orders); las diferencias reales
 * entre ellas en Node son de PRESENTACIÓN (agrupación Smart+, "solo API"
 * habilitado por funnel) que vive en la capa de dashboard, no en lo que se
 * guarda en resultados.
 */
class CruceCostosAppsFlyer
{
    /**
     * Clasifica cada Ad ID agregado del CSV por plataforma (ClasificadorNombres)
     * y separa meta/tiktok/excluidos -- un ad sin bloque FB-/TKT- reconocible
     * nunca se adivina, va a excluidos.
     *
     * @param  list<array{adId: string, campaign: string, adRaw: string, serie: list<array<string, mixed>>}>  $limpias
     * @return array{meta: list<array<string, mixed>>, tiktok: list<array<string, mixed>>, excluidos: list<array<string, mixed>>}
     */
    public static function clasificarPorPlataforma(array $limpias): array
    {
        $meta = [];
        $tiktok = [];
        $excluidos = [];

        foreach ($limpias as $ad) {
            $r = ClasificadorNombres::parsearNombre($ad['campaign'], $ad['adRaw']);
            if ($r['plataforma'] === null) {
                $excluidos[] = $ad;

                continue;
            }
            $enriquecido = array_merge($ad, [
                'etapaFunnel' => $r['funnel'] ?? 'Sin clasificar',
                'tipoCuenta' => $r['tipoCuenta'],
                'arte' => $r['arte'],
                'patronCampania' => $r['patron'],
                'formato' => $r['formato'],
            ]);
            if ($r['plataforma'] === 'meta') {
                $meta[] = $enriquecido;
            } else {
                $tiktok[] = $enriquecido;
            }
        }

        return ['meta' => $meta, 'tiktok' => $tiktok, 'excluidos' => $excluidos];
    }

    /**
     * Acota la serie diaria de cada Ad ID al rango [desde, hasta] -- suma
     * installs/orders/newCustomers SOLO de los puntos dentro del rango.
     * columnaNC/columnaOrders null (la columna no existía en el CSV) hace
     * que el total quede null, nunca 0 inventado.
     *
     * @param  list<array<string, mixed>>  $clasificados
     * @return list<array<string, mixed>>
     */
    public static function acotarARango(array $clasificados, string $desde, string $hasta, ?string $columnaNC, ?string $columnaOrders): array
    {
        return array_map(function (array $a) use ($desde, $hasta, $columnaNC, $columnaOrders) {
            $sumas = self::sumarSerieEnRango($a['serie'], $desde, $hasta, ['installs', 'orders', 'newCustomers']);

            return array_merge($a, [
                'installs' => $sumas['installs'],
                'orders' => $columnaOrders === null ? null : $sumas['orders'],
                'newCustomers' => $columnaNC === null ? null : $sumas['newCustomers'],
            ]);
        }, $clasificados);
    }

    /**
     * @param  list<array{fecha: string, installs: int|float, orders: int|float, newCustomers: int|float}>  $serie
     * @param  list<string>  $campos
     * @return array<string, int|float>
     */
    private static function sumarSerieEnRango(array $serie, string $desde, string $hasta, array $campos): array
    {
        $resultado = array_fill_keys($campos, 0);
        foreach ($serie as $punto) {
            $clave = self::fechaOrdenable($punto['fecha']);
            if ($desde !== '' && $clave < $desde) {
                continue;
            }
            if ($hasta !== '' && $clave > $hasta) {
                continue;
            }
            foreach ($campos as $c) {
                $resultado[$c] += $punto[$c] ?? 0;
            }
        }

        return $resultado;
    }

    /**
     * fecha viene como DD-MM-YYYY (formato de AppsFlyerCsvParser) --
     * invertido queda YYYY-MM-DD, comparable como string igual que en Node.
     */
    private static function fechaOrdenable(string $fecha): string
    {
        return implode('-', array_reverse(explode('-', $fecha)));
    }

    /**
     * Cruce por Ad ID: AppsFlyer (installs/NC/orders, YA acotado al rango
     * por acotarARango) x costos de la API (cost/impressions/clicks/status/
     * imagen, YA de TODO el rango vía EnriquecedorCostosMeta/Tiktok). Un Ad
     * ID puede estar en uno de los dos, en los dos, o en ninguno -- se
     * incluyen los 3 casos, nunca se descarta uno por faltar el otro (un ad
     * con gasto real pero sin AppsFlyer, o con AppsFlyer pero sin gasto en
     * el rango exacto, igual debe aparecer).
     *
     * @param  list<array<string, mixed>>  $afClasificados  salida de acotarARango
     * @param  list<array<string, mixed>>  $costosApi  salida de EnriquecedorCostosMeta::enriquecer / EnriquecedorCostosTiktok::enriquecer
     * @return list<array<string, mixed>>
     */
    public static function cruzar(array $afClasificados, array $costosApi, string $plataforma, bool $esRangoParcial): array
    {
        $afPorId = [];
        foreach ($afClasificados as $a) {
            $afPorId[$a['adId']] = $a;
        }
        $costosPorId = [];
        foreach ($costosApi as $c) {
            $costosPorId[$c['adId']] = $c;
        }
        $todosLosIds = array_unique(array_merge(array_keys($afPorId), array_keys($costosPorId)));

        $cards = [];
        foreach ($todosLosIds as $adId) {
            $af = $afPorId[$adId] ?? null;
            $costos = $costosPorId[$adId] ?? null;

            $installs = $af['installs'] ?? null;
            // AppsFlyer es la ÚNICA fuente válida para NC/Orders -- sin
            // match en AppsFlyer, null, nunca 0 inventado.
            $newCustomers = $af['newCustomers'] ?? null;
            $orders = $af['orders'] ?? null;
            $cost = $costos['cost'] ?? null;
            $impressions = $costos['impressions'] ?? null;
            $clicks = $costos['clicks'] ?? null;
            $ctr = ($impressions && $clicks !== null && $impressions > 0) ? ($clicks / $impressions) * 100 : null;
            $cpm = ($impressions && $cost !== null && $impressions > 0) ? ($cost / $impressions) * 1000 : null;
            $cpi = (! $esRangoParcial && $cost !== null && $installs) ? $cost / $installs : null;

            // Ads con gasto real pero SIN fila en el CSV de AppsFlyer (gasto
            // sin conversión atribuida todavía) no tienen $af -- su única
            // fuente de nombre/campaña es lo que trajo el enriquecedor de
            // costos (Ad Name/Campaign Name de la API).
            $soloApiInfo = (! $af && $costos && ! empty($costos['adName']))
                ? ClasificadorNombres::parsearNombre($costos['campaignName'] ?? null, $costos['adName'])
                : null;

            $afArte = $af['arte'] ?? null;
            $afAdRaw = $af['adRaw'] ?? null;
            $soloApiArte = $soloApiInfo['arte'] ?? null;
            $costosAdName = $costos['adName'] ?? null;
            $adNameShort = $afArte ?: ($afAdRaw ?: ($soloApiArte ?: ($costosAdName ?: "Ad {$adId}")));

            $cards[] = [
                'adId' => $adId,
                'plataforma' => $plataforma,
                'adNameShort' => $adNameShort,
                // tieneMeta real (motor.js: !!costos) -- misma variable
                // $costos de arriba, ANTES de que cost/impressions/clicks se
                // pisen con `?? 0` al persistir.
                'tieneMeta' => $costos !== null,
                'etapaFunnel' => $af ? $af['etapaFunnel'] : ($soloApiInfo ? ($soloApiInfo['funnel'] ?? 'Sin clasificar') : 'Sin clasificar'),
                'tipoCuenta' => $af ? $af['tipoCuenta'] : ($soloApiInfo['tipoCuenta'] ?? null),
                'arte' => $af ? $af['arte'] : ($soloApiInfo['arte'] ?? null),
                // Formato busca en el Ad crudo -- $af ya trae 'formato'
                // resuelto por ClasificadorNombres::parsearNombre() en
                // clasificarPorPlataforma(); soloApiInfo lo resuelve igual
                // cuando no hay match de AppsFlyer.
                'formato' => $af ? $af['formato'] : ($soloApiInfo['formato'] ?? null),
                // campaignName: el Campaign del CSV de AppsFlyer coincide
                // EXACTO con el campaign_name real de Meta/TikTok
                // (verificado 2026-08-04) -- se prioriza por ser el dato ya
                // disponible sin llamada extra; costos['campaignName'] cubre
                // el caso "solo API" (gasto sin fila en AppsFlyer todavía).
                'campaignName' => $af ? $af['campaign'] : ($costos['campaignName'] ?? ''),
                // copy SOLO sale de la API (Meta: creative.body/title:
                // TikTok: ad_text) -- AppsFlyer nunca trae texto de anuncio.
                'copy' => $costos['copy'] ?? null,
                'imageUrl' => $costos['imageUrl'] ?? '',
                'status' => $costos['status'] ?? '',
                'impressions' => $impressions,
                'clicks' => $clicks,
                'cost' => $cost,
                'ctr' => $ctr,
                'cpm' => $cpm,
                'installs' => $installs,
                'newCustomers' => $newCustomers,
                'orders' => $orders,
                'cpi' => $cpi,
            ];
        }

        return $cards;
    }
}
