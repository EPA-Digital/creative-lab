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
// Lo común de TaDa: medios paid, medios sin API y eventos de AppsFlyer.
$tada = [
    'columnas' => [
        'nc' => 'Activity - Event Counter - First Order',
        'recompras' => 'Activity - Event Counter - Order Submitted',
    ],
    'fuente_paid' => 'TADA PERFORMANCE',
    'canales_paid' => [
        'Facebook Ads' => 'Facebook Ads',
        'googleadwords_int' => 'Google Ads',
        'Apple Search Ads' => 'Apple Search Ads',
        'tiktokglobal_int' => 'TikTok',
        'rtbhouse_int' => 'RTB House',
        'jampp_int' => 'Jampp',
    ],
    'medios_manuales' => [
        'Google Ads' => 'CNV',
        'Apple Search Ads' => 'CONS',
        'RTB House' => 'LOY',
        'Jampp' => 'CONS',
    ],
];

return [
    'ecuador' => [
        'reporte' => 'Ecuador | DATA WEEKLY',
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

    /*
     * Panamá -- pivot "Panamá | DATA WEEKLY". Trae la columna GEO: solo
     * cuentan las filas de Panama (el equipo borra los demás países y la
     * columna). Reglas comprobadas contra af.data.2026 de "TaDa PAN | Daily
     * Cuentas" (columna Partner); sus etiquetas son BRANDS y OTRO. A
     * diferencia de Ecuador, QR y SMS no son CRM, y lo orgánico de redes
     * (campañas con ORGANIC, google_organic_seo) es ORGANIC.
     */
    'panama' => [
        ...$tada,
        'reporte' => 'Panamá | DATA WEEKLY',
        'geo' => 'Panama',
        'reglas' => [
            ['si' => [['campania', 'empieza', 'CRM']], 'fuente' => 'CRM'],
            // Journey de onboarding (correo automático de primera compra).
            ['si' => [['campania', 'empieza', 'OB2_']], 'fuente' => 'CRM'],
            ['si' => [['campania', 'contiene', '_BRD_']], 'fuente' => 'BRANDS'],
            ['si' => [['campania', 'contiene', '_EPA_']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'TOPVIEW']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'MGM']], 'fuente' => 'MGM'],
            ['si' => [['media', 'igual', 'Organic']], 'fuente' => 'ORGANIC'],
            ['si' => [['media', 'igual', 'Facebook Ads'], ['campania', 'igual', 'None']], 'fuente' => 'ORGANIC'],
            ['si' => [['campania', 'contiene', 'ORGANIC']], 'fuente' => 'ORGANIC'],
            ['si' => [['media', 'igual', 'google_organic_seo']], 'fuente' => 'ORGANIC'],
        ],
        'por_defecto' => 'OTRO',
        'nombres_canal' => [
            'ORGANIC' => 'Organic',
            'OTRO' => 'Other',
            'BRANDS' => 'BI',
        ],
    ],

    /*
     * Perú -- pivot "Perú | DATA WEEKLY (ADSET) (copy)", con Adset y Adset ID
     * como columnas de agrupación. Reglas comprobadas contra ✅AF.DATA de
     * "TaDa PE | Daily Cuentas AON" (columna Partner, 2,229 filas, 0
     * diferencias). Jampp sin EPA es su propia fuente (JAMPP); lo orgánico
     * de buscadores y redes sin campaña es ORGANIC. Tinder (awareness) no
     * tiene media source en AppsFlyer: solo entra como inversión manual.
     */
    'peru' => [
        ...$tada,
        'reporte' => 'Perú | DATA WEEKLY (ADSET) (copy)',
        'adset' => true,
        // A diferencia de Ecuador, Perú quita Facebook Ads sin campaña (no
        // lo cuenta como orgánico): así lo limpia el equipo.
        'descartar' => [
            ['si' => [['media', 'igual', 'Facebook Ads'], ['campania', 'igual', 'None']]],
        ],
        'reglas' => [
            ['si' => [['campania', 'empieza', 'CRM']], 'fuente' => 'CRM'],
            ['si' => [['campania', 'contiene', '_BRD_']], 'fuente' => 'BRANDS'],
            ['si' => [['campania', 'contiene', '_EPA_']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'TOPVIEW']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'MGM']], 'fuente' => 'MGM'],
            ['si' => [['media', 'igual', 'jampp_int']], 'fuente' => 'JAMPP'],
            ['si' => [['media', 'igual', 'Organic']], 'fuente' => 'ORGANIC'],
            ['si' => [['media', 'contiene', 'organic_seo']], 'fuente' => 'ORGANIC'],
            ['si' => [['media', 'empieza', 'Social'], ['campania', 'igual', 'None']], 'fuente' => 'ORGANIC'],
        ],
        'por_defecto' => 'OTRO',
        'medios_manuales' => [
            'Google Ads' => 'CNV',
            'Apple Search Ads' => 'CONS',
            'RTB House' => 'LOY',
            'Jampp' => 'CONS',
            'Tinder' => 'AWA',
        ],
        'nombres_canal' => [
            'ORGANIC' => 'Organic',
            'OTRO' => 'Other',
            'BRANDS' => 'BI',
            'JAMPP' => 'Jampp (sin EPA)',
        ],
    ],

    // México (2026-11-18): reglas sacadas de su hoja (AF.DATA, columna
    // Partner) -- reproducen 138,790 de 138,879 filas de ene-nov 2026
    // (99.94%); las 89 restantes son casos que el equipo marcó a mano.
    // A diferencia de Ecuador, Email / SMS de México son OTRO (no CRM), salvo
    // las campañas que dicen CRM y LuisMiguelSMS. Pivot con adset, como Perú.
    'mexico' => [
        ...$tada,
        'reporte' => 'México | DATA WEEKLY (ADSET)',
        'adset' => true,
        'reglas' => [
            ['si' => [['campania', 'contiene', 'CRM']], 'fuente' => 'CRM'],
            ['si' => [['media', 'igual', 'LuisMiguelSMS']], 'fuente' => 'CRM'],
            ['si' => [['campania', 'empieza', 'MGM']], 'fuente' => 'MGM'],
            ['si' => [['campania', 'contiene', '_EPA_']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'TOPVIEW']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'MEX_DTC_']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['campania', 'empieza', 'SOC_']], 'fuente' => 'TADA PERFORMANCE'],
            ['si' => [['media', 'igual', 'Organic']], 'fuente' => 'ORGANIC'],
            ['si' => [['campania', 'igual', 'None']], 'fuente' => 'ORGANIC'],
        ],
        'por_defecto' => 'OTRO',
        'nombres_canal' => [
            'ORGANIC' => 'Organic',
            'OTRO' => 'Other',
        ],
    ],
];
