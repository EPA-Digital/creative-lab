<?php

namespace App\Http\Controllers;

use App\Jobs\RecuperarImagenesMes;
use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Reportes\ExportadorExcelCreativos;
use App\Services\Reportes\ExportadorPdfCreativos;
use App\Services\Reportes\ReporteCreativosService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Datos por mes" en Cargar datos (pedido explícito 2026-10-05) -- la data
 * importada de un mes, tipo Excel (mismas columnas que el sheet de QA), por
 * arte o por anuncio. Mismo criterio de eficiencias que resumenPorArte
 * (ImportarDatosController::filaConEficiencias), pero sobre `resultados`
 * (mensual, todos los países) en vez de `resultados_diarios` (solo países
 * con pipeline diario).
 *
 * También elimina la data de un mes (solo superadmin, Gate
 * 'eliminar-mes-importado').
 */
class DatosMensualesController extends Controller
{
    public function index(Request $request, string $pais): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);

        $meses = DB::table('resultados')
            ->join('creativos', 'creativos.id', '=', 'resultados.creativo_id')
            ->where('creativos.pais_id', $paisModelo->id)
            ->groupBy('resultados.mes')
            ->orderByDesc('resultados.mes')
            ->selectRaw('resultados.mes, COUNT(*) as anuncios, SUM(resultados.cost) as costo')
            ->get()
            ->map(fn ($m) => ['mes' => $m->mes, 'anuncios' => (int) $m->anuncios, 'costo' => round((float) $m->costo, 2)]);

        $mes = $request->query('mes');
        if (! $mes || ! $meses->contains('mes', $mes)) {
            $mes = $meses->first()['mes'] ?? null;
        }
        $agrupar = $request->query('agrupar') === 'anuncio' ? 'anuncio' : 'arte';

        $filas = $mes ? $this->filas($paisModelo->id, $mes, $agrupar) : collect();

        return response()->json([
            'meses' => $meses->values(),
            'mes' => $mes,
            'agrupar' => $agrupar,
            'filas' => $filas->values(),
            'totales' => self::conEficiencias([
                'cost' => $filas->sum('cost'),
                'impressions' => $filas->sum('impressions'),
                'clicks' => $filas->sum('clicks'),
                'installs' => $filas->sum('installs'),
                // null (no 0) si el mes no tiene venta real cargada en ninguna fila.
                'nc' => $filas->whereNotNull('nc')->isEmpty() ? null : $filas->sum('nc'),
                'orders' => $filas->whereNotNull('orders')->isEmpty() ? null : $filas->sum('orders'),
            ]),
            // Para el aviso "X creativos sin imagen" + "Recuperar imágenes".
            'sinImagen' => $mes ? RecuperarImagenesMes::creativosSinImagen($paisModelo->id, $mes)->count() : 0,
            'importaciones' => $mes ? DB::table('importaciones')
                ->where('pais_id', $paisModelo->id)
                ->where('desde', '>=', "{$mes}-01")
                ->where('desde', '<=', date('Y-m-t', strtotime("{$mes}-01")))
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'origen', 'estado', 'creado_en']) : [],
        ]);
    }

    /**
     * Borra los `resultados` del mes (y los `resultados_diarios` de esos
     * días) de los creativos del país. Los creativos en sí se conservan:
     * su nombre amigable, evaluaciones IA y la data de otros meses siguen
     * sirviendo. Exige repetir el mes en el body (confirmación explícita,
     * no solo un click).
     */
    public function destroy(Request $request, string $pais, string $mes): JsonResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $mes) === 1, 404);

        $request->validate(['confirmacion' => ['required', 'in:'.$mes]], [
            'confirmacion.in' => "Para eliminar, escribe exactamente {$mes}.",
            'confirmacion.required' => "Para eliminar, escribe exactamente {$mes}.",
        ]);

        $creativoIds = DB::table('creativos')->where('pais_id', $paisModelo->id)->pluck('id');

        [$resultados, $diarios] = DB::transaction(function () use ($creativoIds, $mes) {
            $resultados = DB::table('resultados')->whereIn('creativo_id', $creativoIds)->where('mes', $mes)->delete();
            $diarios = DB::table('resultados_diarios')
                ->whereIn('creativo_id', $creativoIds)
                ->whereBetween('fecha', ["{$mes}-01", date('Y-m-t', strtotime("{$mes}-01"))])
                ->delete();

            return [$resultados, $diarios];
        });

        Auditoria::registrar('datos.mes_eliminado', $request->user(), detalle: [
            'pais' => $paisModelo->codigo,
            'mes' => $mes,
            'resultados' => $resultados,
            'resultados_diarios' => $diarios,
        ]);

        return response()->json(['mes' => $mes, 'resultados' => $resultados, 'resultadosDiarios' => $diarios]);
    }

    /**
     * Reporte del mes en Excel o PDF (pedido explícito 2026-10-07): lo que
     * se ve en la tabla (por arte/anuncio, mismos filtros) + imagen,
     * campaña, copy, formato y cuenta. Síncrono: con miniaturas y ~cientos
     * de filas tarda segundos, no minutos.
     */
    public function exportar(Request $request, string $pais, string $mes, ReporteCreativosService $reporte): BinaryFileResponse
    {
        $paisModelo = $this->paisDesdeSlug($pais);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $mes) === 1, 404);
        $datos = $request->validate([
            'formato' => ['required', 'in:xlsx,pdf'],
            'agrupar' => ['nullable', 'in:arte,anuncio'],
            'plataforma' => ['nullable', 'string', 'max:10'],
            'funnel' => ['nullable', 'string', 'max:10'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $agrupar = $datos['agrupar'] ?? 'arte';
        $filas = $reporte->filas($paisModelo->id, $mes, $agrupar, $datos);
        $miniaturas = $reporte->miniaturas($paisModelo->id, $filas);

        $etiquetaMes = Carbon::createFromFormat('Y-m', $mes)->locale('es')->translatedFormat('F Y');
        $titulo = "Reporte de creativos — {$paisModelo->nombre} · {$etiquetaMes}";
        $subtitulo = ($agrupar === 'arte' ? 'Por arte' : 'Por anuncio').' · '.count($filas).' creativo(s) · generado el '.now()->locale('es')->translatedFormat('j \d\e F Y, H:i');

        $archivo = "reporte-creativos-{$pais}-{$mes}.{$datos['formato']}";
        $ruta = tempnam(sys_get_temp_dir(), 'reporte-');
        $datos['formato'] === 'xlsx'
            ? (new ExportadorExcelCreativos)->generar($filas, $miniaturas, $titulo, $subtitulo, $ruta)
            : (new ExportadorPdfCreativos)->generar($filas, $miniaturas, $titulo, $subtitulo, $ruta);

        return response()->download($ruta, $archivo)->deleteFileAfterSend();
    }

    private function paisDesdeSlug(string $pais): Pais
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        return Pais::where('codigo', $config['codigo'])->firstOrFail();
    }

    /**
     * Por arte: una fila por (nombre común, etapa, plataforma) -- mismo
     * grano que el sheet de QA; un anuncio sin arte reconocible queda en
     * su propia fila con el nombre completo. Por anuncio: una fila por
     * Ad ID, con su campaña y si cruzó con costo de la API.
     */
    private function filas(int $paisId, string $mes, string $agrupar)
    {
        $base = DB::table('resultados')
            ->join('creativos', 'creativos.id', '=', 'resultados.creativo_id')
            ->leftJoin('cuentas_publicitarias', 'cuentas_publicitarias.id', '=', 'creativos.cuenta_publicitaria_id')
            ->where('creativos.pais_id', $paisId)
            ->where('resultados.mes', $mes);

        $sumas = [
            DB::raw('SUM(resultados.cost) as cost'),
            DB::raw('SUM(resultados.impressions) as impressions'),
            DB::raw('SUM(resultados.clicks) as clicks'),
            DB::raw('SUM(resultados.installs) as installs'),
            DB::raw('SUM(resultados.nc) as nc'),
            DB::raw('SUM(resultados.orders) as orders'),
        ];

        if ($agrupar === 'anuncio') {
            $filas = $base->get([
                'creativos.ad_id',
                'creativos.nombre_comun',
                'creativos.nombre_completo',
                'creativos.nombre_campania',
                'creativos.funnel',
                'creativos.plataforma',
                'creativos.tipo_cuenta',
                'cuentas_publicitarias.nombre as cuenta',
                'resultados.tiene_meta',
                'resultados.cost',
                'resultados.impressions',
                'resultados.clicks',
                'resultados.installs',
                'resultados.nc',
                'resultados.orders',
            ]);

            return $filas->map(fn ($f) => [
                'adId' => $f->ad_id,
                'nombreComun' => $f->nombre_comun ?: $f->nombre_completo,
                'campania' => $f->nombre_campania,
                'funnel' => $f->funnel,
                'plataforma' => $f->plataforma,
                'tipoCuenta' => $f->tipo_cuenta,
                'cuenta' => $f->cuenta,
                'conCosto' => (bool) $f->tiene_meta,
                ...self::conEficiencias((array) $f),
            ])->sortByDesc('cost')->values();
        }

        $nombre = DB::raw('COALESCE(creativos.nombre_comun, creativos.nombre_completo) as nombre');
        $filas = $base
            ->groupBy(DB::raw('COALESCE(creativos.nombre_comun, creativos.nombre_completo)'), 'creativos.funnel', 'creativos.plataforma')
            ->get([$nombre, 'creativos.funnel', 'creativos.plataforma', DB::raw('COUNT(*) as anuncios'), ...$sumas]);

        return $filas->map(fn ($f) => [
            'nombreComun' => $f->nombre,
            'funnel' => $f->funnel,
            'plataforma' => $f->plataforma,
            'anuncios' => (int) $f->anuncios,
            ...self::conEficiencias((array) $f),
        ])->sortByDesc('cost')->values();
    }

    /**
     * CPI/CAC/CPO siempre desde las sumas, nunca promediando ratios -- mismo
     * criterio que ImportarDatosController::filaConEficiencias. nc/orders
     * null (mes sin venta real cargada) se respetan como null.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private static function conEficiencias(array $f): array
    {
        $cost = round((float) ($f['cost'] ?? 0), 2);
        $installs = (int) ($f['installs'] ?? 0);
        $nc = $f['nc'] === null ? null : (int) $f['nc'];
        $orders = $f['orders'] === null ? null : (int) $f['orders'];

        return [
            'cost' => $cost,
            'impressions' => (int) ($f['impressions'] ?? 0),
            'clicks' => (int) ($f['clicks'] ?? 0),
            'installs' => $installs,
            'cpi' => $installs > 0 ? round($cost / $installs, 2) : null,
            'nc' => $nc,
            'cac' => $nc ? round($cost / $nc, 2) : null,
            'orders' => $orders,
            'cpo' => $orders ? round($cost / $orders, 2) : null,
        ];
    }
}
