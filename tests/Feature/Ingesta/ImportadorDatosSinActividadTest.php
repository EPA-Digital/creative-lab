<?php

use App\Services\Ingesta\ImportadorDatos;

/**
 * Cubre el fix de 2026-08-11: ImportadorDatos::esSinActividad() -- descarta
 * (sin crear/tocar creativo ni resultado) las cards sin actividad real, para
 * limpiar el ruido de variantes de anuncio de campañas automatizadas de
 * TikTok (Smart+/AUTOMATIC-PLACEMENTS) que nunca reciben presupuesto real.
 * Diagnosticado contra datos reales de Ecuador: 529 de 813 creativos (65%,
 * 100% TikTok) tenían cost/impressions/clicks/installs=0 en TODAS sus filas.
 */
function esSinActividad(array $card): bool
{
    $metodo = new ReflectionMethod(ImportadorDatos::class, 'esSinActividad');
    $metodo->setAccessible(true);

    return $metodo->invoke(null, $card);
}

it('descarta una card con cost/impressions/clicks/installs en 0', function () {
    expect(esSinActividad(['cost' => 0, 'impressions' => 0, 'clicks' => 0, 'installs' => 0]))->toBeTrue();
});

it('descarta una card donde faltan las claves (tratadas como 0)', function () {
    expect(esSinActividad([]))->toBeTrue();
});

it('NO descarta una card con costo real aunque el resto sea 0', function () {
    expect(esSinActividad(['cost' => 5.5, 'impressions' => 0, 'clicks' => 0, 'installs' => 0]))->toBeFalse();
});

it('NO descarta una card con impressions reales aunque el resto sea 0', function () {
    expect(esSinActividad(['cost' => 0, 'impressions' => 120, 'clicks' => 0, 'installs' => 0]))->toBeFalse();
});

it('NO descarta una card con clicks reales aunque el resto sea 0', function () {
    expect(esSinActividad(['cost' => 0, 'impressions' => 0, 'clicks' => 3, 'installs' => 0]))->toBeFalse();
});

it('NO descarta una card con installs reales aunque el resto sea 0', function () {
    expect(esSinActividad(['cost' => 0, 'impressions' => 0, 'clicks' => 0, 'installs' => 2]))->toBeFalse();
});
