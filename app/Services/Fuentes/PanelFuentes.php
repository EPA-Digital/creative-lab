<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Datos de la vista Fuentes (2026-11-13): por mes y por semana, AppsFlyer
 * por canal (AF data), la venta real capturada del mes y la inversión.
 *
 * - Semanas de lunes a domingo, cortadas en el cambio de mes. La venta real
 *   semanal se captura por semana (no se deriva del mes).
 * - Inversión: la de Meta y TikTok que ya tiene el dashboard (resultados),
 *   solo cuentas de performance (todo lo que no es BRD): es lo que se
 *   divide entre los volúmenes Paid EPA. La semanal se prorratea por días
 *   del mes (el gasto se guarda por mes).
 * - El último mes con datos es un corte parcial si AppsFlyer no llega a fin
 *   de mes.
 */
class PanelFuentes
{
    private const MEDIO_POR_PLATAFORMA = ['meta' => 'Facebook Ads', 'tiktok' => 'TikTok'];

    private const FUNNELS = ['AWA', 'CONS', 'CNV', 'LOY'];

    /**
     * @param  list<string>  $medios  orden de los medios paid (config canales_paid)
     * @param  array<string, string>  $mediosManuales  medio sin API → etapa del funnel
     * @return array{meses: list<array<string, mixed>>, semanas: list<array<string, mixed>>}
     */
    public function armar(int $paisId, array $medios = [], array $mediosManuales = []): array
    {
        $cuentaId = CuentaActual::id();

        $diario = DB::table('fuentes_appsflyer')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->groupBy('fecha', 'canal')
            ->selectRaw('fecha, canal, SUM(installs) as installs, SUM(nc) as nc, SUM(ordenes) as ordenes')
            ->orderBy('fecha')
            ->get();
        if ($diario->isEmpty()) {
            return ['meses' => [], 'semanas' => []];
        }

        $ventaReal = DB::table('fuentes_venta_real')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->get()->keyBy(fn ($v) => "{$v->tipo}|{$v->clave}");
        $venta = fn ($vr) => $vr ? ['ordenes' => $vr->ordenes !== null ? (int) $vr->ordenes : null, 'nc' => $vr->nc !== null ? (int) $vr->nc : null] : null;

        $inversion = DB::table('resultados')
            ->join('creativos', 'creativos.id', '=', 'resultados.creativo_id')
            ->where('creativos.cuenta_id', $cuentaId)->where('creativos.pais_id', $paisId)
            ->where(fn ($q) => $q->whereNull('creativos.tipo_cuenta')->orWhere('creativos.tipo_cuenta', '!=', 'BRD'))
            ->groupBy('resultados.mes', 'creativos.plataforma', 'creativos.funnel')
            ->selectRaw('resultados.mes, creativos.plataforma, creativos.funnel, SUM(resultados.cost) as costo')
            ->get();

        // Gasto de los medios sin API, capturado por fecha en Medios data.
        $gastoManual = DB::table('medios_gasto_manual')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereIn('medio', array_keys($mediosManuales))
            ->groupBy('medio', 'fecha')
            ->selectRaw('medio, fecha, SUM(gasto) as gasto')
            ->get()
            ->map(fn ($g) => (object) ['medio' => $g->medio, 'fecha' => substr((string) $g->fecha, 0, 10), 'gasto' => (float) $g->gasto]);
        $manualEntre = fn (string $desde, string $hasta) => $gastoManual
            ->filter(fn ($g) => $g->fecha >= $desde && $g->fecha <= $hasta)
            ->groupBy('medio')->map(fn ($g) => $g->sum('gasto'))->all();

        $ultimoDia = CarbonImmutable::parse($diario->max('fecha'));

        // --- Meses -------------------------------------------------------------
        $meses = [];
        foreach ($diario as $fila) {
            $mes = substr((string) $fila->fecha, 0, 7);
            $meses[$mes] ??= ['af' => [], 'hasta' => substr((string) $fila->fecha, 0, 10)];
            self::sumar($meses[$mes]['af'], $fila);
            $meses[$mes]['hasta'] = max($meses[$mes]['hasta'], substr((string) $fila->fecha, 0, 10));
        }

        $salidaMeses = [];
        foreach ($meses as $mes => $m) {
            $inicio = CarbonImmutable::parse("{$mes}-01");
            $hasta = CarbonImmutable::parse($m['hasta']);
            $diasMes = $inicio->daysInMonth;
            $vr = $ventaReal->get("mes|{$mes}");
            $api = self::inversionApi($inversion->where('mes', $mes), $medios);
            $salidaMeses[] = [
                'clave' => $mes,
                'desde' => $inicio->toDateString(),
                'hasta' => $hasta->toDateString(),
                'diasMes' => $diasMes,
                'parcial' => $hasta->day < $diasMes,
                'af' => $m['af'],
                'ventaReal' => $venta($vr),
                'inversion' => self::conManual($api, $manualEntre($inicio->toDateString(), $inicio->endOfMonth()->toDateString()), $mediosManuales),
                'inversionApi' => $api,
            ];
        }

        // --- Semanas (lunes a domingo, cortadas por mes) ----------------------
        $semanas = [];
        foreach ($diario as $fila) {
            $dia = CarbonImmutable::parse($fila->fecha);
            $lunes = $dia->startOfWeek(CarbonImmutable::MONDAY);
            $inicio = $lunes->month === $dia->month ? $lunes : $dia->startOfMonth();
            $clave = $inicio->toDateString();
            $semanas[$clave] ??= ['af' => [], 'desde' => $clave, 'hasta' => $dia->toDateString(), 'mes' => $dia->format('Y-m'), 'semana' => $lunes->isoWeek()];
            self::sumar($semanas[$clave]['af'], $fila);
            $semanas[$clave]['hasta'] = max($semanas[$clave]['hasta'], $dia->toDateString());
        }

        $porMes = collect($salidaMeses)->keyBy('clave');
        $salidaSemanas = [];
        foreach ($semanas as $s) {
            $desde = CarbonImmutable::parse($s['desde']);
            $hasta = CarbonImmutable::parse($s['hasta']);
            $dias = (int) $desde->diffInDays($hasta) + 1;
            $mes = $porMes[$s['mes']];
            // Facebook y TikTok: el gasto se guarda por mes, así que la semana
            // lleva la parte proporcional de los días que cubre el mes. Los
            // medios de Medios data tienen fecha: entran exactos.
            $diasCubiertos = CarbonImmutable::parse($mes['hasta'])->day;
            $factor = $dias / $diasCubiertos;
            $apiSemana = [
                'medio' => array_map(fn ($v) => $v * $factor, $mes['inversionApi']['medio']),
                'funnel' => array_map(fn ($v) => $v * $factor, $mes['inversionApi']['funnel']),
            ];
            $salidaSemanas[] = [
                'clave' => $s['desde'],
                'semana' => $s['semana'],
                'mes' => $s['mes'],
                // Venta real de la semana: se captura aparte, no sale del mes.
                'ventaReal' => $venta($ventaReal->get("semana|{$s['desde']}")),
                'desde' => $s['desde'],
                'hasta' => $s['hasta'],
                'dias' => $dias,
                'parcial' => $dias < 7,
                'af' => $s['af'],
                // Gasto por fecha: la semana completa (hasta el domingo, el fin de
                // mes o el último día con datos), no solo los días con AppsFlyer.
                'inversion' => self::conManual($apiSemana, $manualEntre($s['desde'], min(
                    $desde->endOfWeek(CarbonImmutable::SUNDAY)->toDateString(),
                    $desde->endOfMonth()->toDateString(),
                    $ultimoDia->toDateString(),
                )), $mediosManuales),
            ];
        }
        $salidaMeses = array_map(function ($m) {
            unset($m['inversionApi']);

            return $m;
        }, $salidaMeses);

        return ['meses' => $salidaMeses, 'semanas' => $salidaSemanas, 'ultimoDia' => $ultimoDia->toDateString()];
    }

    /** @param array<string, array{installs: int, nc: int, ordenes: int}> $af */
    private static function sumar(array &$af, object $fila): void
    {
        $af[$fila->canal] ??= ['installs' => 0, 'nc' => 0, 'ordenes' => 0];
        $af[$fila->canal]['installs'] += (int) $fila->installs;
        $af[$fila->canal]['nc'] += (int) $fila->nc;
        $af[$fila->canal]['ordenes'] += (int) $fila->ordenes;
    }

    /**
     * Gasto de Facebook y TikTok del mes (performance, sin BRD) por medio y
     * etapa del funnel.
     *
     * @param  list<string>  $medios
     * @return array{medio: array<string, float>, funnel: array<string, float>}
     */
    private static function inversionApi($filas, array $medios): array
    {
        $medio = array_fill_keys($medios ?: array_values(self::MEDIO_POR_PLATAFORMA), 0.0);
        $funnel = array_fill_keys([...self::FUNNELS, 'SIN'], 0.0);
        foreach ($filas as $f) {
            $costo = (float) $f->costo;
            $nombre = self::MEDIO_POR_PLATAFORMA[$f->plataforma] ?? $f->plataforma;
            $medio[$nombre] = ($medio[$nombre] ?? 0) + $costo;
            $funnel[in_array($f->funnel, self::FUNNELS, true) ? $f->funnel : 'SIN'] += $costo;
        }

        return ['medio' => $medio, 'funnel' => $funnel];
    }

    /**
     * Suma el gasto de Medios data (medios sin API) a su medio y a su etapa.
     *
     * @param  array{medio: array<string, float>, funnel: array<string, float>}  $api
     * @param  array<string, float>  $manual
     * @param  array<string, string>  $mediosManuales
     * @return array{medio: array<string, float>, funnel: array<string, float>, total: float}
     */
    private static function conManual(array $api, array $manual, array $mediosManuales): array
    {
        ['medio' => $medio, 'funnel' => $funnel] = $api;
        foreach ($manual as $nombre => $costo) {
            if (! isset($mediosManuales[$nombre]) || $costo <= 0) {
                continue;
            }
            $medio[$nombre] = ($medio[$nombre] ?? 0) + $costo;
            $funnel[$mediosManuales[$nombre]] += $costo;
        }

        return [
            'medio' => array_map(fn ($v) => round($v, 2), $medio),
            'funnel' => array_map(fn ($v) => round($v, 2), $funnel),
            'total' => round(array_sum($medio), 2),
        ];
    }
}
