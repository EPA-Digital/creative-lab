<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Fuentes\LimpiadorFuentes;
use App\Services\Fuentes\PanelFuentes;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fuentes (2026-11-13, pedido explícito; empieza con Ecuador): la venta
 * real del mes repartida por canal con el mix de AppsFlyer (AF data), con
 * inversión, brecha AppsFlyer vs real, participación y eficiencia (CPO, CAC,
 * CPI), por mes o por semana. EPA únicamente.
 */
class FuentesController extends Controller
{
    public function index(string $pais, PanelFuentes $panel): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $limpiador = LimpiadorFuentes::paraPais($pais);

        return Inertia::render('Fuentes', [
            'pais' => $pais,
            'tieneReglas' => $limpiador !== null,
            'canalesPaid' => $limpiador?->canalesPaid() ?? [],
            'mediosManuales' => array_keys(config("fuentes.{$pais}.medios_manuales", [])),
            ...$panel->armar($paisModelo->id, $limpiador?->canalesPaid() ?? [], config("fuentes.{$pais}.medios_manuales", [])),
        ]);
    }

    /**
     * Venta real de un periodo (mes o semana, por separado): órdenes y/o NC;
     * vacío = sin capturar. La inversión de los medios sin API se captura en
     * Medios data.
     */
    public function guardarVentaReal(Request $request, string $pais): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $datos = $request->validate([
            'tipo' => ['required', 'in:mes,semana'],
            'clave' => ['required', $request->input('tipo') === 'semana' ? 'date_format:Y-m-d' : 'date_format:Y-m'],
            'ordenes' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'nc' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        DB::table('fuentes_venta_real')->updateOrInsert(
            ['cuenta_id' => CuentaActual::id(), 'pais_id' => $paisModelo->id, 'tipo' => $datos['tipo'], 'clave' => $datos['clave']],
            ['ordenes' => $datos['ordenes'] ?? null, 'nc' => $datos['nc'] ?? null, 'capturado_por' => $request->user()->id, 'actualizado_en' => now()],
        );

        Auditoria::registrar('fuentes.venta_real', $request->user(), detalle: [
            'pais' => $pais, 'tipo' => $datos['tipo'], 'clave' => $datos['clave'], 'ordenes' => $datos['ordenes'] ?? null, 'nc' => $datos['nc'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
