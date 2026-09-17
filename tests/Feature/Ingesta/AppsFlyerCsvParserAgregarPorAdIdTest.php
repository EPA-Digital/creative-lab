<?php

use App\Services\Ingesta\AppsFlyerCsvParser;

/**
 * Cubre agregarPorAdId() directamente (2026-08-11, refactor para reuso con
 * el futuro path de API de AppsFlyer) -- en particular $sumarDuplicados,
 * que NO existía antes del refactor y por lo tanto no está cubierto por
 * AppsFlyerCsvParserTest (ese test solo ejercita parse(), que siempre llama
 * a agregarPorAdId() con el default sumarDuplicados=false).
 */
function filaNormalizada(array $overrides = []): array
{
    return array_merge([
        'adId' => '123',
        'esOrganico' => false,
        'campaign' => 'FB-CNV-DTC',
        'adRaw' => 'VID-01-01-ARTE',
        'fecha' => '01-07-2026',
        'installs' => 1,
        'orders' => 1,
        'newCustomers' => 1,
        'filaOriginal' => [],
    ], $overrides);
}

it('con sumarDuplicados=false (default), descarta la fila duplicada con valores distintos y flaggea un problema', function () {
    $filas = [
        filaNormalizada(['installs' => 10, 'newCustomers' => 2]),
        filaNormalizada(['installs' => 5, 'newCustomers' => 1]), // mismo adId+fecha, valores distintos
    ];

    $r = AppsFlyerCsvParser::agregarPorAdId($filas);

    expect($r['limpias'])->toHaveCount(1);
    expect($r['limpias'][0]['serie'][0]['installs'])->toBe(10); // se conserva la primera
    expect($r['problemasDuplicado'])->toHaveCount(1);
    expect($r['problemasDuplicado'][0]['motivo'])->toContain('Fila duplicada para Ad ID 123');
});

it('con sumarDuplicados=true, SUMA los valores de la fila duplicada en vez de descartarla', function () {
    $filas = [
        filaNormalizada(['installs' => 10, 'orders' => 3, 'newCustomers' => 2]),
        filaNormalizada(['installs' => 5, 'orders' => 1, 'newCustomers' => 1]),
    ];

    $r = AppsFlyerCsvParser::agregarPorAdId($filas, sumarDuplicados: true);

    expect($r['limpias'])->toHaveCount(1);
    expect($r['limpias'][0]['serie'])->toHaveCount(1);
    expect($r['limpias'][0]['serie'][0])->toBe([
        'fecha' => '01-07-2026', 'installs' => 15, 'orders' => 4, 'newCustomers' => 3,
    ]);
    expect($r['problemasDuplicado'])->toBeEmpty();
});

it('no marca como duplicado ni suma cuando la fila repetida trae exactamente los mismos valores', function () {
    $filas = [filaNormalizada(), filaNormalizada()];

    $r = AppsFlyerCsvParser::agregarPorAdId($filas, sumarDuplicados: true);

    expect($r['limpias'][0]['serie'])->toHaveCount(1);
    expect($r['limpias'][0]['serie'][0]['installs'])->toBe(1);
    expect($r['problemasDuplicado'])->toBeEmpty();
});

it('separa filas orgánicas (adId null) sin mezclarlas con la agregación por Ad ID', function () {
    $filas = [
        filaNormalizada(['adId' => null, 'esOrganico' => true, 'installs' => 7]),
        filaNormalizada(),
    ];

    $r = AppsFlyerCsvParser::agregarPorAdId($filas);

    expect($r['organico'])->toHaveCount(1);
    expect($r['organico'][0]['installs'])->toBe(7);
    expect($r['limpias'])->toHaveCount(1);
});

it('ordena la serie diaria cronológicamente aunque llegue desordenada', function () {
    $filas = [
        filaNormalizada(['fecha' => '15-07-2026', 'installs' => 2]),
        filaNormalizada(['fecha' => '01-07-2026', 'installs' => 1]),
    ];

    $r = AppsFlyerCsvParser::agregarPorAdId($filas);

    expect($r['limpias'][0]['serie'][0]['fecha'])->toBe('01-07-2026');
    expect($r['limpias'][0]['serie'][1]['fecha'])->toBe('15-07-2026');
});
