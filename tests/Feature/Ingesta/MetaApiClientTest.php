<?php

use App\Services\Ingesta\MetaApiClient;
use Illuminate\Support\Facades\Http;

/**
 * Cubre el retry/backoff agregado a MetaApiClient (2026-08-11): fallas
 * transitorias (429/5xx/timeout) se reintentan hasta 3 veces antes de
 * rendirse; errores no transitorios (400 con mensaje de negocio real) NO se
 * reintentan y siguen preservando el mensaje real de Graph API, sin volverse
 * un "falló" genérico.
 */
it('reintenta ante un 500 transitorio y devuelve el resultado del intento que sí funciona', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Please retry']], 500)
            ->push(['data' => [['id' => '123']]], 200),
    ]);

    $cliente = new MetaApiClient('token-de-prueba');
    $resultado = $cliente->get('act_1/insights', ['fields' => 'ad_id']);

    expect($resultado)->toBe(['data' => [['id' => '123']]]);
    Http::assertSentCount(2);
});

it('agota los reintentos y lanza el error real cuando la falla persiste', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 500),
    ]);

    $cliente = new MetaApiClient('token-de-prueba');

    expect(fn () => $cliente->get('act_1/insights', []))
        ->toThrow(RuntimeException::class, 'Rate limit reached');

    Http::assertSentCount(4); // intento inicial + 3 reintentos
});

it('no reintenta un error de negocio no transitorio (400) y preserva el mensaje real', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter']], 400),
    ]);

    $cliente = new MetaApiClient('token-de-prueba');

    expect(fn () => $cliente->get('act_1/insights', []))
        ->toThrow(RuntimeException::class, 'Invalid parameter');

    Http::assertSentCount(1);
});
