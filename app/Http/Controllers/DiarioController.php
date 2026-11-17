<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Fuentes\DiarioMedios;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Diario medio / Diario campaña (2026-11-18, pedido explícito): por día,
 * la inversión de data.medios junto con installs / NC / purchase de AF data,
 * por medio o por campaña. Base del Daily. Todos los países con canales.
 * EPA únicamente.
 */
class DiarioController extends Controller
{
    public function medio(Request $request, string $pais, DiarioMedios $diario): Response
    {
        return $this->vista($request, $pais, $diario, 'medio');
    }

    public function campania(Request $request, string $pais, DiarioMedios $diario): Response
    {
        return $this->vista($request, $pais, $diario, 'campania');
    }

    private function vista(Request $request, string $pais, DiarioMedios $diario, string $modo): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless(config("canales.{$pais}"), 404, 'Este país todavía no tiene medios configurados.');
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $meses = $diario->meses($paisModelo->id);
        $mes = $request->query('mes');
        if (! $mes || ! in_array($mes, $meses, true)) {
            $mes = $meses[0] ?? null;
        }

        return Inertia::render('Diario', [
            'pais' => $pais,
            'modo' => $modo,
            'meses' => $meses,
            'mes' => $mes,
            ...($mes ? $diario->mes($paisModelo->id, $pais, $mes) : ['medios' => [], 'dias' => (object) [], 'campanias' => [], 'hasta' => null]),
        ]);
    }
}
