<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Fuentes\ControlGasto;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Control de gasto (2026-11-18, pedido explícito): alertas del día, cómo le
 * fue a cada campaña contra anteayer / semana / mes pasado, histórico y
 * metas. Todos los países con canales. EPA únicamente.
 */
class ControlGastoController extends Controller
{
    public function index(Request $request, string $pais, ControlGasto $control): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);

        return Inertia::render('ControlGasto', [
            'pais' => $pais,
            ...$control->armar($paisModelo->id, $pais, is_string($request->query('al')) ? $request->query('al') : null),
        ]);
    }

    /** Marca o desmarca una alerta como revisada (la ve todo el equipo). */
    public function revisar(Request $request, string $pais): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'clave' => ['required', 'string', 'max:320'],
            'revisada' => ['required', 'boolean'],
        ]);
        $donde = ['cuenta_id' => CuentaActual::id(), 'pais_id' => $paisModelo->id, 'fecha' => $datos['fecha'], 'clave' => $datos['clave']];
        DB::table('control_revisiones')->where($donde)->delete();
        if ($datos['revisada']) {
            DB::table('control_revisiones')->insert([...$donde, 'usuario_id' => $request->user()->id, 'creado_en' => now()]);
        }
        Auditoria::registrar('control.revision', $request->user(), detalle: ['pais' => $pais, ...$datos]);

        return response()->json(['ok' => true]);
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless(config("canales.{$pais}"), 404, 'Este país todavía no tiene medios configurados.');

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
