<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PASO 1 de Fase 3 (dashboard visual) -- flujo de datos mínimo, sin diseño.
 * Confirma que Laravel lee creativos+resultados reales de MySQL y Vue los
 * pinta vía Inertia. La vista es una tabla HTML sin estilo a propósito --
 * lo bonito (card, podio, carrusel) es el paso siguiente, una vez que este
 * flujo esté confirmado funcionando de punta a punta.
 */
class AnalisisCreativoController extends Controller
{
    public function index(Request $request, string $pais, ?string $plataforma = null): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $creativos = $paisModelo->creativos()
            ->with('resultados')
            ->when($plataforma, fn ($query) => $query->where('plataforma', $plataforma))
            ->orderBy('nombre_comun')
            ->get();

        return Inertia::render('AnalisisCreativo', [
            'pais' => $pais,
            'plataforma' => $plataforma,
            'creativos' => $creativos,
        ]);
    }
}
