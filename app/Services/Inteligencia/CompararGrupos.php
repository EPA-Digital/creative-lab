<?php

namespace App\Services\Inteligencia;

use App\Services\Reportes\BenchmarkMes;
use App\Services\Reportes\ReporteCreativosService;
use Illuminate\Support\Str;

/**
 * Inteligencia por grupos (2026-11-17, pedido explícito): poner a competir
 * conjuntos de creativos, ej. "corona" de agosto contra "corona" de
 * octubre. Cada grupo es una búsqueda (nombre, campaña o ID) dentro de un
 * mes; se suman sus resultados por etapa y se comparan contra el benchmark
 * de su mes (todo lo de esa etapa ese mes, con los mismos filtros de
 * plataforma y tipo de cuenta). Cada creativo del grupo trae además su
 * benchmark individual (BenchmarkMes), igual que el PDF.
 *
 * Las filas son las del reporte por anuncio (ReporteCreativosService), así
 * la vista y el PDF de la comparación salen del mismo cálculo.
 *
 * Los KPIs de grupo y de la media del mes van sobre totales (costo total
 * entre resultados totales): un anuncio con 2 instalaciones no pesa igual
 * que uno con 2,000.
 */
class CompararGrupos
{
    private const SUMAS = ['cost', 'impressions', 'clicks', 'installs', 'orders', 'nc'];

    private const MARGEN_EN_LA_MEDIA = 10;

    private const ORDEN_FUNNEL = ['AWA', 'CON', 'CONS', 'CNV', 'LOY', 'SIN'];

    public function __construct(private readonly ReporteCreativosService $reporte) {}

    /**
     * @param  list<array{q: string, mes: string}>  $grupos
     * @param  array{plataforma?: ?string, tipo_cuenta?: ?string}  $filtros
     * @return list<array<string, mixed>> cada grupo trae además `filas` (las del reporte) y `benchmark` (BenchmarkMes de su mes)
     */
    public function comparar(int $paisId, array $grupos, array $filtros = []): array
    {
        $porMes = [];
        foreach (array_unique(array_column($grupos, 'mes')) as $mes) {
            $filas = $this->filasDelMes($paisId, $mes, $filtros);
            $porMes[$mes] = ['filas' => $filas, 'benchmark' => new BenchmarkMes($filas)];
        }

        return array_map(function (array $g) use ($porMes) {
            ['filas' => $delMes, 'benchmark' => $benchmark] = $porMes[$g['mes']];
            $q = self::normalizar($g['q']);
            $delGrupo = array_values(array_filter($delMes, fn (array $f) => str_contains($f['buscable'], $q)));

            $etapas = [];
            foreach (self::etapasPresentes($delGrupo) as $funnel) {
                $grupoEtapa = array_values(array_filter($delGrupo, fn ($f) => self::funnel($f) === $funnel));
                $mesEtapa = array_values(array_filter($delMes, fn ($f) => self::funnel($f) === $funnel));
                $totales = self::sumar($grupoEtapa);
                $kpis = self::kpis($totales);
                $media = self::kpis(self::sumar($mesEtapa));

                $etapas[] = [
                    'funnel' => $funnel,
                    'creativos' => count($grupoEtapa),
                    'totales' => $totales,
                    'kpis' => array_map(fn (string $campo) => [
                        'campo' => $campo,
                        'valor' => $kpis[$campo],
                        'benchmark' => $media[$campo],
                        ...self::estado($kpis[$campo], $media[$campo], $campo),
                    ], BenchmarkMes::kpisDe($funnel === 'SIN' ? null : $funnel)),
                    'top' => self::top($grupoEtapa, $funnel),
                ];
            }

            return [
                'q' => $g['q'],
                'mes' => $g['mes'],
                'creativos' => count($delGrupo),
                'totales' => self::sumar($delGrupo),
                'etapas' => $etapas,
                'filas' => $delGrupo,
                'benchmark' => $benchmark,
            ];
        }, $grupos);
    }

    /** Benchmark de un creativo del grupo, con el veredicto (lo que muestran la card y el modal). */
    public static function benchmarkDe(array $fila, BenchmarkMes $benchmark): array
    {
        $cmp = $benchmark->comparar($fila);
        $ver = BenchmarkMes::veredicto($cmp);

        return [...$cmp, 'veredicto' => [
            'calificacion' => $ver['calificacion'],
            'etiqueta' => ['bueno' => 'Sobre la media', 'promedio' => 'En la media', 'malo' => 'Bajo la media'][$ver['calificacion']] ?? 'Sin comparables',
            'cautela' => (bool) ($cmp['kpis'][0]['pocoVolumen'] ?? false),
            'texto' => $ver['texto'],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function filasDelMes(int $paisId, string $mes, array $filtros): array
    {
        $tipoCuenta = $filtros['tipo_cuenta'] ?? null;
        $filas = $this->reporte->filas($paisId, $mes, 'anuncio', ['plataforma' => $filtros['plataforma'] ?? null]);

        return array_values(array_map(
            fn (array $f) => [...$f, 'buscable' => self::normalizar(implode(' ', [$f['nombreAmigable'], $f['nombre'], ...$f['campanias'], ...$f['adIds']]))],
            array_filter($filas, fn (array $f) => match (true) {
                ! $tipoCuenta => true,
                $tipoCuenta === 'SIN_CLASIFICAR' => $f['tipoCuenta'] === null,
                default => $f['tipoCuenta'] === $tipoCuenta,
            }),
        ));
    }

    private static function normalizar(?string $t): string
    {
        return mb_strtolower(Str::ascii((string) $t));
    }

    private static function funnel(array $f): string
    {
        return $f['funnel'] ?: 'SIN';
    }

    /** @return list<string> en orden de funnel */
    private static function etapasPresentes(array $filas): array
    {
        $presentes = array_values(array_unique(array_map(fn ($f) => self::funnel($f), $filas)));
        $pos = fn (string $f) => ($i = array_search($f, self::ORDEN_FUNNEL, true)) === false ? 99 : $i;
        usort($presentes, fn ($a, $b) => $pos($a) <=> $pos($b));

        return $presentes;
    }

    /** @return array<string, float> */
    private static function sumar(array $filas): array
    {
        $t = array_fill_keys(self::SUMAS, 0.0);
        foreach ($filas as $f) {
            foreach (self::SUMAS as $s) {
                $t[$s] += (float) ($f[$s] ?? 0);
            }
        }

        return $t;
    }

    /** @return array<string, ?float> */
    private static function kpis(array $t): array
    {
        $div = fn (float $a, float $b, float $x = 1) => $b > 0 ? $a / $b * $x : null;

        return [
            'cpm' => $div($t['cost'], $t['impressions'], 1000),
            'ctr' => $div($t['clicks'], $t['impressions'], 100),
            'cpi' => $div($t['cost'], $t['installs']),
            'cac' => $div($t['cost'], $t['nc']),
            'cpo' => $div($t['cost'], $t['orders']),
        ];
    }

    /** @return array{pct: ?int, estado: ?string} */
    private static function estado(?float $valor, ?float $media, string $campo): array
    {
        if ($valor === null || ! $media) {
            return ['pct' => null, 'estado' => null];
        }
        $pct = ($valor - $media) / $media * 100;
        if (abs($pct) <= self::MARGEN_EN_LA_MEDIA) {
            return ['pct' => (int) round(abs($pct)), 'estado' => 'media'];
        }

        return ['pct' => (int) round(abs($pct)), 'estado' => ($pct > 0) === BenchmarkMes::mayorEsMejor($campo) ? 'mejor' : 'peor'];
    }

    /** Los 3 más baratos del grupo en el KPI de costo de la etapa, con volumen suficiente. */
    private static function top(array $filas, string $funnel): array
    {
        $campo = BenchmarkMes::kpisDe($funnel === 'SIN' ? null : $funnel)[0];
        [$volumen, $minimo] = BenchmarkMes::VOLUMEN_MINIMO[$campo];
        $con = array_values(array_filter($filas, fn ($f) => $f[$campo] !== null && ($f[$volumen] ?? 0) >= $minimo && $f['cost'] > 0));
        usort($con, fn ($a, $b) => $a[$campo] <=> $b[$campo]);

        return array_map(fn ($f) => [
            'id' => $f['creativoId'],
            'nombre' => $f['nombreAmigable'] ?: $f['nombre'],
            'campo' => $campo,
            'valor' => $f[$campo],
        ], array_slice($con, 0, 3));
    }
}
