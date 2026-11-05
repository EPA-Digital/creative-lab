<?php

use App\Services\Ingesta\VentaRealYAgrupacion;

/**
 * Reparto de órdenes reales (2026-11-05, confirmado con el negocio contra la
 * hoja de Ecuador octubre): el peso de cada ad es su TOTAL de órdenes en
 * AppsFlyer -- primeras compras (newCustomers) + recompras (orders, que es
 * Order Submitted) --, igual que la columna ORDERS = NEW CUSTOMERS +
 * REPURCHASES de la hoja. NC sigue pesándose solo por primeras compras.
 *
 * Nota: esto se aparta a propósito del motor.js de Node (que pesaba solo por
 * recompras); la prueba de paridad contra Node se omite cuando ese repo no
 * está y ya no aplica para órdenes.
 */
it('reparte las órdenes reales por primeras compras + recompras', function () {
    $cards = [
        ['adId' => 'conversion', 'newCustomers' => 30, 'orders' => 10, 'cost' => 100],
        ['adId' => 'recompra', 'newCustomers' => 0, 'orders' => 60, 'cost' => 100],
    ];

    [$conversion, $recompra] = VentaRealYAgrupacion::calcularVentaReal($cards, 300, 1000, false);

    // Pesos de órdenes: 40 y 60 de 100 (antes: 10 y 60 de 70).
    expect($conversion['ordersReal'])->toBe(400.0)
        ->and($recompra['ordersReal'])->toBe(600.0)
        // NC: solo primeras compras, sin cambios.
        ->and($conversion['ncReal'])->toEqual(300)
        ->and($recompra['ncReal'])->toEqual(0)
        ->and($conversion['cpo'])->toBe(0.25);
});

it('sin órdenes en AppsFlyer no reparte órdenes a nadie', function () {
    $r = VentaRealYAgrupacion::calcularVentaReal([['adId' => 'a', 'newCustomers' => 0, 'orders' => 0, 'cost' => 10]], 5, 50, false);

    expect($r[0]['ordersReal'])->toEqual(0);
});
