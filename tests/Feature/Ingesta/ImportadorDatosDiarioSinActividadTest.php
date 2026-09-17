<?php

use App\Services\Ingesta\ImportadorDatosDiario;

/**
 * Cubre ImportadorDatosDiario::esSinActividad() (2026-08-27, ver plan del
 * selector de fecha) -- mismo criterio conceptual que
 * ImportadorDatos::esSinActividad (ver ImportadorDatosSinActividadTest.php)
 * pero evaluado por FILA DIARIA: un día sin cost/impressions/clicks/
 * installs/orders/nc se omite, nunca se persiste una fila de puro 0.
 */
function esSinActividadDiario(array $fila): bool
{
    $metodo = new ReflectionMethod(ImportadorDatosDiario::class, 'esSinActividad');
    $metodo->setAccessible(true);

    return $metodo->invoke(null, $fila);
}

function filaBase(array $overrides = []): array
{
    return array_merge(
        ['cost' => 0, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'orders' => 0, 'nc' => 0],
        $overrides
    );
}

it('descarta una fila con todo en 0', function () {
    expect(esSinActividadDiario(filaBase()))->toBeTrue();
});

it('NO descarta una fila con costo real aunque el resto sea 0', function () {
    expect(esSinActividadDiario(filaBase(['cost' => 5.5])))->toBeFalse();
});

it('NO descarta una fila con orders reales aunque cost/impressions/clicks/installs sean 0', function () {
    // Caso real que motivó este fix a nivel diario: un día de re-atribución
    // sin gasto/impresiones nuevas pero con una orden real de AppsFlyer.
    expect(esSinActividadDiario(filaBase(['orders' => 2])))->toBeFalse();
});

it('NO descarta una fila con nc real aunque el resto sea 0', function () {
    expect(esSinActividadDiario(filaBase(['nc' => 1])))->toBeFalse();
});
