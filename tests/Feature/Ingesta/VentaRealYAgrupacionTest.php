<?php

use App\Services\Ingesta\VentaRealYAgrupacion;
use Symfony\Component\Process\Process;

/**
 * Compara el puerto PHP (VentaRealYAgrupacion) contra calcularVentaReal +
 * agruparPorArteYFunnel REALES de Node (dashboard/shared/motor.js, proyecto
 * epa-fer-automation) usando un set de cards inspirado en casos reales que
 * ya vivimos en la sesión de trabajo del lado Node:
 *   - SLIDE-24: 2 Ad IDs reales (uno literal "- Copia" del otro), mismo
 *     arte + mismo funnel -> deben sumarse en 1 sola card.
 *   - PILSENER-KIT-QUE-NECESITAS: mismo arte, 2 campañas de funnels
 *     DISTINTOS (CNV y LOY) -> deben quedar como 2 cards separadas (el bug
 *     que arreglamos del lado Node -- este test es la red de seguridad
 *     contra que el puerto lo reintroduzca).
 *   - ACER-TADAS: 2 miembros TikTok, uno PAUSED y otro con
 *     AD_STATUS_DELIVERY_OK -> el grupo completo debe quedar "ACTIVE"
 *     (estado compuesto: alcanza con que UN miembro esté activo).
 *   - Un ad sin arte y otro con funnel "Sin clasificar" -> deben quedar
 *     sueltos, nunca agrupados bajo una llave inventada.
 */
function rutaMotorNodeVenta(): string
{
    return __DIR__.'/../../../../epa-fer-automation/dashboard/shared/motor.js';
}

function rutaBridgeVentaReal(): string
{
    return __DIR__.'/../../Support/node_venta_real_bridge.cjs';
}

function rutaFixtureCards(): string
{
    return __DIR__.'/../../Fixtures/cards-venta-real.json';
}

function resultadosNodeVentaReal(array $payload): array
{
    $process = new Process(['node', rutaBridgeVentaReal(), rutaMotorNodeVenta()]);
    $process->setInput(json_encode($payload));
    $process->mustRun();

    return json_decode($process->getOutput(), true);
}

it('calcularVentaReal y agruparPorArteYFunnel producen exactamente el mismo resultado que Node', function () {
    $cards = json_decode(file_get_contents(rutaFixtureCards()), true);
    $ncTotalReal = '1000';
    $ordersTotalReal = '2000';

    $esperado = resultadosNodeVentaReal([
        'cards' => $cards,
        'ncTotalReal' => $ncTotalReal,
        'ordersTotalReal' => $ordersTotalReal,
    ]);

    // toEqual (no toBe) a propósito en las 3 comparaciones estructurales de
    // abajo: Node no distingue int de float (JSON.stringify de 12.0 emite
    // "12"), así que un valor entero-de-hecho vuelve de la comparación
    // Node como int y del lado PHP como float (o viceversa) sin que sea una
    // divergencia real de negocio -- toEqual compara valores (12 == 12.0),
    // toBe (===) compararía también el tipo y fallaría por ese artefacto de
    // serialización, no por un bug real.

    // Rango completo -- ncReal/ordersReal/cac/cpo calculados por share.
    $conVenta = VentaRealYAgrupacion::calcularVentaReal($cards, $ncTotalReal, $ordersTotalReal, false);
    expect($conVenta)->toEqual($esperado['conVenta']);

    // Rango parcial -- ncReal/ordersReal deben quedar null aunque haya
    // totales tecleados (el costo de la API no se puede prorratear a un
    // sub-rango).
    $parcial = VentaRealYAgrupacion::calcularVentaReal($cards, $ncTotalReal, $ordersTotalReal, true);
    expect($parcial)->toEqual($esperado['parcial']);

    // Agrupación por arte+funnel sobre las cards YA con venta real (flujo
    // real: primero se reparte el total, después se agrupa).
    $agrupado = VentaRealYAgrupacion::agruparPorArteYFunnel($conVenta);
    expect($agrupado)->toEqual($esperado['agrupado']);

    // Aserciones de negocio explícitas, no solo el diff estructural --
    // dicen QUÉ regla se está confirmando, no solo "coincide con Node".
    expect($agrupado['stats'])->toBe([
        'totalEntrada' => 8,
        'grupos' => 4,
        'filasAgrupadas' => 6,
        'filasSueltas' => 2,
    ]);

    $porArteFunnel = collect($agrupado['cards'])
        ->filter(fn ($c) => $c['arte'] !== null)
        ->keyBy(fn ($c) => ($c['etapaFunnel'] ?? '').'::'.$c['arte']);

    // SLIDE-24: 2 Ad IDs reales sumados en 1 card.
    $slide24 = $porArteFunnel['LOY::SLIDE-24'];
    expect($slide24['miembros'])->toHaveCount(2);
    expect(round($slide24['cost'], 2))->toBe(9.74);

    // PILSENER-KIT-QUE-NECESITAS: NO se suma entre funnels distintos.
    expect($porArteFunnel->has('CNV::PILSENER-KIT-QUE-NECESITAS'))->toBeTrue();
    expect($porArteFunnel->has('LOY::PILSENER-KIT-QUE-NECESITAS'))->toBeTrue();
    expect($porArteFunnel['CNV::PILSENER-KIT-QUE-NECESITAS']['miembros'])->toHaveCount(1);
    expect($porArteFunnel['LOY::PILSENER-KIT-QUE-NECESITAS']['miembros'])->toHaveCount(1);

    // ACER-TADAS: estado compuesto ACTIVE (un miembro PAUSED + uno ACTIVO).
    expect($porArteFunnel['CNV::ACER-TADAS']['status'])->toBe('ACTIVE');
})->skip(! is_file(rutaMotorNodeVenta()), 'No se encontró el proyecto Node de referencia en '.rutaMotorNodeVenta());
