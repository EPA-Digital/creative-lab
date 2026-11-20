<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alertas de la cuenta en Control de gasto (2026-11-20, pedido explícito):
 *
 * - externo: campaña creada en Meta por alguien que no es del equipo. Meta
 *   solo da el nombre de quien la creó (no el correo), así que se compara
 *   contra los usuarios del dashboard con correo @epa.digital y los
 *   responsables de config/optimizaciones.php. TikTok no dice quién crea.
 * - base: alguien subió ≥ 50% el presupuesto de una campaña o grupo de un
 *   jalón (eventos "Ajuste de dinero" del sync de Optimizaciones).
 * - sobre_plan: un cambio de presupuesto (de quien sea, también del equipo)
 *   deja el daily de la campaña ≥ 50% arriba del daily planeado en el Daily.
 * - plan: la campaña va a gastar ≥ 50% más de lo planeado en el Daily del
 *   mes (proyección del gasto al fin del plan, o el daily de plataforma
 *   por los días del plan).
 */
class AlertasCuenta
{
    public const UMBRAL = 50;

    /** Días hacia atrás (desde hoy) para creaciones y cambios de base. */
    public const DIAS_EVENTOS = 14;

    /** Días mínimos del plan transcurridos para proyectar el gasto. */
    private const DIAS_MIN_PROYECCION = 3;

    /**
     * @param  list<array<string, mixed>>  $campanias  campañas de ControlGasto (budget, daily, serie)
     * @param  list<string>  $fechas
     * @return list<array<string, mixed>>
     */
    public function armar(int $paisId, array $campanias, array $fechas, string $inicioPlan, string $finPlan, string $corte, ?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::today();
        $desde = $hoy->subDays(self::DIAS_EVENTOS)->toDateString();
        $eventos = DB::table('optimizaciones')
            ->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)
            ->whereIn('tipo', ['creacion_campania', 'ajuste_dinero'])
            ->whereBetween('fecha', [$desde, $hoy->toDateString()])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();

        $equipo = null;
        $alertas = [];
        foreach ($eventos as $e) {
            $fecha = substr((string) $e->fecha, 0, 10);
            $campania = (json_decode((string) $e->campanias, true) ?: [])[0] ?? '';
            if ($e->tipo === 'creacion_campania') {
                $quien = trim((string) $e->responsable);
                if ($e->origen !== 'meta' || $quien === '' || $quien === 'Auto-Meta') {
                    continue;
                }
                $equipo ??= self::equipo();
                if (self::esDelEquipo($quien, $equipo)) {
                    continue;
                }
                $alertas[] = [
                    'clave' => "cuenta:opt:{$e->id}", 'tipo' => 'externo', 'fecha' => $fecha, 'medio' => $e->medio, 'campania' => $campania,
                    'titulo' => 'Campaña creada fuera del equipo',
                    'detalle' => "La creó {$quien}, que no está en el equipo EPA.", 'quien' => $quien,
                ];

                continue;
            }
            $cambio = $e->presupuesto_nuevo !== null && (float) $e->presupuesto_anterior > 0
                ? ['antes' => (float) $e->presupuesto_anterior, 'despues' => (float) $e->presupuesto_nuevo, 'pct' => ((float) $e->presupuesto_nuevo / (float) $e->presupuesto_anterior - 1) * 100]
                : self::cambioPresupuesto((string) $e->descripcion);
            $vsPlan = $this->vsPlan($paisId, $e, $fecha);
            $subioBase = $cambio && $cambio['pct'] >= self::UMBRAL;
            $sobrePlan = $vsPlan && $vsPlan['pct'] >= self::UMBRAL;
            if (! $subioBase && ! $sobrePlan) {
                continue;
            }
            $detalle = $cambio ? 'Presupuesto de $'.number_format($cambio['antes'], 0).' a $'.number_format($cambio['despues'], 0).($cambio['pct'] > 0 ? ' (+'.round($cambio['pct']).'%)' : '').'.' : 'Cambio de presupuesto.';
            if ($vsPlan) {
                $detalle .= ' La campaña queda en $'.number_format($vsPlan['diario'], 0).'/día contra $'.number_format($vsPlan['planeado'], 0).'/día planeados'
                    .' ('.($vsPlan['pct'] >= 0 ? '+' : '').round($vsPlan['pct']).'%).';
            }
            $alertas[] = [
                'clave' => "cuenta:opt:{$e->id}", 'tipo' => $sobrePlan ? 'sobre_plan' : 'base', 'fecha' => $fecha, 'medio' => $e->medio,
                'campania' => $e->campania_padre ?: $campania,
                'titulo' => $sobrePlan ? 'Presupuesto sobre lo planeado +'.round($vsPlan['pct']).'%' : 'Subió la base +'.round($cambio['pct']).'%',
                'detalle' => $detalle,
                'quien' => $e->responsable ?: null,
            ];
        }

        // Budget del Daily vs lo que va a gastar.
        $diasPlan = CarbonImmutable::parse($inicioPlan)->diffInDays(CarbonImmutable::parse($finPlan)) + 1;
        $hastaPlan = min($corte, $finPlan);
        $transcurridos = $hastaPlan >= $inicioPlan ? CarbonImmutable::parse($inicioPlan)->diffInDays(CarbonImmutable::parse($hastaPlan)) + 1 : 0;
        foreach ($campanias as $c) {
            $budget = (float) ($c['budget'] ?? 0);
            // Solo activas: una pausada o finalizada ya no va a seguir gastando.
            if ($budget <= 0 || ($c['status'] ?? 'ACTIVA') !== 'ACTIVA') {
                continue;
            }
            $gastado = 0.0;
            foreach ($fechas as $i => $f) {
                if ($f >= $inicioPlan && $f <= $hastaPlan) {
                    $gastado += (float) $c['serie'][$i][0];
                }
            }
            $proyeccion = $transcurridos >= self::DIAS_MIN_PROYECCION ? $gastado / $transcurridos * $diasPlan : null;
            $porDaily = ! empty($c['daily']) ? (float) $c['daily'] * $diasPlan : null;
            $motivos = [];
            if ($proyeccion !== null && $proyeccion >= $budget * (1 + self::UMBRAL / 100)) {
                $motivos[] = 'Al ritmo actual cerraría en $'.number_format($proyeccion, 0).' (+'.round(($proyeccion / $budget - 1) * 100).'%)';
            }
            if ($porDaily !== null && $porDaily >= $budget * (1 + self::UMBRAL / 100)) {
                $motivos[] = 'El daily de plataforma ($'.number_format((float) $c['daily'], 0).') da $'.number_format($porDaily, 0).' en el mes (+'.round(($porDaily / $budget - 1) * 100).'%)';
            }
            if ($motivos) {
                $alertas[] = [
                    'clave' => 'cuenta:plan:'.$c['k'], 'tipo' => 'plan', 'fecha' => $inicioPlan, 'medio' => $c['medio'] ?? null, 'campania' => $c['campania'],
                    'titulo' => 'Arriba de lo planeado',
                    'detalle' => 'Planeado $'.number_format($budget, 0).', lleva $'.number_format($gastado, 0).'. '.implode('. ', $motivos).'.',
                    'quien' => $c['responsable'] ?? null,
                ];
            }
        }

        return $alertas;
    }

    /** @var array<string, array<string, object>> Daily por mes: clave de campaña / "id:..." → fila con daily planeado. */
    private array $planes = [];

    /**
     * Daily total de la campaña después del cambio vs su daily planeado en el
     * Daily del mes del cambio (daily de plataforma, o budget / días del plan).
     *
     * @return array{diario: float, planeado: float, pct: float}|null
     */
    private function vsPlan(int $paisId, object $e, string $fecha): ?array
    {
        if (! $e->presupuesto_diario || $e->presupuesto_campania === null) {
            return null;
        }
        $mes = substr($fecha, 0, 7);
        if (! isset($this->planes[$mes])) {
            $this->planes[$mes] = [];
            $planes = DB::table('daily_planes')->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)->where('mes', $mes)->get()->keyBy('id');
            foreach (DB::table('daily_campanias')->whereIn('plan_id', $planes->keys())->get() as $c) {
                $plan = $planes[$c->plan_id];
                $dias = CarbonImmutable::parse($plan->fecha_inicio)->diffInDays(CarbonImmutable::parse($plan->fecha_fin)) + 1;
                $c->planeado = $c->daily_plataforma !== null && (float) $c->daily_plataforma > 0 ? (float) $c->daily_plataforma : (float) $c->budget / max(1, $dias);
                $this->planes[$mes][ControlGasto::clave($c->campania)] = $c;
                if ($c->campania_id) {
                    $this->planes[$mes]['id:'.$c->campania_id] = $c;
                }
            }
        }
        $nombre = $e->campania_padre ?: ((json_decode((string) $e->campanias, true) ?: [])[0] ?? '');
        $c = ($e->campania_padre_id ? ($this->planes[$mes]['id:'.$e->campania_padre_id] ?? null) : null) ?? ($this->planes[$mes][ControlGasto::clave($nombre)] ?? null);
        if (! $c || $c->planeado <= 0) {
            return null;
        }
        $diario = (float) $e->presupuesto_campania;

        return ['diario' => $diario, 'planeado' => $c->planeado, 'pct' => ($diario / $c->planeado - 1) * 100];
    }

    /** "Se ajusta ppto de $70 a $120 (+71%)" → antes, después, % (solo subidas cuentan como alerta). */
    public static function cambioPresupuesto(string $texto): ?array
    {
        if (! preg_match('/de \$([\d,.]+) a \$([\d,.]+)/', $texto, $m)) {
            return null;
        }
        $antes = (float) str_replace(',', '', $m[1]);
        $despues = (float) str_replace(',', '', $m[2]);
        if ($antes <= 0) {
            return null;
        }

        return ['antes' => $antes, 'despues' => $despues, 'pct' => ($despues - $antes) / $antes * 100];
    }

    /** Nombres normalizados del equipo: usuarios @epa.digital + responsables configurados. @return list<string> */
    public static function equipo(): array
    {
        $nombres = DB::table('users')->where('email', 'like', '%@epa.digital')->pluck('name')->all();

        return array_values(array_unique(array_filter(array_map(
            fn ($n) => self::normalizar((string) $n),
            [...$nombres, ...array_values(config('optimizaciones.responsables', [])), ...config('optimizaciones.equipo_meta', [])],
        ))));
    }

    /** Mismo nombre, o uno contiene todas las palabras del otro (al menos nombre y apellido). */
    public static function esDelEquipo(string $quien, array $equipo): bool
    {
        $q = self::normalizar($quien);
        $palabrasQ = explode(' ', $q);
        foreach ($equipo as $n) {
            if ($n === $q) {
                return true;
            }
            $palabrasN = explode(' ', $n);
            $menor = count($palabrasN) <= count($palabrasQ) ? $palabrasN : $palabrasQ;
            $mayor = $menor === $palabrasN ? $palabrasQ : $palabrasN;
            if (count($menor) >= 2 && ! array_diff($menor, $mayor)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizar(string $n): string
    {
        return Str::of($n)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]/', ' ')->squish()->toString();
    }
}
