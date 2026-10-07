<?php

use App\Jobs\CachearImagenesCreativos;
use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Models\Importacion;
use App\Models\Pais;
use App\Models\TareaMedios;
use App\Models\User;
use App\Services\Ingesta\EnriquecedorCostosTiktok;
use App\Services\Ingesta\ImagenCacheService;
use App\Services\Ingesta\ImportadorDatos;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Optimización de la importación (pedido explícito 2026-10-06, Perú 20+
 * minutos atorada al 19%): imágenes en background, solo de los ads del mes,
 * sin re-descargar las que ya están en el bucket, Meta sin reintentar
 * "reduce the amount of data", e importaciones colgadas marcadas como error.
 */
uses(RefreshDatabase::class);

it('Meta no reintenta "reduce the amount of data" (siempre falla igual)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => "Please reduce the amount of data you're asking for, then retry your request"]], 500)]);

    expect(fn () => (new MetaApiClient('token'))->get('act_1/ads', []))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('Meta sí reintenta un 500 transitorio', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['message' => 'An unknown error occurred']], 500)->push(['data' => []])]);

    expect((new MetaApiClient('token'))->get('act_1/ads', []))->toBe(['data' => []]);
    Http::assertSentCount(2);
});

it('TikTok solo busca nombre y portada de los ads con actividad, y no descarga imágenes', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/report/integrated/get/')) {
            return Http::response(['code' => 0, 'data' => ['page_info' => ['total_page' => 1], 'list' => [
                ['dimensions' => ['ad_id' => '1'], 'metrics' => ['spend' => '10', 'impressions' => '100', 'clicks' => '2']],
                ['dimensions' => ['ad_id' => '2'], 'metrics' => ['spend' => '0', 'impressions' => '0', 'clicks' => '0']],
            ]]]);
        }
        if (str_contains($request->url(), '/ad/get/')) {
            return Http::response(['code' => 0, 'data' => ['list' => [['ad_id' => '1', 'ad_name' => 'VID-UNO', 'video_id' => 'v1', 'campaign_name' => 'CAMP']], 'page_info' => ['total_page' => 1]]]);
        }

        return Http::response(['code' => 0, 'data' => ['list' => [['video_id' => 'v1', 'video_cover_url' => 'https://cdn.tiktok/v1.jpg']]]]);
    });

    $filas = (new EnriquecedorCostosTiktok(new TiktokApiClient('token'), new ImagenCacheService))->enriquecer('adv', '2026-09-01', '2026-09-30');

    expect(collect($filas)->firstWhere('adId', '1'))->toMatchArray(['adName' => 'VID-UNO', 'imageUrl' => 'https://cdn.tiktok/v1.jpg']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/ad/get/') && str_contains(urldecode($r->url()), '"ad_ids":["1"]'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cdn.tiktok'));
});

it('el Job de imágenes guarda en el bucket y actualiza el creativo', function () {
    Storage::fake('gcs');
    Http::fake(['cdn.meta/*' => Http::response('jpgbytes', 200, ['Content-Type' => 'image/jpeg'])]);
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    $c = Creativo::create(['pais_id' => $pe->id, 'ad_id' => '9', 'nombre_completo' => 'X', 'plataforma' => 'meta', 'tipo_cuenta' => 'DTC']);

    (new CachearImagenesCreativos([$c->id => ['url' => 'https://cdn.meta/9.jpg', 'nombre' => 'meta-costo-9']]))->handle(new ImagenCacheService);

    expect($c->fresh()->imagen_url)->toBe('https://storage.googleapis.com/test-bucket/creative-images/meta-costo-9.jpg');
    Storage::disk('gcs')->assertExists('creative-images/meta-costo-9.jpg');
});

it('la importación encola solo las imágenes que todavía no están en el bucket', function () {
    Queue::fake();
    // Videos fuera de este test (el fixture trae creativos de video de
    // Meta, que también cuentan en la tarea) -- acá solo importan imágenes.
    config(['services.meta.access_token' => 't', 'services.tiktok.access_token' => 't', 'videos.top_por_mes' => 0]);
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    CuentaPublicitaria::create(['pais_id' => $pe->id, 'plataforma' => 'meta', 'cuenta_id' => '111', 'tipo' => 'tada']);
    // Un ad del fixture que YA tiene imagen en el bucket.
    Creativo::create(['pais_id' => $pe->id, 'ad_id' => '120243455653870661', 'nombre_completo' => 'X', 'plataforma' => 'meta', 'tipo_cuenta' => 'DTC', 'imagen_url' => 'https://storage.googleapis.com/test-bucket/creative-images/meta-costo-120243455653870661.jpg']);

    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $fields = $q['fields'] ?? '';
        if ($fields === 'ad_id,ad_name,campaign_name,spend,impressions,inline_link_clicks') {
            return Http::response(['data' => [
                ['ad_id' => '120243455653870661', 'ad_name' => 'A', 'campaign_name' => 'ECU_DTC_TAD_X_FB-CONVERSION', 'spend' => '5', 'impressions' => '50', 'inline_link_clicks' => '1'],
                ['ad_id' => '555', 'ad_name' => 'B', 'campaign_name' => 'ECU_DTC_TAD_X_FB-CONVERSION', 'spend' => '7', 'impressions' => '70', 'inline_link_clicks' => '1'],
            ]]);
        }
        if (str_starts_with($fields, 'id,effective_status')) {
            return Http::response(['data' => [
                ['id' => '120243455653870661', 'effective_status' => 'ACTIVE', 'creative' => ['image_url' => 'https://cdn.meta/a.jpg']],
                ['id' => '555', 'effective_status' => 'ACTIVE', 'creative' => ['image_url' => 'https://cdn.meta/b.jpg']],
            ]]);
        }
        $ids = json_decode($q['filtering'] ?? '[]', true)[0]['value'] ?? [];

        return Http::response(['data' => array_map(fn ($id) => ['ad_id' => $id, 'impressions' => 1, 'spend' => 1], $ids)]);
    });

    ImportadorDatos::importar(__DIR__.'/../../Fixtures/appsflyer-data-5.csv', 'peru', '2026-07-01', '2026-07-31', null, null, null, null);

    Queue::assertPushed(CachearImagenesCreativos::class, function (CachearImagenesCreativos $job) {
        $urls = array_column($job->pendientes, 'url');
        $tarea = TareaMedios::find($job->tareaId);

        // La tarea de seguimiento (aviso "ya están las imágenes") cuenta
        // exactamente lo que se encoló.
        return $urls === ['https://cdn.meta/b.jpg'] && $tarea?->total === 1 && $tarea->mes === '2026-07';
    });
    expect(Creativo::where('ad_id', '555')->value('imagen_url'))->toBeNull('nunca se guarda la URL remota');
});

it('una importación "procesando" de hace más de 70 minutos se marca como error', function () {
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    $user = User::factory()->create(['rol' => 'gerente']);
    $user->paises()->attach($pe->id);
    $imp = Importacion::create(['pais_id' => $pe->id, 'origen' => 'csv', 'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'estado' => 'procesando']);
    $this->travel(71)->minutes();

    $this->actingAs($user)->getJson("/pais/peru/importar/estado/{$imp->id}")
        ->assertStatus(422)
        ->assertJsonPath('estado', 'error')
        ->assertJsonPath('error', fn (string $e) => str_contains($e, 'tardó más de 70 minutos'));
    expect($imp->fresh()->estado)->toBe('error');
});

it('Ajustes muestra las campañas del país con su tipo y gasto', function () {
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    $user = User::factory()->create(['rol' => 'gerente']);
    $user->paises()->attach($pe->id);
    foreach ([['1', 'PER_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONVERSION', 100], ['2', 'PER_DTC_MLM_AON_AON_NON_CON_EPA_TKT-INSTALL', 40]] as [$id, $camp, $costo]) {
        $c = Creativo::create(['pais_id' => $pe->id, 'ad_id' => $id, 'nombre_completo' => 'X', 'nombre_campania' => $camp, 'plataforma' => 'meta', 'tipo_cuenta' => 'DTC', 'funnel' => 'CNV']);
        $c->resultados()->create(['mes' => '2026-09', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30', 'cost' => $costo, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'reorders' => 0]);
    }

    $this->actingAs($user)->get('/pais/peru/ajustes')
        ->assertInertia(fn ($page) => $page
            ->has('campanias', 2)
            ->where('campanias.0.campania', 'PER_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONVERSION')
            ->where('campanias.0.paid', true)
            ->where('campanias.1.paid', false));
});
