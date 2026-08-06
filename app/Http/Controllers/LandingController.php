<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Puerto de index.html (selector de país) -- lee config/paises.php, el mismo
 * mirror de config/paises.js que ya usa AnalisisCreativoController.
 */
class LandingController extends Controller
{
    public function index(): Response
    {
        $paises = collect(config('paises'))
            ->map(fn ($config, $id) => [...$config, 'id' => $id])
            ->values();

        return Inertia::render('Landing', [
            'paises' => $paises,
        ]);
    }
}
