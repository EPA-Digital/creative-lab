<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Models\Resultado;
use App\Services\RangosActividadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inteligencia -- comparación head-to-head de 2-4 creativos (2026-08-27,
 * ver plan). Mismo patrón de país/mes que AnalisisCreativoController, pero
 * `resultados` NO se acota a un solo mes: Inteligencia necesita el
 * historial completo por creativo para el gráfico de tendencia mensual
 * (motor.js:serieMensual). El score/las barras del head-to-head usan solo
 * la fila de `$mes` (motor.js:cardDesdeCreativo(creativo, mes) ya sabe
 * buscarla dentro del historial completo).
 */
class InteligenciaController extends Controller
{
    public function index(Request $request, string $pais): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $mesesDisponibles = Resultado::whereHas('creativo', fn ($q) => $q->where('pais_id', $paisModelo->id))
            ->distinct()
            ->orderBy('mes')
            ->pluck('mes');

        $mes = $request->query('mes');
        if (! $mes || ! $mesesDisponibles->contains($mes)) {
            $mes = $mesesDisponibles->last();
        }

        // plataforma -- mismo filtro opcional que analisis-creativo (query
        // param, no segmento de ruta acá porque Inteligencia no tiene vistas
        // separadas por plataforma como meta.html/tiktok.html en el
        // prototipo). Filtra en el mismo whereHas de actividad del mes, así
        // que el carrito de selección de Inteligencia.vue solo ve creativos
        // ya acotados a la plataforma elegida.
        $plataforma = $request->query('plataforma');

        // Rango del mes elegido para acotar resultadosDiarios -- mismo
        // criterio portable (whereBetween, no strftime/DATE_FORMAT) que
        // ImportadorDatosDiario::recalcularResultadoMensual.
        $primerDia = "{$mes}-01";
        $ultimoDia = date('Y-m-t', strtotime($primerDia));

        // whereHas acota a creativos ACTIVOS en $mes (mismo criterio que
        // analisis-creativo: no mostrar una card para competir si no tiene
        // actividad ese mes) -- with('resultados') sin filtro trae el
        // historial completo de esos creativos para la tendencia mensual.
        //
        // resultadosDiarios (2026-08-28, pedido explícito: tendencia DIARIA
        // en la comparación) -- acotado al mes elegido, no al historial
        // completo (a diferencia de `resultados`, acá sí hay que filtrar:
        // un mes real trae ~30 filas por creativo, el historial completo de
        // varios meses sería impracticable para el payload). Países sin
        // pipeline diario (Ecuador/México, ver ImportadorDatosDiario) simplemente
        // no tienen filas acá -- motor.js:serieDiaria las trata como
        // "sin datos" y ComparacionCreativos oculta el bloque, nunca fuerza
        // una gráfica vacía.
        $creativos = $paisModelo->creativos()
            ->whereHas('resultados', fn ($q) => $q->where('mes', $mes))
            ->with([
                'resultados',
                'resultadosDiarios' => fn ($q) => $q->whereBetween('fecha', [$primerDia, $ultimoDia])->orderBy('fecha'),
                'correccionNombre',
            ])
            ->when($plataforma, fn ($query) => $query->where('plataforma', $plataforma))
            ->orderBy('nombre_comun')
            ->get();

        // rangos_actividad -- 2026-08-28, pedido explícito: "días activos"
        // visible en Inteligencia + marcar comparabilidad real entre
        // creativos (no la comparación de meses/tramos, la de días REALES
        // de entrega). Mismo servicio compartido que ya usa
        // AnalisisCreativoController -- ver RangosActividadService, una
        // sola query agregada para todo el país, nunca N+1.
        $rangosPorCreativo = RangosActividadService::porCreativo($paisModelo->id);
        foreach ($creativos as $creativo) {
            $creativo->setAttribute('rangos_actividad', $rangosPorCreativo[$creativo->id] ?? []);
        }

        return Inertia::render('Inteligencia', [
            'pais' => $pais,
            'plataforma' => $plataforma,
            'creativos' => $creativos,
            'mes' => $mes,
            'mesesDisponibles' => $mesesDisponibles,
        ]);
    }
}
