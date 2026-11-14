<?php

namespace App\Http\Controllers;

use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Fuentes\LimpiadorFuentes;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * AF data (2026-11-13, pedido explícito; empieza solo con Ecuador): el
 * export del pivot de AppsFlyer limpio y clasificado por canal, en una
 * tabla tipo Excel. Base de la vista de canales (venta real → canales →
 * anuncios). EPA únicamente.
 */
class AfDataController extends Controller
{
    public function index(Request $request, string $pais): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $limpiador = LimpiadorFuentes::paraPais($pais);

        $meses = FuenteAppsflyer::query()
            ->where('pais_id', $paisModelo->id)
            ->selectRaw('SUBSTR(fecha, 1, 7) as mes, COUNT(*) as filas, MIN(fecha) as desde, MAX(fecha) as hasta')
            ->groupBy('mes')
            ->orderByDesc('mes')
            ->get()
            ->map(fn ($m) => ['mes' => $m->mes, 'filas' => (int) $m->filas, 'desde' => $m->desde, 'hasta' => $m->hasta]);

        $mes = $request->query('mes');
        if (! $mes || ! $meses->contains('mes', $mes)) {
            $mes = $meses->first()['mes'] ?? null;
        }

        $filas = $mes ? FuenteAppsflyer::query()
            ->where('pais_id', $paisModelo->id)
            ->whereBetween('fecha', ["{$mes}-01", date('Y-m-t', strtotime("{$mes}-01"))])
            ->orderBy('fecha')->orderBy('media_source')->orderBy('campania')
            ->get(['id', 'fecha', 'app', 'media_source', 'campania', 'campania_id', 'installs', 'nc', 'recompras', 'ordenes', 'otros', 'fuente_final', 'canal'])
            : collect();

        return Inertia::render('AfData', [
            'pais' => $pais,
            'tieneReglas' => $limpiador !== null,
            'canalesPaid' => $limpiador?->canalesPaid() ?? [],
            'meses' => $meses->values(),
            'mes' => $mes,
            'filas' => $filas->values(),
        ]);
    }

    public function importar(Request $request, string $pais): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $limpiador = LimpiadorFuentes::paraPais($pais);
        abort_unless($limpiador, 422, 'Este país todavía no tiene reglas de limpieza de AppsFlyer.');

        $request->validate(['archivo' => ['required', 'file', 'max:20480']]);

        try {
            $resultado = $limpiador->limpiar((string) file_get_contents($request->file('archivo')->getRealPath()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($resultado['filas'] === []) {
            return response()->json(['message' => 'El archivo no trae filas de datos (solo subtotales).'], 422);
        }

        $cuentaId = CuentaActual::id();
        $ahora = now();
        DB::transaction(function () use ($resultado, $paisModelo, $cuentaId, $ahora) {
            // Reimportar reemplaza los días que trae el archivo.
            FuenteAppsflyer::query()
                ->where('pais_id', $paisModelo->id)
                ->whereBetween('fecha', [$resultado['desde'], $resultado['hasta']])
                ->delete();

            foreach (array_chunk($resultado['filas'], 500) as $tanda) {
                DB::table('fuentes_appsflyer')->insert(array_map(fn ($f) => [
                    ...$f,
                    'otros' => json_encode($f['otros']),
                    'cuenta_id' => $cuentaId,
                    'pais_id' => $paisModelo->id,
                    'creado_en' => $ahora,
                ], $tanda));
            }
        });

        Auditoria::registrar('fuentes.importadas', $request->user(), detalle: [
            'pais' => $pais,
            'desde' => $resultado['desde'],
            'hasta' => $resultado['hasta'],
            'filas' => count($resultado['filas']),
        ]);

        return response()->json([
            'filas' => count($resultado['filas']),
            'subtotales' => $resultado['subtotales'],
            'desde' => $resultado['desde'],
            'hasta' => $resultado['hasta'],
            'mes' => substr($resultado['desde'], 0, 7),
        ]);
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
