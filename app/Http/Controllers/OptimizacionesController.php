<?php

namespace App\Http\Controllers;

use App\Console\Commands\SincronizarOptimizaciones;
use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Fuentes\Optimizaciones;
use App\Services\Fuentes\SincronizadorOptimizaciones;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Optimizaciones (2026-11-18, pedido explícito): la bitácora de lo que el
 * equipo hace en las plataformas, que antes vivía en la pestaña
 * optimizaciones.2026 / ✅OPTIS. Se captura aquí y se puede importar lo de
 * la hoja. Todos los países con canales. EPA únicamente.
 */
class OptimizacionesController extends Controller
{
    public function index(Request $request, string $pais, Optimizaciones $optis): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $meses = collect($optis->meses($paisModelo->id))->push(now()->format('Y-m'))->unique()->sortDesc()->values();
        $mes = $request->query('mes');
        $mes = is_string($mes) && ($mes === 'todo' || preg_match('/^\d{4}-\d{2}$/', $mes)) ? $mes : $meses->first();
        [$desde, $hasta] = $mes === 'todo'
            ? ['2000-01-01', '2100-12-31']
            : [CarbonImmutable::parse("{$mes}-01")->toDateString(), CarbonImmutable::parse("{$mes}-01")->endOfMonth()->toDateString()];

        return Inertia::render('Optimizaciones', [
            'pais' => $pais,
            'mes' => $mes,
            'meses' => $meses,
            'optimizaciones' => $optis->entre($paisModelo->id, $desde, $hasta),
            'medios' => array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'color' => $c['color']], config("canales.{$pais}", [])),
            'tipos' => collect(config('optimizaciones.tipos'))->map(fn ($t, $k) => ['key' => $k, 'label' => $t['nombre'], 'grupo' => $t['grupo']])->values(),
            'campaniasSugeridas' => $this->campaniasSugeridas($paisModelo->id),
            'yo' => $request->user()->name,
        ]);
    }

    public function store(Request $request, string $pais): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $datos = $this->validar($request);
        $id = DB::table('optimizaciones')->insertGetId([
            ...$this->fila($datos),
            'responsable' => Optimizaciones::responsable($datos['responsable'] ?? $request->user()->name),
            'cuenta_id' => CuentaActual::id(), 'pais_id' => $paisModelo->id,
            'usuario_id' => $request->user()->id, 'origen' => 'dashboard',
            'creado_en' => now(), 'actualizado_en' => now(),
        ]);
        Auditoria::registrar('optimizacion.creada', $request->user(), detalle: ['pais' => $pais, 'id' => $id, 'fecha' => $datos['fecha'], 'tipo' => $datos['tipo'], 'campanias' => $datos['campanias'] ?? []]);

        return response()->json(['id' => $id, 'mes' => substr($datos['fecha'], 0, 7)]);
    }

    public function update(Request $request, string $pais, int $optimizacion): JsonResponse
    {
        $fila = $this->deEstePais($pais, $optimizacion);
        $datos = $this->validar($request);
        DB::table('optimizaciones')->where('id', $fila->id)->update([...$this->fila($datos), 'responsable' => Optimizaciones::responsable($datos['responsable'] ?? $fila->responsable), 'actualizado_en' => now()]);
        Auditoria::registrar('optimizacion.editada', $request->user(), detalle: ['pais' => $pais, 'id' => $fila->id, 'fecha' => $datos['fecha'], 'tipo' => $datos['tipo'], 'campanias' => $datos['campanias'] ?? []]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $pais, int $optimizacion): JsonResponse
    {
        $fila = $this->deEstePais($pais, $optimizacion);
        DB::table('optimizaciones')->where('id', $fila->id)->delete();
        Auditoria::registrar('optimizacion.borrada', $request->user(), detalle: [
            'pais' => $pais, 'id' => $fila->id, 'fecha' => substr((string) $fila->fecha, 0, 10), 'tipo' => $fila->tipo,
            'campanias' => json_decode((string) $fila->campanias, true) ?: [], 'descripcion' => $fila->descripcion,
        ]);

        return response()->json(['ok' => true]);
    }

    /** CSV de la pestaña optimizaciones.2026 / ✅OPTIS. Reimportar reemplaza lo importado de esas fechas (no lo capturado aquí). */
    public function importar(Request $request, string $pais, Optimizaciones $optis): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $request->validate(['archivo' => ['required', 'file', 'max:20480']]);
        try {
            $r = $optis->leer($pais, (string) file_get_contents($request->file('archivo')->getRealPath()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $ahora = now();
        DB::transaction(function () use ($r, $paisModelo, $ahora) {
            DB::table('optimizaciones')->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisModelo->id)
                ->where('origen', 'hoja')->whereBetween('fecha', [$r['desde'], $r['hasta']])->delete();
            foreach (array_chunk($r['filas'], 500) as $lote) {
                DB::table('optimizaciones')->insert(array_map(fn ($f) => [
                    ...$f, 'campanias' => json_encode($f['campanias']),
                    'cuenta_id' => CuentaActual::id(), 'pais_id' => $paisModelo->id, 'origen' => 'hoja',
                    'creado_en' => $ahora, 'actualizado_en' => $ahora,
                ], $lote));
            }
        });
        Auditoria::registrar('optimizacion.importadas', $request->user(), detalle: ['pais' => $pais, 'desde' => $r['desde'], 'hasta' => $r['hasta'], 'filas' => count($r['filas'])]);

        return response()->json(['filas' => count($r['filas']), 'desde' => $r['desde'], 'hasta' => $r['hasta'], 'sinFecha' => $r['sinFecha'], 'mes' => substr($r['hasta'], 0, 7)]);
    }

    /** Trae ya los cambios de Meta y TikTok del país (lo mismo que corre cada mañana). */
    public function sincronizar(Request $request, string $pais): JsonResponse
    {
        $this->paisDesdeSlug($pais);
        $r = (new SincronizadorOptimizaciones(
            SincronizarOptimizaciones::cliente(fn () => MetaApiClient::fromConfig()),
            SincronizarOptimizaciones::cliente(fn () => TiktokApiClient::fromConfig()),
        ))->sincronizar($pais);
        Auditoria::registrar('optimizacion.sincronizadas', $request->user(), detalle: ['pais' => $pais, 'meta' => $r['meta'], 'tiktok' => $r['tiktok'], 'errores' => count($r['errores'])]);

        return response()->json($r);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        return $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'medio' => ['nullable', 'string', 'max:60'],
            'campanias' => ['nullable', 'array', 'max:50'],
            'campanias.*' => ['string', 'max:300'],
            'tipo' => ['required', Rule::in(array_keys(config('optimizaciones.tipos')))],
            'descripcion' => ['required', 'string', 'max:5000'],
            'comentarios' => ['nullable', 'string', 'max:2000'],
            'responsable' => ['nullable', 'string', 'max:160'],
        ], ['descripcion.required' => 'Escribe qué se hizo.']);
    }

    /** @return array<string, mixed> */
    private function fila(array $d): array
    {
        $campanias = array_values(array_filter(array_map('trim', $d['campanias'] ?? [])));

        return [
            'fecha' => $d['fecha'], 'medio' => $d['medio'] ?? null, 'campanias' => json_encode($campanias),
            'tipo' => $d['tipo'], 'descripcion' => trim($d['descripcion']), 'comentarios' => $d['comentarios'] ?? null,
        ];
    }

    /** Campañas recientes (data.medios y Daily) para elegir al capturar. @return list<array{campania: string, medio: string}> */
    private function campaniasSugeridas(int $paisId): array
    {
        $desde = now()->subDays(90)->toDateString();

        return DB::table('medios_diario')->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)
            ->where('fecha', '>=', $desde)
            ->groupBy('campania', 'medio')
            ->selectRaw('campania, medio, SUM(costo) AS costo')
            ->orderByDesc('costo')
            ->limit(400)
            ->get()
            ->map(fn ($c) => ['campania' => $c->campania, 'medio' => $c->medio])
            ->all();
    }

    private function deEstePais(string $pais, int $id): object
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $fila = DB::table('optimizaciones')->where('id', $id)->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisModelo->id)->first();
        abort_unless($fila, 404);

        return $fila;
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless(config("canales.{$pais}"), 404, 'Este país todavía no tiene medios configurados.');

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
