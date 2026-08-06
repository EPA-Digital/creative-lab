#!/usr/bin/env node
// Bridge de verificación: corre calcularVentaReal + agruparPorArteYFunnel
// REALES de Node (proyecto de referencia epa-fer-automation,
// dashboard/shared/motor.js) sobre el JSON recibido por stdin
// ({cards, ncTotalReal, ordersTotalReal, esRangoParcial}), y devuelve los 3
// resultados relevantes por JSON. Usado SOLO por
// tests/Feature/Ingesta/VentaRealYAgrupacionTest.php -- nunca en producción.
'use strict';

const path = require('path');

const motorPath = process.argv[2];
if (!motorPath) {
  process.stderr.write('Uso: node node_venta_real_bridge.cjs <ruta a motor.js>\n');
  process.exit(1);
}

global.window = {};
require(path.resolve(motorPath));
const motor = window.TadaMotor;

let input = '';
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
  const { cards, ncTotalReal, ordersTotalReal } = JSON.parse(input);

  const conVenta = motor.calcularVentaReal(cards, { ncTotalReal, ordersTotalReal, esRangoParcial: false });
  const parcial = motor.calcularVentaReal(cards, { ncTotalReal, ordersTotalReal, esRangoParcial: true });
  const agrupado = motor.agruparPorArteYFunnel(conVenta);

  process.stdout.write(JSON.stringify({ conVenta, parcial, agrupado }));
});
