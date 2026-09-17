<?php

use App\Services\Ingesta\EnriquecedorCostosTiktok;
use App\Services\Ingesta\ImagenCacheService;
use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Support\Facades\Http;

/**
 * Cubre el fix de 2026-08-12: una página que falla en /ad/get/ (paginado)
 * ya NO debe perder nombre/video_id/campaña/copy de la cuenta ENTERA --
 * antes el try/catch envolvía el loop completo (confirmado con datos reales:
 * México, con muchas más páginas que Ecuador, tenía 464 ads con
 * formato=null contra 18 de Ecuador).
 */
function adTiktok(string $id, string $nombre): array
{
    return ['ad_id' => $id, 'ad_name' => $nombre, 'video_id' => "video-{$id}", 'campaign_name' => 'CAMP', 'ad_text' => 'texto'];
}

it('preserva nombre/video de las páginas que sí funcionan aunque una página del medio falle', function () {
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/ad/get/*' => Http::sequence()
            ->push(['code' => 0, 'message' => 'OK', 'data' => ['list' => [adTiktok('1', 'Pagina Uno')], 'page_info' => ['total_page' => 3]]])
            // página 2: agota los 4 intentos (1 inicial + 3 reintentos) del
            // retry/backoff ya existente en TiktokApiClient antes de darse
            // por vencida de verdad.
            ->push(['code' => 50002, 'message' => 'Internal error'], 500)
            ->push(['code' => 50002, 'message' => 'Internal error'], 500)
            ->push(['code' => 50002, 'message' => 'Internal error'], 500)
            ->push(['code' => 50002, 'message' => 'Internal error'], 500)
            ->push(['code' => 0, 'message' => 'OK', 'data' => ['list' => [adTiktok('3', 'Pagina Tres')], 'page_info' => ['total_page' => 3]]]),
    ]);

    $enriquecedor = new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService());
    $metodo = new ReflectionMethod(EnriquecedorCostosTiktok::class, 'traerNombreYVideoId');
    $metodo->setAccessible(true);
    [$nombrePorAdId] = $metodo->invoke($enriquecedor, 'adv-1');

    expect($nombrePorAdId)->toBe(['1' => 'Pagina Uno', '3' => 'Pagina Tres']);
});

it('corta el loop si la PRIMERA página falla (no se conoce total_page, no hay forma segura de seguir)', function () {
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/ad/get/*' => Http::response(['code' => 50002, 'message' => 'Internal error'], 500),
    ]);

    $enriquecedor = new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService());
    $metodo = new ReflectionMethod(EnriquecedorCostosTiktok::class, 'traerNombreYVideoId');
    $metodo->setAccessible(true);
    [$nombrePorAdId] = $metodo->invoke($enriquecedor, 'adv-1');

    expect($nombrePorAdId)->toBe([]);
    // 1 intento inicial + 3 reintentos del retry/backoff ya existente en TiktokApiClient.
    Http::assertSentCount(4);
});
