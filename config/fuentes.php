<?php

/*
 * Limpieza del export de AppsFlyer por país (vista Fuentes, 2026-11-13).
 * Cada país limpia distinto; por ahora solo Ecuador.
 *
 * Ecuador -- AppsFlyer → Analyze → Pivot → "Ecuador | DATA WEEKLY", CSV
 * (Install Day, App Name, Agency, Media Source, Campaign, Campaign ID,
 * Installs y contadores de eventos). Reglas explicadas por el equipo y
 * comprobadas contra su hoja "TaDa ECU | Daily Cuentas" → af.data.2026.
 *
 * - columnas: encabezado del export (sin " (sum)") de NC y recompras.
 *   Órdenes = NC + recompras.
 * - reglas: "Fuente final", gana la primera que se cumple; todas sus
 *   condiciones deben cumplirse. Campo: campania | media. Operador:
 *   empieza | contiene | igual (sin distinguir mayúsculas).
 * - canales_paid: dentro de la fuente paid, el canal es la media source.
 *   Fuera de ella el canal es la propia fuente (nombres_canal la renombra).
 */
return [
    'ecuador' => [
        'columnas' => [
            'nc' => 'Activity - Event Counter - First Order',
            'recompras' => 'Activity - Event Counter - Order Submitted',
        ],
        'fuente_paid' => 'TADA PERFORMANCE',
        'reglas' => [
            ['si' => [['campania', 'empieza', 'CRM']], 'fuente' => 'CRM'],
            ['si' => [['campania', 'contiene', 'ECU_BRD']], 'fuente' => 'BI'],
            ['si' => [['campania', 'contiene', '_EPA_']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'TOPVIEW']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'MGM']], 'fuente' => 'MGM'],
            ['si' => [['media', 'igual', 'Organic']], 'fuente' => 'ORGANIC'],
            ['si' => [['media', 'igual', 'QR_code']], 'fuente' => 'CRM'],
            ['si' => [['media', 'igual', 'SMS']], 'fuente' => 'CRM'],
            ['si' => [['media', 'igual', 'Facebook Ads'], ['campania', 'igual', 'None']], 'fuente' => 'ORGANIC'],
        ],
        'por_defecto' => 'OTHER',
        'canales_paid' => [
            'Facebook Ads' => 'Facebook Ads',
            'googleadwords_int' => 'Google Ads',
            'Apple Search Ads' => 'Apple Search Ads',
            'tiktokglobal_int' => 'TikTok',
            'rtbhouse_int' => 'RTB House',
            'jampp_int' => 'Jampp',
        ],
        // Medios sin API: su inversión se captura a mano en Fuentes, con la
        // etapa del funnel a la que suma (ASA = Consideración, como en la hoja
        // fuentes.2026).
        'medios_manuales' => [
            'Google Ads' => 'CNV',
            'Apple Search Ads' => 'CONS',
            'RTB House' => 'LOY',
            'Jampp' => 'CONS',
        ],
        'nombres_canal' => [
            'ORGANIC' => 'Organic',
            'OTHER' => 'Other',
        ],
    ],
];
