<?php

use App\Models\Creativo;
use App\Models\EstadoCreativoEvento;
use App\Models\Pais;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\MetaEstadoAdsSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Cubre MetaEstadoAdsSyncService (2026-08-28, pedido explícito): puerto del
 * Google Apps Script de México, acotado a solo estado de anuncios.
 *
 * NO usa RefreshDatabase/migrate -- mismo motivo que
 * EvaluadorCreativoServiceTest (la migración make_tipo_cuenta_nullable usa
 * `ALTER TABLE ... MODIFY`, raw MySQL, incompatible con sqlite:memory):
 * arma a mano el subset de esquema sqlite-compatible que necesita.
 *
 * Usa el país 'panama' real de config/paises.php (ya trae meta_ad_account_id
 * configurado) para no necesitar mockear config().
 */
beforeEach(function () {
    Schema::create('paises', function (Blueprint $table) {
        $table->increments('id');
        $table->string('codigo', 3)->unique();
        $table->string('nombre', 50);
    });
    Schema::create('creativos', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('pais_id');
        $table->string('ad_id', 50);
        $table->string('nombre_completo', 500)->default('');
        $table->string('plataforma', 10)->default('meta');
        $table->timestamp('creado_en')->useCurrent();
    });
    Schema::create('estado_creativo_eventos', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('pais_id');
        $table->string('ad_id', 50);
        $table->unsignedInteger('creativo_id')->nullable();
        $table->string('estado_anterior', 40)->nullable();
        $table->string('estado_nuevo', 40);
        $table->timestamp('evento_en');
        $table->string('actor_name', 120)->nullable();
        $table->json('extra_data')->nullable();
        $table->timestamp('creado_en')->useCurrent();
        $table->unique(['pais_id', 'ad_id', 'evento_en', 'estado_nuevo'], 'estado_evento_unico');
    });

    Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
});

afterEach(function () {
    Schema::dropIfExists('estado_creativo_eventos');
    Schema::dropIfExists('creativos');
    Schema::dropIfExists('paises');
});

function actividadAd(string $adId, string $eventTime, string $oldValue, string $newValue): array
{
    return [
        'event_type' => 'update_ad_run_status',
        'event_time' => $eventTime,
        'object_id' => $adId,
        'actor_name' => 'Fer',
        'extra_data' => json_encode(['old_value' => $oldValue, 'new_value' => $newValue, 'run_status' => ['old_value' => 1, 'new_value' => 17]]),
    ];
}

it('guarda el estado tal cual viene de Meta, incluso uno que no es Activo/Inactivo', function () {
    Http::fake([
        'graph.facebook.com/*/act_268039321215783/activities*' => Http::response([
            'data' => [actividadAd('999', '2026-08-26T17:37:16+0000', 'Activo', 'Procesamiento pendiente')],
            'paging' => [],
        ]),
    ]);

    $servicio = new MetaEstadoAdsSyncService(new MetaApiClient('token'));
    $r = $servicio->sincronizar('panama');

    expect($r['eventosNuevos'])->toBe(1);
    $evento = EstadoCreativoEvento::first();
    expect($evento->estado_anterior)->toBe('Activo');
    expect($evento->estado_nuevo)->toBe('Procesamiento pendiente');
});

it('resuelve creativo_id cuando ya existe el ad_id, queda null cuando no', function () {
    $pais = Pais::where('codigo', 'PA')->first();
    Creativo::create(['pais_id' => $pais->id, 'ad_id' => '111', 'nombre_completo' => 'Ad conocido', 'plataforma' => 'meta']);

    Http::fake([
        'graph.facebook.com/*/act_268039321215783/activities*' => Http::response([
            'data' => [
                actividadAd('111', '2026-08-26T10:00:00+0000', 'Activo', 'Inactivo'),
                actividadAd('222', '2026-08-26T11:00:00+0000', 'Activo', 'Inactivo'),
            ],
            'paging' => [],
        ]),
    ]);

    $servicio = new MetaEstadoAdsSyncService(new MetaApiClient('token'));
    $r = $servicio->sincronizar('panama');

    expect($r['resueltosACreativo'])->toBe(1);
    expect($r['sinCreativo'])->toBe(1);
    expect(EstadoCreativoEvento::where('ad_id', '111')->first()->creativo_id)->not->toBeNull();
    expect(EstadoCreativoEvento::where('ad_id', '222')->first()->creativo_id)->toBeNull();
});

it('nunca duplica el mismo evento si sincronizar() corre dos veces sobre una ventana que se solapa', function () {
    Http::fake([
        'graph.facebook.com/*/act_268039321215783/activities*' => Http::response([
            'data' => [actividadAd('999', '2026-08-26T17:37:16+0000', 'Activo', 'Inactivo')],
            'paging' => [],
        ]),
    ]);

    $servicio = new MetaEstadoAdsSyncService(new MetaApiClient('token'));
    $servicio->sincronizar('panama');
    $r2 = $servicio->sincronizar('panama'); // segunda corrida, misma ventana (1h de solape) trae el mismo evento

    expect($r2['eventosNuevos'])->toBe(0);
    expect(EstadoCreativoEvento::count())->toBe(1);
});

it('sigue la paginación (paging.cursors.after) hasta juntar todas las páginas', function () {
    Http::fake([
        'graph.facebook.com/*/act_268039321215783/activities*' => Http::sequence()
            ->push([
                'data' => [actividadAd('1', '2026-08-26T10:00:00+0000', 'Activo', 'Inactivo')],
                'paging' => ['next' => 'https://graph.facebook.com/x', 'cursors' => ['after' => 'CURSOR1']],
            ])
            ->push([
                'data' => [actividadAd('2', '2026-08-26T11:00:00+0000', 'Activo', 'Inactivo')],
                'paging' => [],
            ]),
    ]);

    $servicio = new MetaEstadoAdsSyncService(new MetaApiClient('token'));
    $r = $servicio->sincronizar('panama');

    expect($r['eventosNuevos'])->toBe(2);
    Http::assertSentCount(2);
});

it('ignora otros event_type (update_campaign_run_status, etc.) -- solo le interesan los ads', function () {
    Http::fake([
        'graph.facebook.com/*/act_268039321215783/activities*' => Http::response([
            'data' => [
                ['event_type' => 'update_campaign_run_status', 'event_time' => '2026-08-26T10:00:00+0000', 'object_id' => '1', 'extra_data' => json_encode(['old_value' => 'Activo', 'new_value' => 'Inactivo'])],
                ['event_type' => 'update_campaign_group_high_demand_periods', 'event_time' => '2026-08-26T10:00:00+0000', 'object_id' => '1', 'extra_data' => json_encode(['operation' => 'CREATE'])],
            ],
            'paging' => [],
        ]),
    ]);

    $servicio = new MetaEstadoAdsSyncService(new MetaApiClient('token'));
    $r = $servicio->sincronizar('panama');

    expect($r['eventosNuevos'])->toBe(0);
    expect(EstadoCreativoEvento::count())->toBe(0);
});
