<?php

namespace Tests\Feature\Auth;

use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scope por país (2026-09-23, pedido explícito) -- aplica a TODOS los
 * roles por igual, incluido 'director' (ver EnsureAccesoPais). Se asigna
 * vía usuario_pais (tabla que ya existía sin usar), solo un 'director'
 * puede tocarla (Gate 'gestionar-usuarios').
 */
class AccesoPaisTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_director_sin_paises_asignados_no_puede_ver_ninguno(): void
    {
        Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $director = User::factory()->create(['rol' => 'director']);

        $this->actingAs($director)
            ->get('/pais/ecuador/analisis')
            ->assertForbidden();
    }

    public function test_un_director_con_el_pais_asignado_si_puede_verlo(): void
    {
        $ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $director = User::factory()->create(['rol' => 'director']);
        $director->paises()->attach($ec->id);

        // Sin creativos/resultados (fuera de foco de este test) el
        // controller igual responde 200 (lista vacía) -- confirma que
        // EnsureAccesoPais dejó pasar hasta el final.
        $this->actingAs($director)
            ->get('/pais/ecuador/analisis')
            ->assertOk();
    }

    public function test_un_cliente_solo_ve_los_paises_que_tiene_asignados_en_landing(): void
    {
        $ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
        $cliente = User::factory()->create(['rol' => 'cliente']);
        $cliente->paises()->attach($ec->id);

        $response = $this->actingAs($cliente)->get('/');

        $response->assertInertia(fn ($page) => $page
            ->component('Landing')
            ->has('paises', 1)
            ->where('paises.0.codigo', 'EC'));
    }

    public function test_un_usuario_no_puede_gestionar_a_otro_de_su_mismo_nivel_o_mas_alto(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $otroGerente = User::factory()->create(['rol' => 'gerente']);
        $director = User::factory()->create(['rol' => 'director']);

        $this->actingAs($gerente)
            ->patchJson("/usuarios/{$otroGerente->id}", ['rol' => 'gerente', 'pais_ids' => []])
            ->assertForbidden();

        $this->actingAs($gerente)
            ->patchJson("/usuarios/{$director->id}", ['rol' => 'gerente', 'pais_ids' => []])
            ->assertForbidden();
    }

    public function test_un_gerente_si_puede_gestionar_a_un_senior_junior_o_cliente(): void
    {
        // Jerarquía completa (2026-09-23) -- no solo director/superadmin
        // administran gente, cualquiera administra a quien tenga
        // ESTRICTAMENTE debajo.
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $cliente = User::factory()->create(['rol' => 'cliente']);

        $response = $this->actingAs($gerente)->patchJson("/usuarios/{$cliente->id}", [
            'rol' => 'cliente',
            'pais_ids' => [],
        ]);

        $response->assertOk();
    }

    public function test_un_director_puede_asignar_paises_a_otro_usuario_si_el_mismo_los_tiene(): void
    {
        // Nadie otorga acceso a un país que ni él mismo puede ver -- ver
        // UsuariosController::actualizarRolYPaises().
        $pa = Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
        $director = User::factory()->create(['rol' => 'director']);
        $director->paises()->attach($pa->id);
        $cliente = User::factory()->create(['rol' => 'cliente']);

        $response = $this->actingAs($director)->patchJson("/usuarios/{$cliente->id}", [
            'rol' => 'cliente',
            'pais_ids' => [$pa->id],
        ]);

        $response->assertOk();
        $this->assertTrue($cliente->fresh()->paises()->where('paises.id', $pa->id)->exists());
    }

    public function test_no_se_puede_otorgar_acceso_a_un_pais_que_el_actor_mismo_no_tiene(): void
    {
        $pa = Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
        $director = User::factory()->create(['rol' => 'director']); // sin países asignados
        $cliente = User::factory()->create(['rol' => 'cliente']);

        $this->actingAs($director)
            ->patchJson("/usuarios/{$cliente->id}", ['rol' => 'cliente', 'pais_ids' => [$pa->id]])
            ->assertStatus(422);
    }

    public function test_solo_director_o_superadmin_pueden_desactivar_usuarios(): void
    {
        // Más estricto que la jerarquía general (pedido explícito
        // 2026-09-23) -- un gerente no desactiva ni a su propio junior.
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $junior = User::factory()->create(['rol' => 'junior']);

        $this->actingAs($gerente)
            ->deleteJson("/usuarios/{$junior->id}")
            ->assertForbidden();

        $director = User::factory()->create(['rol' => 'director']);
        $this->actingAs($director)
            ->deleteJson("/usuarios/{$junior->id}")
            ->assertOk();
        $this->assertFalse($junior->fresh()->activo);
    }

    public function test_un_director_no_puede_tocar_su_propio_rol_de_director_ni_para_bajarlo(): void
    {
        // Solo superadmin toca 'director'/'superadmin' (ver
        // ROLES_SOLO_SUPERADMIN) -- un director ni siquiera puede
        // bajarse a sí mismo, mucho menos a otro.
        $director = User::factory()->create(['rol' => 'director']);

        $response = $this->actingAs($director)->patchJson("/usuarios/{$director->id}", [
            'rol' => 'junior',
            'pais_ids' => [],
        ]);

        $response->assertForbidden();
        $this->assertSame('director', $director->fresh()->rol);
    }

    public function test_un_director_no_puede_ascender_a_nadie_a_director_o_superadmin(): void
    {
        $director = User::factory()->create(['rol' => 'director']);
        $gerente = User::factory()->create(['rol' => 'gerente']);

        $response = $this->actingAs($director)->patchJson("/usuarios/{$gerente->id}", [
            'rol' => 'director',
            'pais_ids' => [],
        ]);

        $response->assertForbidden();
        $this->assertSame('gerente', $gerente->fresh()->rol);
    }

    public function test_un_superadmin_si_puede_ascender_a_alguien_a_director(): void
    {
        $superadmin = User::factory()->create(['rol' => 'superadmin']);
        $gerente = User::factory()->create(['rol' => 'gerente']);

        $response = $this->actingAs($superadmin)->patchJson("/usuarios/{$gerente->id}", [
            'rol' => 'director',
            'pais_ids' => [],
        ]);

        $response->assertOk();
        $this->assertSame('director', $gerente->fresh()->rol);
    }
}
