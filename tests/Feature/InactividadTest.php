<?php

namespace Tests\Feature;

use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cierre de sesión por inactividad (2026-11-27): EPA 60 min, externos 15. */
class InactividadTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach(Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador'])->id);

        return $user;
    }

    public function test_cliente_se_cierra_a_los_15_minutos_con_aviso(): void
    {
        $cliente = $this->usuario('cliente');
        $this->actingAs($cliente)->withSession(['ultima_actividad' => now()->subMinutes(16)->timestamp])
            ->get('/tada/pais/ecuador/analisis')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Se cerró tu sesión por inactividad.');
        $this->assertGuest();
    }

    public function test_epa_aguanta_hasta_la_hora(): void
    {
        $junior = $this->usuario('junior');
        $this->actingAs($junior)->withSession(['ultima_actividad' => now()->subMinutes(40)->timestamp])
            ->get('/tada/pais/ecuador/en-vivo')->assertOk();
        $this->assertAuthenticated();

        $this->withSession(['ultima_actividad' => now()->subMinutes(61)->timestamp])
            ->getJson('/usuarios/presencia')
            ->assertStatus(401)->assertJson(['inactividad' => true]);
        $this->assertGuest();
    }

    public function test_las_recargas_automaticas_no_cuentan_como_uso(): void
    {
        $junior = $this->usuario('junior');
        $hace = now()->subMinutes(30)->timestamp;
        $this->actingAs($junior)->withSession(['ultima_actividad' => $hace])
            ->get('/tada/pais/ecuador/en-vivo', ['X-Sin-Actividad' => '1'])
            ->assertOk()->assertSessionHas('ultima_actividad', $hace);

        $this->get('/tada/pais/ecuador/en-vivo')->assertOk();
        $this->assertGreaterThan($hace, session('ultima_actividad'));
    }
}
