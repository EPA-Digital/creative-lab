<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Daily (2026-11-18, pedido explícito): réplica de la pestaña daily del
 * Daily Cuentas. Lo morado (budget, status, funnel, daily de plataforma,
 * KPIs, metas) vive en daily_planes / daily_campanias; lo demás se calcula:
 *
 * - Costo, costo de ayer, clics, impresiones: data.medios (medios_diario).
 * - Installs, NC (first purchase), recompras y orders: AF data por campaña.
 *   Ambos se cruzan con la campaña por ID y, si no, por nombre.
 * - Periodo: del inicio a "ayer" (hoy - 1, sin pasar del fin del plan).
 * - % ideal = días transcurridos / días del periodo; dif = % consumido - %
 *   ideal; daily ideal = remanente / días restantes (como la hoja).
 */
class PanelDaily
{
    public const STATUS = ['ACTIVA', 'EN PAUSA', 'FINALIZADA', 'PENDIENTE'];

    public const FUNNELS = ['AWA', 'CONS', 'CNV', 'LOY'];

    public function plan(int $paisId, string $mes): ?object
    {
        return DB::table('daily_planes')
            ->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)->where('mes', $mes)
            ->first();
    }

    /** @return array<string, mixed> */
    public function armar(int $paisId, string $pais, string $mes, ?CarbonImmutable $hoy = null): array
    {
        $plan = $this->plan($paisId, $mes);
        $inicio = CarbonImmutable::parse($plan->fecha_inicio ?? "{$mes}-01");
        $fin = CarbonImmutable::parse($plan->fecha_fin ?? CarbonImmutable::parse("{$mes}-01")->endOfMonth()->toDateString());
        $hoy ??= CarbonImmutable::today();
        $ayer = $hoy->subDay();
        if ($ayer->gt($fin)) {
            $ayer = $fin;
        }
        $diasPeriodo = (int) $inicio->diffInDays($fin) + 1;
        $transcurridos = $ayer->lt($inicio) ? 0 : (int) $inicio->diffInDays($ayer) + 1;
        $restantes = max(0, $diasPeriodo - $transcurridos);
        $ideal = $diasPeriodo ? $transcurridos / $diasPeriodo : 0;

        [$costos, $resultados, $hasta] = $this->datos($paisId, $inicio, $ayer);

        $filas = $plan
            ? DB::table('daily_campanias')->where('plan_id', $plan->id)->orderBy('orden')->orderBy('id')->get()
            : collect();

        $campanias = $filas->map(function ($c) use ($costos, $resultados, $ideal, $restantes) {
            $k = self::clave($c->campania);
            $co = ($c->campania_id ? ($costos['id'][$c->campania_id] ?? null) : null) ?? $costos['nombre'][$k] ?? null;
            $re = ($c->campania_id ? ($resultados['id'][$c->campania_id] ?? null) : null) ?? $resultados['nombre'][$k] ?? null;
            $budget = (float) $c->budget;
            $costo = round((float) ($co['costo'] ?? 0), 2);
            $consumido = $budget > 0 ? $costo / $budget : null;
            $remanente = $budget - $costo;

            return [
                'id' => $c->id,
                'medio' => $c->medio,
                'status' => $c->status,
                'funnel' => $c->funnel,
                'nombre_comun' => $c->nombre_comun,
                'campania' => $c->campania,
                'campania_id' => $c->campania_id,
                'region' => $c->region,
                'budget' => $budget,
                'daily_plataforma' => $c->daily_plataforma !== null ? (float) $c->daily_plataforma : null,
                'kpi_installs' => $c->kpi_installs,
                'kpi_nc' => $c->kpi_nc,
                'kpi_recompras' => $c->kpi_recompras,
                'kpi_orders' => $c->kpi_orders,
                'ajustes' => self::semana($c->ajustes),
                'costo' => $costo,
                'costo_ayer' => round((float) ($co['ayer'] ?? 0), 2),
                'clicks' => (int) ($co['clicks'] ?? 0),
                'impresiones' => (int) ($co['impresiones'] ?? 0),
                'installs' => (int) ($re['installs'] ?? 0),
                'nc' => (int) ($re['nc'] ?? 0),
                'recompras' => (int) ($re['recompras'] ?? 0),
                'orders' => (int) ($re['ordenes'] ?? 0),
                'remanente' => round($remanente, 2),
                'consumido' => $consumido,
                'dif' => $consumido !== null ? $consumido - $ideal : null,
                'daily_ideal' => $restantes > 0 ? round($remanente / $restantes, 2) : null,
                'con_datos' => $co !== null || $re !== null,
            ];
        })->values()->all();

        $enPlan = [];
        foreach ($filas as $c) {
            $enPlan['n:'.self::clave($c->campania)] = true;
            if ($c->campania_id) {
                $enPlan['i:'.$c->campania_id] = true;
            }
        }

        return [
            'mes' => $mes,
            'plan' => $plan ? [
                'meta_installs' => $plan->meta_installs,
                'meta_nc' => $plan->meta_nc,
                'meta_orders' => $plan->meta_orders,
                'notas' => $plan->notas,
                'total_dia' => self::semana($plan->total_dia),
            ] : null,
            'fechas' => [
                'inicio' => $inicio->toDateString(),
                'fin' => $fin->toDateString(),
                'ayer' => $ayer->toDateString(),
                'hoy' => $hoy->toDateString(),
                'dias' => $diasPeriodo,
                'transcurridos' => $transcurridos,
                'restantes' => $restantes,
                'ideal' => $ideal,
                'datosHasta' => $hasta,
            ],
            'campanias' => $campanias,
            'disponibles' => $this->disponibles($costos, $resultados, $enPlan),
        ];
    }

    /**
     * Crea el plan del mes: copiando las campañas del mes anterior (sin las
     * finalizadas) o con las campañas que ya tienen gasto en el mes.
     */
    public function iniciar(int $paisId, string $mes, string $desde, ?int $usuarioId): int
    {
        $cuentaId = CuentaActual::id();
        $primero = CarbonImmutable::parse("{$mes}-01");

        return DB::transaction(function () use ($paisId, $mes, $desde, $usuarioId, $cuentaId, $primero) {
            $planId = DB::table('daily_planes')->insertGetId([
                'cuenta_id' => $cuentaId, 'pais_id' => $paisId, 'mes' => $mes,
                'fecha_inicio' => $primero->toDateString(), 'fecha_fin' => $primero->endOfMonth()->toDateString(),
                'actualizado_en' => now(),
            ]);

            $nuevas = [];
            if ($desde === 'anterior') {
                $anterior = $this->plan($paisId, $primero->subMonth()->format('Y-m'));
                if ($anterior) {
                    DB::table('daily_planes')->where('id', $planId)->update(['total_dia' => $anterior->total_dia]);
                    $nuevas = DB::table('daily_campanias')->where('plan_id', $anterior->id)
                        ->where('status', '!=', 'FINALIZADA')->orderBy('orden')->orderBy('id')->get()
                        ->map(fn ($c) => collect($c)->except(['id', 'plan_id', 'actualizado_por', 'actualizado_en'])->all())->all();
                }
            } else {
                [$costos, $resultados] = $this->datos($paisId, $primero, $primero->endOfMonth());
                foreach ($this->disponibles($costos, $resultados, []) as $d) {
                    if ($d['costo'] > 0) {
                        $nuevas[] = ['medio' => $d['medio'], 'status' => 'ACTIVA', 'funnel' => $d['funnel'], 'campania' => $d['campania'], 'campania_id' => $d['campania_id'], 'budget' => 0];
                    }
                }
            }
            foreach (array_values($nuevas) as $i => $c) {
                DB::table('daily_campanias')->insert([...$c, 'plan_id' => $planId, 'orden' => $i, 'actualizado_por' => $usuarioId, 'actualizado_en' => now()]);
            }

            return $planId;
        });
    }

    /**
     * Costos y resultados por campaña en el rango, con el costo del último día.
     *
     * @return array{0: array{id: array<string, array<string, float>>, nombre: array<string, array<string, mixed>>}, 1: array{id: array<string, array<string, int>>, nombre: array<string, array<string, mixed>>}, 2: ?string}
     */
    private function datos(int $paisId, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $cuentaId = CuentaActual::id();
        $costos = ['id' => [], 'nombre' => []];
        $resultados = ['id' => [], 'nombre' => []];
        $ultimo = null;
        if ($hasta->lt($desde)) {
            return [$costos, $resultados, null];
        }
        $ayer = $hasta->toDateString();

        $filas = DB::table('medios_diario')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereBetween('fecha', [$desde->toDateString(), $ayer])
            ->groupBy('medio', 'campania', 'campania_id')
            ->selectRaw('medio, campania, campania_id, SUM(costo) AS costo, SUM(clicks) AS clicks, SUM(impresiones) AS impresiones, SUM(CASE WHEN fecha = ? THEN costo ELSE 0 END) AS ayer, MAX(fecha) AS ultimo', [$ayer])
            ->get();
        foreach ($filas as $f) {
            $v = ['costo' => (float) $f->costo, 'clicks' => (int) $f->clicks, 'impresiones' => (int) $f->impresiones, 'ayer' => (float) $f->ayer];
            $ultimo = max($ultimo ?? '', substr((string) $f->ultimo, 0, 10));
            if ($f->campania_id) {
                self::sumar($costos['id'][$f->campania_id], $v);
            }
            $k = self::clave($f->campania);
            self::sumar($costos['nombre'][$k], $v);
            $costos['nombre'][$k]['_campania'] = $f->campania;
            $costos['nombre'][$k]['_id'] ??= $f->campania_id;
            $costos['nombre'][$k]['_medio'] = $f->medio;
        }

        $af = DB::table('fuentes_appsflyer')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereBetween('fecha', [$desde->toDateString(), $ayer])
            ->where('campania', '!=', 'None')
            ->groupBy('media_source', 'campania', 'campania_id')
            ->selectRaw('media_source, campania, campania_id, SUM(installs) AS installs, SUM(nc) AS nc, SUM(recompras) AS recompras, SUM(ordenes) AS ordenes, MAX(fecha) AS ultimo')
            ->get();
        foreach ($af as $f) {
            $v = ['installs' => (int) $f->installs, 'nc' => (int) $f->nc, 'recompras' => (int) $f->recompras, 'ordenes' => (int) $f->ordenes];
            $ultimo = max($ultimo ?? '', substr((string) $f->ultimo, 0, 10));
            if ($f->campania_id) {
                self::sumar($resultados['id'][$f->campania_id], $v);
            }
            $k = self::clave($f->campania);
            self::sumar($resultados['nombre'][$k], $v);
            $resultados['nombre'][$k]['_campania'] = $f->campania;
            $resultados['nombre'][$k]['_id'] ??= $f->campania_id;
            $resultados['nombre'][$k]['_media'] = $f->media_source;
        }

        return [$costos, $resultados, $ultimo ?: null];
    }

    /** Campañas con gasto o resultados que todavía no están en el plan. @return list<array<string, mixed>> */
    private function disponibles(array $costos, array $resultados, array $enPlan): array
    {
        $salida = [];
        foreach ($costos['nombre'] as $k => $c) {
            if (isset($enPlan['n:'.$k]) || ($c['_id'] && isset($enPlan['i:'.$c['_id']]))) {
                continue;
            }
            $re = $resultados['nombre'][$k] ?? [];
            $salida[$k] = [
                'campania' => $c['_campania'], 'campania_id' => $c['_id'], 'medio' => $c['_medio'],
                'funnel' => self::funnelDe($c['_campania']), 'costo' => round($c['costo'], 2), 'installs' => (int) ($re['installs'] ?? 0),
            ];
        }
        usort($salida, fn ($a, $b) => $b['costo'] <=> $a['costo'] ?: strcmp($a['campania'], $b['campania']));

        return array_values($salida);
    }

    /** JSON de 7 valores [lun..dom] (null = sin dato). @return list<float|null> */
    public static function semana(?string $json): array
    {
        $v = json_decode((string) $json, true);
        $v = is_array($v) ? $v : [];

        return array_map(fn ($i) => isset($v[$i]) && $v[$i] !== null ? (float) $v[$i] : null, range(0, 6));
    }

    /** Etapa por el token del nombre (ECU_..._AWA_EPA_...). */
    public static function funnelDe(string $campania): ?string
    {
        $c = mb_strtoupper($campania);
        foreach (['_AWA_' => 'AWA', '_CON_' => 'CONS', '_CNV_' => 'CNV', '_LOY_' => 'LOY'] as $token => $f) {
            if (str_contains($c, $token)) {
                return $f;
            }
        }

        return null;
    }

    public static function clave(string $campania): string
    {
        return Str::lower(trim($campania));
    }

    private static function sumar(?array &$acc, array $v): void
    {
        $acc ??= [];
        foreach ($v as $k => $x) {
            $acc[$k] = ($acc[$k] ?? 0) + $x;
        }
    }
}
