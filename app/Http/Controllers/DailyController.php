<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Fuentes\PanelDaily;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily (2026-11-18, pedido explícito): control de presupuesto y pacing del
 * mes por campaña, como la pestaña daily del Daily Cuentas. Lo morado de la
 * hoja (budget, status, funnel, daily, KPIs, metas) se edita aquí; lo demás
 * sale de data.medios y AF data (PanelDaily). EPA únicamente.
 */
class DailyController extends Controller
{
    public function index(Request $request, string $pais, PanelDaily $panel): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $mes = $request->query('mes');
        if (! is_string($mes) || ! preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $mes = now()->format('Y-m');
        }
        $meses = DB::table('daily_planes')->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisModelo->id)
            ->pluck('mes')->push(now()->format('Y-m'))->push($mes)->unique()->sortDesc()->values();

        $canales = config("canales.{$pais}", []);

        return Inertia::render('Daily', [
            'pais' => $pais,
            'meses' => $meses,
            'medios' => array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'color' => $c['color']], $canales),
            'status' => PanelDaily::STATUS,
            'hayAnterior' => (bool) $panel->plan($paisModelo->id, CarbonImmutable::parse("{$mes}-01")->subMonth()->format('Y-m')),
            ...$panel->armar($paisModelo->id, $pais, $mes, al: is_string($request->query('al')) ? $request->query('al') : null),
        ]);
    }

    public function iniciar(Request $request, string $pais, string $mes, PanelDaily $panel): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $this->validarMes($mes);
        $datos = $request->validate(['desde' => ['required', 'in:anterior,datos,vacio']]);
        abort_if($panel->plan($paisModelo->id, $mes), 422, 'Este mes ya tiene planeación.');
        $panel->iniciar($paisModelo->id, $mes, $datos['desde'], $request->user()->id);
        Auditoria::registrar('daily.iniciado', $request->user(), detalle: ['pais' => $pais, 'mes' => $mes, 'desde' => $datos['desde']]);

        return response()->json(['ok' => true]);
    }

    /** Metas, notas y fechas del plan. */
    public function plan(Request $request, string $pais, string $mes, PanelDaily $panel): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $plan = $panel->plan($paisModelo->id, $mes);
        abort_unless($plan, 404);
        $datos = $request->validate([
            'meta_installs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'meta_nc' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'meta_orders' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'notas' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fecha_inicio' => ['sometimes', 'date_format:Y-m-d'],
            'fecha_fin' => ['sometimes', 'date_format:Y-m-d'],
            // Ajustes por día: "Total a gastar" de un día de la semana (0 = lunes).
            'total_dia' => ['sometimes', 'array:dia,valor'],
            'total_dia.dia' => ['required_with:total_dia', 'integer', 'between:0,6'],
            'total_dia.valor' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);
        if (isset($datos['total_dia'])) {
            $datos['total_dia'] = self::conDia($plan->total_dia, $datos['total_dia']);
        }
        $inicio = $datos['fecha_inicio'] ?? substr((string) $plan->fecha_inicio, 0, 10);
        $fin = $datos['fecha_fin'] ?? substr((string) $plan->fecha_fin, 0, 10);
        abort_if($fin < $inicio, 422, 'La fecha final no puede ser antes del inicio.');

        DB::table('daily_planes')->where('id', $plan->id)->update([...$datos, 'actualizado_en' => now()]);
        Auditoria::registrar('daily.plan', $request->user(), detalle: ['pais' => $pais, 'mes' => $mes, ...$datos]);

        return response()->json(['ok' => true]);
    }

    public function agregar(Request $request, string $pais, string $mes, PanelDaily $panel): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $plan = $panel->plan($paisModelo->id, $mes);
        abort_unless($plan, 404);
        $datos = $request->validate([
            'medio' => ['required', 'string', 'max:60'],
            'campania' => ['required', 'string', 'max:300'],
            'campania_id' => ['nullable', 'string', 'max:64'],
            'funnel' => ['nullable', Rule::in(PanelDaily::FUNNELS)],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);
        $orden = (int) DB::table('daily_campanias')->where('plan_id', $plan->id)->max('orden') + 1;
        $id = DB::table('daily_campanias')->insertGetId([
            ...$datos,
            'funnel' => $datos['funnel'] ?? PanelDaily::funnelDe($datos['campania']),
            'budget' => $datos['budget'] ?? 0,
            'status' => 'ACTIVA',
            'plan_id' => $plan->id,
            'orden' => $orden,
            'actualizado_por' => $request->user()->id,
            'actualizado_en' => now(),
        ]);
        Auditoria::registrar('daily.campania_agregada', $request->user(), detalle: ['pais' => $pais, 'mes' => $mes, 'campania' => $datos['campania']]);

        return response()->json(['id' => $id]);
    }

    /** Un campo morado de una campaña (budget, status, funnel, daily, KPIs...). */
    public function editar(Request $request, string $pais, int $campania): JsonResponse
    {
        $fila = $this->campaniaDelPais($pais, $campania);
        $datos = $request->validate([
            'status' => ['sometimes', Rule::in(PanelDaily::STATUS)],
            'funnel' => ['sometimes', 'nullable', Rule::in(PanelDaily::FUNNELS)],
            'nombre_comun' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:60'],
            'responsable' => ['sometimes', 'nullable', 'string', 'max:80'],
            'budget' => ['sometimes', 'numeric', 'min:0', 'max:100000000'],
            'daily_plataforma' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'kpi_installs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'kpi_nc' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'kpi_recompras' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'kpi_orders' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            // Ajuste del daily de un día de la semana (0 = lunes).
            'ajuste' => ['sometimes', 'array:dia,valor'],
            'ajuste.dia' => ['required_with:ajuste', 'integer', 'between:0,6'],
            'ajuste.valor' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);
        if (isset($datos['ajuste'])) {
            $datos['ajustes'] = self::conDia($fila->ajustes, $datos['ajuste']);
            unset($datos['ajuste']);
        }
        DB::table('daily_campanias')->where('id', $fila->id)->update([...$datos, 'actualizado_por' => $request->user()->id, 'actualizado_en' => now()]);
        Auditoria::registrar('daily.campania_editada', $request->user(), detalle: ['pais' => $pais, 'id' => $fila->id, 'campania' => $fila->campania, ...$datos]);

        return response()->json(['ok' => true]);
    }

    public function quitar(Request $request, string $pais, int $campania): JsonResponse
    {
        $fila = $this->campaniaDelPais($pais, $campania);
        DB::table('daily_campanias')->where('id', $fila->id)->delete();
        Auditoria::registrar('daily.campania_quitada', $request->user(), detalle: ['pais' => $pais, 'id' => $fila->id, 'campania' => $fila->campania]);

        return response()->json(['ok' => true]);
    }

    /** El JSON [lun..dom] con un día cambiado. @param array{dia: int, valor: mixed} $cambio */
    private static function conDia(?string $json, array $cambio): string
    {
        $semana = PanelDaily::semana($json);
        $semana[(int) $cambio['dia']] = isset($cambio['valor']) ? round((float) $cambio['valor'], 2) : null;

        return json_encode($semana);
    }

    /** La campaña tiene que ser de un plan de esta cuenta y país. */
    private function campaniaDelPais(string $pais, int $id): object
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $fila = DB::table('daily_campanias')
            ->join('daily_planes', 'daily_planes.id', '=', 'daily_campanias.plan_id')
            ->where('daily_campanias.id', $id)
            ->where('daily_planes.cuenta_id', CuentaActual::id())->where('daily_planes.pais_id', $paisModelo->id)
            ->select('daily_campanias.*')
            ->first();
        abort_unless($fila, 404);

        return $fila;
    }

    private function validarMes(string $mes): void
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $mes), 404);
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");
        abort_unless(config("canales.{$pais}"), 404, 'Este país todavía no tiene medios configurados.');

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
