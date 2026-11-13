<?php

namespace App\Services\Reportes;

/**
 * Benchmark del mes para el reporte PDF (pedido explícito 2026-11-13):
 * cada creativo contra los DEMÁS creativos del mismo mes, misma plataforma
 * y misma etapa, en los KPIs principales de su etapa. Se muestra la media
 * (promedio simple) y el mejor del grupo; "en la media" es ±10%, igual que
 * compararConPromedio (motor.js) en el modal del creativo.
 *
 * El grupo es el mes completo del país, sin los filtros del reporte: si se
 * descarga solo "IZI", se compara contra todos los del mes, no solo
 * contra los IZI.
 */
class BenchmarkMes
{
    /** KPIs por etapa (pedido explícito 2026-11-13; Conversion: CAC, CTR y CPM). */
    public const KPIS_POR_FUNNEL = [
        'AWA' => ['cpm', 'ctr'],
        'CONS' => ['cpi', 'ctr', 'cpm'],
        'CNV' => ['cac', 'ctr', 'cpm'],
        'LOY' => ['cpo', 'ctr', 'cpm'],
    ];

    /** Sin etapa: se comparan los costos generales. */
    private const KPIS_SIN_FUNNEL = ['cpm', 'ctr', 'cpi', 'cpo', 'cac'];

    public const ETIQUETAS = ['cpm' => 'CPM', 'ctr' => 'CTR', 'cpi' => 'CPI', 'cac' => 'CAC', 'cpo' => 'CPO'];

    /** En estos, más es mejor; en el resto (costos), menos es mejor. */
    private const MAYOR_ES_MEJOR = ['ctr'];

    private const MARGEN_EN_LA_MEDIA = 10;

    /**
     * Volumen mínimo para que un creativo entre a la media y al mejor del
     * grupo -- los mismos pisos que los rankings de Creativos
     * (METRICAS_RANKING.umbralDefault en AnalisisCreativo.vue). Sin esto, un
     * anuncio con $0 de gasto o 1 instalación salía como "mejor" con CPI
     * $0.00. Los de costo además exigen gasto > 0.
     */
    public const VOLUMEN_MINIMO = [
        'cpm' => ['impressions', 1000],
        'ctr' => ['impressions', 1000],
        'cpi' => ['installs', 30],
        'cac' => ['nc', 10],
        'cpo' => ['orders', 10],
    ];

    /** @var array<string, list<array<string, mixed>>> filas del mes por "plataforma|funnel" */
    private array $grupos = [];

    /**
     * @param  list<array<string, mixed>>  $filasDelMes  ReporteCreativosService::filas() sin filtros
     */
    public function __construct(array $filasDelMes)
    {
        foreach ($filasDelMes as $fila) {
            $this->grupos[self::clave($fila)][] = $fila;
        }
    }

    public static function kpisDe(?string $funnel): array
    {
        return self::KPIS_POR_FUNNEL[$funnel] ?? self::KPIS_SIN_FUNNEL;
    }

    /**
     * Resumen por plataforma y etapa (todos los creativos del grupo).
     *
     * @return list<array{plataforma: string, funnel: ?string, creativos: int, kpis: list<array{campo: string, media: ?float, mejor: ?float, mejorNombre: ?string}>}>
     */
    public function resumen(): array
    {
        $orden = array_flip(['AWA', 'CONS', 'CNV', 'LOY']);
        $resumen = [];
        foreach ($this->grupos as $filas) {
            $primera = $filas[0];
            $resumen[] = [
                'plataforma' => $primera['plataforma'],
                'funnel' => $primera['funnel'],
                'creativos' => count($filas),
                'kpis' => array_map(fn (string $campo) => ['campo' => $campo, ...self::mediaYMejor($filas, $campo)], self::kpisDe($primera['funnel'])),
            ];
        }
        usort($resumen, fn ($a, $b) => [$a['plataforma'], $orden[$a['funnel']] ?? 9] <=> [$b['plataforma'], $orden[$b['funnel']] ?? 9]);

        return $resumen;
    }

    /**
     * Un creativo contra los demás de su grupo.
     *
     * @param  array<string, mixed>  $fila
     * @return array{comparables: int, kpis: list<array{campo: string, valor: ?float, media: ?float, mejor: ?float, mejorNombre: ?string, pct: ?int, estado: ?string, esElMejor: bool, pocoVolumen: bool}>}
     */
    public function comparar(array $fila): array
    {
        $demas = array_values(array_filter(
            $this->grupos[self::clave($fila)] ?? [],
            fn (array $otra) => $otra['creativoId'] !== $fila['creativoId'],
        ));

        $kpis = [];
        foreach (self::kpisDe($fila['funnel']) as $campo) {
            $valor = $fila[$campo] ?? null;
            ['media' => $media, 'mejor' => $mejor, 'mejorNombre' => $mejorNombre] = self::mediaYMejor($demas, $campo);
            [$pct, $estado] = self::estado($valor, $media, $campo);
            // Con volumen suficiente y mejor (o igual) que el mejor de los demás: es el mejor del mes.
            $suficiente = self::cuentaParaBenchmark($fila, $campo);
            $esElMejor = $suficiente && $valor !== null && $mejor !== null
                && (self::mayorEsMejor($campo) ? $valor >= $mejor : $valor <= $mejor);
            $pocoVolumen = $valor !== null && ! $suficiente;
            $kpis[] = compact('campo', 'valor', 'media', 'mejor', 'mejorNombre', 'pct', 'estado', 'esElMejor', 'pocoVolumen');
        }

        return ['comparables' => count($demas), 'kpis' => $kpis];
    }

    public static function mayorEsMejor(string $campo): bool
    {
        return in_array($campo, self::MAYOR_ES_MEJOR, true);
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array{media: ?float, mejor: ?float, mejorNombre: ?string}
     */
    private static function mediaYMejor(array $filas, string $campo): array
    {
        $conValor = array_values(array_filter($filas, fn (array $f) => self::cuentaParaBenchmark($f, $campo)));
        if ($conValor === []) {
            return ['media' => null, 'mejor' => null, 'mejorNombre' => null];
        }
        usort($conValor, fn ($a, $b) => self::mayorEsMejor($campo) ? $b[$campo] <=> $a[$campo] : $a[$campo] <=> $b[$campo]);

        return [
            'media' => array_sum(array_column($conValor, $campo)) / count($conValor),
            'mejor' => (float) $conValor[0][$campo],
            'mejorNombre' => $conValor[0]['nombreAmigable'] ?: $conValor[0]['nombre'],
        ];
    }

    /** @param  array<string, mixed>  $f */
    private static function cuentaParaBenchmark(array $f, string $campo): bool
    {
        [$volumen, $minimo] = self::VOLUMEN_MINIMO[$campo];

        return ($f[$campo] ?? null) !== null
            && ($f[$volumen] ?? 0) >= $minimo
            && ($campo === 'ctr' || $f['cost'] > 0);
    }

    /**
     * 'mejor' | 'peor' | 'media' contra la media, y la diferencia en %.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private static function estado(?float $valor, ?float $media, string $campo): array
    {
        if ($valor === null || ! $media) {
            return [null, null];
        }
        $pct = ($valor - $media) / $media * 100;
        if (abs($pct) <= self::MARGEN_EN_LA_MEDIA) {
            return [(int) round(abs($pct)), 'media'];
        }
        $esMayor = $pct > 0;

        return [(int) round(abs($pct)), $esMayor === self::mayorEsMejor($campo) ? 'mejor' : 'peor'];
    }

    /** @param  array<string, mixed>  $fila */
    private static function clave(array $fila): string
    {
        return $fila['plataforma'].'|'.($fila['funnel'] ?? 'SIN');
    }
}
