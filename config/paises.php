<?php

// Mirror de config/paises.js del proyecto Node (referencia) -- única fuente
// de verdad de qué países existen y su cuenta de Meta/TikTok. Solo Ecuador
// tiene meta_ad_account_id/tiktok_advertiser_id reales; México/Panamá/Perú
// son stubs (habilitado => false) hasta que haya cuenta real que conectar
// en cada plataforma. Los IDs de cuenta viven acá (config), no en la tabla
// `paises` de la base -- son config operativa, no dato de negocio del
// esquema que ya se aprobó.
//
// bandera_gradiente/bandera_colores son la única fuente de verdad visual del
// selector de país (Landing.vue) -- portados literal de config/paises.js.

return [
    'ecuador' => [
        'codigo' => 'EC',
        'nombre' => 'Ecuador',
        'habilitado' => true,
        'meta_ad_account_id' => '913224553013929',
        'tiktok_advertiser_id' => '7332930135332749314',
        'bandera_gradiente' => 'linear-gradient(to bottom, #FFD100 0% 50%, #034EA2 50% 75%, #EF3340 75% 100%)',
        'bandera_colores' => ['#FFD100', '#034EA2', '#EF3340'],
    ],
    'mexico' => [
        'codigo' => 'MX',
        'nombre' => 'México',
        'habilitado' => false,
        'meta_ad_account_id' => null,
        'tiktok_advertiser_id' => null,
        'bandera_gradiente' => 'linear-gradient(to right, #006341 0% 33.3%, #FFFFFF 33.3% 66.6%, #CE1126 66.6% 100%)',
        'bandera_colores' => ['#006341', '#FFFFFF', '#CE1126'],
    ],
    'panama' => [
        'codigo' => 'PA',
        'nombre' => 'Panamá',
        'habilitado' => false,
        'meta_ad_account_id' => null,
        'tiktok_advertiser_id' => null,
        'bandera_gradiente' => 'conic-gradient(from 0deg, #D21034 0deg 90deg, #FFFFFF 90deg 180deg, #001489 180deg 270deg, #FFFFFF 270deg 360deg)',
        'bandera_colores' => ['#D21034', '#001489'],
    ],
    'peru' => [
        'codigo' => 'PE',
        'nombre' => 'Perú',
        'habilitado' => false,
        'meta_ad_account_id' => null,
        'tiktok_advertiser_id' => null,
        'bandera_gradiente' => 'linear-gradient(to right, #D91023 0% 25%, #FFFFFF 25% 75%, #D91023 75% 100%)',
        'bandera_colores' => ['#D91023', '#FFFFFF'],
    ],
];
