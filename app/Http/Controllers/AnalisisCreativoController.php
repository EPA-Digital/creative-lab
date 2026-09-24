<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Models\Resultado;
use App\Services\ImagenFirmadaService;
use App\Services\Ingesta\VentaRealYAgrupacion;
use App\Services\RangosActividadService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PASO 1 de Fase 3 (dashboard visual) -- flujo de datos mínimo, sin diseño.
 * Confirma que Laravel lee creativos+resultados reales de MySQL y Vue los
 * pinta vía Inertia. La vista es una tabla HTML sin estilo a propósito --
 * lo bonito (card, podio, carrusel) es el paso siguiente, una vez que este
 * flujo esté confirmado funcionando de punta a punta.
 *
 * 2026-09-17 (spec Adenda A/B) -- deja de mandar creativos por Ad ID suelto:
 * consolida por arte+etapa vía VentaRealYAgrupacion::agruparPorArteYFunnel()
 * ANTES de mandar a Inertia, y cada "grupo" se sirve disfrazado de
 * creativo+resultado (mismos nombres de campo que ya esperan
 * cardDesdeCreativo()/valorCampo() en el frontend) para no tener que tocar
 * ese código compartido. Con `desde`/`hasta` en el request, la fuente pasa
 * de `resultados` (mes) a `resultados_diarios` sumado en el rango -- mismo
 * criterio de "consolidar siempre, cambiar solo la fuente" que pide el plan.
 */
class AnalisisCreativoController extends Controller
{
    public function __construct(private readonly ImagenFirmadaService $imagenes) {}

    public function index(Request $request, string $pais, ?string $plataforma = null): Response
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        $paisModelo = Pais::where('codigo', $config['codigo'])->firstOrFail();

        $tieneDatosDiarios = DB::table('resultados_diarios')
            ->join('creativos', 'creativos.id', '=', 'resultados_diarios.creativo_id')
            ->where('creativos.pais_id', $paisModelo->id)
            ->exists();

        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $usaRango = $tieneDatosDiarios && $desde && $hasta;

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

        if ($usaRango) {
            [$desde, $hasta] = $this->clampearRango($paisModelo->id, $desde, $hasta);
            $cards = $this->cardsDesdeRango($paisModelo->id, $desde, $hasta, $plataforma);
        } else {
            $creativos = $paisModelo->creativos()
                ->whereHas('resultados', fn ($q) => $q->where('mes', $mes))
                ->with(['resultados' => fn ($q) => $q->where('mes', $mes), 'correccionNombre'])
                ->when($plataforma, fn ($query) => $query->where('plataforma', $plataforma))
                ->orderBy('nombre_comun')
                ->get();
            $cards = $this->cardsDesdeMes($creativos);
        }

        $agrupado = VentaRealYAgrupacion::agruparPorArteYFunnel($cards);
        $creativosJson = array_map(fn (array $c) => $this->cardAJson($c, $usaRango ? null : $mes), $agrupado['cards']);

        // Un 'cliente' (solo lectura) no ve creativos "sin clasificar"
        // (pedido explícito 2026-09-24) -- cardAJson() ya deja `funnel`
        // en null para esos, EPA sigue viéndolos igual que siempre (los
        // necesita para clasificarlos, ver GestionNombresController).
        if (! $request->user()->esEpa()) {
            $creativosJson = array_values(array_filter($creativosJson, fn (array $c) => $c['funnel'] !== null));
        }

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
        // Solo tiene sentido con `id` real (creativo sin consolidar, o
        // grupo cuyo representante prestó su id) -- un grupo con 2+
        // miembros de ad_id distintos no tiene un rango único válido, se
        // deja vacío a propósito en vez de mostrar el rango de uno solo
        // como si fuera el del arte completo.
        $rangosPorCreativo = RangosActividadService::porCreativo($paisModelo->id);
        foreach ($creativosJson as &$c) {
            $c['rangos_actividad'] = (! $c['esGrupoArte']) ? ($rangosPorCreativo[$c['id']] ?? []) : [];
        }
        unset($c);

        $rangoDiario = $tieneDatosDiarios ? $this->rangoDiarioDisponible($paisModelo->id) : ['min' => null, 'max' => null];

        return Inertia::render('AnalisisCreativo', [
            'pais' => $pais,
            'plataforma' => $plataforma,
            'creativos' => $creativosJson,
            'mes' => $mes,
            'mesesDisponibles' => $mesesDisponibles,
            'tieneDatosDiarios' => $tieneDatosDiarios,
            'desde' => $usaRango ? $desde : null,
            'hasta' => $usaRango ? $hasta : null,
            'rangoDiarioMin' => $rangoDiario['min'],
            'rangoDiarioMax' => $rangoDiario['max'],
        ]);
    }

    /**
     * @return array{min: ?string, max: ?string}
     */
    private function rangoDiarioDisponible(int $paisId): array
    {
        $rango = DB::table('resultados_diarios')
            ->join('creativos', 'creativos.id', '=', 'resultados_diarios.creativo_id')
            ->where('creativos.pais_id', $paisId)
            ->selectRaw('MIN(fecha) as min, MAX(fecha) as max')
            ->first();

        return [
            'min' => $rango?->min ? substr((string) $rango->min, 0, 10) : null,
            'max' => $rango?->max ? substr((string) $rango->max, 0, 10) : null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function clampearRango(int $paisId, string $desde, string $hasta): array
    {
        $disponible = $this->rangoDiarioDisponible($paisId);
        if ($disponible['min'] && $desde < $disponible['min']) {
            $desde = $disponible['min'];
        }
        if ($disponible['max'] && $hasta > $disponible['max']) {
            $hasta = $disponible['max'];
        }
        if ($hasta < $desde) {
            $hasta = $desde;
        }

        return [$desde, $hasta];
    }

    /**
     * Arma las "cards" (forma que espera VentaRealYAgrupacion::agruparPorArteYFunnel)
     * a partir de Creativo+Resultado(mes) ya cargados -- una card por Ad ID,
     * SIN consolidar todavía (eso lo hace el caller). cac/cpo/cpi/ctr/cpm YA
     * vienen calculados en `resultados` -- se pasan tal cual porque un
     * creativo que NO se agrupa con nadie (arte/funnel null, o único en su
     * grupo) sale de agruparPorArteYFunnel() sin tocar, y necesita esos
     * campos ya resueltos.
     *
     * @return list<array<string, mixed>>
     */
    private function cardsDesdeMes(Collection $creativos): array
    {
        return $creativos->map(function ($c) {
            $r = $c->resultados->first();

            return [
                'id' => $c->id,
                'adId' => $c->ad_id,
                'adIdMostrable' => $c->ad_id,
                'arte' => $c->nombre_comun,
                'etapaFunnel' => $c->funnel ?? 'Sin clasificar',
                'plataforma' => $c->plataforma,
                'tipoCuenta' => $c->tipo_cuenta,
                'formato' => $c->formato,
                'imageUrl' => $c->imagen_url ?? '',
                'adNameShort' => $c->nombre_completo,
                'campaignName' => $c->nombre_campania ?? '',
                'copyBodies' => ! empty($c->copy['texto'] ?? null) ? [$c->copy['texto']] : [],
                'nombreAmigable' => $c->nombre_amigable,
                'secondaryStatusApi' => null,
                'cost' => $r?->cost !== null ? (float) $r->cost : null,
                'impressions' => $r?->impressions,
                'clicks' => $r?->clicks,
                'installs' => $r?->installs,
                'newCustomers' => $r?->nc,
                'orders' => $r?->orders,
                'ncReal' => $r?->nc,
                'ordersReal' => $r?->orders,
                'cac' => $r?->cac !== null ? (float) $r->cac : null,
                'cpo' => $r?->cpo !== null ? (float) $r->cpo : null,
                'cpi' => $r?->cpi !== null ? (float) $r->cpi : null,
                'ctr' => $r?->ctr !== null ? (float) $r->ctr : null,
                'cpm' => $r?->cpm !== null ? (float) $r->cpm : null,
                'tieneMeta' => $r?->tiene_meta ?? false,
            ];
        })->all();
    }

    /**
     * Mismo shape que cardsDesdeMes(), pero sumando resultados_diarios en
     * [desde, hasta] por ad_id -- cac/cpo/cpi/ctr/cpm se RECALCULAN acá
     * desde los totales sumados (resultados_diarios no los guarda, solo los
     * conteos/montos base) usando las mismas fórmulas que
     * ImportarDatosController::filaConEficiencias.
     *
     * tieneMeta: resultados_diarios no tiene esa columna (no viene del
     * pipeline de costos por API, viene del CSV diario) -- se aproxima con
     * cost>0 OR impressions>0, la misma señal que ya usa
     * RangosActividadService para "actividad real". No es la señal exacta
     * de tiene_meta mensual, pero es lo único disponible en esta fuente.
     *
     * @return list<array<string, mixed>>
     */
    private function cardsDesdeRango(int $paisId, string $desde, string $hasta, ?string $plataforma): array
    {
        $filas = DB::table('resultados_diarios')
            ->join('creativos', 'creativos.id', '=', 'resultados_diarios.creativo_id')
            ->where('creativos.pais_id', $paisId)
            ->when($plataforma, fn ($q) => $q->where('creativos.plataforma', $plataforma))
            ->whereBetween('resultados_diarios.fecha', [$desde, $hasta])
            ->groupBy('resultados_diarios.creativo_id')
            ->get([
                'resultados_diarios.creativo_id',
                DB::raw('MIN(creativos.ad_id) as ad_id'),
                DB::raw('MIN(creativos.nombre_comun) as nombre_comun'),
                DB::raw('MIN(creativos.nombre_completo) as nombre_completo'),
                DB::raw('MIN(creativos.nombre_campania) as nombre_campania'),
                DB::raw('MIN(creativos.funnel) as funnel'),
                DB::raw('MIN(creativos.plataforma) as plataforma'),
                DB::raw('MIN(creativos.tipo_cuenta) as tipo_cuenta'),
                DB::raw('MIN(creativos.formato) as formato'),
                DB::raw('MIN(creativos.imagen_url) as imagen_url'),
                DB::raw('SUM(resultados_diarios.cost) as cost'),
                DB::raw('SUM(resultados_diarios.impressions) as impressions'),
                DB::raw('SUM(resultados_diarios.clicks) as clicks'),
                DB::raw('SUM(resultados_diarios.installs) as installs'),
                DB::raw('SUM(COALESCE(resultados_diarios.nc_real, resultados_diarios.nc)) as nc'),
                DB::raw('SUM(COALESCE(resultados_diarios.orders_real, resultados_diarios.orders)) as orders'),
            ]);

        return $filas->map(function ($f) {
            $cost = (float) $f->cost;
            $impressions = (int) $f->impressions;
            $installs = (int) $f->installs;
            $nc = (float) $f->nc;
            $orders = (float) $f->orders;

            return [
                'id' => $f->creativo_id,
                'adId' => $f->ad_id,
                'adIdMostrable' => $f->ad_id,
                'arte' => $f->nombre_comun,
                'etapaFunnel' => $f->funnel ?? 'Sin clasificar',
                'plataforma' => $f->plataforma,
                'tipoCuenta' => $f->tipo_cuenta,
                'formato' => $f->formato,
                'imageUrl' => $f->imagen_url ?? '',
                'adNameShort' => $f->nombre_completo,
                'campaignName' => $f->nombre_campania ?? '',
                'copyBodies' => [],
                'nombreAmigable' => null,
                'secondaryStatusApi' => null,
                'cost' => $cost,
                'impressions' => $impressions,
                'clicks' => (int) $f->clicks,
                'installs' => $installs,
                'newCustomers' => $nc,
                'orders' => $orders,
                'ncReal' => $nc,
                'ordersReal' => $orders,
                'cac' => $nc > 0 ? $cost / $nc : null,
                'cpo' => $orders > 0 ? $cost / $orders : null,
                'cpi' => $installs > 0 ? $cost / $installs : null,
                'ctr' => $impressions > 0 ? ((float) $f->clicks / $impressions) * 100 : null,
                'cpm' => $impressions > 0 ? ($cost / $impressions) * 1000 : null,
                'tieneMeta' => $cost > 0 || $impressions > 0,
            ];
        })->all();
    }

    /**
     * Traduce una "card" (forma de VentaRealYAgrupacion) a la forma
     * Creativo+resultados[0] que ya espera el frontend
     * (valorCampo()/cardDesdeCreativo() en AnalisisCreativo.vue/motor.js) --
     * así el front no necesita saber que ahora puede recibir un arte
     * consolidado en vez de un Ad ID suelto.
     *
     * @param  array<string, mixed>  $card
     */
    private function cardAJson(array $card, ?string $mes): array
    {
        $esGrupo = $card['esGrupoArte'] ?? false;
        $funnel = $card['etapaFunnel'] ?? null;

        return [
            'id' => $card['id'] ?? $card['adId'],
            'ad_id' => $esGrupo ? implode(', ', $card['adIdsMostrables'] ?? []) : ($card['adId'] ?? null),
            'nombre_comun' => $card['arte'] ?? null,
            'nombre_completo' => $card['adNameShort'] ?? null,
            'nombre_campania' => $card['campaignName'] ?? null,
            'copy' => null,
            'copyBodies' => $card['copyBodies'] ?? [],
            'imagen_url' => $this->imagenes->firmar(($card['imageUrl'] ?? '') !== '' ? $card['imageUrl'] : null),
            'plataforma' => $card['plataforma'] ?? null,
            'formato' => $card['formato'] ?? null,
            'funnel' => $funnel === 'Sin clasificar' ? null : $funnel,
            'tipo_cuenta' => $card['tipoCuenta'] ?? null,
            'nombre_amigable' => $card['nombreAmigable'] ?? null,
            'status' => $card['status'] ?? null,
            'esGrupoArte' => $esGrupo,
            'miembros' => $esGrupo ? ($card['miembros'] ?? []) : null,
            'adIdsMostrables' => $card['adIdsMostrables'] ?? null,
            'rangos_actividad' => [],
            'resultados' => [[
                'mes' => $mes,
                'cost' => $card['cost'] ?? null,
                'impressions' => $card['impressions'] ?? null,
                'clicks' => $card['clicks'] ?? null,
                'installs' => $card['installs'] ?? null,
                'nc' => $card['newCustomers'] ?? $card['ncReal'] ?? null,
                'orders' => $card['orders'] ?? $card['ordersReal'] ?? null,
                'cac' => $card['cac'] ?? null,
                'cpo' => $card['cpo'] ?? null,
                'cpi' => $card['cpi'] ?? null,
                'ctr' => $card['ctr'] ?? null,
                'cpm' => $card['cpm'] ?? null,
                'tiene_meta' => $card['tieneMeta'] ?? false,
            ]],
        ];
    }
}
