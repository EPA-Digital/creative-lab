<?php

namespace App\Http\Controllers;

use App\Models\Creativo;
use App\Models\Pais;
use App\Services\Ia\AnthropicApiClient;
use App\Services\Ia\EvaluadorCreativoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evaluación IA on-demand del modal de detalle de creativo (2026-08-26, ver
 * plan) -- botones "Evaluar por métricas"/"Evaluar por arte". El servicio ya
 * revisa caché antes de llamar a Anthropic, así que esta ruta siempre puede
 * ser idempotente para el frontend sin lógica extra de caché en el cliente.
 */
class EvaluacionCreativoController extends Controller
{
    public function evaluar(Request $request, string $pais, int $creativo): JsonResponse
    {
        $data = $request->validate([
            'modo' => 'required|in:metricas,arte',
            'mes' => 'required|string',
        ]);

        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();
        $creativoModelo = Creativo::where('id', $creativo)->where('pais_id', $paisModelo->id)->firstOrFail();

        $servicio = new EvaluadorCreativoService(AnthropicApiClient::fromConfig());
        $evaluacion = $data['modo'] === 'metricas'
            ? $servicio->evaluarPorMetricas($creativoModelo, $data['mes'])
            : $servicio->evaluarPorArte($creativoModelo, $data['mes']);

        return response()->json([
            'resultado' => $evaluacion->resultado,
            'score' => $evaluacion->score,
            'desglose' => $evaluacion->desglose,
            'modelo' => $evaluacion->modelo,
        ]);
    }
}
