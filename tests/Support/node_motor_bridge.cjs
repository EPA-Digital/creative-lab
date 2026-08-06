#!/usr/bin/env node
// Bridge de verificación: corre limpiarAppsFlyerAdId REAL de Node (proyecto
// de referencia epa-fer-automation, dashboard/shared/motor.js) sobre el CSV
// recibido por stdin, y devuelve el resultado por JSON. Usado SOLO por
// tests/Feature/Ingesta/AppsFlyerCsvParserTest.php -- nunca en producción.
'use strict';

const path = require('path');

const motorPath = process.argv[2];
if (!motorPath) {
  process.stderr.write('Uso: node node_motor_bridge.cjs <ruta a motor.js>\n');
  process.exit(1);
}

global.window = {};
require(path.resolve(motorPath));
const motor = window.TadaMotor;

let input = '';
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
  const resultado = motor.limpiarAppsFlyerAdId(input);
  process.stdout.write(JSON.stringify(resultado));
});
