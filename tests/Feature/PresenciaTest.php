<?php

use App\Models\User;
use App\Services\Presencia\Presencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Presencia en Usuarios (2026-11-11): verde si lo está usando, amarillo si
 * tiene la sesión abierta sin uso reciente, rojo si cerró sesión.
 */
uses(RefreshDatabase::class);

function sesionDe(User $u, int $minutosAtras): void
{
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $u->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => '', 'last_activity' => now()->subMinutes($minutosAtras)->getTimestamp(),
    ]);
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lifetime' => 60]);
});

it('distingue en línea, inactivo y desconectado', function () {
    [$activo, $ausente, $fuera, $vencido] = User::factory()->count(4)->create();
    sesionDe($activo, 2);
    sesionDe($ausente, 20);
    sesionDe($vencido, 90); // más viejo que SESSION_LIFETIME
    $fuera->forceFill(['ultima_actividad_en' => now()->subHours(3)])->save();

    $p = Presencia::de(collect([$activo, $ausente, $fuera, $vencido]));

    expect($p[$activo->id]['estado'])->toBe(Presencia::EN_LINEA)
        ->and($p[$ausente->id]['estado'])->toBe(Presencia::INACTIVO)
        ->and($p[$fuera->id]['estado'])->toBe(Presencia::DESCONECTADO)
        ->and($p[$fuera->id]['ultima_actividad'])->not->toBeNull()
        ->and($p[$vencido->id]['estado'])->toBe(Presencia::DESCONECTADO);
});

it('el aviso de actividad guarda la hora sin tocar el perfil', function () {
    $u = User::factory()->create();
    $antes = $u->fresh()->updated_at;

    $this->actingAs($u)->postJson('/perfil/actividad')->assertNoContent();

    expect($u->fresh()->ultima_actividad_en)->not->toBeNull()
        ->and($u->fresh()->updated_at?->toIso8601String())->toBe($antes?->toIso8601String());
});

it('la presencia solo muestra a quien se puede gestionar', function () {
    $director = User::factory()->create(['rol' => 'director']);
    $junior = User::factory()->create(['rol' => 'junior']);
    $super = User::factory()->create(['rol' => 'superadmin']);

    $ids = array_keys($this->actingAs($director)->getJson('/usuarios/presencia')->assertOk()->json());

    expect($ids)->toContain($director->id, $junior->id)->not->toContain($super->id);
});

it('quien no ve usuarios no puede pedir la presencia', function () {
    $cliente = User::factory()->create(['rol' => 'cliente']);

    $this->actingAs($cliente)->getJson('/usuarios/presencia')->assertForbidden();
});
