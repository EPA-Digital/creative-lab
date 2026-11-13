<?php

use App\Services\Reportes\BenchmarkMes;

/**
 * Benchmark del mes en el PDF (2026-11-13): cada creativo contra los demás
 * de su plataforma y etapa, con media y mejor, y mínimos de volumen.
 */
function filaBench(int $id, string $funnel, float $cost, int $impressions, int $clicks, int $installs = 0, ?int $nc = null, ?int $orders = null, string $plataforma = 'meta', string $tipoCuenta = 'DTC'): array
{
    return [
        'creativoId' => $id, 'nombre' => "ARTE-{$id}", 'nombreAmigable' => null, 'plataforma' => $plataforma, 'tipoCuenta' => $tipoCuenta, 'funnel' => $funnel,
        'cost' => $cost, 'impressions' => $impressions, 'clicks' => $clicks, 'installs' => $installs, 'nc' => $nc, 'orders' => $orders,
        'ctr' => $impressions ? $clicks / $impressions * 100 : null,
        'cpm' => $impressions ? $cost / $impressions * 1000 : null,
        'cpi' => $installs ? $cost / $installs : null,
        'cac' => $nc ? $cost / $nc : null,
        'cpo' => $orders ? $cost / $orders : null,
    ];
}

it('compara contra la media y el mejor de los demás de su plataforma y etapa', function () {
    $este = filaBench(1, 'AWA', 250, 1_000_000, 2000);                   // CPM 0.25
    $bench = new BenchmarkMes([
        $este,
        filaBench(2, 'AWA', 200, 1_000_000, 1000),                       // CPM 0.20 (el más barato)
        filaBench(3, 'AWA', 400, 1_000_000, 1000),                       // CPM 0.40
        filaBench(4, 'AWA', 50, 1_000_000, 500, plataforma: 'tiktok'),   // otra plataforma: no cuenta
        filaBench(5, 'CNV', 10, 1_000_000, 500),                         // otra etapa: no cuenta
        filaBench(6, 'AWA', 10, 1_000_000, 500, tipoCuenta: 'BRD'),      // BRD: no se mezcla con DTC
    ]);

    $comparacion = $bench->comparar($este);
    $cpm = collect($comparacion['kpis'])->firstWhere('campo', 'cpm');

    expect($comparacion['comparables'])->toBe(2)
        ->and($cpm['media'])->toEqualWithDelta(0.30, 0.0001)
        ->and($cpm['mejor'])->toEqualWithDelta(0.20, 0.0001)
        ->and($cpm['estado'])->toBe('mejor')   // 0.25 vs 0.30: 17% más barato
        ->and($cpm['pct'])->toBe(17)
        ->and($cpm['esElMejor'])->toBeFalse();
});

it('usa los KPIs de la etapa', function () {
    expect(BenchmarkMes::kpisDe('AWA'))->toBe(['cpm', 'ctr'])
        ->and(BenchmarkMes::kpisDe('CNV'))->toBe(['cac', 'ctr', 'cpm'])
        ->and(BenchmarkMes::kpisDe('LOY')[0])->toBe('cpo');
});

it('deja fuera de la media y el mejor a los de poco volumen o sin gasto', function () {
    $este = filaBench(1, 'CONS', 300, 100_000, 1000, installs: 100);     // CPI 3.00
    $bench = new BenchmarkMes([
        $este,
        filaBench(2, 'CONS', 0, 100_000, 1000, installs: 50),             // gasto 0: CPI 0.00, no cuenta
        filaBench(3, 'CONS', 10, 100_000, 1000, installs: 5),             // 5 instalaciones: no cuenta
        filaBench(4, 'CONS', 400, 100_000, 1000, installs: 100),          // CPI 4.00
    ]);

    $cpi = collect($bench->comparar($este)['kpis'])->firstWhere('campo', 'cpi');

    expect($cpi['media'])->toEqualWithDelta(4.0, 0.0001)
        ->and($cpi['mejor'])->toEqualWithDelta(4.0, 0.0001)
        ->and($cpi['esElMejor'])->toBeTrue();
});

it('marca poco volumen y "en la media" dentro del ±10%', function () {
    $este = filaBench(1, 'LOY', 105, 500, 5, orders: 3);     // 500 impresiones, 3 órdenes
    $bench = new BenchmarkMes([
        $este,
        filaBench(2, 'LOY', 100, 50_000, 500, orders: 100),
    ]);

    $kpis = collect($bench->comparar($este)['kpis'])->keyBy('campo');

    expect($kpis['cpo']['pocoVolumen'])->toBeTrue()
        ->and($kpis['ctr']['estado'])->toBe('media'); // 1.00% vs 1.00%
});

it('califica bueno, promedio o malo con el KPI principal pesando más', function () {
    // Conversion: el CAC (principal) decide aunque el CTR vaya al revés.
    $grupo = [
        filaBench(2, 'CNV', 1000, 1_000_000, 10_000, nc: 100),   // CAC 10, CTR 1%, CPM 1
        filaBench(3, 'CNV', 1000, 1_000_000, 10_000, nc: 100),
    ];
    $barato = filaBench(1, 'CNV', 500, 1_000_000, 5_000, nc: 100);    // CAC 5 (mejor), CTR 0.5% (peor)
    $caro = filaBench(1, 'CNV', 2000, 1_000_000, 20_000, nc: 100);    // CAC 20 (peor), CTR 2% (mejor)
    $igual = filaBench(1, 'CNV', 1000, 1_000_000, 10_000, nc: 100);

    $bueno = BenchmarkMes::veredicto((new BenchmarkMes([$barato, ...$grupo]))->comparar($barato));
    $malo = BenchmarkMes::veredicto((new BenchmarkMes([$caro, ...$grupo]))->comparar($caro));
    $promedio = BenchmarkMes::veredicto((new BenchmarkMes([$igual, ...$grupo]))->comparar($igual));

    expect($bueno['calificacion'])->toBe('bueno')
        ->and($bueno['texto'])->toContain('costo por cliente nuevo (CAC) ($5.00) es 50% más barato que la media ($10.00)')
        ->and($bueno['texto'])->toContain('A mejorar: su tasa de clics (CTR)')
        ->and($malo['calificacion'])->toBe('malo')
        ->and($malo['texto'])->toContain('En contra: ')
        ->and($promedio['calificacion'])->toBe('promedio');
});

it('sin creativos comparables no califica', function () {
    $solo = filaBench(1, 'AWA', 100, 100_000, 100);

    expect(BenchmarkMes::veredicto((new BenchmarkMes([$solo]))->comparar($solo))['calificacion'])->toBeNull();
});
