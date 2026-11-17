<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Fuentes\FunnelFuentes;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Funnel de conversión (2026-11-17, pedido explícito): Installs → App Opened
 * → Sessions → ... → Total Order por mes, semana o día, para el negocio
 * completo, Paid, Orgánico o cada medio paid. Sale de AF data, igual que
 * las hojas funnel.2026 / funnel.medio.2026. Solo países con Fuentes. EPA.
 */
class FunnelController extends Controller
{
    public function index(string $pais, FunnelFuentes $funnel): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        $fuentes = config("fuentes.{$pais}");
        abort_unless($fuentes, 404, 'Este país todavía no tiene Fuentes.');
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        return Inertia::render('Funnel', [
            'pais' => $pais,
            ...$funnel->armar($paisModelo->id, $fuentes['fuente_paid'], $fuentes['canales_paid'] ?? []),
        ]);
    }
}
