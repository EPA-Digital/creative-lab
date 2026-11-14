<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Auditoria;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Medios data (2026-11-13, pedido explícito): el gasto del mes por medio y
 * campaña, como la pestaña data.medios de la hoja del equipo. Facebook y
 * TikTok salen de lo que ya importa el dashboard por API; Apple Search Ads,
 * RTB House, Google Ads y Jampp se capturan aquí por fecha (por ahora no
 * tienen conexión) y de aquí los toma Fuentes. EPA únicamente.
 */
class MediosDataController extends Controller
{
    private const MEDIO_POR_PLATAFORMA = ['meta' => 'Facebook Ads', 'tiktok' => 'TikTok'];

    public function index(Request $request, string $pais): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $cuentaId = CuentaActual::id();

        $base = DB::table('resultados')
            ->join('creativos', 'creativos.id', '=', 'resultados.creativo_id')
            ->where('creativos.cuenta_id', $cuentaId)
            ->where('creativos.pais_id', $paisModelo->id);

        $manuales = DB::table('medios_gasto_manual')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisModelo->id);

        $meses = (clone $base)->distinct()->pluck('resultados.mes')
            ->merge((clone $manuales)->pluck('fecha')->map(fn ($f) => substr((string) $f, 0, 7)))
            ->unique()->sortDesc()->values();

        $mes = $request->query('mes');
        if (! $mes || ! $meses->contains($mes)) {
            $mes = $meses->first();
        }

        $filas = collect();
        if ($mes) {
            $filas = (clone $base)
                ->where('resultados.mes', $mes)
                ->groupBy('creativos.plataforma', 'creativos.tipo_cuenta', 'creativos.nombre_campania', 'creativos.funnel')
                ->selectRaw('creativos.plataforma, creativos.tipo_cuenta, creativos.nombre_campania, creativos.funnel, SUM(resultados.cost) as costo, SUM(resultados.impressions) as impresiones, SUM(resultados.clicks) as clicks, SUM(resultados.installs) as installs, COUNT(*) as anuncios')
                ->get()
                ->filter(fn ($f) => (float) $f->costo > 0 || (int) $f->impresiones > 0)
                ->map(fn ($f) => [
                    'medio' => self::MEDIO_POR_PLATAFORMA[$f->plataforma] ?? $f->plataforma,
                    'origen' => 'api',
                    'fecha' => null,
                    'marca' => $f->tipo_cuenta ?? 'Sin clasificar',
                    'campania' => $f->nombre_campania ?: '(sin nombre de campaña)',
                    'funnel' => $f->funnel,
                    'costo' => round((float) $f->costo, 2),
                    'impresiones' => (int) $f->impresiones,
                    'clicks' => (int) $f->clicks,
                    'installs' => (int) $f->installs,
                    'anuncios' => (int) $f->anuncios,
                ]);

            $etapas = config("fuentes.{$pais}.medios_manuales", []);
            $desde = "{$mes}-01";
            foreach ((clone $manuales)->whereBetween('fecha', [$desde, date('Y-m-t', strtotime($desde))])->orderBy('fecha')->get() as $g) {
                $filas->push([
                    'id' => $g->id,
                    'medio' => $g->medio,
                    'origen' => 'manual',
                    'fecha' => substr((string) $g->fecha, 0, 10),
                    'marca' => 'DTC',
                    'campania' => $g->campania ?: 'Captura manual',
                    'funnel' => $etapas[$g->medio] ?? null,
                    'costo' => round((float) $g->gasto, 2),
                    'impresiones' => null,
                    'clicks' => null,
                    'installs' => null,
                    'anuncios' => null,
                ]);
            }
        }

        return Inertia::render('MediosData', [
            'pais' => $pais,
            'meses' => $meses,
            'mes' => $mes,
            'filas' => $filas->sortByDesc('costo')->values(),
            'mediosManuales' => array_keys(config("fuentes.{$pais}.medios_manuales", [])),
        ]);
    }

    /** Agrega gasto de un medio sin API (medio, fecha, gasto, campaña opcional). */
    public function store(Request $request, string $pais): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $datos = $request->validate([
            'medio' => ['required', 'string', Rule::in(array_keys(config("fuentes.{$pais}.medios_manuales", [])))],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'gasto' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'campania' => ['nullable', 'string', 'max:300'],
        ], ['medio.in' => 'Ese medio no se captura a mano en este país.']);

        $id = DB::table('medios_gasto_manual')->insertGetId([
            ...$datos,
            'gasto' => round((float) $datos['gasto'], 2),
            'cuenta_id' => CuentaActual::id(),
            'pais_id' => $paisModelo->id,
            'capturado_por' => $request->user()->id,
            'creado_en' => now(),
        ]);
        Auditoria::registrar('medios.gasto_manual', $request->user(), detalle: ['pais' => $pais, ...$datos]);

        return response()->json(['id' => $id, 'mes' => substr($datos['fecha'], 0, 7)]);
    }

    public function destroy(Request $request, string $pais, int $gasto): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $borradas = DB::table('medios_gasto_manual')
            ->where('id', $gasto)->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisModelo->id)
            ->delete();
        abort_unless($borradas, 404);
        Auditoria::registrar('medios.gasto_manual_borrado', $request->user(), detalle: ['pais' => $pais, 'id' => $gasto]);

        return response()->json(['ok' => true]);
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
