<?php

/*
 * Costos desde la API de Meta y TikTok (2026-11-26). El comando
 * costos:sincronizar-api corre cada `intervalo_minutos` (Cloud Scheduler en
 * producción, schedule de Laravel en local) y vuelve a traer los últimos
 * `dias` días: las plataformas siguen ajustando el gasto de días pasados.
 */
return [
    'intervalo_minutos' => (int) env('COSTOS_API_INTERVALO_MINUTOS', 60),
    // Minuto de cada hora en que arranca (Cloud Scheduler: "10 * * * *").
    'minuto' => (int) env('COSTOS_API_MINUTO', 10),
    'dias' => (int) env('COSTOS_API_DIAS', 3),
];
