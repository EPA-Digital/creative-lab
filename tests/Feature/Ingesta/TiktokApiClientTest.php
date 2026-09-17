<?php

use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Support\Facades\Http;

/**
 * Cubre el retry/backoff agregado a TiktokApiClient (2026-08-11): mismo
 * criterio que MetaApiClient -- solo status 429/5xx/timeout se reintentan,
 * los errores de negocio propios de TikTok (envueltos en {code, message})
 * con HTTP 200 NO se reintentan (ver nota en el código: no hay evidencia
 * confirmada del código exacto de rate limit de TikTok).
 */
it('reintenta ante un 500 transitorio y devuelve el resultado del intento que sí funciona', function () {
    Http::fake([
        'business-api.tiktok.com/*' => Http::sequence()
            ->push(['code' => 50002, 'message' => 'Internal error'], 500)
            ->push(['code' => 0, 'message' => 'OK', 'data' => ['list' => []]], 200),
    ]);

    $cliente = new TiktokApiClient('token-de-prueba');
    $resultado = $cliente->get('/ad/get/', ['advertiser_id' => '1']);

    expect($resultado)->toBe(['list' => []]);
    Http::assertSentCount(2);
});

it('agota los reintentos y lanza el error real cuando la falla persiste', function () {
    Http::fake([
        'business-api.tiktok.com/*' => Http::response(['code' => 50002, 'message' => 'Internal error'], 500),
    ]);

    $cliente = new TiktokApiClient('token-de-prueba');

    expect(fn () => $cliente->get('/ad/get/', []))
        ->toThrow(RuntimeException::class, 'Internal error');

    Http::assertSentCount(4);
});

it('no reintenta un error de negocio de TikTok (code != 0 con HTTP 200) y preserva el mensaje real', function () {
    Http::fake([
        'business-api.tiktok.com/*' => Http::response(['code' => 40002, 'message' => 'Not a valid string', 'data' => []], 200),
    ]);

    $cliente = new TiktokApiClient('token-de-prueba');

    expect(fn () => $cliente->get('/ad/get/', []))
        ->toThrow(RuntimeException::class, 'Not a valid string');

    Http::assertSentCount(1);
});
