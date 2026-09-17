<?php

namespace App\Services\Ingesta;

use App\Models\AppsflyerApp;
use App\Models\Creativo;
use App\Models\Importacion;
use App\Models\Pais;
use App\Models\Resultado;
use Illuminate\Support\Facades\Log;
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
     * @return array{pais: Pais, esRangoParcial: bool, creativosTocados: int, resultadosTocados: int, tieneMetaTrue: int, problemas: int, excluidos: int, sinActividadDescartados: int}
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
        ?string $nombreArchivo = null,
    ): array {
        [$config, $pais] = self::resolverPais($paisSlug);
        self::validarRango($desde, $hasta);

        $csv = AppsFlyerCsvParser::parse(file_get_contents($archivo));

        return self::ejecutarPipeline(
            $config, $pais, $csv, $desde, $hasta,
            $ncTotalRealMeta, $ordersTotalRealMeta, $ncTotalRealTiktok, $ordersTotalRealTiktok,
            'csv', $nombreArchivo ?? $archivo,
        );
    }

    /**
     * Mismo pipeline que importar(), pero AppsFlyer viene de un pull en vivo
     * al Master API (EnriquecedorAppsFlyerApi) en vez de un CSV subido a
     * mano -- ver plan de 2026-08-11. Los totales manuales (ncTotalReal/
     * ordersTotalReal) siguen siendo input humano, NUNCA se derivan de la
     * API: eso es una regla de negocio explícita, no un detalle técnico.
     *
     * @return array{pais: Pais, esRangoParcial: bool, creativosTocados: int, resultadosTocados: int, tieneMetaTrue: int, problemas: int, excluidos: int, sinActividadDescartados: int}
     */
    public static function importarDesdeApi(
        string $paisSlug,
        string $desde,
        string $hasta,
        mixed $ncTotalRealMeta,
        mixed $ordersTotalRealMeta,
        mixed $ncTotalRealTiktok,
        mixed $ordersTotalRealTiktok,
    ): array {
        [$config, $pais] = self::resolverPais($paisSlug);
        self::validarRango($desde, $hasta);

        $appIds = AppsflyerApp::where('pais_id', $pais->id)->pluck('app_id')->all();
        if (empty($appIds)) {
            throw new InvalidArgumentException("País \"{$paisSlug}\" no tiene apps de AppsFlyer configuradas (tabla appsflyer_apps) -- no se puede traer AppsFlyer vía API para este país todavía.");
        }

        $enriquecedorAppsFlyer = new EnriquecedorAppsFlyerApi(AppsFlyerApiClient::fromConfig());
        $csv = $enriquecedorAppsFlyer->enriquecer($appIds, $desde, $hasta);

        return self::ejecutarPipeline(
            $config, $pais, $csv, $desde, $hasta,
            $ncTotalRealMeta, $ordersTotalRealMeta, $ncTotalRealTiktok, $ordersTotalRealTiktok,
            'appsflyer_api', "AppsFlyer API {$desde}..{$hasta}",
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: Pais}
     */
    private static function resolverPais(string $paisSlug): array
    {
        $config = config("paises.{$paisSlug}");
        if (! $config) {
            throw new InvalidArgumentException("País \"{$paisSlug}\" no existe en config/paises.php.");
        }

        $pais = Pais::where('codigo', $config['codigo'])->first();
        if (! $pais) {
            throw new InvalidArgumentException("País \"{$config['codigo']}\" no existe en la tabla paises -- corré el PaisSeeder primero.");
        }

        return [$config, $pais];
    }

    private static function validarRango(string $desde, string $hasta): void
    {
        if (substr($desde, 0, 7) !== substr($hasta, 0, 7)) {
            throw new InvalidArgumentException('Desde y hasta deben caer en el mismo mes -- un resultado es por creativo y por mes (importá mes a mes).');
        }
        if ($desde > $hasta) {
            throw new InvalidArgumentException('Desde no puede ser después de Hasta.');
        }
    }

    /**
     * Compartido por importar() (CSV) e importarDesdeApi() -- desde acá para
     * abajo es idéntico sin importar de dónde vino AppsFlyer (clasificar ->
     * costos de Meta/TikTok -> venta real -> persistir -> registrar en
     * `importaciones`), así que los dos puntos de entrada nunca pueden
     * desincronizarse.
     *
     * @return array{pais: Pais, esRangoParcial: bool, creativosTocados: int, resultadosTocados: int, tieneMetaTrue: int, problemas: int, excluidos: int, sinActividadDescartados: int}
     */
    private static function ejecutarPipeline(
        array $config,
        Pais $pais,
        array $csv,
        string $desde,
        string $hasta,
        mixed $ncTotalRealMeta,
        mixed $ordersTotalRealMeta,
        mixed $ncTotalRealTiktok,
        mixed $ordersTotalRealTiktok,
        string $origen,
        string $nombreArchivo,
    ): array {
        $clasificados = CruceCostosAppsFlyer::clasificarPorPlataforma($csv['limpias']);

        // esRangoParcial se calcula SOLO, comparando desde/hasta contra el
        // rango real de todos los datos de AppsFlyer de esta corrida (CSV
        // completo o pull de API) -- nunca un flag que alguien tenga que
        // acordarse de marcar a mano.
        [$rangoMin, $rangoMax] = self::rangoFechas($csv['limpias'], $csv['organico']);
        $esRangoParcial = (bool) ($rangoMin && $rangoMax
            && (($desde ?: $rangoMin) !== $rangoMin || ($hasta ?: $rangoMax) !== $rangoMax));

        $totalCreativos = 0;
        $totalResultados = 0;
        $tieneMetaTrue = 0;
        $ncPreservados = 0;
        $ncRecalculados = 0;
        $ordersPreservados = 0;
        $ordersRecalculados = 0;
        $sinActividadDescartados = 0;

        if ($config['meta_ad_account_id'] && count($clasificados['meta']) > 0) {
            $enriquecedorMeta = new EnriquecedorCostosMeta(MetaApiClient::fromConfig(), new ImagenCacheService());
            $costosMeta = $enriquecedorMeta->enriquecer($config['meta_ad_account_id'], $desde, $hasta);

            $r = self::procesarPlataforma(
                $pais, 'meta', $clasificados['meta'], $costosMeta,
                $csv['columnaNC'], $csv['columnaOrders'], $desde, $hasta,
                $esRangoParcial, $ncTotalRealMeta, $ordersTotalRealMeta,
                // Meta nunca confirma "eliminado" -- la señal equivalente es
                // "sin ninguna actividad en los últimos 4 meses" (ver
                // EnriquecedorCostosMeta::detectarSinActividadReciente).
                fn (array $adIds) => $enriquecedorMeta->detectarSinActividadReciente($config['meta_ad_account_id'], $adIds, $hasta),
            );
            $totalCreativos += $r['creativosTocados'];
            $totalResultados += $r['resultadosTocados'];
            $tieneMetaTrue += $r['tieneMetaTrue'];
            $ncPreservados += $r['ncPreservados'];
            $ncRecalculados += $r['ncRecalculados'];
            $ordersPreservados += $r['ordersPreservados'];
            $ordersRecalculados += $r['ordersRecalculados'];
            $sinActividadDescartados += $r['sinActividadDescartados'];
        }

        if ($config['tiktok_advertiser_id'] && count($clasificados['tiktok']) > 0) {
            $enriquecedorTiktok = new EnriquecedorCostosTiktok(TiktokApiClient::fromConfig(), new ImagenCacheService());
            $costosTiktok = $enriquecedorTiktok->enriquecer($config['tiktok_advertiser_id'], $desde, $hasta);

            $r = self::procesarPlataforma(
                $pais, 'tiktok', $clasificados['tiktok'], $costosTiktok,
                $csv['columnaNC'], $csv['columnaOrders'], $desde, $hasta,
                $esRangoParcial, $ncTotalRealTiktok, $ordersTotalRealTiktok,
                // TikTok SÍ confirma "eliminado" -- /ad/get/ filtrado por un
                // ad_id borrado devuelve list:[] (ver
                // EnriquecedorCostosTiktok::detectarEliminados).
                fn (array $adIds) => $enriquecedorTiktok->detectarEliminados($config['tiktok_advertiser_id'], $adIds),
            );
            $totalCreativos += $r['creativosTocados'];
            $totalResultados += $r['resultadosTocados'];
            $tieneMetaTrue += $r['tieneMetaTrue'];
            $ncPreservados += $r['ncPreservados'];
            $ncRecalculados += $r['ncRecalculados'];
            $ordersPreservados += $r['ordersPreservados'];
            $ordersRecalculados += $r['ordersRecalculados'];
            $sinActividadDescartados += $r['sinActividadDescartados'];
        }

        // Un solo lugar hace el trabajo -- importar() (CSV, consola + panel
        // web) e importarDesdeApi() llaman las dos a ejecutarPipeline(), así
        // que el historial de `importaciones` queda completo sin duplicar el
        // Importacion::create() en cada punto de entrada.
        Importacion::create([
            'pais_id' => $pais->id,
            'origen' => $origen,
            'nombre_archivo' => $nombreArchivo,
            'desde' => $desde,
            'hasta' => $hasta,
            'nc_total_real_meta' => $ncTotalRealMeta !== '' ? $ncTotalRealMeta : null,
            'orders_total_real_meta' => $ordersTotalRealMeta !== '' ? $ordersTotalRealMeta : null,
            'nc_total_real_tiktok' => $ncTotalRealTiktok !== '' ? $ncTotalRealTiktok : null,
            'orders_total_real_tiktok' => $ordersTotalRealTiktok !== '' ? $ordersTotalRealTiktok : null,
            'es_rango_parcial' => $esRangoParcial,
            'creativos_tocados' => $totalCreativos,
            'resultados_tocados' => $totalResultados,
            'tiene_meta_true' => $tieneMetaTrue,
            'problemas' => count($csv['problemas']),
            'nc_preservados' => $ncPreservados,
            'nc_recalculados' => $ncRecalculados,
            'orders_preservados' => $ordersPreservados,
            'orders_recalculados' => $ordersRecalculados,
        ]);

        return [
            'pais' => $pais,
            'esRangoParcial' => $esRangoParcial,
            'creativosTocados' => $totalCreativos,
            'resultadosTocados' => $totalResultados,
            'tieneMetaTrue' => $tieneMetaTrue,
            'problemas' => count($csv['problemas']),
            'excluidos' => count($clasificados['excluidos']),
            'sinActividadDescartados' => $sinActividadDescartados,
        ];
    }

    /**
     * @return array{creativosTocados: int, resultadosTocados: int, tieneMetaTrue: int, ncPreservados: int, ncRecalculados: int, ordersPreservados: int, ordersRecalculados: int}
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
        $cardsCrudas = CruceCostosAppsFlyer::cruzar($acotados, $costosApi, $plataforma, $esRangoParcial);

        // "Sin actividad" se filtra ANTES de repartir venta real (fix
        // 2026-08-27, caso real Panamá: el total tecleado se repartía entre
        // TODOS los ads clasificados y DESPUÉS se descartaban los "sin
        // actividad" -- la porción del total ya asignada a esos ads se
        // perdía para siempre (nunca se persistía en ningún lado), dejando
        // la suma real en BD hasta 23% por debajo del total real tecleado.
        // Filtrando acá, calcularVentaReal reparte el total SOLO entre los
        // ads que sí van a quedar guardados -- la suma final en BD cuadra
        // exacto con el total tecleado (salvo redondeo por ad, +-1).
        $sinActividadDescartados = 0;
        $cards = [];
        foreach ($cardsCrudas as $card) {
            if (self::esSinActividad($card)) {
                $sinActividadDescartados++;

                continue;
            }
            $cards[] = $card;
        }

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
        $ncPreservados = 0;
        $ncRecalculados = 0;
        $ordersPreservados = 0;
        $ordersRecalculados = 0;
        $adIdsVistos = [];

        // imagenActualPorAdId: mismo criterio que ImportadorDatosDiario --
        // no pisar una imagen curada a mano contra el Drive real (ver
        // ImagenCacheService::esImagenCurada) con el thumbnail de rutina de
        // esta corrida. Una sola query para todos los ad_id del CSV.
        $imagenActualPorAdId = Creativo::where('pais_id', $pais->id)
            ->whereIn('ad_id', array_column($cardsConVenta, 'adId'))
            ->pluck('imagen_url', 'ad_id');

        foreach ($cardsConVenta as $card) {
            // Guarda defensiva agregada 2026-08-12: detectamos un caso real
            // donde resultadosTocados (677) no coincidía con las filas
            // realmente persistidas (148) para el mismo país+mes+plataforma,
            // sin poder reproducir la causa exacta contra la API en vivo (los
            // datos de Meta/TikTok cambian con el tiempo). Si $cardsConVenta
            // trae el mismo ad_id más de una vez -- por la razón que sea --
            // acá se ignora la repetición (se conserva la primera) y se deja
            // un Log::warning explícito en vez de re-contar/re-persistir en
            // silencio, para tener visibilidad si vuelve a pasar.
            if (isset($adIdsVistos[$card['adId']])) {
                Log::warning('ImportadorDatos: ad_id duplicado dentro de la misma corrida, se ignora la repetición', [
                    'plataforma' => $plataforma,
                    'adId' => $card['adId'],
                    'mes' => $mes,
                ]);

                continue;
            }
            $adIdsVistos[$card['adId']] = true;

            // "Sin actividad" ya se filtró arriba (antes de repartir venta
            // real) -- $cardsConVenta nunca trae esos ads acá.

            $funnel = in_array($card['etapaFunnel'], ['AWA', 'CONS', 'CNV', 'LOY'], true) ? $card['etapaFunnel'] : null;
            $tipoCuenta = isset($confirmadosSinClasificar[$card['adId']]) ? null : ($card['tipoCuenta'] ?? 'DTC');

            $imagenActual = $imagenActualPorAdId[$card['adId']] ?? null;
            $imagenUrl = ImagenCacheService::esImagenCurada($imagenActual)
                ? $imagenActual
                : ($card['imageUrl'] !== '' ? $card['imageUrl'] : null);

            $creativo = Creativo::updateOrCreate(
                ['ad_id' => $card['adId'], 'pais_id' => $pais->id],
                [
                    'nombre_comun' => $card['arte'],
                    'nombre_completo' => $card['adNameShort'],
                    'nombre_campania' => ($card['campaignName'] ?? '') !== '' ? $card['campaignName'] : null,
                    'copy' => $card['copy'] ?? null,
                    'imagen_url' => $imagenUrl,
                    'plataforma' => $plataforma,
                    'funnel' => $funnel,
                    'tipo_cuenta' => $tipoCuenta,
                    'formato' => $card['formato'] ?? null,
                    'fecha_carga' => $desde,
                ]
            );
            $creativosTocados++;

            $existente = $creativo->resultados()->where('mes', $mes)->first();
            $ventaReal = self::resolverNcOrdersFinal($existente, $card, $esRangoParcial, $ncTotalReal, $ordersTotalReal);
            $nc = $ventaReal['nc'];
            $orders = $ventaReal['orders'];
            if ($ventaReal['ncPreservado']) {
                $ncPreservados++;
            } elseif ($ventaReal['ncRecalculado']) {
                $ncRecalculados++;
            }
            if ($ventaReal['ordersPreservado']) {
                $ordersPreservados++;
            } elseif ($ventaReal['ordersRecalculado']) {
                $ordersRecalculados++;
            }
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
                    'tiene_venta_real' => $ventaReal['tieneVentaReal'],
                    'tiene_meta' => $tieneMeta,
                    'ctr' => $card['ctr'],
                    'cpm' => $card['cpm'],
                ]
            );
            $resultadosTocados++;
        }

        return [
            'creativosTocados' => $creativosTocados,
            'resultadosTocados' => $resultadosTocados,
            'tieneMetaTrue' => $tieneMetaTrue,
            'ncPreservados' => $ncPreservados,
            'ncRecalculados' => $ncRecalculados,
            'ordersPreservados' => $ordersPreservados,
            'ordersRecalculados' => $ordersRecalculados,
            'sinActividadDescartados' => $sinActividadDescartados,
        ];
    }

    /**
     * cost/impressions/clicks/installs son los 4 valores "de fuente" de una
     * card -- vienen directo de la API de costos (Meta/TikTok) y de
     * AppsFlyer, sin depender de ningún lookup a BD, así que este chequeo se
     * puede hacer ANTES de decidir si vale la pena tocar creativos/
     * resultados. No se chequean nc/orders acá a propósito: si installs=0,
     * su share de venta_real ya sale en 0 por construcción (ver
     * VentaRealYAgrupacion::calcularVentaReal), así que agregarlos sería
     * redundante.
     */
    private static function esSinActividad(array $card): bool
    {
        return ($card['cost'] ?? 0) <= 0
            && ($card['impressions'] ?? 0) <= 0
            && ($card['clicks'] ?? 0) <= 0
            && ($card['installs'] ?? 0) <= 0;
    }

    /**
     * Resuelve nc/orders finales para el Resultado de este mes SIN pisar un
     * valor ya persistido cuando esta corrida no trajo el total manual
     * correspondiente (ej. un refresh de costo recurrente que no tecleó
     * ncTotalReal porque el cierre de mes todavía no se conoce). NC y Orders
     * se resuelven de forma INDEPENDIENTE -- igual que calcularVentaReal, un
     * total puede llegar sin el otro. Si esRangoParcial es true, se trata
     * igual que "no hubo total esta corrida" (preserva) aunque se hayan
     * pasado totales por error, porque un costo parcial no se puede
     * prorratear.
     *
     * cac/cpo (calculados por el llamador, no acá) siempre usan el costo
     * NUEVO de esta corrida aunque nc/orders vengan preservados de una
     * corrida anterior -- es matemáticamente correcto porque
     * cac = cost / ncReal no depende de qué corrida originó ncReal.
     *
     * ncPreservado/ordersPreservado (true solo si se conservó un valor
     * previo NO nulo) y ncRecalculado/ordersRecalculado (true si esta
     * corrida sí trajo el total) alimentan los contadores de
     * `importaciones.nc_preservados`/`nc_recalculados`/etc -- visibilidad de
     * cuántas filas de esta corrida tocaron venta_real de verdad vs cuántas
     * solo refrescaron costo.
     *
     * @return array{nc: ?int, orders: ?int, tieneVentaReal: bool, ncPreservado: bool, ncRecalculado: bool, ordersPreservado: bool, ordersRecalculado: bool}
     */
    private static function resolverNcOrdersFinal(
        ?Resultado $existente,
        array $card,
        bool $esRangoParcial,
        mixed $ncTotalReal,
        mixed $ordersTotalReal,
    ): array {
        $huboNcEstaCorrida = ! $esRangoParcial && $ncTotalReal !== null && $ncTotalReal !== '';
        $nc = $huboNcEstaCorrida
            ? ($card['ncReal'] !== null ? (int) round($card['ncReal']) : null)
            : $existente?->nc;

        $huboOrdersEstaCorrida = ! $esRangoParcial && $ordersTotalReal !== null && $ordersTotalReal !== '';
        $orders = $huboOrdersEstaCorrida
            ? ($card['ordersReal'] !== null ? (int) round($card['ordersReal']) : null)
            : $existente?->orders;

        return [
            'nc' => $nc,
            'ncPreservado' => ! $huboNcEstaCorrida && $existente?->nc !== null,
            'ncRecalculado' => $huboNcEstaCorrida,
            'ordersPreservado' => ! $huboOrdersEstaCorrida && $existente?->orders !== null,
            'ordersRecalculado' => $huboOrdersEstaCorrida,
            'orders' => $orders,
            'tieneVentaReal' => $nc !== null && $orders !== null,
        ];
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
