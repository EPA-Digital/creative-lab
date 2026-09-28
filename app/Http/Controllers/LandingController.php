<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Puerto de index.html (selector de país) -- lee config/paises.php, el mismo
 * mirror de config/paises.js que ya usa AnalisisCreativoController.
 */
class LandingController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // Scope por país (2026-09-23) -- solo se muestran los países que el
        // usuario tiene asignados en usuario_pais (ver EnsureAccesoPais);
        // nunca una tarjeta que después 403ee al hacer clic.
        $codigosPermitidos = $request->user()->paises()->pluck('codigo')->all();

        $paises = collect(config('paises'))
            ->map(fn ($config, $id) => [...$config, 'id' => $id])
            ->filter(fn ($config) => in_array($config['codigo'], $codigosPermitidos, true))
            ->values();

        // Si el usuario solo tiene un país asignado, el selector no aporta
        // nada -- se salta directo a su análisis (pedido explícito
        // 2026-09-25). Se exige además 'habilitado' porque el filtro de
        // arriba solo mira el acceso asignado, no si el país en sí ya
        // tiene datos/cuenta conectada (ver config/paises.php) -- un único
        // país asignado pero deshabilitado sí debe mostrar el selector
        // (con su tarjeta en "próximamente"), no un redirect a una
        // pantalla vacía.
        if ($paises->count() === 1 && $paises->first()['habilitado']) {
            return redirect()->route('analisis-creativo', ['pais' => $paises->first()['id']]);
        }

        return Inertia::render('Landing', [
            'paises' => $paises,
        ]);
    }
}
