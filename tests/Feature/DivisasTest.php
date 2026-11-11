<?php

use App\Models\Pais;
use App\Models\TipoCambio;
use App\Models\User;
use App\Services\Divisas\Divisas;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Divisas (2026-11-11): todo se reporta en USD; TikTok México viene en MXN.
 * La migración deja MXN = 18.677168 desde 2026-01-01.
 */
uses(RefreshDatabase::class);

it('convierte cada día con el tipo de cambio vigente ese día', function () {
    TipoCambio::create(['moneda' => 'MXN', 'vigente_desde' => '2027-01-01', 'por_usd' => 20]);

    $filas = (new Divisas)->filasAUsd([[
        'adId' => '1', 'cost' => 0,
        'dias' => [
            '2026-12-31' => ['cost' => 186.77168, 'impressions' => 1, 'clicks' => 0],
            '2027-01-01' => ['cost' => 200.0, 'impressions' => 1, 'clicks' => 0],
        ],
    ]], 'MXN', '2027-01-31');

    expect($filas[0]['dias']['2026-12-31']['cost'])->toBe(10.0)
        ->and($filas[0]['dias']['2027-01-01']['cost'])->toBe(10.0)
        ->and($filas[0]['cost'])->toBe(20.0);
});

it('no toca lo que ya está en USD ni lo que no se sabe', function () {
    $filas = [['adId' => '1', 'cost' => 50.0, 'dias' => []]];

    expect((new Divisas)->filasAUsd($filas, 'USD', '2026-11-01'))->toBe($filas)
        ->and((new Divisas)->filasAUsd($filas, null, '2026-11-01'))->toBe($filas);
});

it('sin tipo de cambio para la moneda avisa en vez de mezclar', function () {
    (new Divisas)->tasa('COP', '2026-11-01');
})->throws(RuntimeException::class, 'Falta el tipo de cambio de COP');

it('EPA guarda el tipo de cambio del año y no deja borrar el único', function () {
    $mx = Pais::firstOrCreate(['codigo' => 'MX'], ['nombre' => 'México']);
    $user = User::factory()->create(['rol' => 'superadmin']);
    $user->paises()->attach($mx->id);

    $this->actingAs($user)->postJson('/tada/pais/mexico/ajustes/tipos-cambio', ['moneda' => 'mxn', 'vigente_desde' => '2027-01-01', 'por_usd' => 19.5])
        ->assertOk()->assertJsonPath('moneda', 'MXN');
    expect((new Divisas)->tasa('MXN', '2027-03-01'))->toBe(19.5)
        ->and((new Divisas)->tasa('MXN', '2026-06-01'))->toBe(18.677168);

    $this->actingAs($user)->postJson('/tada/pais/mexico/ajustes/tipos-cambio', ['moneda' => 'USD', 'vigente_desde' => '2027-01-01', 'por_usd' => 1])
        ->assertStatus(422);

    TipoCambio::whereDate('vigente_desde', '2027-01-01')->delete();
    $unico = TipoCambio::where('moneda', 'MXN')->first();
    $this->actingAs($user)->deleteJson("/tada/pais/mexico/ajustes/tipos-cambio/{$unico->id}")->assertStatus(422);
});

it('un usuario sin permiso no cambia el tipo de cambio', function () {
    $mx = Pais::firstOrCreate(['codigo' => 'MX'], ['nombre' => 'México']);
    $user = User::factory()->create(['rol' => 'junior']);
    $user->paises()->attach($mx->id);

    $this->actingAs($user)->postJson('/tada/pais/mexico/ajustes/tipos-cambio', ['moneda' => 'MXN', 'vigente_desde' => '2027-01-01', 'por_usd' => 1])
        ->assertForbidden();
});
