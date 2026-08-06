<?php

namespace App\Services\Ingesta;

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\Resultado;
use InvalidArgumentException;

/**
 * Pipeline de importación de punta a punta -- equivalente a "subir el CSV +
 * npm run meta + npm run tiktok-api + escribir los totales de venta real"
 * del dashboard Node, pero persistiendo en creativos/resultados. Extraído de
 * ImportarCsvAppsFlyer (el comando de consola) para que el comando Y el
 * panel ImportarDatos.vue (vía ImportarDatosController) llamen exactamente
 * a la misma lógica -- nunca pueden desincronizarse porque no hay dos
 * copias, hay un solo lugar que hace el trabajo.
 */
class ImportadorDatos
{
    /**
     * Solo lectura -- parsea y clasifica el CSV para mostrar un preview
     * (rango de fechas real, conteos por plataforma, problemas) ANTES de
     * pedir confirmación al usuario. No toca la base de datos.
     *
     * @return array{rangoMin: ?string, rangoMax: ?string, totalAdIds: int, subtotales: int, problemas: int, meta: int, tiktok: int, excluidos: int, tipoCuentaCounts: array{DTC: int, BRD: int, SIN_CLASIFICAR: int}}
     */
    public static function previsualizar(string $archivo): array
    {
        $csv = AppsFlyerCsvParser::parse(file_get_contents($archivo));
        $clasificados = CruceCostosAppsFlyer::clasificarPorPlataforma($csv['limpias']);
        [$rangoMin, $rangoMax] = self::rangoFechas($csv['limpias'], $csv['organico']);

        // Preview de tipoCuenta: el criterio REAL del clasificador (segmento
        // explícito _DTC_/_BRD_), sin aplicar todavía el fallback
        // "DTC como último recurso" que solo se aplica al persistir -- acá
        // se muestra la distribución real, incluido lo genuinamente sin
        // clasificar.
        $tipoCuentaCounts = ['DTC' => 0, 'BRD' => 0, 'SIN_CLASIFICAR' => 0];
        foreach ([...$clasificados['meta'], ...$clasificados['tiktok']] as $ad) {
            $tipoCuentaCounts[$ad['tipoCuenta'] ?? 'SIN_CLASIFICAR']++;
        }

        return [
            'rangoMin' => $rangoMin,
            'rangoMax' => $rangoMax,
            'totalAdIds' => count($csv['limpias']),
            'subtotales' => $csv['subtotales'],
            'problemas' => count($csv['problemas']),
            'meta' => count($clasificados['meta']),
            'tiktok' => count($clasificados['tiktok']),
            'excluidos' => count($clasificados['excluidos']),
            'tipoCuentaCounts' => $tipoCuentaCounts,
        ];
    }

    /**
     * Corre el pipeline completo (clasificar -> costos de la API -> venta
     * real -> agrupar por arte -> persistir) y devuelve un resumen real, no
     * un "OK" genérico. NC/Orders total real son POR PLATAFORMA -- Meta y
     * TikTok son negocios/cuentas distintas, cada uno reparte SU PROPIO
     * total tecleado entre sus propios ads, nunca un total compartido entre
     * las dos.
     *
     * @return array{pais: Pais, esRangoParcial: bool, creativosTocados: int, resultadosTocados: int, tieneMetaTrue: int, problemas: int, excluidos: int}
     */
    public static function importar(
        string $archivo,
        string $paisSlug,
        string $desde,
        string $hasta,
        mixed $ncTotalRealMeta,
        mixed $ordersTotalRealMeta,
        mixed $ncTotalRealTiktok,
        mixed $ordersTotalRealTiktok,
    ): array {
        $config = config("paises.{$paisSlug}");
        if (! $config) {
            throw new InvalidArgumentException("País \"{$paisSlug}\" no existe en config/paises.php.");
        }

        $pais = Pais::where('codigo', $config['codigo'])->first();
        if (! $pais) {
            throw new InvalidArgumentException("País \"{$config['codigo']}\" no existe en la tabla paises -- corré el PaisSeeder primero.");
        }

        if (substr($desde, 0, 7) !== substr($hasta, 0, 7)) {
            throw new InvalidArgumentException('Desde y hasta deben caer en el mismo mes -- un resultado es por creativo y por mes (importá mes a mes).');
        }
        if ($desde > $hasta) {
            throw new InvalidArgumentException('Desde no puede ser después de Hasta.');
        }

        $csv = AppsFlyerCsvParser::parse(file_get_contents($archivo));
        $clasificados = CruceCostosAppsFlyer::clasificarPorPlataforma($csv['limpias']);

        // esRangoParcial se calcula SOLO, comparando desde/hasta contra el
        // rango real de todo el CSV (motor.js: calcularCardsFinal) -- nunca
        // un flag que alguien tenga que acordarse de marcar a mano.
        [$rangoMin, $rangoMax] = self::rangoFechas($csv['limpias'], $csv['organico']);
        $esRangoParcial = (bool) ($rangoMin && $rangoMax
            && (($desde ?: $rangoMin) !== $rangoMin || ($hasta ?: $rangoMax) !== $rangoMax));

        $totalCreativos = 0;
        $totalResultados = 0;
        $tieneMetaTrue = 0;

        if ($config['meta_ad_account_id'] && count($clasificados['meta']) > 0) {
            $enriquecedorMeta = new EnriquecedorCostosMeta(MetaApiClient::fromConfig(), new ImagenCacheService());
            $costosMeta = $enriquecedorMeta->enriquecer($config['meta_ad_account_id'], $desde, $hasta);

            [$c, $r, $tm] = self::procesarPlataforma(
                $pais, 'meta', $clasificados['meta'], $costosMeta,
                $csv['columnaNC'], $csv['columnaOrders'], $desde, $hasta,
                $esRangoParcial, $ncTotalRealMeta, $ordersTotalRealMeta,
                // Meta nunca confirma "eliminado" -- la señal equivalente es
                // "sin ninguna actividad en los últimos 4 meses" (ver
                // EnriquecedorCostosMeta::detectarSinActividadReciente).
                fn (array $adIds) => $enriquecedorMeta->detectarSinActividadReciente($config['meta_ad_account_id'], $adIds, $hasta),
            );
            $totalCreativos += $c;
            $totalResultados += $r;
            $tieneMetaTrue += $tm;
        }

        if ($config['tiktok_advertiser_id'] && count($clasificados['tiktok']) > 0) {
            $enriquecedorTiktok = new EnriquecedorCostosTiktok(TiktokApiClient::fromConfig(), new ImagenCacheService());
            $costosTiktok = $enriquecedorTiktok->enriquecer($config['tiktok_advertiser_id'], $desde, $hasta);

            [$c, $r, $tm] = self::procesarPlataforma(
                $pais, 'tiktok', $clasificados['tiktok'], $costosTiktok,
                $csv['columnaNC'], $csv['columnaOrders'], $desde, $hasta,
                $esRangoParcial, $ncTotalRealTiktok, $ordersTotalRealTiktok,
                // TikTok SÍ confirma "eliminado" -- /ad/get/ filtrado por un
                // ad_id borrado devuelve list:[] (ver
                // EnriquecedorCostosTiktok::detectarEliminados).
                fn (array $adIds) => $enriquecedorTiktok->detectarEliminados($config['tiktok_advertiser_id'], $adIds),
            );
            $totalCreativos += $c;
            $totalResultados += $r;
            $tieneMetaTrue += $tm;
        }

        return [
            'pais' => $pais,
            'esRangoParcial' => $esRangoParcial,
            'creativosTocados' => $totalCreativos,
            'resultadosTocados' => $totalResultados,
            'tieneMetaTrue' => $tieneMetaTrue,
            'problemas' => count($csv['problemas']),
            'excluidos' => count($clasificados['excluidos']),
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int} [creativos tocados, resultados tocados, con tiene_meta=true]
     */
    private static function procesarPlataforma(
        Pais $pais,
        string $plataforma,
        array $clasificados,
        array $costosApi,
        ?string $columnaNC,
        ?string $columnaOrders,
        string $desde,
        string $hasta,
        bool $esRangoParcial,
        mixed $ncTotalReal,
        mixed $ordersTotalReal,
        \Closure $detectarSinClasificar,
    ): array {
        $acotados = CruceCostosAppsFlyer::acotarARango($clasificados, $desde, $hasta, $columnaNC, $columnaOrders);
        $cards = CruceCostosAppsFlyer::cruzar($acotados, $costosApi, $plataforma, $esRangoParcial);
        $cardsConVenta = VentaRealYAgrupacion::calcularVentaReal($cards, $ncTotalReal, $ordersTotalReal, $esRangoParcial);

        // Solo se confirma "sin clasificar" (tipo_cuenta = null) para ads que
        // YA vinieron sin match de costo en el rango (tieneMeta=false) --
        // detectarSinClasificar hace la llamada real a la API (existencia en
        // TikTok, actividad de 4 meses en Meta) solo sobre ese subconjunto.
        $adIdsSinMatch = [];
        foreach ($cardsConVenta as $card) {
            if (($card['tieneMeta'] ?? false) === false) {
                $adIdsSinMatch[] = $card['adId'];
            }
        }
        $confirmadosSinClasificar = array_flip($detectarSinClasificar($adIdsSinMatch));

        $mes = substr($desde, 0, 7);
        $creativosTocados = 0;
        $resultadosTocados = 0;
        $tieneMetaTrue = 0;
        $tieneVentaReal = ! $esRangoParcial
            && $ncTotalReal !== null && $ncTotalReal !== ''
            && $ordersTotalReal !== null && $ordersTotalReal !== '';

        foreach ($cardsConVenta as $card) {
            $funnel = in_array($card['etapaFunnel'], ['AWA', 'CONS', 'CNV', 'LOY'], true) ? $card['etapaFunnel'] : null;
            $tipoCuenta = isset($confirmadosSinClasificar[$card['adId']]) ? null : ($card['tipoCuenta'] ?? 'DTC');

            $creativo = Creativo::updateOrCreate(
                ['ad_id' => $card['adId'], 'pais_id' => $pais->id],
                [
                    'nombre_comun' => $card['arte'],
                    'nombre_completo' => $card['adNameShort'],
                    'nombre_campania' => ($card['campaignName'] ?? '') !== '' ? $card['campaignName'] : null,
                    'copy' => $card['copy'] ?? null,
                    'imagen_url' => $card['imageUrl'] !== '' ? $card['imageUrl'] : null,
                    'plataforma' => $plataforma,
                    'funnel' => $funnel,
                    'tipo_cuenta' => $tipoCuenta,
                    'formato' => $card['formato'] ?? null,
                    'fecha_carga' => $desde,
                ]
            );
            $creativosTocados++;

            $nc = $card['ncReal'] !== null ? (int) round($card['ncReal']) : null;
            $orders = $card['ordersReal'] !== null ? (int) round($card['ordersReal']) : null;
            $cost = $card['cost'] ?? 0;
            $cac = ($nc !== null && $nc > 0 && $cost > 0) ? $cost / $nc : null;
            $cpo = ($orders !== null && $orders > 0 && $cost > 0) ? $cost / $orders : null;
            $tieneMeta = $card['tieneMeta'] ?? false;
            if ($tieneMeta) {
                $tieneMetaTrue++;
            }

            Resultado::updateOrCreate(
                ['creativo_id' => $creativo->id, 'mes' => $mes],
                [
                    'fecha_inicio' => $desde,
                    'fecha_fin' => $hasta,
                    'cost' => $cost,
                    'impressions' => $card['impressions'] ?? 0,
                    'clicks' => $card['clicks'] ?? 0,
                    'installs' => $card['installs'] ?? 0,
                    'cpi' => $card['cpi'],
                    'nc' => $nc,
                    'cac' => $cac,
                    'orders' => $orders,
                    'reorders' => 0,
                    'cpo' => $cpo,
                    'tiene_venta_real' => $tieneVentaReal,
                    'tiene_meta' => $tieneMeta,
                    'ctr' => $card['ctr'],
                    'cpm' => $card['cpm'],
                ]
            );
            $resultadosTocados++;
        }

        return [$creativosTocados, $resultadosTocados, $tieneMetaTrue];
    }

    /**
     * Puerto de minMaxFecha (motor.js:665-677) -- rango real de fechas de
     * TODAS las filas con fecha válida, incluido el tráfico orgánico. Sirve
     * para autocompletar Desde/Hasta y para detectar rango parcial.
     *
     * @param  list<array{serie: list<array{fecha: string}>}>  $limpias
     * @param  list<array{fecha: string}>  $organico
     * @return array{0: ?string, 1: ?string}
     */
    private static function rangoFechas(array $limpias, array $organico): array
    {
        $min = null;
        $max = null;
        foreach ($limpias as $ad) {
            foreach ($ad['serie'] as $punto) {
                $clave = self::fechaOrdenable($punto['fecha']);
                if ($min === null || $clave < $min) {
                    $min = $clave;
                }
                if ($max === null || $clave > $max) {
                    $max = $clave;
                }
            }
        }
        foreach ($organico as $punto) {
            $clave = self::fechaOrdenable($punto['fecha']);
            if ($min === null || $clave < $min) {
                $min = $clave;
            }
            if ($max === null || $clave > $max) {
                $max = $clave;
            }
        }

        return [$min, $max];
    }

    /**
     * fecha viene como DD-MM-YYYY -- invertido queda YYYY-MM-DD, comparable
     * como string y directamente usable en <input type="date">.
     */
    private static function fechaOrdenable(string $fecha): string
    {
        return implode('-', array_reverse(explode('-', $fecha)));
    }
}
