<?php

namespace App\Http\Controllers;

use App\Models\CorreccionNombre;
use App\Models\Creativo;
use App\Models\Pais;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Taxonomía legible (2026-08-27, ver plan del rediseño de Creativos/
 * Inteligencia): cablea la tabla correcciones_nombres (existía desde el
 * inicio del proyecto, nunca se había usado) como el mecanismo de "nombre
 * amigable" por creativo -- ad_id -> nombre_corregido, mostrado como texto
 * principal en cards/tabla/modal con el nombre técnico (nombre_comun/
 * nombre_completo) siempre visible debajo, nunca reemplazado.
 *
 * Sin acotar a un mes a propósito -- es gestión de taxonomía (identidad del
 * creativo), no de rendimiento, así que trae TODOS los creativos del país
 * sin importar si tuvieron actividad reciente.
 */
class GestionNombresController extends Controller
{
    public function index(string $pais): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $creativos = $paisModelo->creativos()
            ->with('correccionNombre')
            ->orderBy('nombre_comun')
            ->get(['id', 'ad_id', 'nombre_comun', 'nombre_completo', 'plataforma']);

        return Inertia::render('GestionNombres', [
            'pais' => $pais,
            'creativos' => $creativos,
        ]);
    }

    /**
     * Mismo patrón que AjustesController::storeAppsflyerApp -- upsert por
     * clave natural (acá ad_id, único en correcciones_nombres) en vez de
     * romper con un duplicado si el creativo ya tenía nombre asignado.
     * corregido_por queda null sin login funcional todavía (Modelo A de
     * invitación, ver AnalisisCreativoController) -- nunca se inventa un
     * usuario.
     */
    public function store(Request $request, string $pais, Creativo $creativo): JsonResponse
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless($creativo->pais->codigo === $config['codigo'], 404);

        $data = $request->validate([
            'nombre_corregido' => ['required', 'string', 'max:255'],
        ]);

        $correccion = CorreccionNombre::updateOrCreate(
            ['ad_id' => $creativo->ad_id],
            ['nombre_corregido' => trim($data['nombre_corregido']), 'corregido_por' => $request->user()?->id],
        );

        return response()->json($correccion);
    }
}
