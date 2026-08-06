<?php

namespace App\Services\Ingesta;

/**
 * Puerto de calcularVentaReal + agruparPorArteYFunnel (dashboard/shared/motor.js,
 * proyecto Node, referencia). Ambas son funciones puras que operan sobre
 * arrays de "cards" ya construidas (no sobre CSV crudo) -- reciben y
 * devuelven arrays asociativos sin forma fija, igual que los objetos JS del
 * lado Node (las cards tienen muchos más campos de los que estas funciones
 * tocan; el resto pasa intacto).
 */
class VentaRealYAgrupacion
{
    /**
     * Reparte el total real tecleado a mano (NC/Orders reales del negocio,
     * NO de AppsFlyer) entre los ads de UNA plataforma según su
     * participación relativa en AppsFlyer -- AppsFlyer da la distribución,
     * el input manual da la magnitud real:
     *   share_ad  = newCustomers_ad (AppsFlyer) / Σ newCustomers de TODOS
     *               los cards recibidos
     *   ncReal_ad = share_ad × ncTotalReal
     * (mismo cálculo para Orders). El denominador incluye TODOS los cards,
     * incluso los que no tengan match de costo en la API -- diluyen el
     * share de los demás igual que en el Excel de referencia del negocio.
     * cac/cpo se recalculan desde ncReal/ordersReal, NUNCA desde
     * newCustomers/orders crudos de AppsFlyer.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    public static function calcularVentaReal(
        array $cards,
        mixed $ncTotalReal,
        mixed $ordersTotalReal,
        bool $esRangoParcial
    ): array {
        $sumaNC = 0;
        $sumaOrders = 0;
        foreach ($cards as $c) {
            $sumaNC += $c['newCustomers'] ?? 0;
            $sumaOrders += $c['orders'] ?? 0;
        }
        $ncTotal = self::normalizarNumero($ncTotalReal);
        $ordersTotal = self::normalizarNumero($ordersTotalReal);
        $hayNcTotal = $ncTotalReal !== null && $ncTotalReal !== '';
        $hayOrdersTotal = $ordersTotalReal !== null && $ordersTotalReal !== '';

        return array_map(function (array $c) use ($sumaNC, $sumaOrders, $ncTotal, $ordersTotal, $hayNcTotal, $hayOrdersTotal, $esRangoParcial) {
            $shareNC = $sumaNC > 0 ? ($c['newCustomers'] ?? 0) / $sumaNC : 0;
            $shareOrders = $sumaOrders > 0 ? ($c['orders'] ?? 0) / $sumaOrders : 0;
            // null explícito (nunca 0) si todavía no se tecleó el total, o
            // si el rango es parcial (el costo de la API no tiene desglose
            // diario, no se puede prorratear a un sub-rango).
            $ncReal = (! $esRangoParcial && $hayNcTotal) ? $shareNC * $ncTotal : null;
            $ordersReal = (! $esRangoParcial && $hayOrdersTotal) ? $shareOrders * $ordersTotal : null;
            $cost = $c['cost'] ?? null;

            return array_merge($c, [
                'ncReal' => $ncReal,
                'ordersReal' => $ordersReal,
                'cac' => ($cost !== null && $ncReal) ? $cost / $ncReal : null,
                'cpo' => ($cost !== null && $ordersReal) ? $cost / $ordersReal : null,
            ]);
        }, $cards);
    }

    /**
     * REGLA DE SUMA POR ARTE -- el mismo concepto creativo (arte) publicado
     * bajo campañas del MISMO funnel se suma. El mismo arte en un funnel
     * distinto NUNCA se suma con sus pares -- es otra evaluación, va aparte.
     * La llave de agrupación es (funnel + arte), no solo arte.
     *
     * Ratios (CAC, CPO, CPI, CTR, CPM) se recalculan desde los totales YA
     * sumados, nunca promediando los ratios de cada fila -- promediar
     * distorsiona el resultado cuando el volumen de cada fila es distinto.
     *
     * Filas sin arte o sin funnel clasificado quedan SUELTAS (no agrupadas)
     * -- nunca se agrupan solas bajo una llave inventada.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return array{cards: list<array<string, mixed>>, stats: array{totalEntrada: int, grupos: int, filasAgrupadas: int, filasSueltas: int}}
     */
    public static function agruparPorArteYFunnel(array $cards): array
    {
        $grupos = [];
        $sueltas = [];

        foreach ($cards as $card) {
            $arte = $card['arte'] ?? null;
            $etapaFunnel = $card['etapaFunnel'] ?? null;
            if (! self::truthy($arte) || ! self::truthy($etapaFunnel) || $etapaFunnel === 'Sin clasificar') {
                $sueltas[] = $card;

                continue;
            }
            $key = $etapaFunnel.'::'.$arte;
            $grupos[$key][] = $card;
        }

        $agrupadas = [];
        foreach ($grupos as $miembros) {
            // El representante (para thumbnail/copy/modal) es el miembro de
            // mayor costo del grupo -- las métricas de negocio se
            // sobreescriben abajo con los totales sumados, nunca se muestra
            // el dato de un solo miembro como si fuera el del grupo
            // completo.
            $ordenadosPorCosto = $miembros;
            usort($ordenadosPorCosto, fn (array $a, array $b) => ($b['cost'] ?? -1) <=> ($a['cost'] ?? -1));
            $representante = $ordenadosPorCosto[0];

            $cost = self::sumOrNull($miembros, 'cost');
            $newCustomers = self::sumOrNull($miembros, 'newCustomers');
            $orders = self::sumOrNull($miembros, 'orders');
            $installs = self::sumOrNull($miembros, 'installs');
            $ncReal = self::sumOrNull($miembros, 'ncReal');
            $ordersReal = self::sumOrNull($miembros, 'ordersReal');
            $totalAttributions = self::sumOrNull($miembros, 'totalAttributions');
            // impressions/clicks se SUMAN (conteos del período); ctr/cpm se
            // RECALCULAN desde esos totales, nunca promediados.
            $impressions = self::sumOrNull($miembros, 'impressions');
            $clicks = self::sumOrNull($miembros, 'clicks');
            $ctr = ($impressions && $clicks !== null) ? round(($clicks / $impressions) * 100 * 100) / 100 : null;
            $cpm = ($cost && $impressions) ? round(($cost / $impressions) * 1000 * 100) / 100 : null;

            // Un arte con 2 campañas trae 2 ad_id de la API -- NUNCA se
            // colapsan a uno solo en silencio, se listan los dos. "Activo"
            // es compuesto: si CUALQUIER miembro tiene secondary_status
            // "AD_STATUS_DELIVERY_OK", el arte completo se considera activo.
            $adIdsMostrables = [];
            $secondaryStatusesApi = [];
            $campanias = [];
            foreach ($miembros as $m) {
                $adId = $m['adIdMostrable'] ?? null;
                if (self::truthy($adId) && ! in_array($adId, $adIdsMostrables, true)) {
                    $adIdsMostrables[] = $adId;
                }
                $secStatus = $m['secondaryStatusApi'] ?? null;
                if (self::truthy($secStatus) && ! in_array($secStatus, $secondaryStatusesApi, true)) {
                    $secondaryStatusesApi[] = $secStatus;
                }
                $campania = $m['campaignName'] ?? null;
                if (self::truthy($campania) && ! in_array($campania, $campanias, true)) {
                    $campanias[] = $campania;
                }
            }
            $algunoActivo = in_array('AD_STATUS_DELIVERY_OK', $secondaryStatusesApi, true);
            $status = $algunoActivo
                ? 'ACTIVE'
                : (count($secondaryStatusesApi) ? self::estadoTikTokLegible($secondaryStatusesApi[0]) : '');

            $copyBodies = self::consolidarCopyBodiesPorArte($miembros);

            $agrupadas[] = array_merge($representante, [
                // El título de la card es el arte, no el nombre completo de
                // un solo miembro -- con 2 campañas sumadas, mostrar el
                // ad_name de una sola sería engañoso.
                'adNameShort' => count($miembros) > 1
                    ? "{$representante['arte']} (".count($miembros).' anuncios)'
                    : ($representante['adNameShort'] ?? null),
                'esGrupoArte' => true,
                'miembros' => $miembros,
                'cost' => $cost,
                'newCustomers' => $newCustomers,
                'orders' => $orders,
                'installs' => $installs,
                'ncReal' => $ncReal,
                'ordersReal' => $ordersReal,
                'totalAttributions' => $totalAttributions,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'cpm' => $cpm,
                'cac' => ($cost && $ncReal) ? $cost / $ncReal : null,
                'cpi' => ($cost && $installs) ? $cost / $installs : null,
                'cpo' => ($cost && $ordersReal) ? $cost / $ordersReal : null,
                'adIdsMostrables' => $adIdsMostrables,
                'status' => $status,
                'campaignName' => implode(', ', $campanias),
                'copyBodies' => $copyBodies,
            ]);
        }

        return [
            'cards' => array_merge($agrupadas, $sueltas),
            'stats' => [
                'totalEntrada' => count($cards),
                'grupos' => count($agrupadas),
                'filasAgrupadas' => count($cards) - count($sueltas),
                'filasSueltas' => count($sueltas),
            ],
        ];
    }

    /**
     * Un arte con 2 campañas puede tener el mismo copy en las dos (TikTok
     * Smart+ suele reusar el mismo ad_text_list al duplicar el ad entre
     * campañas) o textos distintos -- nunca se asume, se compara. Si son
     * iguales, se muestra una sola vez (dedup); si difieren, cada texto se
     * etiqueta con SU campaña de origen para no mezclar variantes de
     * anuncios distintos como si fueran "las opciones que rota este arte".
     *
     * @param  list<array<string, mixed>>  $miembros
     * @return list<string>
     */
    private static function consolidarCopyBodiesPorArte(array $miembros): array
    {
        $porMiembro = [];
        foreach ($miembros as $m) {
            $bodies = $m['copyBodies'] ?? [];
            if (count($bodies) > 0) {
                $campania = self::truthy($m['campaignName'] ?? null) ? $m['campaignName'] : ($m['arte'] ?? null);
                $porMiembro[] = ['campania' => $campania, 'bodies' => $bodies];
            }
        }
        if (count($porMiembro) === 0) {
            return [];
        }

        $firma = function (array $bodies): string {
            $ordenado = $bodies;
            sort($ordenado, SORT_STRING);

            return implode(' ', $ordenado);
        };

        $firmaBase = $firma($porMiembro[0]['bodies']);
        $todosIguales = true;
        foreach ($porMiembro as $m) {
            if ($firma($m['bodies']) !== $firmaBase) {
                $todosIguales = false;
                break;
            }
        }
        if ($todosIguales) {
            return array_values(array_unique($porMiembro[0]['bodies']));
        }

        $out = [];
        foreach ($porMiembro as $m) {
            foreach ($m['bodies'] as $b) {
                $out[] = "[{$m['campania']}] {$b}";
            }
        }

        return $out;
    }

    /**
     * Estado crudo de TikTok (pausado, en revisión, rechazado, etc.) se
     * muestra tal cual lo manda la API, legible pero SIN inventar una
     * traducción a Active/Paused que no se ha verificado contra un ad real
     * en ese estado.
     */
    private static function estadoTikTokLegible(?string $secondaryStatus): string
    {
        if (! self::truthy($secondaryStatus)) {
            return '';
        }
        if ($secondaryStatus === 'AD_STATUS_DELIVERY_OK') {
            return 'ACTIVE';
        }

        return str_replace('_', ' ', preg_replace('/^AD_STATUS_/', '', $secondaryStatus));
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    private static function sumOrNull(array $cards, string $key): int|float|null
    {
        $valores = [];
        foreach ($cards as $c) {
            $v = $c[$key] ?? null;
            if ($v !== null) {
                $valores[] = $v;
            }
        }
        if (count($valores) === 0) {
            return null;
        }

        return array_sum($valores);
    }

    private static function normalizarNumero(mixed $raw): int|float
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        return is_numeric($raw) ? $raw + 0 : 0;
    }

    /**
     * Replica Boolean(v) de JS -- PHP's empty()/!! nativos difieren en un
     * caso real: PHP trata el string "0" como falsy, JS no (Boolean('0') es
     * true, es un string no vacío). Los campos que pasan por acá (arte,
     * ad IDs, nombres de campaña, estados) nunca son literalmente "0" en la
     * práctica, pero se usa este helper en vez de !$v/empty($v) para no
     * depender de esa coincidencia.
     */
    private static function truthy(mixed $v): bool
    {
        return $v !== null && $v !== '' && $v !== 0 && $v !== 0.0 && $v !== false;
    }
}
