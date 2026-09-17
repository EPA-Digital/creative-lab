<?php

use App\Services\Ingesta\EnriquecedorCostosMeta;
use App\Services\Ingesta\ImagenCacheService;
use App\Services\Ingesta\MetaApiClient;
use Illuminate\Support\Facades\Http;

/**
 * Cubre el fix de 2026-08-12: una tanda de 50 ads que Meta rechaza completa
 * (confirmado en logs reales: "500 Please reduce the amount of data you're
 * asking for") ya NO pierde los 50 ads enteros -- se parte a la mitad y se
 * reintenta cada mitad, recursivo, hasta un piso de 5.
 */
function adMeta(string $id, string $status, string $imagen): array
{
    return ['id' => $id, 'effective_status' => $status, 'creative' => ['image_url' => $imagen]];
}

it('recupera la mitad liviana de una tanda cuando Meta rechaza la tanda completa por "reduce the amount of data"', function () {
    // 6 ads: se parte en 3+3, la primera mitad (3, bajo el piso de 5) queda
    // marcada como perdida solo si también falla -- acá hacemos que la
    // primera mitad SÍ funcione y la segunda siga fallando hasta el piso.
    $adIds = ['1', '2', '3', '4', '5', '6'];

    $body500 = ['error' => ['message' => "Please reduce the amount of data you're asking for, then retry your request"]];

    Http::fake([
        'graph.facebook.com/*/act_1/ads*' => Http::sequence()
            // tanda completa de 6 -- falla los 4 intentos (1 inicial + 3
            // reintentos del retry/backoff ya existente en MetaApiClient)
            // antes de que procesarTandaAds la parta a la mitad.
            ->push($body500, 500)->push($body500, 500)->push($body500, 500)->push($body500, 500)
            // mitad 1 (3 ads) -- funciona al primer intento
            ->push(['data' => [adMeta('1', 'ACTIVE', 'https://img/1.jpg'), adMeta('2', 'ACTIVE', 'https://img/2.jpg'), adMeta('3', 'ACTIVE', 'https://img/3.jpg')]])
            // mitad 2 (3 ads) -- sigue fallando los 4 intentos; 3 <= piso de
            // 5, así que ahí sí se da por vencida (no se parte más).
            ->push($body500, 500)->push($body500, 500)->push($body500, 500)->push($body500, 500),
    ]);

    $enriquecedor = new EnriquecedorCostosMeta(new MetaApiClient('token'), new ImagenCacheService());
    [$status, $imagenRemota] = $enriquecedor->reintentarImagenYCopy('1', $adIds);

    expect($status)->toBe(['1' => 'ACTIVE', '2' => 'ACTIVE', '3' => 'ACTIVE']);
    expect($imagenRemota)->toBe(['1' => 'https://img/1.jpg', '2' => 'https://img/2.jpg', '3' => 'https://img/3.jpg']);
});

it('cuando la tanda completa funciona, no se parte en absoluto (comportamiento actual sin cambios)', function () {
    Http::fake([
        'graph.facebook.com/*/act_1/ads*' => Http::response([
            'data' => [adMeta('1', 'ACTIVE', 'https://img/1.jpg'), adMeta('2', 'PAUSED', 'https://img/2.jpg')],
        ]),
    ]);

    $enriquecedor = new EnriquecedorCostosMeta(new MetaApiClient('token'), new ImagenCacheService());
    [$status] = $enriquecedor->reintentarImagenYCopy('1', ['1', '2']);

    expect($status)->toBe(['1' => 'ACTIVE', '2' => 'PAUSED']);
    Http::assertSentCount(1);
});
