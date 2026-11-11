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

    $enriquecedor = new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService);
    $metodo = new ReflectionMethod(EnriquecedorCostosTiktok::class, 'traerNombreYVideoId');
    $metodo->setAccessible(true);
    [$nombrePorAdId] = $metodo->invoke($enriquecedor, 'adv-1');

    expect($nombrePorAdId)->toBe(['1' => 'Pagina Uno', '3' => 'Pagina Tres']);
});

it('corta el loop si la PRIMERA página falla (no se conoce total_page, no hay forma segura de seguir)', function () {
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/ad/get/*' => Http::response(['code' => 50002, 'message' => 'Internal error'], 500),
    ]);

    $enriquecedor = new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService);
    $metodo = new ReflectionMethod(EnriquecedorCostosTiktok::class, 'traerNombreYVideoId');
    $metodo->setAccessible(true);
    [$nombrePorAdId] = $metodo->invoke($enriquecedor, 'adv-1');

    expect($nombrePorAdId)->toBe([]);
    // 1 intento inicial + 3 reintentos del retry/backoff ya existente en TiktokApiClient.
    Http::assertSentCount(4);
});

/**
 * Caso real Panamá sep-2026: TikTok deja fuera del reporte y de /ad/get/ los
 * ads ELIMINADOS salvo que se pida STATUS_ALL -- se veían $207 de $4,982.
 */
it('pide también los ads eliminados en el gasto y en los nombres', function () {
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/report/integrated/get/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => [
            'list' => [['dimensions' => ['ad_id' => '1875987818230354'], 'metrics' => ['spend' => '222.70', 'impressions' => '1000', 'clicks' => '10']]],
            'page_info' => ['total_page' => 1],
        ]]),
        'business-api.tiktok.com/open_api/v1.3/ad/get/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [adTiktok('1875987818230354', 'ATLAS-2-CAJAS')], 'page_info' => ['total_page' => 1]]]),
    ]);

    $enriquecedor = new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService);
    $costos = (new ReflectionMethod(EnriquecedorCostosTiktok::class, 'traerCostos'))->invoke($enriquecedor, 'adv-1', '2026-09-01', '2026-09-30');
    [$nombres] = (new ReflectionMethod(EnriquecedorCostosTiktok::class, 'traerNombreYVideoId'))->invoke($enriquecedor, 'adv-1', ['1875987818230354']);

    expect($costos[0]['cost'])->toBe(222.70)
        ->and($nombres)->toBe(['1875987818230354' => 'ATLAS-2-CAJAS']);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'report/integrated')
        && json_decode($req['filtering'], true)[0] === ['field_name' => 'ad_status', 'filter_type' => 'IN', 'filter_value' => '["STATUS_ALL"]']);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'ad/get')
        && json_decode($req['filtering'], true)['primary_status'] === 'STATUS_ALL');
});

it('detectarEliminados sigue sin pedir STATUS_ALL (depende de que TikTok omita los eliminados)', function () {
    Http::fake(['business-api.tiktok.com/open_api/v1.3/ad/get/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => []]])]);

    $eliminados = (new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService))->detectarEliminados('adv-1', ['111']);

    expect($eliminados)->toBe(['111']);
    Http::assertSent(fn ($req) => ! array_key_exists('primary_status', json_decode($req['filtering'], true)));
});

it('junta el reporte diario de TikTok por anuncio y no guarda los días en 0', function () {
    Http::fake(['business-api.tiktok.com/open_api/v1.3/report/integrated/get/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => [
        'list' => [
            ['dimensions' => ['ad_id' => '7', 'stat_time_day' => '2026-10-01 00:00:00'], 'metrics' => ['spend' => '5.5', 'impressions' => '10', 'clicks' => '1']],
            ['dimensions' => ['ad_id' => '7', 'stat_time_day' => '2026-10-02 00:00:00'], 'metrics' => ['spend' => '0', 'impressions' => '0', 'clicks' => '0']],
            ['dimensions' => ['ad_id' => '7', 'stat_time_day' => '2026-10-03 00:00:00'], 'metrics' => ['spend' => '4.5', 'impressions' => '30', 'clicks' => '2']],
        ],
        'page_info' => ['total_page' => 1],
    ]])]);

    $enriquecedor = new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService);
    $filas = (new ReflectionMethod(EnriquecedorCostosTiktok::class, 'traerCostos'))->invoke($enriquecedor, 'adv-1', '2026-10-01', '2026-10-31');

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['cost'])->toBe(10.0)
        ->and(array_keys($filas[0]['dias']))->toBe(['2026-10-01', '2026-10-03']);
    Http::assertSent(fn ($req) => json_decode($req['dimensions'], true) === ['ad_id', 'stat_time_day']);
});
