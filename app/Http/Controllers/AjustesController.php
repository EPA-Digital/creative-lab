<?php

namespace App\Http\Controllers;

use App\Models\AppsflyerApp;
use App\Models\Pais;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de apps de AppsFlyer por país (tabla appsflyer_apps, 2026-08-12) --
 * reemplaza tener que agregar un país a mano por seeder/tinker.
 *
 * El {pais} de la URL sigue siendo solo contexto visual del rail
 * (DashboardLayout exige :pais como prop obligatoria) -- pero la
 * gestión en sí (2026-09-23, fix de scope) quedó acotada a los países
 * que el usuario tiene asignados, no a los 4 países como antes: ver/
 * editar el App ID de un país que ni siquiera puede ver en el dashboard
 * era un hueco real del scope por país.
 */
class AjustesController extends Controller
{
    public function index(string $pais, Request $request): Response
    {
        abort_unless(config("paises.{$pais}"), 404, "País \"{$pais}\" no existe en config/paises.php.");

        $paisesPermitidos = $request->user()->paises()->orderBy('nombre')->get(['paises.id', 'codigo', 'nombre']);

        return Inertia::render('Ajustes/Index', [
            'pais' => $pais,
            'paises' => $paisesPermitidos,
            'appsflyerApps' => AppsflyerApp::with('pais')
                ->whereIn('pais_id', $paisesPermitidos->pluck('id'))
                ->orderBy('pais_id')->orderBy('plataforma')->get(),
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
            // No alcanza con exists:paises,id -- tiene que ser un país que
            // el usuario realmente tenga asignado (ver comentario de
            // clase), si no cualquier EPA podía editar el App ID de un
            // país ajeno con solo cambiar el pais_id en el POST.
            'pais_id' => ['required', 'integer', Rule::in($request->user()->paises()->pluck('paises.id'))],
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
