<?php

use App\Models\Resultado;
use App\Services\Ingesta\ImportadorDatos;

/**
 * Cubre el fix de 2026-08-11: ImportadorDatos::resolverNcOrdersFinal() no
 * debe pisar con null un nc/orders ya persistido cuando la corrida actual no
 * trae el total manual correspondiente (caso real: un refresh de costo
 * recurrente que no tecleó ncTotalReal/ordersTotalReal porque el cierre de
 * mes todavía no se conoce). Sin este fix, correr el pipeline una segunda
 * vez sin pasar los 4 totales borraba en silencio el nc/cac/orders/cpo
 * correctos de la corrida anterior -- exactamente lo que la regla de negocio
 * "venta_real es sagrado" prohíbe.
 *
 * También cubre ncPreservado/ncRecalculado/ordersPreservado/ordersRecalculado
 * -- los flags que alimentan los contadores de `importaciones` (2026-08-11,
 * mover Importacion::create al service).
 *
 * Es un método privado (vive junto a la única lógica que toca BD,
 * procesarPlataforma) -- se invoca vía Reflection a propósito, mismo criterio
 * que los demás tests de este proyecto que prueban lógica pura sin levantar
 * todo el pipeline HTTP+CSV.
 */
function resolverNcOrdersFinal(?Resultado $existente, array $card, bool $esRangoParcial, mixed $ncTotalReal, mixed $ordersTotalReal): array
{
    $metodo = new ReflectionMethod(ImportadorDatos::class, 'resolverNcOrdersFinal');
    $metodo->setAccessible(true);

    return $metodo->invoke(null, $existente, $card, $esRangoParcial, $ncTotalReal, $ordersTotalReal);
}

it('calcula nc/orders normalmente cuando se pasan los totales (comportamiento actual, sin cambios)', function () {
    $card = ['ncReal' => 30.6, 'ordersReal' => 12.4];

    $r = resolverNcOrdersFinal(null, $card, false, '1000', '2000');

    expect($r)->toBe([
        'nc' => 31, 'ncPreservado' => false, 'ncRecalculado' => true,
        'ordersPreservado' => false, 'ordersRecalculado' => true,
        'orders' => 12, 'tieneVentaReal' => true,
    ]);
});

it('preserva nc/orders existentes cuando esta corrida NO trae ningún total (refresh de costo recurrente)', function () {
    $existente = new Resultado(['nc' => 50, 'orders' => 20]);
    $card = ['ncReal' => null, 'ordersReal' => null];

    $r = resolverNcOrdersFinal($existente, $card, false, null, null);

    expect($r)->toBe([
        'nc' => 50, 'ncPreservado' => true, 'ncRecalculado' => false,
        'ordersPreservado' => true, 'ordersRecalculado' => false,
        'orders' => 20, 'tieneVentaReal' => true,
    ]);
});

it('resuelve NC y Orders de forma independiente -- un total puede llegar sin el otro', function () {
    $existente = new Resultado(['nc' => 50, 'orders' => 20]);
    $card = ['ncReal' => 40.0, 'ordersReal' => null];

    // Solo se pasó el total de NC esta corrida -- Orders se preserva tal cual.
    $r = resolverNcOrdersFinal($existente, $card, false, '1000', null);

    expect($r)->toBe([
        'nc' => 40, 'ncPreservado' => false, 'ncRecalculado' => true,
        'ordersPreservado' => true, 'ordersRecalculado' => false,
        'orders' => 20, 'tieneVentaReal' => true,
    ]);
});

it('no inventa un valor cuando es la primera vez y no hay total ni fila previa (ni preservado ni recalculado)', function () {
    $card = ['ncReal' => null, 'ordersReal' => null];

    $r = resolverNcOrdersFinal(null, $card, false, null, null);

    expect($r)->toBe([
        'nc' => null, 'ncPreservado' => false, 'ncRecalculado' => false,
        'ordersPreservado' => false, 'ordersRecalculado' => false,
        'orders' => null, 'tieneVentaReal' => false,
    ]);
});

it('trata un rango parcial como "no hubo total esta corrida" aunque se hayan tecleado totales por error', function () {
    $existente = new Resultado(['nc' => 50, 'orders' => 20]);
    $card = ['ncReal' => null, 'ordersReal' => null]; // calcularVentaReal ya los pone null si esRangoParcial=true

    $r = resolverNcOrdersFinal($existente, $card, true, '1000', '2000');

    expect($r)->toBe([
        'nc' => 50, 'ncPreservado' => true, 'ncRecalculado' => false,
        'ordersPreservado' => true, 'ordersRecalculado' => false,
        'orders' => 20, 'tieneVentaReal' => true,
    ]);
});
