<?php

use App\Services\Ingesta\ImagenCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Cubre el fix de 2026-08-12: timeout(45)+retry(2,1000) agregado a las
 * descargas del pool -- confirmado en logs reales 44 timeouts de CDN de
 * TikTok sin ningún retry hasta ahora.
 */
it('recupera una descarga que falla transitoriamente gracias al retry', function () {
    Storage::fake('gcs');
    Http::fake([
        'cdn.ejemplo.com/1.jpg' => Http::sequence()
            ->push('', 500) // falla transitoria
            ->push('contenido-de-imagen', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $servicio = new ImagenCacheService;
    $resultado = $servicio->cachearVarias(['ad1' => 'https://cdn.ejemplo.com/1.jpg'], fn ($id) => "test-{$id}");

    expect($resultado['ad1'])->toBe('https://storage.googleapis.com/test-bucket/creative-images/test-ad1.jpg');
    Storage::disk('gcs')->assertExists('creative-images/test-ad1.jpg');
});

it('si la descarga falla del todo, conserva la URL remota (nunca queda vacío)', function () {
    Http::fake([
        'cdn.ejemplo.com/2.jpg' => Http::response('', 500),
    ]);

    $servicio = new ImagenCacheService;
    $resultado = $servicio->cachearVarias(['ad2' => 'https://cdn.ejemplo.com/2.jpg'], fn ($id) => "test-{$id}");

    expect($resultado['ad2'])->toBe('https://cdn.ejemplo.com/2.jpg');
});
