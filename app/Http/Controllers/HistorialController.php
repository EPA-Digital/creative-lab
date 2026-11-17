<?php

namespace App\Http\Controllers;

use App\Services\Fuentes\HistorialCambios;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial de cambios (2026-11-17, pedido explícito): quién cambió qué y
 * cuándo en el país -- Daily, Control de gasto, cargas y capturas. EPA.
 */
class HistorialController extends Controller
{
    public function index(Request $request, string $pais, HistorialCambios $historial): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        $area = $request->query('area');
        $area = is_string($area) && isset(HistorialCambios::AREAS[$area]) ? $area : null;

        return Inertia::render('Historial', [
            'pais' => $pais,
            'area' => $area,
            'usuario' => $request->integer('usuario') ?: null,
            'areas' => collect(HistorialCambios::AREAS)->map(fn ($a, $id) => ['key' => $id, 'label' => $a['nombre']])->values(),
            ...$historial->listar($pais, $config['codigo'], [
                'area' => $area,
                'usuario' => $request->integer('usuario') ?: null,
                'antes' => $request->integer('antes') ?: null,
            ]),
        ]);
    }
}
