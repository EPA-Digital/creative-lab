<?php

use App\Services\Ingesta\ClasificadorNombres;
use Symfony\Component\Process\Process;

/**
 * Compara el puerto PHP (ClasificadorNombres) contra el parser REAL de Node
 * (dashboard/shared/parser-nombres.js, proyecto epa-fer-automation, hermano
 * de este) para el mismo set de pares Campaign/Ad -- "mismo input -> mismo
 * output" no es una promesa, es este test.
 *
 * Los casos de abajo son reales, extraídos del CSV de AppsFlyer y
 * confirmados contra la ODT durante la sesión de trabajo del lado Node --
 * varios son justo los bugs que ya vivimos y arreglamos ahí (CNV/LOY
 * colapsados por texto libre, BI/DTC mal clasificado, segmento CON mapeado
 * mal a funnel CONS), así que este test es también una red de seguridad
 * contra que el puerto reintroduzca esos bugs.
 */
function rutaNodeParser(): string
{
    return __DIR__.'/../../../../epa-fer-automation/dashboard/shared/parser-nombres.js';
}

function rutaBridge(): string
{
    return __DIR__.'/../../Support/node_parser_bridge.cjs';
}

function casosReales(): array
{
    return [
        // Meta -- CNV vs LOY, mismo texto libre "CONVERSION-AND-RMK"; solo
        // el segmento explícito (_CNV_/_LOY_) los distingue.
        'meta CNV (PILSENER-KIT-QUE-NECESITAS)' => [
            'ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONVERSION-AND-RMK-INSTALL-CHECKBOX-V2',
            'IMG_MUL_STA_AON_LLA_1_FB-CONVERSION-AND-RMK-INSTALL-CHECKBOX-SP-01JUL-31JUL-PILSENER-KIT-QUE-NECESITAS',
        ],
        'meta LOY (PILSENER-KIT-QUE-NECESITAS)' => [
            'ECU_DTC_TAD_AON_AON_TAD_LOY_EPA_FB-CONVERSION-AND-RMK-PURCHASE-ADVANTAGE',
            'IMG_MUL_STA_AON_LLA_1_FB-CONVERSION-AND-RMK-PURCHASE-ADVANTAGE-SP-01JUL-31JUL-PILSENER-KIT-QUE-NECESITAS',
        ],
        // Meta -- BRD real con patrón que el mapa asume DTC.
        'meta BRD con patron ADVANTAGE-AND-SHOPPING' => [
            'ECU_BRD_MLM_AON_AON_NON_CNV_EPA_FB-ADVANTAGE-AND-SHOPPING-BROAD',
            'SLIDE-14_FB-ADVANTAGE-AND-SHOPPING-BROAD-SP-01JUL-31JUL-SLIDE-14',
        ],
        // Meta -- DTC real con patron INSTALL-AND-VOLUME (el mapa asume
        // BRD) y segmento _CON_ -> debe traducirse a funnel CONS, no "CON".
        'meta DTC con segmento _CON_ (ACER-TADAS)' => [
            'ECU_DTC_TAD_AON_AON_TAD_CON_EPA_FB-ADVANTAGE-INSTALL-AND-VOLUME-INT',
            'IMG_MUL_STA_AON_LLA_8_FB-ADVANTAGE-INSTALL-AND-VOLUME-INT-SP-03JUL-31JUL-AON-ACER-TADAS',
        ],
        // TikTok -- plataforma correcta, funnel AWA.
        'tiktok AWA (ACER-TADAS)' => [
            'ECU_DTC_TAD_AON_AON_TAD_AWA_EPA_TKT-AWARENESS-BROAD',
            'VID_MUL_AON_LLA_TKT-AWARENESS-BROAD-VID-03JUL-31JUL-ACER-TADAS',
        ],
        // Ad duplicado (" - Copia") -- el arte debe truncarse antes del
        // espacio (la clase de caracteres del bloque no incluye espacios).
        'meta arte con " - Copia"' => [
            'ECU_DTC_TAD_AON_AON_TAD_LOY_EPA_FB-CONVERSION-AND-RMK-PURCHASE-ADVANTAGE',
            'IMG_MUL_STA_AON_LLA_FB-CONVERSION-AND-RMK-PURCHASE-ADVANTAGE-SP-11JUN-30JUN-SLIDE-24 - Copia',
        ],
        // Sin bloque FB-/TKT- -- no es Meta ni TikTok pagado, todo null.
        'sin plataforma (CRM)' => [
            'CRM_EMAIL_JULIO_PROMO',
            '',
        ],
        // Campaign sin bloque, Ad SÍ lo trae -- plataforma/funnel deben
        // salir del respaldo en Ad.
        'plataforma por respaldo en Ad' => [
            'CAMPANA_SIN_BLOQUE',
            'ALGO_FB-AWARENESS-BROAD-SP-01JUL-31JUL-UN-ARTE',
        ],
        // Casos reales de México (2026-08-12, encontrados diagnosticando por
        // qué 1,740 de 5,350 creativos tenían arte NULL) -- cubren los 3
        // patrones que ARTE_RE/bloqueRegex no contemplaban: guion bajo justo
        // antes del marcador de fecha, una sola fecha (sin rango), token
        // "AON" entre el marcador y la fecha, y el marcador CARO.
        'mexico guion bajo antes del marcador (CUPON-REFERIDOS)' => [
            'MEX_DTC_MOD_AON_AON_MOD_CNV_EPA_FB-CONVERSION-AND-RMK-INSTALL-CHECKBOX',
            'IMG_MUL_STA_AON_LLA_1_FB-CONVERSION-AND-RMK-INSTALL-CHECKBOX_SP-23JUL-1AGO-CUPON-REFERIDOS',
        ],
        'mexico fecha unica con token AON (CHEVE-CORONA)' => [
            'MEX_DTC_MOD_AON_AON_MOD_AWA_EPA_FB-AWARENESS-FOCO-TORREON',
            'VID_MUL_16_AON_LLA_1_FB-AWARENESS-FOCO-TORREON-VID-08MAY-AON-CHEVE-CORONA',
        ],
        'mexico token AON antes de fecha unica (CATALOGO-COMPLETO)' => [
            'MEX_DTC_MOD_AON_AON_MOD_CON_EPA_FB-ADVANTAGE-INSTALL-AND-VOLUME-BROAD',
            'CARO_MUL_STA_AON_LLA_1_FB-ADVANTAGE-INSTALL-AND-VOLUME-BROAD-MP-AON-03JUL-CATALOGO-COMPLETO',
        ],
        'mexico marcador CARO (SEMANA-CERVEZA-MULTIMARCA)' => [
            'MEX_DTC_MOD_AON_AON_MOD_CON_EPA_FB-ADVANTAGE-INSTALL-AND-VOLUME-BROAD',
            'CARO_MUL_STA_AON_LLA_1_FB-ADVANTAGE-INSTALL-AND-VOLUME-BROAD-CARO-31JUL-07AGO-SEMANA-CERVEZA-MULTIMARCA',
        ],
        // Sin bloque FB-/TKT- en ninguno de los dos, pero el Ad name YA ES
        // el bloque completo (TikTok con nombre corto, sin envoltorio de
        // campaña) -- arte debe resolverse igual aunque plataforma quede
        // null.
        'mexico sin bloque, ad name ya es el bloque (CRISTIAN-ONTIVEROS)' => [
            'CAMPANA_SIN_BLOQUE_NI_PREFIJO',
            'VID-01JUL-31JUL-CRISTIAN-ONTIVEROS',
        ],
    ];
}

function resultadosNode(array $pares): array
{
    $process = new Process(['node', rutaBridge(), rutaNodeParser()]);
    $process->setInput(json_encode(array_values($pares)));
    $process->mustRun();

    return json_decode($process->getOutput(), true);
}

it('produce exactamente el mismo resultado que el parser de Node para casos reales', function () {
    $casos = casosReales();
    $esperados = resultadosNode($casos);

    $i = 0;
    foreach ($casos as $nombre => [$campaign, $ad]) {
        $obtenido = ClasificadorNombres::parsearNombre($campaign, $ad);
        // 'formato' es un campo exclusivo de PHP (FORMATO_CODES, pedido del
        // negocio 2026-08-04) -- Node nunca lo implementó, así que se excluye
        // de la comparación de paridad; todo lo demás sí debe ser idéntico.
        unset($obtenido['formato']);
        expect($obtenido)->toBe($esperados[$i], "Caso: {$nombre}");
        $i++;
    }
})->skip(! is_file(rutaNodeParser()), 'No se encontró el proyecto Node de referencia en '.rutaNodeParser());

/**
 * REGEX_FUNNEL_SHEET -- fallback agregado 2026-09-17, exclusivo del puerto
 * PHP (no existe en el parser de Node, por eso va en un test aparte sin
 * comparación de paridad). Replica el regex de la pestaña "#1 Meta Ads" del
 * sheet de referencia de Panamá, y solo se alcanza cuando el segmento
 * explícito Y MAPA_CAMPANIA_META/TIKTOK ya fallaron -- los 9 nombres de
 * abajo son reales, sacados de los creativos de Panamá (pais_id=4) que
 * quedaban "Sin clasificar" antes de este cambio.
 */
it('clasifica por el regex del sheet cuando el segmento y el mapa de patrones no matchean', function () {
    $casos = [
        'FB-INSTALL-AGOSTO2025' => 'CONS',
        'FB-INSTALL-JUNIO2025' => 'CONS',
        'FB-ADVANTAGE-INSTALL-AND-EVENT' => 'CNV', // "EVENT" gana sobre "INSTALL": regla 3 antes que regla 4.
        'FB-ENGAGEMENT' => 'CNV',
        'TADA-COUT-FB-TADA-PA-CONV-3CO-ASC' => 'CNV',
        'FB-INSTALL-AND-EVENT' => 'CNV', // mismo caso "EVENT" gana sobre "INSTALL", nombre real distinto.
        'TADA-COUT-FB-TADA-PA-ADVANTAGE-AND-APP-VOLUME' => 'CONS',
    ];

    foreach ($casos as $bloque => $funnelEsperado) {
        $resultado = ClasificadorNombres::clasificarCampania($bloque, 'meta');
        expect($resultado['funnel'])->toBe($funnelEsperado, "Bloque: {$bloque}");
    }
});

it('el mapa de patrones existente sigue ganando antes de llegar al regex del sheet', function () {
    // "AWARENESS" y "PURCHASE" ya están en MAPA_CAMPANIA_META -- deben
    // resolverse ahí, sin depender del fallback nuevo (que da el mismo
    // funnel para estos dos casos puntuales, así que esta prueba no lo
    // distinguiría por resultado; lo que importa es que un patrón real de
    // negocio con contexto adicional, "BI-", que el regex del sheet no
    // reconoce en absoluto, siga resolviendo por el mapa).
    $resultado = ClasificadorNombres::clasificarCampania('BI-ADVANTAGE-AND-SHOPPING-BROAD', 'meta');
    expect($resultado['funnel'])->toBe('CONS')->and($resultado['patron'])->toBe('BI-');
});
