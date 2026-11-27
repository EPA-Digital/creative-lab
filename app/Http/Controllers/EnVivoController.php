<?php

namespace App\Http\Controllers;

use App\Console\Commands\SincronizarOptimizaciones;
use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Divisas\Divisas;
use App\Services\Fuentes\SincronizadorCostosApi;
use App\Services\Ingesta\GoogleAdsApiClient;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * En vivo (2026-11-26, pedido explícito): campaña → conjunto / ad group →
 * anuncio con costo, impresiones, clicks, CTR, CPM y CPC tal como los trae
 * la API de Meta, TikTok y Google cada hora (SincronizadorCostosApi), y cuánto se
 * movió cada uno desde la actualización anterior. EPA únicamente.
 */
class EnVivoController extends Controller
{
    public const RANGOS = ['hoy', 'ayer', '7d', 'mes'];

    public function index(Request $request, string $pais): Response
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $rango = in_array($request->query('rango'), self::RANGOS, true) ? $request->query('rango') : 'hoy';
        [$desde, $hasta] = self::fechas($rango);

        // El "desde la última actualización" solo vale para filas que tocó la última corrida.
        $ultima = DB::table('sincronizaciones_costos')->where('cuenta_id', CuentaActual::id())->whereNotNull('terminado_en')
            ->orderByDesc('terminado_en')->value('iniciado_en') ?? '9999-12-31';
        $filas = DB::table('plataforma_anuncios_diario')
            ->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisModelo->id)
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('plataforma', 'campania_id', 'conjunto_id', 'anuncio_id')
            ->selectRaw('plataforma, campania_id, MAX(campania) campania, conjunto_id, MAX(conjunto) conjunto, anuncio_id, MAX(anuncio) anuncio,
                SUM(costo) costo, SUM(impresiones) impresiones, SUM(clicks) clicks,
                SUM(CASE WHEN actualizado_en >= ? THEN costo - COALESCE(costo_previo, 0) ELSE 0 END) d_costo,
                SUM(CASE WHEN actualizado_en >= ? THEN CAST(impresiones AS SIGNED) - CAST(COALESCE(impresiones_previo, 0) AS SIGNED) ELSE 0 END) d_impresiones,
                SUM(CASE WHEN actualizado_en >= ? THEN CAST(clicks AS SIGNED) - CAST(COALESCE(clicks_previo, 0) AS SIGNED) ELSE 0 END) d_clicks,
                MAX(actualizado_en) actualizado', [$ultima, $ultima, $ultima])
            ->get();

        $campanias = [];
        foreach ($filas as $f) {
            $c = &$campanias[$f->plataforma.'|'.$f->campania_id];
            $c ??= ['id' => $f->campania_id, 'nombre' => $f->campania, 'plataforma' => $f->plataforma, 'conjuntos' => []];
            $g = &$c['conjuntos'][$f->conjunto_id ?? '-'];
            $g ??= ['id' => $f->conjunto_id, 'nombre' => $f->conjunto ?? 'Sin conjunto', 'anuncios' => []];
            $g['anuncios'][] = [
                'id' => $f->anuncio_id, 'nombre' => $f->anuncio ?? $f->anuncio_id,
                'm' => [round((float) $f->costo, 2), (int) $f->impresiones, (int) $f->clicks],
                'd' => [round((float) $f->d_costo, 2), (int) $f->d_impresiones, (int) $f->d_clicks],
            ];
            unset($c, $g);
        }
        $campanias = array_values(array_map(function ($c) {
            $c['conjuntos'] = array_values($c['conjuntos']);

            return $c;
        }, $campanias));

        // Delta solo cuando ya hubo una corrida antes de la última (si no, todo sería "nuevo").
        $corridas = DB::table('sincronizaciones_costos')->where('cuenta_id', CuentaActual::id())->whereNotNull('terminado_en')->count();

        return Inertia::render('EnVivo', [
            'pais' => $pais,
            'rango' => $rango,
            'desde' => $desde,
            'hasta' => $hasta,
            'campanias' => $campanias,
            'conDelta' => $corridas >= 2,
            'actualizadoDatos' => $filas->max('actualizado'),
            'graficas' => self::graficas($paisModelo->id, $rango, $desde, $hasta),
        ]);
    }

    /**
     * Datos de las gráficas (2026-11-27). Hoy / Ayer: por hora (zona de cada
     * cuenta) con el día anterior para comparar y el plan diario del Daily;
     * 7 días / Mes: por día. Cada punto es [costo, impresiones, clicks] por
     * plataforma; "mini" es el costo de cada campaña por punto (la mini
     * gráfica de la tabla).
     *
     * @return array<string, mixed>
     */
    public static function graficas(int $paisId, string $rango, string $desde, string $hasta): array
    {
        $cuentaId = CuentaActual::id();
        $vacio = fn (int $n) => array_fill(0, $n, [0.0, 0, 0]);

        if (in_array($rango, ['hoy', 'ayer'], true)) {
            $previo = CarbonImmutable::parse($desde)->subDay()->toDateString();
            $series = [];
            $comparacion = [];
            $ultimaHora = null;
            $filas = DB::table('plataforma_campanias_hora')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
                ->whereIn('fecha', [$previo, $desde])
                ->groupBy('fecha', 'hora', 'plataforma')
                ->selectRaw('fecha, hora, plataforma, SUM(costo) costo, SUM(impresiones) impresiones, SUM(clicks) clicks')->get();
            foreach ($filas as $f) {
                $esDia = substr((string) $f->fecha, 0, 10) === $desde;
                $destino = &${$esDia ? 'series' : 'comparacion'};
                $destino[$f->plataforma] ??= $vacio(24);
                $destino[$f->plataforma][(int) $f->hora] = [round((float) $f->costo, 2), (int) $f->impresiones, (int) $f->clicks];
                unset($destino);
                if ($esDia) {
                    $ultimaHora = max($ultimaHora ?? 0, (int) $f->hora);
                }
            }
            $mini = [];
            foreach (DB::table('plataforma_campanias_hora')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->where('fecha', $desde)
                ->groupBy('plataforma', 'campania_id', 'hora')->selectRaw('plataforma, campania_id, hora, SUM(costo) costo')->get() as $f) {
                $k = $f->plataforma.'|'.$f->campania_id;
                $mini[$k] ??= array_fill(0, 24, 0.0);
                $mini[$k][(int) $f->hora] = round((float) $f->costo, 2);
            }

            // Plan diario del Daily del mes (daily_plataforma), por medio.
            $plan = DB::table('daily_campanias')->join('daily_planes', 'daily_planes.id', '=', 'daily_campanias.plan_id')
                ->where('daily_planes.cuenta_id', $cuentaId)->where('daily_planes.pais_id', $paisId)
                ->where('daily_planes.mes', substr($desde, 0, 7))->whereIn('daily_campanias.medio', ['meta', 'tiktok', 'google'])
                ->groupBy('daily_campanias.medio')->selectRaw('daily_campanias.medio, SUM(daily_campanias.daily_plataforma) d')
                ->pluck('d', 'medio')->map(fn ($d) => round((float) $d, 2))->filter(fn ($d) => $d > 0)->all();

            return [
                'modo' => 'horas', 'puntos' => array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23)),
                'dia' => $desde, 'previo' => $previo, 'series' => $series, 'comparacion' => $comparacion,
                'ultimaHora' => $rango === 'hoy' ? $ultimaHora : 23, 'plan' => $plan, 'mini' => $mini,
            ];
        }

        $fechas = [];
        for ($d = CarbonImmutable::parse($desde); $d->lte(CarbonImmutable::parse($hasta)); $d = $d->addDay()) {
            $fechas[] = $d->toDateString();
        }
        $pos = array_flip($fechas);
        $series = [];
        foreach (DB::table('plataforma_anuncios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('fecha', 'plataforma')->selectRaw('fecha, plataforma, SUM(costo) costo, SUM(impresiones) impresiones, SUM(clicks) clicks')->get() as $f) {
            $series[$f->plataforma] ??= $vacio(count($fechas));
            $series[$f->plataforma][$pos[substr((string) $f->fecha, 0, 10)]] = [round((float) $f->costo, 2), (int) $f->impresiones, (int) $f->clicks];
        }
        $mini = [];
        foreach (DB::table('plataforma_anuncios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('plataforma', 'campania_id', 'fecha')->selectRaw('plataforma, campania_id, fecha, SUM(costo) costo')->get() as $f) {
            $k = $f->plataforma.'|'.$f->campania_id;
            $mini[$k] ??= array_fill(0, count($fechas), 0.0);
            $mini[$k][$pos[substr((string) $f->fecha, 0, 10)]] = round((float) $f->costo, 2);
        }

        return ['modo' => 'dias', 'puntos' => $fechas, 'series' => $series, 'comparacion' => [], 'ultimaHora' => null, 'plan' => [], 'mini' => $mini];
    }

    /** "Actualizar ahora": trae hoy y ayer de este país sin esperar al scheduler. */
    public function actualizar(Request $request, string $pais, Divisas $divisas): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        $inicio = now();
        $hasta = CarbonImmutable::now('UTC')->toDateString();
        $desde = CarbonImmutable::parse($hasta)->subDay()->toDateString();
        $r = (new SincronizadorCostosApi(
            SincronizarOptimizaciones::cliente(fn () => MetaApiClient::fromConfig()),
            SincronizarOptimizaciones::cliente(fn () => TiktokApiClient::fromConfig()),
            $divisas,
            SincronizarOptimizaciones::cliente(fn () => GoogleAdsApiClient::fromConfig()),
        ))->sincronizar($paisModelo->id, $desde, $hasta);
        DB::table('sincronizaciones_costos')->insert([
            'cuenta_id' => CuentaActual::id(), 'iniciado_en' => $inicio, 'terminado_en' => now(),
            'estado' => $r['errores'] === [] ? 'ok' : ($r['cuentas'] ? 'parcial' : 'error'),
            'desde' => $desde, 'hasta' => $hasta, 'detalle' => json_encode([$pais => $r], JSON_UNESCAPED_UNICODE),
        ]);
        Auditoria::registrar('costos.actualizados', $request->user(), detalle: ['pais' => $pais, 'cuentas' => count($r['cuentas']), 'errores' => count($r['errores'])]);

        return response()->json(['cuentas' => $r['cuentas'], 'errores' => $r['errores']], $r['cuentas'] || $r['errores'] === [] ? 200 : 422);
    }

    /** @return array{0: string, 1: string} desde, hasta (hora de CDMX). */
    public static function fechas(string $rango, ?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::now('America/Mexico_City')->startOfDay();

        return match ($rango) {
            'ayer' => [$hoy->subDay()->toDateString(), $hoy->subDay()->toDateString()],
            '7d' => [$hoy->subDays(6)->toDateString(), $hoy->toDateString()],
            'mes' => [$hoy->startOfMonth()->toDateString(), $hoy->toDateString()],
            default => [$hoy->toDateString(), $hoy->toDateString()],
        };
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }
}
