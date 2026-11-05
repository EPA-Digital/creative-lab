<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entrada de la app: primero la cuenta (multi-cuenta, 2026-11-04), después
 * el país -- puerto de index.html (selector de país), lee config/paises.php,
 * el mismo mirror de config/paises.js que ya usa AnalisisCreativoController.
 */
class LandingController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // Scope por cuenta y país -- solo lo que el usuario tiene asignado
        // (usuario_cuenta + usuario_pais) y donde opera cada cuenta
        // (cuenta_pais); nunca una tarjeta que después 403ee al hacer clic
        // (ver EnsureAccesoPais).
        $codigosPermitidos = $request->user()->paises()->pluck('codigo')->all();
        $configPaises = collect(config('paises'))->map(fn ($config, $id) => [...$config, 'id' => $id]);

        $cuentas = $request->user()->cuentas()
            ->where('activa', true)
            ->with(['cliente:id,nombre', 'paises:id,codigo'])
            ->orderBy('nombre')
            ->get()
            ->map(function (Cuenta $c) use ($configPaises, $codigosPermitidos) {
                $codigosCuenta = $c->paises->pluck('codigo')->all();

                return [
                    'slug' => $c->slug,
                    'nombre' => $c->nombre,
                    'cliente' => $c->cliente?->nombre,
                    'color' => $c->color,
                    'logo' => $c->logoSrc(),
                    'paises' => $configPaises
                        ->filter(fn ($config) => in_array($config['codigo'], $codigosCuenta, true) && in_array($config['codigo'], $codigosPermitidos, true))
                        ->values(),
                ];
            })
            ->filter(fn (array $c) => $c['paises']->isNotEmpty())
            ->values();

        // Una sola cuenta con un solo país habilitado: el selector no aporta
        // nada, se salta directo a su análisis (pedido explícito
        // 2026-09-25). Se exige además 'habilitado' -- un único país
        // asignado pero deshabilitado sí debe mostrar el selector (con su
        // tarjeta en "próximamente"), no un redirect a una pantalla vacía.
        if ($cuentas->count() === 1 && $cuentas[0]['paises']->count() === 1 && $cuentas[0]['paises'][0]['habilitado']) {
            return redirect()->route('analisis-creativo', ['cuenta' => $cuentas[0]['slug'], 'pais' => $cuentas[0]['paises'][0]['id']]);
        }

        return Inertia::render('Landing', [
            'cuentas' => $cuentas,
            // ?cuenta=slug abre directo los países de esa cuenta ("Cambiar
            // país" desde el rail).
            'cuentaInicial' => $request->query('cuenta'),
        ]);
    }
}
