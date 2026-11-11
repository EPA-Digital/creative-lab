<?php

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\ResultadoDiario;
use App\Models\User;
use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Detalle por día en la importación normal (CSV / API, 2026-11-11): cada
 * anuncio guarda sus días, la venta real del periodo se reparte entre ellos
 * y el mes queda como la suma de TODOS sus días -- subir un día suelto ya no
 * pisa el resto del mes.
 */
uses(RefreshDatabase::class);

function creativoMx(): Creativo
{
    $mx = Pais::firstOrCreate(['codigo' => 'MX'], ['nombre' => 'México']);

    return Creativo::create(['pais_id' => $mx->id, 'ad_id' => '123', 'nombre_completo' => 'X', 'plataforma' => 'meta', 'tipo_cuenta' => 'DTC']);
}

/** Corre lo mismo que procesarPlataforma después de guardar el Resultado del rango. */
function guardarRango(Creativo $creativo, array $card, string $desde, string $hasta, bool $huboVentaReal): void
{
    $nc = $huboVentaReal ? (int) round($card['ncReal']) : Resultado::where('creativo_id', $creativo->id)->value('nc');
    $orders = $huboVentaReal ? (int) round($card['ordersReal']) : Resultado::where('creativo_id', $creativo->id)->value('orders');
    Resultado::updateOrCreate(['creativo_id' => $creativo->id, 'mes' => substr($desde, 0, 7)], [
        'fecha_inicio' => $desde, 'fecha_fin' => $hasta, 'cost' => $card['cost'], 'impressions' => 0, 'clicks' => 0,
        'installs' => 0, 'nc' => $nc, 'orders' => $orders, 'reorders' => 0, 'tiene_venta_real' => $nc !== null, 'tiene_meta' => true,
    ]);
    (new ReflectionMethod(ImportadorDatos::class, 'guardarDias'))->invoke(null, $creativo->id, $card, $desde, $hasta, $huboVentaReal, $huboVentaReal);
    (new ReflectionMethod(ImportadorDatos::class, 'consolidarMesDesdeDias'))->invoke(null, $creativo->id, substr($desde, 0, 7));
}

function cardConDias(array $dias, ?float $ncReal, ?float $ordersReal): array
{
    $costo = [];
    $serie = [];
    foreach ($dias as $fecha => [$cost, $installs, $nc, $orders]) {
        $costo[$fecha] = ['cost' => $cost, 'impressions' => 100, 'clicks' => 10];
        [$a, $m, $d] = explode('-', $fecha);
        $serie[] = ['fecha' => "{$d}-{$m}-{$a}", 'installs' => $installs, 'newCustomers' => $nc, 'orders' => $orders];
    }

    return ['cost' => array_sum(array_column($costo, 'cost')), 'ncReal' => $ncReal, 'ordersReal' => $ordersReal, 'diasCosto' => $costo, 'serieAppsFlyer' => $serie];
}

it('guarda cada día y reparte la venta real con el mismo peso que entre anuncios', function () {
    $c = creativoMx();
    // NC por primeras compras (3 y 1); órdenes por primeras + recompras (3+1=4 y 1+3=4).
    $card = cardConDias([
        '2026-10-01' => [60.0, 10, 3, 1],
        '2026-10-02' => [40.0, 5, 1, 3],
    ], ncReal: 40.0, ordersReal: 100.0);

    guardarRango($c, $card, '2026-10-01', '2026-10-31', true);

    $dias = ResultadoDiario::where('creativo_id', $c->id)->orderBy('fecha')->get();
    expect($dias)->toHaveCount(2)
        ->and($dias->pluck('cost')->all())->toBe(['60.00', '40.00'])
        ->and($dias->pluck('nc_real')->all())->toBe(['30.00', '10.00'])
        ->and($dias->pluck('orders_real')->all())->toBe(['50.00', '50.00']);

    $mes = Resultado::where('creativo_id', $c->id)->first();
    expect((float) $mes->cost)->toBe(100.0)
        ->and($mes->installs)->toBe(15)
        ->and($mes->nc)->toBe(40)
        ->and($mes->orders)->toBe(100);
});

it('subir un día suelto suma al mes en vez de pisarlo', function () {
    $c = creativoMx();
    guardarRango($c, cardConDias(['2026-11-05' => [10.0, 2, 1, 0]], 4.0, 6.0), '2026-11-05', '2026-11-05', true);
    guardarRango($c, cardConDias(['2026-11-06' => [30.0, 6, 2, 1]], 8.0, 9.0), '2026-11-06', '2026-11-06', true);

    $mes = Resultado::where('creativo_id', $c->id)->where('mes', '2026-11')->first();
    expect((float) $mes->cost)->toBe(40.0)
        ->and($mes->installs)->toBe(8)
        ->and($mes->nc)->toBe(12)
        ->and($mes->orders)->toBe(15)
        ->and($mes->fecha_inicio->toDateString())->toBe('2026-11-05')
        ->and($mes->fecha_fin->toDateString())->toBe('2026-11-06');

    // Volver a subir el 5 (AppsFlyer siguió atribuyendo) solo reemplaza ese día.
    guardarRango($c, cardConDias(['2026-11-05' => [10.0, 3, 2, 0]], 5.0, 7.0), '2026-11-05', '2026-11-05', true);
    $mes->refresh();
    expect(ResultadoDiario::where('creativo_id', $c->id)->count())->toBe(2)
        ->and($mes->installs)->toBe(9)
        ->and($mes->nc)->toBe(13);
});

it('sin total real en la corrida, cada día conserva su venta real y el mes no la pierde', function () {
    $c = creativoMx();
    $dias = ['2026-10-01' => [60.0, 10, 3, 1], '2026-10-02' => [40.0, 5, 1, 3]];
    guardarRango($c, cardConDias($dias, 40.0, 100.0), '2026-10-01', '2026-10-31', true);

    // Refresh diario por API (sin totales): el gasto se actualiza, la venta real se queda.
    $dias['2026-10-02'][0] = 50.0;
    guardarRango($c, cardConDias($dias, null, null), '2026-10-01', '2026-10-31', false);

    $mes = Resultado::where('creativo_id', $c->id)->first();
    expect((float) $mes->cost)->toBe(110.0)
        ->and($mes->nc)->toBe(40)
        ->and($mes->orders)->toBe(100)
        ->and(ResultadoDiario::where('creativo_id', $c->id)->pluck('nc_real')->all())->toBe(['30.00', '10.00']);
});

it('un día nuevo sin venta real no borra el real del mes', function () {
    $c = creativoMx();
    guardarRango($c, cardConDias(['2026-10-01' => [60.0, 10, 3, 1]], 30.0, 50.0), '2026-10-01', '2026-10-01', true);
    guardarRango($c, cardConDias(['2026-10-02' => [40.0, 5, 1, 3]], null, null), '2026-10-02', '2026-10-02', false);

    $mes = Resultado::where('creativo_id', $c->id)->first();
    expect((float) $mes->cost)->toBe(100.0)
        ->and($mes->nc)->toBe(30)
        ->and($mes->orders)->toBe(50);
});

it('el resumen por día filtra por anuncio, plataforma, etapa y búsqueda', function () {
    $mx = Pais::firstOrCreate(['codigo' => 'MX'], ['nombre' => 'México']);
    $meta = Creativo::create(['pais_id' => $mx->id, 'ad_id' => '111', 'nombre_comun' => 'CLUB-CORONA', 'nombre_completo' => 'X', 'plataforma' => 'meta', 'funnel' => 'CNV', 'tipo_cuenta' => 'DTC']);
    $tiktok = Creativo::create(['pais_id' => $mx->id, 'ad_id' => '222', 'nombre_comun' => 'CLUB-CORONA', 'nombre_completo' => 'X', 'plataforma' => 'tiktok', 'funnel' => 'CNV', 'tipo_cuenta' => 'DTC']);
    $otro = Creativo::create(['pais_id' => $mx->id, 'ad_id' => '333', 'nombre_comun' => 'CAGUAMA', 'nombre_completo' => 'X', 'plataforma' => 'meta', 'funnel' => 'AWA', 'tipo_cuenta' => 'DTC']);
    foreach ([[$meta, 10], [$tiktok, 30], [$otro, 5]] as [$c, $cost]) {
        // insert directo: en SQLite el cast 'date' guardaría "2026-11-01 00:00:00".
        ResultadoDiario::insert(['creativo_id' => $c->id, 'fecha' => '2026-11-01', 'cost' => $cost, 'impressions' => 100, 'clicks' => 1, 'installs' => 1, 'nc' => 1, 'repurchases' => 0, 'orders' => 1]);
    }
    $user = User::factory()->create(['rol' => 'superadmin']);
    $user->paises()->attach($mx->id);
    $url = '/tada/pais/mexico/importar/resumen?desde=2026-11-01&hasta=2026-11-01';

    // Por arte: una fila por arte + plataforma + etapa (como "Datos por mes").
    $this->actingAs($user)->getJson("{$url}&buscar=corona")
        ->assertOk()->assertJsonCount(2, 'filas')
        ->assertJsonPath('filas.0.anuncios', 1)
        ->assertJsonPath('totales.cost', 40);

    // Por anuncio + solo TikTok.
    $this->actingAs($user)->getJson("{$url}&agrupar=anuncio&plataforma=tiktok")
        ->assertOk()->assertJsonCount(1, 'filas')
        ->assertJsonPath('filas.0.adId', '222');

    // Por etapa.
    $this->actingAs($user)->getJson("{$url}&funnel=AWA")
        ->assertOk()->assertJsonCount(1, 'filas')
        ->assertJsonPath('filas.0.nombreComun', 'CAGUAMA');
});
