<?php

use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\User;
use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Caso real Perú 2026-10-06: país recién habilitado, sin cuentas
 * publicitarias -- la importación se saltaba Meta y TikTok en silencio y
 * el panel decía "Importación completa" con 0 creativos. Ahora avisa en el
 * preview y corta con un error claro si ninguna plataforma tiene cuenta.
 */
uses(RefreshDatabase::class);

const CSV_FIXTURE = __DIR__.'/../../Fixtures/appsflyer-data-5.csv';

it('corta con un error claro si ninguna plataforma con anuncios tiene cuenta', function () {
    Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);

    expect(fn () => ImportadorDatos::importar(CSV_FIXTURE, 'peru', '2026-07-01', '2026-07-31', null, null, null, null))
        ->toThrow(InvalidArgumentException::class, 'Perú no tiene cuentas publicitarias activas de Meta ni de TikTok');
});

it('detecta solo las plataformas que tienen anuncios y ninguna cuenta activa', function () {
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    CuentaPublicitaria::create(['pais_id' => $pe->id, 'plataforma' => 'meta', 'cuenta_id' => '1', 'tipo' => 'tada']);
    CuentaPublicitaria::create(['pais_id' => $pe->id, 'plataforma' => 'tiktok', 'cuenta_id' => '2', 'tipo' => 'tada', 'activa' => false]);
    $sinCuenta = fn (array $clasificados) => (new ReflectionMethod(ImportadorDatos::class, 'plataformasSinCuenta'))->invoke(null, $pe, $clasificados);

    expect($sinCuenta(['meta' => [['adId' => 'a']], 'tiktok' => [['adId' => 'b']]]))->toBe(['tiktok']);
    // Sin anuncios de TikTok en el archivo, no hace falta su cuenta.
    expect($sinCuenta(['meta' => [['adId' => 'a']], 'tiktok' => []]))->toBe([]);
});

it('avisa en el preview qué plataformas no tienen cuenta antes de importar', function () {
    Storage::fake();
    $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
    $user = User::factory()->create(['rol' => 'gerente']);
    $user->paises()->attach($pe->id);

    $this->actingAs($user)
        ->post('/pais/peru/importar/previsualizar', ['archivo' => new UploadedFile(CSV_FIXTURE, 'Data.csv', 'text/csv', null, true)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('plataformasSinCuenta', ['meta', 'tiktok']);
});
