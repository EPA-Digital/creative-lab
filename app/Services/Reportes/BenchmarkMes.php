<?php

namespace App\Services\Reportes;

/**
 * Benchmark del mes para el reporte PDF (pedido explícito 2026-11-13):
 * cada creativo contra los DEMÁS creativos del mismo mes, misma plataforma,
 * mismo tipo de cuenta (DTC / BRD / sin clasificar: nunca se mezclan, igual
 * que en el resto del dashboard) y misma etapa, en los KPIs principales de su etapa. Se muestra la media
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

    /** Nombre para el cliente (el reporte lo leen fuera de EPA). */
    private const NOMBRE_CLARO = [
        'cpm' => 'costo por mil impresiones (CPM)',
        'ctr' => 'tasa de clics (CTR)',
        'cpi' => 'costo por instalación (CPI)',
        'cac' => 'costo por cliente nuevo (CAC)',
        'cpo' => 'costo por orden (CPO)',
    ];

    /**
     * Conclusión del creativo para el cliente: bueno / promedio / malo y por
     * qué. El KPI principal de la etapa (el primero de KPIS_POR_FUNNEL: CPM,
     * CPI, CAC o CPO) pesa el triple que los demás -- el costo por resultado
     * es lo que más importa: un CAC mucho más caro no se compensa solo con
     * buen CTR. Mejor que la media suma, peor resta, en la media no cuenta.
     * Puntaje >= 2: bueno; <= -2: malo.
     *
     * @param  array{comparables: int, kpis: list<array<string, mixed>>}  $comparacion  resultado de comparar()
     * @return array{calificacion: ?string, texto: string}
     */
    public static function veredicto(array $comparacion): array
    {
        $kpis = array_values(array_filter($comparacion['kpis'], fn (array $k) => $k['estado'] !== null));
        if ($kpis === []) {
            return ['calificacion' => null, 'texto' => 'No hay suficientes creativos comparables este mes para calificarlo.'];
        }

        $principal = $comparacion['kpis'][0]['campo'];
        $puntaje = 0;
        foreach ($kpis as $k) {
            $peso = $k['campo'] === $principal ? 3 : 1;
            $puntaje += match ($k['estado']) {
                'mejor' => $peso,
                'peor' => -$peso,
                default => 0,
            };
        }
        $calificacion = $puntaje >= 2 ? 'bueno' : ($puntaje <= -2 ? 'malo' : 'promedio');

        $frase = fn (array $k) => self::frase($k);
        $fuertes = array_map($frase, array_values(array_filter($kpis, fn ($k) => $k['estado'] === 'mejor')));
        $debiles = array_map($frase, array_values(array_filter($kpis, fn ($k) => $k['estado'] === 'peor')));
        $enMedia = array_map(fn ($k) => self::NOMBRE_CLARO[$k['campo']], array_values(array_filter($kpis, fn ($k) => $k['estado'] === 'media')));

        $partes = [];
        if ($fuertes) {
            $partes[] = 'A favor: '.self::lista($fuertes).'.';
        }
        if ($debiles) {
            $partes[] = ($calificacion === 'malo' ? 'En contra: ' : 'A mejorar: ').self::lista($debiles).'.';
        }
        if ($enMedia && count($partes) < 2) {
            $partes[] = 'En línea con la media: '.self::lista($enMedia).'.';
        }
        $principalKpi = collect($comparacion['kpis'])->firstWhere('campo', $principal);
        if (($principalKpi['pocoVolumen'] ?? false)) {
            $partes[] = 'Tomarlo con cautela: tuvo poco volumen para su '.self::NOMBRE_CLARO[$principal].'.';
        }

        return ['calificacion' => $calificacion, 'texto' => implode(' ', $partes)];
    }

    /** "su CAC ($5.58) es 26% más barato que la media ($7.53)" @param  array<string, mixed>  $k */
    private static function frase(array $k): string
    {
        $formato = fn (?float $v) => $k['campo'] === 'ctr' ? number_format((float) $v, 2).'%' : '$'.number_format((float) $v, 2);
        $sentido = match (true) {
            $k['campo'] === 'ctr' => $k['valor'] > $k['media'] ? 'más alta' : 'más baja',
            default => $k['valor'] < $k['media'] ? 'más barato' : 'más caro',
        };
        $mejorDelMes = $k['esElMejor'] ? ', el mejor del mes' : '';

        return 'su '.self::NOMBRE_CLARO[$k['campo']].' ('.$formato($k['valor']).") es {$k['pct']}% {$sentido} que la media (".$formato($k['media']).')'.$mejorDelMes;
    }

    /** @param  list<string>  $items */
    private static function lista(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' y '.end($items);
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
        return $fila['plataforma'].'|'.($fila['tipoCuenta'] ?? 'SIN').'|'.($fila['funnel'] ?? 'SIN');
    }
}
