<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Models\Resultado;
use App\Services\RangosActividadService;
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

        // 2026-08-12: con 8 meses cargados (backfill histórico), "resultados"
        // ya no puede traer TODOS los meses de un creativo a la vez --
        // resultados?.[0] del front (motor.js/AnalisisCreativo.vue) asume UN
        // solo resultado por creativo, así que acá se acota a un mes
        // elegido. Un creativo sin actividad ese mes no aparece (mismo
        // criterio que el filtro de "sin actividad" del import: no mostrar
        // una card vacía).
        $mesesDisponibles = Resultado::whereHas('creativo', fn ($q) => $q->where('pais_id', $paisModelo->id))
            ->distinct()
            ->orderBy('mes')
            ->pluck('mes');

        $mes = $request->query('mes');
        if (! $mes || ! $mesesDisponibles->contains($mes)) {
            $mes = $mesesDisponibles->last();
        }

        $creativos = $paisModelo->creativos()
            ->whereHas('resultados', fn ($q) => $q->where('mes', $mes))
            ->with(['resultados' => fn ($q) => $q->where('mes', $mes), 'correccionNombre'])
            ->when($plataforma, fn ($query) => $query->where('plataforma', $plataforma))
            ->orderBy('nombre_comun')
            ->get();

        // rangosActividad -- 2026-08-28, pedido explícito: "el tiempo que
        // estuvieron activos" en la card de cada creativo. Meta NO expone
        // el historial real de encendido/apagado de antes de esta semana
        // (confirmado contra la cuenta real de Ecuador -- ver
        // MetaEstadoAdsSyncService), así que para todo lo ya importado
        // (enero-julio acá) se APROXIMA a partir de resultados_diarios:
        // primer día con impresiones o costo real -> último día con
        // impresiones o costo real (ver rangosActividadPorCreativo) --
        // nunca es el registro exacto de Meta, por eso se etiqueta
        // "aproximado" en el front. Países sin `resultados_diarios`
        // (Ecuador/México, pipeline mensual) simplemente no tienen filas
        // acá -- cada creativo queda con rangos_actividad vacío, el front
        // no muestra nada extra, nunca fuerza un dato que no existe.
        //
        // Una sola query agregada para TODO el país (nunca N+1 por
        // creativo).
        $rangosPorCreativo = RangosActividadService::porCreativo($paisModelo->id);
        foreach ($creativos as $creativo) {
            $creativo->setAttribute('rangos_actividad', $rangosPorCreativo[$creativo->id] ?? []);
        }

        return Inertia::render('AnalisisCreativo', [
            'pais' => $pais,
            'plataforma' => $plataforma,
            'creativos' => $creativos,
            'mes' => $mes,
            'mesesDisponibles' => $mesesDisponibles,
        ]);
    }
}
