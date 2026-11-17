<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Control de gasto (2026-11-18, pedido explícito; diseño "Daily Control de
 * Gasto" de México, para todos los países): cómo le fue a cada campaña "ayer"
 * contra anteayer / la semana / el mes pasado, alertas por variación,
 * histórico y metas del mes.
 *
 * Por campaña y día manda [gasto, installs, first orders, recompras,
 * orders] de los últimos 62 días hasta el corte (data.medios + AF data por
 * campaña). Lo de la planeación (status, funnel, budget, KPIs, responsable,
 * metas) sale del Daily del mes del corte. Las comparaciones y alertas se
 * calculan en la página.
 */
class ControlGasto
{
    public const DIAS = 62;

    /** @return array<string, mixed> */
    public function armar(int $paisId, string $pais, ?string $al = null, ?CarbonImmutable $hoy = null): array
    {
        $cuentaId = CuentaActual::id();
        $hoy ??= CarbonImmutable::today();
        $canales = config("canales.{$pais}", []);
        $canalDeMedia = [];
        foreach ($canales as $c) {
            foreach ($c['media'] as $m) {
                $canalDeMedia[mb_strtolower($m)] = $c['id'];
            }
        }

        // Corte: último día con costos y AF data a la vez (delay), o el elegido.
        $max = fn (string $t) => ($f = DB::table($t)->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->where('fecha', '<', $hoy->toDateString())->max('fecha')) ? substr((string) $f, 0, 10) : null;
        $ultimoCosto = $max('medios_diario');
        $ultimoAf = $max('fuentes_appsflyer');
        $completos = $ultimoCosto && $ultimoAf ? min($ultimoCosto, $ultimoAf) : ($ultimoCosto ?? $ultimoAf);
        $sugerido = CarbonImmutable::parse($completos ?? $hoy->subDay()->toDateString());
        $corte = $al && preg_match('/^\d{4}-\d{2}-\d{2}$/', $al) ? CarbonImmutable::parse($al) : $sugerido;
        if ($corte->gte($hoy)) {
            $corte = $hoy->subDay();
        }
        $desde = $corte->subDays(self::DIAS - 1);
        $fechas = [];
        for ($d = $desde; $d->lte($corte); $d = $d->addDay()) {
            $fechas[] = $d->toDateString();
        }
        $pos = array_flip($fechas);
        $rango = [$desde->toDateString(), $corte->toDateString()];

        // Plan del mes del corte (status, budget, KPIs, responsable, metas).
        $plan = DB::table('daily_planes')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->where('mes', $corte->format('Y-m'))->first();
        $enPlan = [];
        if ($plan) {
            foreach (DB::table('daily_campanias')->where('plan_id', $plan->id)->get() as $c) {
                $enPlan[self::clave($c->campania)] = $c;
                if ($c->campania_id) {
                    $enPlan['id:'.$c->campania_id] = $c;
                }
            }
        }

        $campanias = [];
        $nueva = fn (string $nombre, ?string $medio, ?string $id) => [
            'nombre' => $nombre, 'medio' => $medio, 'campania_id' => $id,
            'serie' => array_fill(0, count($fechas), [0, 0, 0, 0, 0]),
        ];

        $costos = DB::table('medios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereBetween('fecha', $rango)
            ->groupBy('fecha', 'medio', 'campania', 'campania_id')
            ->selectRaw('fecha, medio, campania, campania_id, SUM(costo) AS costo')
            ->get();
        foreach ($costos as $f) {
            $k = self::clave($f->campania);
            $campanias[$k] ??= $nueva($f->campania, $f->medio, $f->campania_id);
            $campanias[$k]['serie'][$pos[substr((string) $f->fecha, 0, 10)]][0] += round((float) $f->costo, 2);
        }

        $af = DB::table('fuentes_appsflyer')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereBetween('fecha', $rango)->where('campania', '!=', 'None')
            ->groupBy('fecha', 'media_source', 'campania', 'campania_id')
            ->selectRaw('fecha, media_source, campania, campania_id, SUM(installs) AS installs, SUM(nc) AS nc, SUM(recompras) AS recompras, SUM(ordenes) AS ordenes')
            ->get();
        foreach ($af as $f) {
            $k = self::clave($f->campania);
            $medio = $canalDeMedia[mb_strtolower($f->media_source)] ?? null;
            if (! isset($campanias[$k]) && ! $medio) {
                continue; // orgánico, CRM, etc.: no es una campaña de medios
            }
            $campanias[$k] ??= $nueva($f->campania, $medio, $f->campania_id);
            $i = $pos[substr((string) $f->fecha, 0, 10)];
            $s = &$campanias[$k]['serie'][$i];
            $s[1] += (int) $f->installs;
            $s[2] += (int) $f->nc;
            $s[3] += (int) $f->recompras;
            $s[4] += (int) $f->ordenes;
            unset($s);
        }

        $salida = [];
        foreach ($campanias as $k => $c) {
            $p = $enPlan[$k] ?? ($c['campania_id'] ? ($enPlan['id:'.$c['campania_id']] ?? null) : null);
            // Sin actividad en el rango y fuera del plan: no aporta nada.
            $activa = array_sum(array_map(fn ($d) => $d[0] + $d[1] + $d[4], $c['serie'])) > 0;
            if (! $activa && ! $p) {
                continue;
            }
            $salida[] = [
                'k' => $k,
                'campania' => $c['nombre'],
                'nombre' => $p->nombre_comun ?? null,
                'medio' => $p->medio ?? $c['medio'] ?? 'otro',
                'funnel' => $p->funnel ?? PanelDaily::funnelDe($c['nombre']),
                'status' => $p->status ?? null,
                'responsable' => $p->responsable ?? null,
                'budget' => $p ? (float) $p->budget : null,
                'kpi' => $p ? [null, $p->kpi_installs, $p->kpi_nc, $p->kpi_recompras, $p->kpi_orders] : null,
                'serie' => $c['serie'],
            ];
        }

        $revisadas = DB::table('control_revisiones')
            ->leftJoin('users', 'users.id', '=', 'control_revisiones.usuario_id')
            ->where('control_revisiones.cuenta_id', $cuentaId)->where('control_revisiones.pais_id', $paisId)
            ->where('control_revisiones.fecha', $corte->toDateString())
            ->get(['control_revisiones.clave', 'users.name'])
            ->mapWithKeys(fn ($r) => [$r->clave => $r->name ?? 'Alguien'])
            ->all();

        $inicioPlan = $plan ? substr((string) $plan->fecha_inicio, 0, 10) : $corte->startOfMonth()->toDateString();
        $finPlan = $plan ? substr((string) $plan->fecha_fin, 0, 10) : $corte->endOfMonth()->toDateString();

        return [
            'fechas' => $fechas,
            'corte' => $corte->toDateString(),
            'sugerido' => $sugerido->toDateString(),
            'ultimoCosto' => $ultimoCosto,
            'ultimoAf' => $ultimoAf,
            'medios' => array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'color' => $c['color']], $canales),
            'campanias' => $salida,
            'plan' => $plan ? [
                'inicio' => $inicioPlan,
                'fin' => $finPlan,
                'metas' => [null, $plan->meta_installs, $plan->meta_nc, null, $plan->meta_orders],
            ] : ['inicio' => $inicioPlan, 'fin' => $finPlan, 'metas' => null],
            'revisadas' => (object) $revisadas,
        ];
    }

    public static function clave(string $campania): string
    {
        return Str::lower(trim($campania));
    }
}
