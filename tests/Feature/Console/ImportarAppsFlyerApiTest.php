<?php

/**
 * Cubre la validación de opciones de importar:appsflyer-api (2026-08-11) --
 * los totales manuales solo tienen sentido con un {pais} explícito, así que
 * el modo multi-país los rechaza ANTES de tocar ninguna API (no hace falta
 * mockear Meta/TikTok/AppsFlyer para probar esto).
 */
it('rechaza los totales manuales cuando se corre en modo multi-país (sin {pais})', function () {
    $this->artisan('importar:appsflyer-api', ['--nc-total-real-meta' => '1000'])
        ->assertFailed()
        ->expectsOutputToContain('no es válido sin especificar un país');
});

it('rechaza cualquiera de los 4 totales individualmente en modo multi-país', function () {
    $this->artisan('importar:appsflyer-api', ['--orders-total-real-tiktok' => '500'])
        ->assertFailed()
        ->expectsOutputToContain('--orders-total-real-tiktok no es válido sin especificar un país');
});
