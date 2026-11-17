<?php

namespace App\Http\Controllers;

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\Resultado;
use App\Services\Inteligencia\CompararGrupos;
use App\Services\RangosActividadService;
use App\Services\Reportes\ExportadorPdfGrupos;
use App\Services\Reportes\ReporteCreativosService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /**
     * Comparar grupos (2026-11-17, pedido explícito): 2 a 4 grupos de
     * búsqueda + mes, cada uno contra el benchmark de su mes. Ver
     * CompararGrupos. Trae los creativos de cada grupo (con su resultado
     * de ese mes y su benchmark) para verlos como en Creativos.
     */
    public function grupos(Request $request, string $pais, CompararGrupos $comparador): JsonResponse
    {
        [$paisModelo, $datos] = $this->validarGrupos($request, $pais);
        $resultado = $comparador->comparar($paisModelo->id, array_values($datos['grupos']), $datos);

        return response()->json([
            'grupos' => array_map(function (array $g) {
                $orden = array_column($g['filas'], 'creativoId');
                $benchPorId = [];
                foreach ($g['filas'] as $f) {
                    $benchPorId[$f['creativoId']] = CompararGrupos::benchmarkDe($f, $g['benchmark']);
                }
                $modelos = Creativo::whereIn('id', $orden)
                    ->with(['resultados' => fn ($q) => $q->where('mes', $g['mes']), 'correccionNombre'])
                    ->get()
                    ->keyBy('id');
                $lista = [];
                foreach ($orden as $id) {
                    if ($c = $modelos->get($id)) {
                        $c->setAttribute('benchmark', $benchPorId[$id]);
                        $lista[] = $c;
                    }
                }
                unset($g['filas'], $g['benchmark']);

                return [...$g, 'lista' => $lista];
            }, $resultado),
        ]);
    }

    /**
     * PDF de la comparación (2026-11-17, pedido explícito): los grupos
     * lado a lado por etapa y, de cada grupo, sus creativos con imagen y
     * su benchmark contra su mes. Solo EPA.
     */
    public function gruposPdf(Request $request, string $pais, CompararGrupos $comparador, ReporteCreativosService $reporte): BinaryFileResponse
    {
        [$paisModelo, $datos] = $this->validarGrupos($request, $pais);
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        $resultado = $comparador->comparar($paisModelo->id, array_values($datos['grupos']), $datos);
        $miniaturas = array_map(fn (array $g) => $reporte->miniaturas($paisModelo->id, $g['filas']), $resultado);

        $mesTexto = fn (string $m) => Carbon::createFromFormat('Y-m', $m)->locale('es')->translatedFormat('F Y');
        $nombres = array_map(fn ($g) => "\"{$g['q']}\" ({$mesTexto($g['mes'])})", $resultado);
        $titulo = "Comparación de creativos — {$paisModelo->nombre}";
        $filtros = array_filter([
            ($datos['plataforma'] ?? null) ? ($datos['plataforma'] === 'meta' ? 'Meta' : 'TikTok') : null,
            $datos['tipo_cuenta'] ?? null,
        ]);
        $subtitulo = implode(' vs ', $nombres).($filtros ? ' · '.implode(' · ', $filtros) : '').' · generado el '.now()->locale('es')->translatedFormat('j \d\e F Y, H:i');

        $ruta = tempnam(sys_get_temp_dir(), 'comparacion-');
        (new ExportadorPdfGrupos)->generar($resultado, $miniaturas, $titulo, $subtitulo, $ruta);
        $archivo = 'comparacion-creativos-'.$pais.'-'.Str::slug(implode('-vs-', array_map(fn ($g) => "{$g['q']}-{$g['mes']}", $resultado))).'.pdf';

        return response()->download($ruta, $archivo)->deleteFileAfterSend();
    }

    /** @return array{0: Pais, 1: array<string, mixed>} */
    private function validarGrupos(Request $request, string $pais): array
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $datos = $request->validate([
            'grupos' => ['required', 'array', 'min:2', 'max:4'],
            'grupos.*.q' => ['required', 'string', 'min:2', 'max:100'],
            'grupos.*.mes' => ['required', 'date_format:Y-m'],
            'plataforma' => ['nullable', 'in:meta,tiktok'],
            'tipo_cuenta' => ['nullable', 'string', 'max:20'],
        ], [
            'grupos.min' => 'Agrega al menos dos grupos para comparar.',
            'grupos.*.q.required' => 'Cada grupo necesita un texto de búsqueda.',
            'grupos.*.q.min' => 'La búsqueda de cada grupo debe tener al menos 2 letras.',
        ]);

        return [$paisModelo, $datos];
    }
}
