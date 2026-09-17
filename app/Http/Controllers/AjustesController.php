<?php

namespace App\Http\Controllers;

use App\Models\AppsflyerApp;
use App\Models\Pais;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de apps de AppsFlyer por país (tabla appsflyer_apps, 2026-08-12) --
 * reemplaza tener que agregar un país a mano por seeder/tinker. El {pais} de
 * la URL es solo contexto visual del rail (DashboardLayout exige :pais como
 * prop obligatoria) -- la gestión en sí es global, cubre los 4 países.
 */
class AjustesController extends Controller
{
    public function index(string $pais): Response
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");

        return Inertia::render('Ajustes/Index', [
            'pais' => $pais,
            'paises' => Pais::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'appsflyerApps' => AppsflyerApp::with('pais')->orderBy('pais_id')->orderBy('plataforma')->get(),
        ]);
    }

    /**
     * Upsert por (pais_id, plataforma) -- mismo criterio que Creativo/
     * Resultado: cargar la misma app dos veces corrige en vez de romper el
     * unique(['pais_id','plataforma']) de la tabla.
     */
    public function storeAppsflyerApp(Request $request, string $pais): JsonResponse
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");

        $data = $request->validate([
            'pais_id' => ['required', 'integer', 'exists:paises,id'],
            'plataforma' => ['required', 'in:ios,android'],
            'app_id' => ['required', 'string', 'max:100'],
            'nombre' => ['nullable', 'string', 'max:100'],
        ]);

        $app = AppsflyerApp::updateOrCreate(
            ['pais_id' => $data['pais_id'], 'plataforma' => $data['plataforma']],
            ['app_id' => $data['app_id'], 'nombre' => $data['nombre'] ?? null],
        );

        return response()->json($app->load('pais'));
    }
}
