<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Puerto de index.html (selector de país) -- lee config/paises.php, el mismo
 * mirror de config/paises.js que ya usa AnalisisCreativoController.
 */
class LandingController extends Controller
{
    public function index(Request $request): Response
    {
        // Scope por país (2026-09-23) -- solo se muestran los países que el
        // usuario tiene asignados en usuario_pais (ver EnsureAccesoPais);
        // nunca una tarjeta que después 403ee al hacer clic.
        $codigosPermitidos = $request->user()->paises()->pluck('codigo')->all();

        $paises = collect(config('paises'))
            ->map(fn ($config, $id) => [...$config, 'id' => $id])
            ->filter(fn ($config) => in_array($config['codigo'], $codigosPermitidos, true))
            ->values();

        return Inertia::render('Landing', [
            'paises' => $paises,
        ]);
    }
}
