<?php

use App\Services\Ingesta\AppsFlyerCsvParser;
use Symfony\Component\Process\Process;

/**
 * Compara el puerto PHP (AppsFlyerCsvParser) contra limpiarAppsFlyerAdId REAL
 * de Node (dashboard/shared/motor.js, proyecto epa-fer-automation) usando el
 * CSV real completo (Data (5).csv, 678KB, ~3300 filas -- copiado a
 * tests/Fixtures/appsflyer-data-5.csv para que el test no dependa de
 * ~/Downloads). No son un puñado de casos curados: es el archivo real
 * completo, fila por fila, comparado 1:1.
 */
function rutaMotorNode(): string
{
    return __DIR__.'/../../../../epa-fer-automation/dashboard/shared/motor.js';
}

function rutaBridgeMotor(): string
{
    return __DIR__.'/../../Support/node_motor_bridge.cjs';
}

function rutaFixtureCsv(): string
{
    return __DIR__.'/../../Fixtures/appsflyer-data-5.csv';
}

function resultadoNode(string $csv): array
{
    $process = new Process(['node', rutaBridgeMotor(), rutaMotorNode()]);
    $process->setInput($csv);
    $process->mustRun();

    return json_decode($process->getOutput(), true);
}

it('produce exactamente el mismo resultado que limpiarAppsFlyerAdId de Node, fila por fila, sobre el CSV real completo', function () {
    $csv = file_get_contents(rutaFixtureCsv());

    $esperado = resultadoNode($csv);
    $obtenido = AppsFlyerCsvParser::parse($csv);

    // Metadatos globales primero -- si estos difieren, el error es más
    // legible que "el array completo no matchea".
    expect($obtenido['subtotales'])->toBe($esperado['subtotales']);
    expect($obtenido['columnaNC'])->toBe($esperado['columnaNC']);
    expect($obtenido['columnaOrders'])->toBe($esperado['columnaOrders']);
    expect($obtenido['organico'])->toBe($esperado['organico']);
    expect($obtenido['problemas'])->toBe($esperado['problemas']);
    expect(count($obtenido['limpias']))->toBe(count($esperado['limpias']));

    // Fila por fila (por Ad ID), no solo el array completo -- un fallo acá
    // dice EXACTAMENTE qué Ad ID diverge, no solo "algo no matchea".
    foreach ($esperado['limpias'] as $i => $filaEsperada) {
        $filaObtenida = $obtenido['limpias'][$i];
        expect($filaObtenida)->toBe($filaEsperada, "Ad ID: {$filaEsperada['adId']} (índice {$i})");
    }
})->skip(! is_file(rutaMotorNode()), 'No se encontró el proyecto Node de referencia en '.rutaMotorNode());
