<?php

namespace App\Services\Ingesta;

use App\Jobs\CachearImagenesCreativos;
use App\Jobs\GuardarVideoCreativo;
use App\Models\AppsflyerApp;
use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Models\Importacion;
use App\Models\Pais;
use App\Models\Resultado;
use Closure;
use Illuminate\Support\Collection;
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
    public static function previsualizar(string $archivo, ?string $paisSlug = null): array
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
            // Aviso ANTES de importar: plataformas con anuncios en el CSV
            // pero sin cuenta publicitaria activa (no se van a importar).
            'plataformasSinCuenta' => $paisSlug ? self::plataformasSinCuenta(self::resolverPais($paisSlug)[1], $clasificados) : [],
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
        // Solo lo pasa ProcesarImportacionCsv (import async vía panel web) --
        // sin esto, ejecutarPipeline crea una fila `Importacion` nueva al
        // terminar (comportamiento de siempre: consola, "Por API"). Con esto,
        // actualiza la fila que ya existe (creada en estado=procesando ANTES
        // de despachar el Job) en vez de duplicarla.
        ?Importacion $importacionExistente = null,
        // fn (int $porcentaje, string $etapa) -- avance para el panel (ver
        // ProcesarImportacion). Opcional: consola y tests no lo pasan.
        ?Closure $progreso = null,
    ): array {
        [$config, $pais] = self::resolverPais($paisSlug);
        self::validarRango($desde, $hasta);

        self::avisar($progreso, 3, 'Leyendo el archivo de AppsFlyer');
        $csv = AppsFlyerCsvParser::parse(file_get_contents($archivo));

        return self::ejecutarPipeline(
            $config, $pais, $csv, $desde, $hasta,
            $ncTotalRealMeta, $ordersTotalRealMeta, $ncTotalRealTiktok, $ordersTotalRealTiktok,
            'csv', $nombreArchivo ?? $archivo,
            $importacionExistente,
            $progreso,
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
        // Ver importar(): fila ya creada por el panel (import async) y avance.
        ?Importacion $importacionExistente = null,
        ?Closure $progreso = null,
    ): array {
        [$config, $pais] = self::resolverPais($paisSlug);
        self::validarRango($desde, $hasta);

        $appIds = AppsflyerApp::where('pais_id', $pais->id)->pluck('app_id')->all();
        if (empty($appIds)) {
            throw new InvalidArgumentException("{$pais->nombre} no tiene apps de AppsFlyer configuradas -- agrégalas en Ajustes para poder importar por API.");
        }

        self::avisar($progreso, 3, 'Trayendo instalaciones y conversiones de AppsFlyer');
        $enriquecedorAppsFlyer = new EnriquecedorAppsFlyerApi(AppsFlyerApiClient::fromConfig());
        $csv = $enriquecedorAppsFlyer->enriquecer($appIds, $desde, $hasta);

        return self::ejecutarPipeline(
            $config, $pais, $csv, $desde, $hasta,
            $ncTotalRealMeta, $ordersTotalRealMeta, $ncTotalRealTiktok, $ordersTotalRealTiktok,
            'appsflyer_api', "AppsFlyer API {$desde}..{$hasta}",
            $importacionExistente,
            $progreso,
        );
    }

    /**
     * Reporta avance si hay a quién (ver $progreso en importar()).
     */
    private static function avisar(?Closure $progreso, int|float $porcentaje, string $etapa): void
    {
        if ($progreso) {
            $progreso((int) max(0, min(99, round($porcentaje))), $etapa);
        }
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
        ?Importacion $importacionExistente = null,
        ?Closure $progreso = null,
    ): array {
        self::avisar($progreso, 10, 'Clasificando '.number_format(count($csv['limpias'])).' anuncios por plataforma y etapa');
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

        // Una o varias cuentas publicitarias por plataforma (tabla
        // cuentas_publicitarias, administrada por superadmin desde Ajustes,
        // 2026-10-05) -- antes una sola cuenta fija por país en
        // config/paises.php. NC/Orders total real son POR PLATAFORMA: Meta
        // y TikTok reparten cada una SU PROPIO total tecleado.
        $totalesPorPlataforma = [
            'meta' => [$ncTotalRealMeta, $ordersTotalRealMeta],
            'tiktok' => [$ncTotalRealTiktok, $ordersTotalRealTiktok],
        ];
        $conciliacion = [];
        $imagenesPendientes = [];

        // Sin cuenta publicitaria activa no hay costo con qué cruzar esa
        // plataforma -- antes se saltaba en silencio y el panel decía
        // "Importación completa" con 0 creativos (caso real Perú
        // 2026-10-06, país recién habilitado sin cuentas). Si NINGUNA
        // plataforma con anuncios tiene cuenta, se corta con un error
        // claro; si falta solo una (ej. Panamá sin TikTok), se importa el
        // resto y la conciliación marca la plataforma omitida.
        $sinCuenta = self::plataformasSinCuenta($pais, $clasificados);
        $conAnuncios = array_keys(array_filter(['meta' => $clasificados['meta'], 'tiktok' => $clasificados['tiktok']], fn (array $ads) => $ads !== []));
        if ($conAnuncios !== [] && count($sinCuenta) === count($conAnuncios)) {
            throw new InvalidArgumentException(self::mensajeSinCuentas($pais, $sinCuenta));
        }

        // Avance: 12% -> 95% repartido en partes iguales entre las
        // plataformas que sí se procesan; dentro de cada una, ~40% para
        // traer costos (por cuenta) y el resto para guardar creativos.
        $aProcesar = array_values(array_diff($conAnuncios, $sinCuenta));
        $tramo = $aProcesar === [] ? 0 : (95 - 12) / count($aProcesar);

        foreach ($totalesPorPlataforma as $plataforma => [$ncTotalReal, $ordersTotalReal]) {
            $inicio = 12 + $tramo * max(0, (int) array_search($plataforma, $aProcesar, true));
            $etiqueta = $plataforma === 'meta' ? 'Meta' : 'TikTok';
            if (in_array($plataforma, $sinCuenta, true)) {
                $conciliacion[$plataforma] = ['omitida' => true, 'anuncios' => count($clasificados[$plataforma])];

                continue;
            }
            $cuentas = CuentaPublicitaria::activasPara($pais->id, $plataforma)->get();
            if ($cuentas->isEmpty() || count($clasificados[$plataforma]) === 0) {
                continue;
            }

            if ($plataforma === 'meta') {
                $enriquecedor = new EnriquecedorCostosMeta(MetaApiClient::fromConfig(), new ImagenCacheService);
                // Meta nunca confirma "eliminado" -- la señal equivalente es
                // "sin ninguna actividad en los últimos 4 meses" (ver
                // EnriquecedorCostosMeta::detectarSinActividadReciente).
                $detectarPorCuenta = fn (string $cuentaId, array $adIds) => $enriquecedor->detectarSinActividadReciente($cuentaId, $adIds, $hasta);
            } else {
                $enriquecedor = new EnriquecedorCostosTiktok(TiktokApiClient::fromConfig(), new ImagenCacheService);
                // TikTok SÍ confirma "eliminado" -- /ad/get/ filtrado por un
                // ad_id borrado devuelve list:[] (ver
                // EnriquecedorCostosTiktok::detectarEliminados).
                $detectarPorCuenta = fn (string $cuentaId, array $adIds) => $enriquecedor->detectarEliminados($cuentaId, $adIds);
            }

            // Traer costos/estado/imágenes = el primer 40% del tramo de la
            // plataforma, repartido entre sus cuentas; cada enriquecedor
            // reporta su avance interno (tandas de Meta, nombres/portadas de
            // TikTok) para que la barra no se quede fija en este paso.
            $nCuentas = $cuentas->count();
            $costos = self::traerCostosDeCuentas(
                $cuentas,
                fn (string $cuentaId, int $i, CuentaPublicitaria $cuenta) => $enriquecedor->enriquecer(
                    $cuentaId, $desde, $hasta,
                    fn (float $fraccion, string $etapa) => self::avisar(
                        $progreso,
                        $inicio + $tramo * 0.4 * (($i + $fraccion) / $nCuentas),
                        $etapa.($nCuentas > 1 && $cuenta->nombre ? " ({$cuenta->nombre})" : ''),
                    ),
                ),
            );

            $r = self::procesarPlataforma(
                $pais, $plataforma, $clasificados[$plataforma], $costos,
                $csv['columnaNC'], $csv['columnaOrders'], $desde, $hasta,
                $esRangoParcial, $ncTotalReal, $ordersTotalReal,
                // Un ad sin costo no se sabe de qué cuenta es -- solo se
                // confirma "sin actividad"/"eliminado" si lo es en TODAS.
                fn (array $adIds) => self::confirmadosEnTodasLasCuentas($cuentas, $detectarPorCuenta, $adIds),
                $cuentas,
                fn (float $fraccion, string $etapa) => self::avisar($progreso, $inicio + $tramo * (0.4 + 0.6 * $fraccion), $etapa),
            );
            $totalCreativos += $r['creativosTocados'];
            $totalResultados += $r['resultadosTocados'];
            $tieneMetaTrue += $r['tieneMetaTrue'];
            $ncPreservados += $r['ncPreservados'];
            $ncRecalculados += $r['ncRecalculados'];
            $ordersPreservados += $r['ordersPreservados'];
            $ordersRecalculados += $r['ordersRecalculados'];
            $sinActividadDescartados += $r['sinActividadDescartados'];
            $conciliacion[$plataforma] = $r['conciliacion'];
            $imagenesPendientes += $r['imagenesPendientes'];
        }

        self::avisar($progreso, 97, 'Guardando el resumen y el double check');

        // Un solo lugar hace el trabajo -- importar() (CSV, consola + panel
        // web) e importarDesdeApi() llaman las dos a ejecutarPipeline(), así
        // que el historial de `importaciones` queda completo sin duplicar
        // esta escritura en cada punto de entrada. Con $importacionExistente
        // (import async vía panel web, ver ProcesarImportacionCsv) actualiza
        // la fila que ya existe en vez de crear una nueva -- esa fila nació
        // en estado=procesando antes de despachar el Job.
        $datosImportacion = [
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
            'excluidos' => count($clasificados['excluidos']),
            'sin_actividad_descartados' => $sinActividadDescartados,
            'conciliacion' => $conciliacion,
        ];
        if ($importacionExistente) {
            $importacionExistente->update([...$datosImportacion, 'estado' => 'completado', 'progreso' => 100, 'etapa' => null]);
        } else {
            Importacion::create($datosImportacion);
        }

        // Las imágenes que todavía no están en el bucket se guardan en
        // background, después de los datos (ver CachearImagenesCreativos).
        if ($imagenesPendientes !== []) {
            CachearImagenesCreativos::dispatch($imagenesPendientes);
        }
        self::encolarVideosConMasGasto($pais, substr($desde, 0, 7));

        return [
            'pais' => $pais,
            'esRangoParcial' => $esRangoParcial,
            'creativosTocados' => $totalCreativos,
            'resultadosTocados' => $totalResultados,
            'tieneMetaTrue' => $tieneMetaTrue,
            'problemas' => count($csv['problemas']),
            'excluidos' => count($clasificados['excluidos']),
            'sinActividadDescartados' => $sinActividadDescartados,
            'conciliacion' => $conciliacion,
            'imagenesEnCola' => count($imagenesPendientes),
        ];
    }

    /**
     * Videos de TikTok de mayor gasto del mes que todavía no están
     * guardados (pedido explícito 2026-10-06): se guardan en background
     * para que queden aunque TikTok borre el anuncio. El resto se guarda al
     * abrirlo en el modal (ver VideoCreativoController). Cuántos:
     * config('videos.top_por_mes'), 0 lo desactiva.
     */
    private static function encolarVideosConMasGasto(Pais $pais, string $mes): void
    {
        $cuantos = (int) config('videos.top_por_mes');
        if ($cuantos <= 0) {
            return;
        }

        Creativo::query()
            ->join('resultados', 'resultados.creativo_id', '=', 'creativos.id')
            ->where('creativos.pais_id', $pais->id)
            ->where('creativos.plataforma', 'tiktok')
            ->whereNotNull('creativos.video_id')
            ->whereNull('creativos.video_url')
            ->where('resultados.mes', $mes)
            ->orderByDesc('resultados.cost')
            ->limit($cuantos)
            ->pluck('creativos.id')
            ->each(fn (int $id) => GuardarVideoCreativo::dispatch($id));
    }

    /**
     * Plataformas que traen anuncios en el CSV/pull de AppsFlyer pero no
     * tienen ninguna cuenta publicitaria activa en el país.
     *
     * @param  array{meta: list<array<string, mixed>>, tiktok: list<array<string, mixed>>}  $clasificados
     * @return list<string>
     */
    private static function plataformasSinCuenta(Pais $pais, array $clasificados): array
    {
        return array_values(array_filter(
            ['meta', 'tiktok'],
            fn (string $plataforma) => $clasificados[$plataforma] !== []
                && CuentaPublicitaria::activasPara($pais->id, $plataforma)->doesntExist(),
        ));
    }

    /**
     * @param  list<string>  $plataformas
     */
    private static function mensajeSinCuentas(Pais $pais, array $plataformas): string
    {
        $nombres = implode(' ni de ', array_map(fn (string $p) => $p === 'meta' ? 'Meta' : 'TikTok', $plataformas));

        return "{$pais->nombre} no tiene cuentas publicitarias activas de {$nombres}, así que no hay costo con qué cruzar los datos y no se importó nada. "
            .'Un superadmin las agrega en Ajustes → Cuentas publicitarias; después vuelve a importar.';
    }

    /**
     * Costos de TODAS las cuentas activas de una plataforma, cada fila
     * etiquetada con la cuenta de la que vino. Un mismo ad_id no puede
     * existir en dos cuentas -- si apareciera, se conserva el primero.
     *
     * @param  Collection<int, CuentaPublicitaria>  $cuentas
     * @param  callable(string, int, CuentaPublicitaria): list<array<string, mixed>>  $traer
     * @return list<array<string, mixed>>
     */
    private static function traerCostosDeCuentas(Collection $cuentas, callable $traer): array
    {
        $filas = [];
        $vistos = [];
        foreach ($cuentas->values() as $i => $cuenta) {
            foreach ($traer($cuenta->cuenta_id, $i, $cuenta) as $fila) {
                if (isset($vistos[$fila['adId']])) {
                    continue;
                }
                $vistos[$fila['adId']] = true;
                $filas[] = [...$fila, 'cuentaPublicitariaId' => $cuenta->id];
            }
        }

        return $filas;
    }

    /**
     * Subconjunto de $adIds que $detectar confirma en CADA cuenta (sin
     * actividad en Meta / eliminado en TikTok). Un ad pertenece a una sola
     * cuenta: en las demás también sale "sin actividad"/"no existe", así
     * que la intersección deja exactamente los confirmados en su cuenta
     * real. Se encadena para no re-consultar lo que ya se descartó.
     *
     * @param  Collection<int, CuentaPublicitaria>  $cuentas
     * @param  list<string>  $adIds
     * @return list<string>
     */
    private static function confirmadosEnTodasLasCuentas(Collection $cuentas, callable $detectar, array $adIds): array
    {
        $restantes = $adIds;
        foreach ($cuentas as $cuenta) {
            if ($restantes === []) {
                break;
            }
            $restantes = array_values(array_intersect($restantes, $detectar($cuenta->cuenta_id, $restantes)));
        }

        return $restantes;
    }

    /**
     * @param  Collection<int, CuentaPublicitaria>  $cuentas
     * @return array{creativosTocados: int, resultadosTocados: int, tieneMetaTrue: int, ncPreservados: int, ncRecalculados: int, ordersPreservados: int, ordersRecalculados: int, sinActividadDescartados: int, conciliacion: array<string, mixed>}
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
        Closure $detectarSinClasificar,
        Collection $cuentas,
        // fn (float $fraccion 0..1, string $etapa) -- avance dentro de esta
        // plataforma (ver ejecutarPipeline).
        ?Closure $avance = null,
    ): array {
        $etiqueta = $plataforma === 'meta' ? 'Meta' : 'TikTok';
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

        // Quién entra al reparto del total real (pedido explícito
        // 2026-10-05): todos los canales (BRD, DTC paid y no paid) de las
        // cuentas marcadas "cuenta para venta real" por superadmin -- así
        // cuadra exacto con el sheet de referencia. Un ad que solo está en
        // AppsFlyer (sin costo, sin cuenta conocida) sigue entrando -- diluye
        // el share igual que en el Excel de referencia, y aparece en la
        // conciliación como "sin cuenta" para que se note. Los que quedan
        // fuera reciben ncReal/ordersReal null ("—" en el dashboard), nunca
        // un 0 que parezca real.
        $cuentasPorId = $cuentas->keyBy('id');
        $participantes = [];
        $fueraDelReparto = [];
        foreach ($cards as $card) {
            if (self::participaEnVentaReal($card, $cuentasPorId)) {
                $participantes[] = $card;
            } else {
                $fueraDelReparto[] = [...$card, 'ncReal' => null, 'ordersReal' => null, 'cac' => null, 'cpo' => null];
            }
        }

        $cardsConVenta = [
            ...VentaRealYAgrupacion::calcularVentaReal($participantes, $ncTotalReal, $ordersTotalReal, $esRangoParcial),
            ...$fueraDelReparto,
        ];
        $conciliacion = new ConciliacionImportacion($cuentas, $ncTotalReal, $ordersTotalReal);

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
        if ($avance && $adIdsSinMatch !== []) {
            $avance(0.0, 'Revisando '.number_format(count($adIdsSinMatch))." anuncios de {$etiqueta} sin costo");
        }
        $confirmadosSinClasificar = array_flip($detectarSinClasificar($adIdsSinMatch));

        $mes = substr($desde, 0, 7);
        $imagenesPendientes = [];
        $totalAGuardar = count($cardsConVenta);
        $guardados = 0;
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
            // Cada 20 creativos (y el último) -- suficiente para que la barra
            // se mueva sin escribir en BD por cada uno.
            $guardados++;
            if ($avance && ($guardados % 20 === 0 || $guardados === $totalAGuardar)) {
                $avance($guardados / $totalAGuardar, "Guardando creativos de {$etiqueta}: ".number_format($guardados).' de '.number_format($totalAGuardar));
            }

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

            // Imágenes en background (2026-10-06): si ya está en el bucket
            // (cacheada antes o curada a mano) se deja como está, sin volver
            // a descargarla; si no, la URL remota se encola para
            // CachearImagenesCreativos y mientras tanto se conserva lo que
            // había. Nunca se guarda la URL remota (expira y la CSP no la
            // deja mostrar).
            $imagenActual = $imagenActualPorAdId[$card['adId']] ?? null;
            $imagenUrl = $imagenActual;
            $imagenPorCachear = ! ImagenCacheService::esImagenEnBucket($imagenActual) && ($card['imageUrl'] ?? '') !== ''
                ? $card['imageUrl']
                : null;

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
                    ...(! empty($card['videoId']) ? ['video_id' => $card['videoId']] : []),
                    // Sin costo en esta corrida no se sabe la cuenta -- se
                    // conserva la que ya tenía en vez de borrarla.
                    ...(isset($card['cuentaPublicitariaId']) ? ['cuenta_publicitaria_id' => $card['cuentaPublicitariaId']] : []),
                ]
            );
            $creativosTocados++;
            if ($imagenPorCachear) {
                $imagenesPendientes[$creativo->id] = ['url' => $imagenPorCachear, 'nombre' => "{$plataforma}-costo-{$card['adId']}"];
            }

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
            $conciliacion->registrar($card, self::participaEnVentaReal($card, $cuentasPorId), $nc, $orders);
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
            'conciliacion' => $conciliacion->toArray($sinActividadDescartados),
            'imagenesPendientes' => $imagenesPendientes,
        ];
    }

    /**
     * Ver el comentario en procesarPlataforma(): todos los canales entran
     * (BRD, DTC paid y DTC no paid -- confirmado contra el sheet de
     * referencia de Ecuador sept 2026); lo único que decide es la cuenta:
     * un ad con costo entra si su cuenta está marcada "cuenta para venta
     * real", un ad solo-AppsFlyer (sin cuenta conocida) entra.
     *
     * @param  Collection<int, CuentaPublicitaria>  $cuentasPorId
     */
    private static function participaEnVentaReal(array $card, Collection $cuentasPorId): bool
    {
        $cuentaId = $card['cuentaPublicitariaId'] ?? null;

        return $cuentaId === null || (bool) ($cuentasPorId->get($cuentaId)?->cuenta_en_venta_real ?? true);
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
