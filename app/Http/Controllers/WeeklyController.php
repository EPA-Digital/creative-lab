<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Fuentes\PanelWeekly;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly (2026-11-18, pedido explícito): cómo vamos cada semana y cada mes en
 * Paid, por tipo de campaña y con la dispersión gasto vs NC (R²) -- la
 * pestaña weekly.fer. Solo los países de config/weekly.php. EPA únicamente.
 */
class WeeklyController extends Controller
{
    public function index(Request $request, string $pais, PanelWeekly $panel): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $anio = (int) $request->query('anio', now()->year);
        $anio = $anio >= 2020 && $anio <= 2100 ? $anio : now()->year;

        return Inertia::render('Weekly', ['pais' => $pais, ...$panel->armar($paisModelo->id, $pais, $anio)]);
    }

    /** Nombre corto ("tipo de campaña") de una campaña, u ocultarla de la tabla. */
    public function tipo(Request $request, string $pais): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $datos = $request->validate([
            'campania' => ['required', 'string', 'max:300'],
            'tipo' => ['nullable', 'string', 'max:120'],
            'oculta' => ['required', 'boolean'],
        ]);
        $tipo = trim((string) ($datos['tipo'] ?? '')) ?: null;
        DB::table('weekly_tipos')->updateOrInsert(
            ['cuenta_id' => CuentaActual::id(), 'pais_id' => $paisModelo->id, 'campania' => trim($datos['campania'])],
            ['tipo' => $tipo, 'oculta' => $datos['oculta'], 'usuario_id' => $request->user()->id, 'actualizado_en' => now()],
        );
        Auditoria::registrar('weekly.tipo', $request->user(), detalle: ['pais' => $pais, 'campania' => $datos['campania'], 'tipo' => $tipo, 'oculta' => $datos['oculta']]);

        return response()->json(['ok' => true]);
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless(in_array($pais, config('weekly.paises', []), true), 404, 'Weekly solo está para Ecuador y Panamá.');

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
