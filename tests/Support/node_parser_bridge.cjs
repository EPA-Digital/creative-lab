#!/usr/bin/env node
// Bridge de verificación: corre el parser REAL de Node (proyecto de
// referencia epa-fer-automation) sobre pares [campaign, ad] recibidos por
// stdin (JSON) y devuelve parsearNombre() para cada uno, también por JSON.
// Usado SOLO por tests/Feature/Ingesta/ClasificadorNombresTest.php para
// comparar 1:1 contra el puerto en PHP -- nunca se usa en producción.
'use strict';

const path = require('path');

const parserPath = process.argv[2];
if (!parserPath) {
  process.stderr.write('Uso: node node_parser_bridge.js <ruta a parser-nombres.js>\n');
  process.exit(1);
}

global.window = {};
require(path.resolve(parserPath));
const parser = global.window.TadaParserNombres;

let input = '';
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
  const pares = JSON.parse(input);
  const resultados = pares.map(([campaign, ad]) => parser.parsearNombre(campaign, ad));
  process.stdout.write(JSON.stringify(resultados));
});
