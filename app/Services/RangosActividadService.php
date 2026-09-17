<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rango de actividad REAL por creativo (2026-08-28, pedido explícito) --
 * extraído de AnalisisCreativoController a un servicio compartido porque
 * Inteligencia también lo necesita (comparabilidad entre creativos, ver
 * plan). Un solo rango por creativo, primer día con actividad real ->
 * último día con actividad real. "Actividad" = impresiones > 0 O costo > 0
 * (lo que pase primero define el inicio, lo que pase último define el
 * fin) -- APROXIMADO a partir de resultados_diarios, nunca el registro
 * exacto de encendido/apagado de Meta (ver MetaEstadoAdsSyncService para
 * eso, todavía acumulando historial). Países sin `resultados_diarios`
 * (Ecuador/México, pipeline mensual) simplemente no tienen filas -- cada
 * creativo queda sin rango, el caller nunca fuerza un dato que no existe.
 */
class RangosActividadService
{
    /**
     * @return array<int, list<array{inicio: string, fin: string}>>
     */
    public static function porCreativo(int $paisId): array
    {
        $filas = DB::table('resultados_diarios')
            ->join('creativos', 'creativos.id', '=', 'resultados_diarios.creativo_id')
            ->where('creativos.pais_id', $paisId)
            ->where(function ($q) {
                $q->where('resultados_diarios.impressions', '>', 0)
                    ->orWhere('resultados_diarios.cost', '>', 0);
            })
            ->groupBy('resultados_diarios.creativo_id')
            ->get([
                'resultados_diarios.creativo_id',
                DB::raw('MIN(resultados_diarios.fecha) as inicio'),
                DB::raw('MAX(resultados_diarios.fecha) as fin'),
            ]);

        $rangos = [];
        foreach ($filas as $fila) {
            $rangos[$fila->creativo_id] = [[
                'inicio' => substr((string) $fila->inicio, 0, 10),
                'fin' => substr((string) $fila->fin, 0, 10),
            ]];
        }

        return $rangos;
    }
}
