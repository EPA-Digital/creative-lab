<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Fuentes\PanelCanales;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Channel (2026-11-17, pedido explícito): installs, new customers, órdenes y
 * OR por canal, año contra año, por mes o semana (channel.monthly /
 * channel.weekly de cada Daily Cuentas). Todos los países con canales en
 * config/canales.php. EPA únicamente.
 */
class ChannelController extends Controller
{
    public function index(string $pais, PanelCanales $panel): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        $canales = config("canales.{$pais}");
        abort_unless($canales, 404, 'Este país todavía no tiene canales configurados.');
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        return Inertia::render('Channel', [
            'pais' => $pais,
            'canales' => array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'color' => $c['color']], $canales),
            // Sin AF data todavía (México): el año en curso sale vacío.
            'tieneAfData' => config("fuentes.{$pais}") !== null,
            ...$panel->armar($paisModelo->id, $canales),
        ]);
    }
}
