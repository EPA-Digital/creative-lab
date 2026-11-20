<?php

/*
 * Optimizaciones (2026-11-18, pedido explícito): tipos unificados de la
 * bitácora "optimizaciones.2026" / "✅OPTIS" de los Daily Cuentas. En las
 * hojas cada país los escribe un poco distinto ("Creacion de Campana",
 * "Creación de Campaña"); al importar se traducen por estas palabras clave
 * (sin acentos ni mayúsculas, gana la primera que coincide).
 *
 * - grupo: crear | ajustar | apagar | pautar (color en la vista).
 */
return [
    // Mismo nombre escrito distinto (2026-11-18: "hay solo una Fer en el
    // team"). Sin distinguir mayúsculas ni acentos; con varios nombres
    // ("Fer, Diana") se traduce cada uno.
    'responsables' => [
        'fer' => 'Fernanda Ochoa',
    ],

    // Nombres en Meta de gente del equipo que no tiene usuario @epa.digital
    // en el dashboard (para no alertar sus campañas como externas).
    'equipo_meta' => [],

    'tipos' => [
        'creacion_campania' => ['nombre' => 'Creación de campaña', 'grupo' => 'crear', 'claves' => ['creacion de campa']],
        'creacion_grupo' => ['nombre' => 'Creación de grupo de anuncios', 'grupo' => 'crear', 'claves' => ['creacion de grupo']],
        'creacion_anuncios' => ['nombre' => 'Creación de anuncios', 'grupo' => 'crear', 'claves' => ['creacion de anuncio']],
        'activacion_campania' => ['nombre' => 'Activación de campaña', 'grupo' => 'crear', 'claves' => ['activacion']],
        'pauta_anuncios' => ['nombre' => 'Pauta de anuncios', 'grupo' => 'pautar', 'claves' => ['pauta']],
        'ajuste_dinero' => ['nombre' => 'Ajuste de dinero', 'grupo' => 'ajustar', 'claves' => ['ajuste de dinero', 'presupuesto', 'ppto']],
        'ajuste_anuncios' => ['nombre' => 'Ajuste de anuncios', 'grupo' => 'ajustar', 'claves' => ['ajuste de anuncio', 'optimizacion de anuncio']],
        'ajuste_estrategia' => ['nombre' => 'Ajuste de estrategia', 'grupo' => 'ajustar', 'claves' => ['estrategia']],
        'ajuste_metas' => ['nombre' => 'Ajuste de metas', 'grupo' => 'ajustar', 'claves' => ['meta']],
        'ajuste_optimizacion' => ['nombre' => 'Ajuste / optimización', 'grupo' => 'ajustar', 'claves' => ['ajuste', 'optimiz']],
        'apagado_campania' => ['nombre' => 'Apagado de campaña', 'grupo' => 'apagar', 'claves' => ['apagado de campa']],
        'apagado_grupo' => ['nombre' => 'Apagado de grupo de anuncios', 'grupo' => 'apagar', 'claves' => ['apagado de grupo']],
        'apagado_anuncio' => ['nombre' => 'Apagado de anuncio', 'grupo' => 'apagar', 'claves' => ['apagado de anuncio', 'apagado']],
        'otro' => ['nombre' => 'Otro', 'grupo' => 'otro', 'claves' => []],
    ],
];
