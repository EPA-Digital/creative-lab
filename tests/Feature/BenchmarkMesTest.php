<?php

use App\Services\Reportes\BenchmarkMes;

/**
 * Benchmark del mes en el PDF (2026-11-13): cada creativo contra los demás
 * de su plataforma y etapa, con media y mejor, y mínimos de volumen.
 */
function filaBench(int $id, string $funnel, float $cost, int $impressions, int $clicks, int $installs = 0, ?int $nc = null, ?int $orders = null, string $plataforma = 'meta'): array
{
    return [
        'creativoId' => $id, 'nombre' => "ARTE-{$id}", 'nombreAmigable' => null, 'plataforma' => $plataforma, 'funnel' => $funnel,
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
