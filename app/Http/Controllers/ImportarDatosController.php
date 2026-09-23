<?php

namespace App\Http\Controllers;

use App\Jobs\ProcesarImportacionCsv;
use App\Models\Importacion;
use App\Models\Pais;
use App\Services\Ingesta\ImportadorDatos;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Puerto del panel "Cargar datos" (import-panel) de meta.html/tiktok.html --
 * los 3 endpoints (previsualizar/importar del tab CSV, importarApi del tab
 * API) llaman a ImportadorDatos, la MISMA clase que usan los comandos de
 * consola `importar:csv`/`importar:appsflyer-api`, para que nunca puedan
 * desincronizarse.
 */
class ImportarDatosController extends Controller
{
    public function show(string $pais): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        return Inertia::render('ImportarDatos', ['pais' => $pais]);
    }

    /**
     * Sube el CSV a un archivo temporal y lo parsea para autocompletar
     * Desde/Hasta + mostrar conteos reales -- no persiste nada todavía.
     */
    public function previsualizar(Request $request, string $pais): JsonResponse
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $token = (string) Str::uuid();
        $rutaRelativa = "imports/{$token}.csv";
        $request->file('archivo')->storeAs('imports', "{$token}.csv");

        $resumen = ImportadorDatos::previsualizar(Storage::path($rutaRelativa));

        return response()->json([
            ...$resumen,
            'token' => $token,
            'nombreArchivo' => $request->file('archivo')->getClientOriginalName(),
        ]);
    }

    /**
     * Despacha el pipeline completo (mismo ImportadorDatos::importar() que
     * consola/"Por API") a un Job en cola en vez de correrlo síncrono --
     * un CSV real (~250 creativos entre Meta+TikTok) tarda más que el
     * timeout de Cloud Run (300s). El controller responde al instante
     * (202) con el id para que el frontend haga polling de
     * estadoImportacion() -- ver ProcesarImportacionCsv para el resto del
     * flujo y por qué el CSV viaja como contenido en BD, no como ruta.
     */
    public function importar(Request $request, string $pais): JsonResponse
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $data = $request->validate([
            'token' => ['required', 'string'],
            'nombre_archivo' => ['required', 'string'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date'],
            'nc_total_real_meta' => ['nullable', 'numeric', 'min:0'],
            'orders_total_real_meta' => ['nullable', 'numeric', 'min:0'],
            'nc_total_real_tiktok' => ['nullable', 'numeric', 'min:0'],
            'orders_total_real_tiktok' => ['nullable', 'numeric', 'min:0'],
        ]);

        $rutaRelativa = "imports/{$data['token']}.csv";
        abort_unless(Storage::exists($rutaRelativa), 404, 'El archivo ya no está disponible -- volvé a subirlo.');

        $contenidoCsv = Storage::get($rutaRelativa);
        Storage::delete($rutaRelativa);

        $importacion = Importacion::create([
            'pais_id' => $paisModelo->id,
            'origen' => 'csv',
            'nombre_archivo' => $data['nombre_archivo'],
            'desde' => $data['desde'],
            'hasta' => $data['hasta'],
            'nc_total_real_meta' => $data['nc_total_real_meta'] ?? null,
            'orders_total_real_meta' => $data['orders_total_real_meta'] ?? null,
            'nc_total_real_tiktok' => $data['nc_total_real_tiktok'] ?? null,
            'orders_total_real_tiktok' => $data['orders_total_real_tiktok'] ?? null,
            'estado' => 'procesando',
            'csv_contenido' => $contenidoCsv,
        ]);

        ProcesarImportacionCsv::dispatch(
            $importacion->id,
            $pais,
            $data['desde'],
            $data['hasta'],
            $data['nc_total_real_meta'] ?? null,
            $data['orders_total_real_meta'] ?? null,
            $data['nc_total_real_tiktok'] ?? null,
            $data['orders_total_real_tiktok'] ?? null,
            $data['nombre_archivo'],
        );

        return response()->json([
            'importacionId' => $importacion->id,
            'estado' => 'procesando',
        ], 202);
    }

    /**
     * Polling del frontend mientras ProcesarImportacionCsv corre en
     * background -- mismo shape de respuesta que el importar() síncrono de
     * antes cuando estado=completado, para no tener que tocar cómo
     * ImportarDatos.vue pinta el resumen final.
     */
    public function estadoImportacion(Request $request, string $pais, int $id): JsonResponse
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $importacion = Importacion::where('pais_id', $paisModelo->id)->findOrFail($id);

        if ($importacion->estado === 'error') {
            return response()->json(['estado' => 'error', 'error' => $importacion->error_mensaje], 422);
        }

        if ($importacion->estado === 'procesando') {
            return response()->json(['estado' => 'procesando']);
        }

        return response()->json([
            'estado' => 'completado',
            'esRangoParcial' => (bool) $importacion->es_rango_parcial,
            'creativosTocados' => $importacion->creativos_tocados,
            'resultadosTocados' => $importacion->resultados_tocados,
            'tieneMetaTrue' => $importacion->tiene_meta_true,
            'problemas' => $importacion->problemas,
            'excluidos' => $importacion->excluidos,
            'sinActividadDescartados' => $importacion->sin_actividad_descartados,
        ]);
    }

    /**
     * Trae AppsFlyer vía API en vez de CSV subido a mano -- misma clase
     * ImportadorDatos (importarDesdeApi) que usa `importar:appsflyer-api`
     * por consola. Sin archivo/token/preview: no hay "archivo equivocado"
     * que previsualizar antes de confirmar.
     */
    public function importarApi(Request $request, string $pais): JsonResponse
    {
        // Misma razón que importar(): llamadas reales a Meta/TikTok/
        // AppsFlyer tardan más que el timeout de 30s de PHP-FPM.
        set_time_limit(0);
        // Ver nota en ImportarAppsFlyerApi::handle() (consola) -- el 128M
        // default también aplica acá, mismo pipeline de caché de imágenes.
        ini_set('memory_limit', '512M');

        $data = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date'],
            'nc_total_real_meta' => ['nullable', 'numeric', 'min:0'],
            'orders_total_real_meta' => ['nullable', 'numeric', 'min:0'],
            'nc_total_real_tiktok' => ['nullable', 'numeric', 'min:0'],
            'orders_total_real_tiktok' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $resumen = ImportadorDatos::importarDesdeApi(
                $pais,
                $data['desde'],
                $data['hasta'],
                $data['nc_total_real_meta'] ?? null,
                $data['orders_total_real_meta'] ?? null,
                $data['nc_total_real_tiktok'] ?? null,
                $data['orders_total_real_tiktok'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        unset($resumen['pais']);

        return response()->json($resumen);
    }

    /**
     * resumenPorArte -- 2026-08-28, pedido explícito: al final del panel
     * "Cargar datos", una tabla agrupada por arte + FECHA (una fila por
     * arte por día -- pedido explícito posterior: "agregar una columna de
     * fecha para que se puedan ver por día"), mismas columnas que la
     * pestaña "Overview"/Meta Ads/TikTok Ads del Google Sheet de QA que ya
     * usan, para comparar lado a lado y cazar diferencias.
     *
     * Grano DIARIO (`resultados_diarios`), no mensual -- es el grano real
     * que trae Supermetrics en el sheet (por día y por anuncio). Solo
     * existe para países con pipeline diario (hoy Panamá, ver
     * ImportadorDatosDiario) -- un país sin esa tabla poblada simplemente
     * devuelve `rangoMin`/`rangoMax` null y `filas` vacío, el front muestra
     * el aviso correspondiente, nunca fuerza un dato que no existe.
     *
     * desde/hasta son querystring opcionales (YYYY-MM-DD) -- sin ellos,
     * default a los últimos 7 días con datos reales (hasta `rangoMax`, el
     * último día con alguna fila para este país), para que el primer
     * vistazo siempre traiga algo aunque hoy todavía no se haya importado.
     * Clampeados a [rangoMin, rangoMax] -- nunca se pide un rango fuera de
     * lo que existe.
     *
     * CPI/CAC/CPO se recalculan sobre la SUMA de cost/installs/nc/orders
     * del grupo (arte+fecha), nunca promediando un cpi/cac/cpo ya guardado
     * -- promediar eso pesaría mal un creativo con pocas installs igual que
     * uno con miles. nc/orders usan `nc_real`/`orders_real` cuando existen
     * (ya prorrateados por fila, ver migración de resultados_diarios),
     * crudo de AppsFlyer como fallback -- mismo criterio que
     * motor.js:derivarMetricaDiaria.
     */
    public function resumenPorArte(Request $request, string $pais): JsonResponse
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $rango = DB::table('resultados_diarios')
            ->join('creativos', 'creativos.id', '=', 'resultados_diarios.creativo_id')
            ->where('creativos.pais_id', $paisModelo->id)
            ->selectRaw('MIN(fecha) as min, MAX(fecha) as max')
            ->first();

        $rangoMin = $rango?->min ? substr((string) $rango->min, 0, 10) : null;
        $rangoMax = $rango?->max ? substr((string) $rango->max, 0, 10) : null;

        $hasta = $request->query('hasta') ?: $rangoMax;
        $desde = $request->query('desde') ?: ($hasta ? Carbon::parse($hasta)->subDays(6)->toDateString() : null);
        if ($desde && $rangoMin && $desde < $rangoMin) {
            $desde = $rangoMin;
        }
        if ($hasta && $rangoMax && $hasta > $rangoMax) {
            $hasta = $rangoMax;
        }

        $filas = collect();
        if ($desde && $hasta) {
            $filas = DB::table('resultados_diarios')
                ->join('creativos', 'creativos.id', '=', 'resultados_diarios.creativo_id')
                ->where('creativos.pais_id', $paisModelo->id)
                ->whereBetween('resultados_diarios.fecha', [$desde, $hasta])
                ->groupBy('creativos.nombre_comun', 'resultados_diarios.fecha')
                ->orderByDesc('resultados_diarios.fecha')
                ->orderByRaw('creativos.nombre_comun is null, creativos.nombre_comun')
                ->get([
                    'resultados_diarios.fecha',
                    'creativos.nombre_comun',
                    // MIN(): la agrupación sigue siendo por (arte, fecha), no
                    // por (arte, funnel, fecha) -- no toco los totales
                    // financieros, solo expongo la etapa para contexto visual
                    // en esta tabla de QA (pedido explícito 2026-09-17). Un
                    // mismo arte casi nunca cambia de funnel entre ad_id's
                    // reales; si alguna vez pasara, MIN() elige uno de forma
                    // determinística en vez de romper la fila.
                    DB::raw('MIN(creativos.funnel) as funnel'),
                    DB::raw('SUM(resultados_diarios.cost) as cost'),
                    DB::raw('SUM(resultados_diarios.impressions) as impressions'),
                    DB::raw('SUM(resultados_diarios.clicks) as clicks'),
                    DB::raw('SUM(resultados_diarios.installs) as installs'),
                    DB::raw('SUM(COALESCE(resultados_diarios.nc_real, resultados_diarios.nc)) as nc'),
                    DB::raw('SUM(COALESCE(resultados_diarios.orders_real, resultados_diarios.orders)) as orders'),
                ])
                ->map(fn ($fila) => $this->filaConEficiencias(
                    $fila->nombre_comun,
                    (float) $fila->cost,
                    (int) $fila->impressions,
                    (int) $fila->clicks,
                    (int) $fila->installs,
                    (float) $fila->nc,
                    (float) $fila->orders,
                    substr((string) $fila->fecha, 0, 10),
                    $fila->funnel,
                ));
        }

        $totales = $this->filaConEficiencias(
            null,
            (float) $filas->sum('cost'),
            (int) $filas->sum('impressions'),
            (int) $filas->sum('clicks'),
            (int) $filas->sum('installs'),
            (float) $filas->sum('nc'),
            (float) $filas->sum('orders'),
        );

        return response()->json([
            'desde' => $desde,
            'hasta' => $hasta,
            'rangoMin' => $rangoMin,
            'rangoMax' => $rangoMax,
            'filas' => $filas->values(),
            'totales' => $totales,
        ]);
    }

    /**
     * @return array{fecha: ?string, nombreComun: ?string, funnel: ?string, cost: float, impressions: int, clicks: int, installs: int, cpi: ?float, nc: float, cac: ?float, orders: float, cpo: ?float}
     */
    private function filaConEficiencias(?string $nombreComun, float $cost, int $impressions, int $clicks, int $installs, float $nc, float $orders, ?string $fecha = null, ?string $funnel = null): array
    {
        return [
            'fecha' => $fecha,
            'nombreComun' => $nombreComun,
            'funnel' => $funnel,
            'cost' => $cost,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'installs' => $installs,
            'cpi' => $installs > 0 ? $cost / $installs : null,
            'nc' => $nc,
            'cac' => $nc > 0 ? $cost / $nc : null,
            'orders' => $orders,
            'cpo' => $orders > 0 ? $cost / $orders : null,
        ];
    }
}
