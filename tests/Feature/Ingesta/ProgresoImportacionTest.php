<?php

use App\Jobs\ProcesarImportacionApi;
use App\Models\AppsflyerApp;
use App\Models\CuentaPublicitaria;
use App\Models\Importacion;
use App\Models\Pais;
use App\Models\User;
use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Progreso de importación (pedido explícito 2026-10-06): el pipeline
 * reporta avance real, el Job lo guarda en importaciones.progreso/etapa y
 * el panel lo lee con polling. "Por API" pasa a correr en background igual
 * que el CSV.
 */
uses(RefreshDatabase::class);

function paisPeruConCuentas(): Pais
{
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    CuentaPublicitaria::create(['pais_id' => $pe->id, 'plataforma' => 'meta', 'cuenta_id' => '111', 'nombre' => 'Perú Meta', 'tipo' => 'tada']);
    CuentaPublicitaria::create(['pais_id' => $pe->id, 'plataforma' => 'tiktok', 'cuenta_id' => '222', 'nombre' => 'Perú TikTok', 'tipo' => 'tada']);

    return $pe;
}

it('"Por API" encola un Job y responde al instante con el id', function () {
    Queue::fake();
    $pe = paisPeruConCuentas();
    AppsflyerApp::create(['pais_id' => $pe->id, 'plataforma' => 'android', 'app_id' => 'pe.app']);
    $user = User::factory()->create(['rol' => 'superadmin']);
    $user->paises()->attach($pe->id);

    $r = $this->actingAs($user)->postJson('/pais/peru/importar/api', ['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'nc_total_real_meta' => 100])
        ->assertStatus(202)
        ->assertJsonPath('estado', 'procesando');

    Queue::assertPushed(ProcesarImportacionApi::class, fn ($job) => $job->importacionId === $r->json('importacionId') && $job->paisSlug === 'peru');
    $this->assertDatabaseHas('importaciones', ['id' => $r->json('importacionId'), 'estado' => 'procesando', 'origen' => 'appsflyer_api']);
});

it('el estado de una importación en curso devuelve el avance', function () {
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    $user = User::factory()->create(['rol' => 'gerente']);
    $user->paises()->attach($pe->id);
    $imp = Importacion::create(['pais_id' => $pe->id, 'origen' => 'csv', 'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'estado' => 'procesando', 'progreso' => 42, 'etapa' => 'Guardando creativos de Meta: 40 de 90']);

    $this->actingAs($user)->getJson("/pais/peru/importar/estado/{$imp->id}")
        ->assertOk()
        ->assertJson(['estado' => 'procesando', 'progreso' => 42, 'etapa' => 'Guardando creativos de Meta: 40 de 90']);
});

it('el reportero escribe como mucho una vez por segundo por etapa', function () {
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    $imp = Importacion::create(['pais_id' => $pe->id, 'origen' => 'csv', 'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'estado' => 'procesando']);
    $reportar = Importacion::reporteroDeProgreso($imp->id);

    $reportar(40, 'Guardando creativos de Meta: 20 de 100');
    $reportar(45, 'Guardando creativos de Meta: 40 de 100');
    expect($imp->fresh()->only(['progreso', 'etapa']))->toBe(['progreso' => 40, 'etapa' => 'Guardando creativos de Meta: 20 de 100']);

    // Cambio de etapa: se escribe aunque no haya pasado un segundo.
    $reportar(55, 'Trayendo costos e imágenes de TikTok');
    expect($imp->fresh()->progreso)->toBe(55);
});

it('el pipeline reporta un avance que siempre sube, de la lectura al guardado', function () {
    // Tokens de mentira -- las APIs están simuladas abajo, pero los clientes
    // exigen token configurado (en CI no hay .env con tokens reales).
    config(['services.meta.access_token' => 'token-test', 'services.tiktok.access_token' => 'token-test']);
    paisPeruConCuentas();
    // Sin costo en el mes, pero "vivos": Meta devuelve actividad histórica
    // y TikTok devuelve los ads que se le preguntan -- así ninguno queda
    // confirmado "sin clasificar" (tipo_cuenta null, que el schema de
    // sqlite de los tests no admite; en MySQL sí, ver la migración
    // make_tipo_cuenta_nullable).
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (str_contains($request->url(), 'graph.facebook.com')) {
            $filtro = json_decode($query['filtering'] ?? '[]', true);
            $ids = str_contains($request->url(), '/insights') && ($query['fields'] ?? '') === 'ad_id,impressions,spend'
                ? ($filtro[0]['value'] ?? [])
                : [];

            return Http::response(['data' => array_map(fn ($id) => ['ad_id' => $id, 'impressions' => 10, 'spend' => 1], $ids)]);
        }
        $ids = json_decode($query['filtering'] ?? '{}', true)['ad_ids'] ?? [];

        return Http::response(['code' => 0, 'data' => ['list' => array_map(fn ($id) => ['ad_id' => $id], $ids), 'page_info' => ['total_page' => 1]]]);
    });
    $avances = [];

    ImportadorDatos::importar(
        __DIR__.'/../../Fixtures/appsflyer-data-5.csv', 'peru', '2026-07-01', '2026-07-31',
        null, null, null, null, 'Data.csv', null,
        function (int $porcentaje, string $etapa) use (&$avances) {
            $avances[] = [$porcentaje, $etapa];
        },
    );

    $porcentajes = array_column($avances, 0);
    $etapas = array_column($avances, 1);
    expect($porcentajes[0])->toBe(3)
        ->and($etapas[0])->toBe('Leyendo el archivo de AppsFlyer')
        ->and(end($porcentajes))->toBe(97)
        ->and($porcentajes)->toBe(collect($porcentajes)->sort()->values()->all(), 'el avance nunca retrocede')
        ->and($etapas)->toContain('Trayendo costos de Meta', 'Trayendo costos de TikTok')
        ->and(collect($etapas)->contains(fn ($e) => str_starts_with($e, 'Guardando creativos de TikTok:')))->toBeTrue();
});
