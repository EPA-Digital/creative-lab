<?php

namespace App\Http\Controllers;

use App\Models\Importacion;
use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Puerto del panel "Cargar datos" (import-panel) de meta.html/tiktok.html --
 * ambos endpoints (previsualizar/importar) llaman a ImportadorDatos, la
 * MISMA clase que usa el comando de consola `importar:csv`, para que nunca
 * puedan desincronizarse.
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
     * Corre el pipeline completo sobre el CSV ya subido en previsualizar() y
     * persiste -- misma clase ImportadorDatos que importar:csv por consola.
     */
    public function importar(Request $request, string $pais): JsonResponse
    {
        // El SAPI web (a diferencia de CLI, que no tiene límite por defecto)
        // corta a los 30s -- esta llamada trae costos de Meta/TikTok para
        // TODOS los ads del rango vía API real, que con cientos de ads
        // legítimamente tarda más que eso. Mismo trabajo que ya hacía
        // importar:csv por consola, sin este límite.
        set_time_limit(0);

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

        try {
            $resumen = ImportadorDatos::importar(
                Storage::path($rutaRelativa),
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
        } finally {
            Storage::delete($rutaRelativa);
        }

        Importacion::create([
            'pais_id' => $resumen['pais']->id,
            'nombre_archivo' => $data['nombre_archivo'],
            'desde' => $data['desde'],
            'hasta' => $data['hasta'],
            'nc_total_real_meta' => $data['nc_total_real_meta'] ?? null,
            'orders_total_real_meta' => $data['orders_total_real_meta'] ?? null,
            'nc_total_real_tiktok' => $data['nc_total_real_tiktok'] ?? null,
            'orders_total_real_tiktok' => $data['orders_total_real_tiktok'] ?? null,
            'es_rango_parcial' => $resumen['esRangoParcial'],
            'creativos_tocados' => $resumen['creativosTocados'],
            'resultados_tocados' => $resumen['resultadosTocados'],
            'tiene_meta_true' => $resumen['tieneMetaTrue'],
            'problemas' => $resumen['problemas'],
        ]);

        unset($resumen['pais']);

        return response()->json($resumen);
    }
}
