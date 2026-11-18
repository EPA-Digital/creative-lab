<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Fuentes\PanelSistemaOperativo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SO (2026-11-18, pedido explícito): installs, new customers, recompras,
 * registros e inversión por sistema operativo, del negocio completo y de
 * Paid -- so.2026 / ✅SO / SO ✅ de cada Daily Cuentas. Países con canales;
 * sin AF data (México por ahora) solo sale la inversión. EPA únicamente.
 */
class SistemaOperativoController extends Controller
{
    public function index(string $pais, PanelSistemaOperativo $panel): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless(config("canales.{$pais}"), 404, 'Este país todavía no tiene medios configurados.');
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();
        $fuentes = config("fuentes.{$pais}");

        return Inertia::render('SistemaOperativo', [
            'pais' => $pais,
            'tieneAfData' => $fuentes !== null,
            'fuentePaid' => $fuentes['fuente_paid'] ?? 'TADA PERFORMANCE',
            ...$panel->armar($paisModelo->id, $fuentes['fuente_paid'] ?? null),
        ]);
    }
}
