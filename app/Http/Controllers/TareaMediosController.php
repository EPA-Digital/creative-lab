<?php

namespace App\Http\Controllers;

use App\Jobs\RecuperarImagenesMes;
use App\Models\Pais;
use App\Models\TareaMedios;
use Illuminate\Http\JsonResponse;

/**
 * Imágenes/videos en background (pedido explícito 2026-10-07): el panel
 * consulta el avance de una tarea para avisar cuando está lista (o mostrar
 * el error), y "Recuperar imágenes" crea una tarea para un mes.
 */
class TareaMediosController extends Controller
{
    public function show(string $pais, TareaMedios $tarea): JsonResponse
    {
        abort_unless($tarea->pais_id === $this->paisId($pais), 404);

        return response()->json($tarea->only(['id', 'mes', 'origen', 'total', 'listos', 'fallidos', 'estado', 'error_mensaje']));
    }

    public function recuperarImagenes(string $pais, string $mes): JsonResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $mes) === 1, 404);
        $paisId = $this->paisId($pais);

        $sinImagen = RecuperarImagenesMes::creativosSinImagen($paisId, $mes)->count();
        if ($sinImagen === 0) {
            return response()->json(['message' => 'Todos los creativos de ese mes ya tienen imagen.'], 422);
        }

        $tarea = TareaMedios::create([
            'pais_id' => $paisId,
            'mes' => $mes,
            'origen' => 'recuperacion',
            'total' => $sinImagen,
        ]);
        RecuperarImagenesMes::dispatch($tarea->id);

        return response()->json(['tareaMediosId' => $tarea->id, 'total' => $sinImagen], 202);
    }

    private function paisId(string $pais): int
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404);

        return Pais::where('codigo', $config['codigo'])->firstOrFail()->id;
    }
}
