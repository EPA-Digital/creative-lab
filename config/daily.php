<?php

/*
 * Daily (2026-11-17, pedido explícito): qué Dailies tiene cada país. Paid
 * es el de performance (todo lo que no es _BRD_); BI es el de marcas
 * (campañas _BRD_), por ahora solo Ecuador. Un país que no está aquí solo
 * tiene Paid.
 */
return [
    'tipos' => [
        'paid' => ['nombre' => 'Paid', 'marca' => false],
        'bi' => ['nombre' => 'BI', 'marca' => true],
    ],
    'paises' => [
        'ecuador' => ['paid', 'bi'],
    ],
];
