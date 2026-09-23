<?php

namespace Tests\Feature\Auth;

use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el reemplazo del /register abierto de Breeze -- único camino de
 * acceso para un 'cliente' es que un EPA lo invite desde /usuarios (ver
 * UsuariosController) y acepte el link con el token (ver
 * InvitacionController).
 */
class InvitacionTest extends TestCase
{
    use RefreshDatabase;

    private function epaUser(): User
    {
        return User::factory()->create(['rol' => 'director']);
    }

    public function test_un_epa_puede_invitar_y_recibe_el_link_de_invitacion(): void
    {
        $epa = $this->epaUser();

        $response = $this->actingAs($epa)->postJson('/usuarios', [
            'name' => 'Cliente Nuevo',
            'email' => 'cliente@afuera.com',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('usuario.rol', 'cliente');
        $this->assertNotNull($response->json('linkInvitacion'));

        $this->assertDatabaseHas('users', [
            'email' => 'cliente@afuera.com',
            'rol' => 'cliente',
            'activo' => true,
            'creado_por' => $epa->id,
        ]);
    }

    public function test_un_cliente_no_puede_invitar_usuarios(): void
    {
        $cliente = User::factory()->create(['rol' => 'cliente']);

        $response = $this->actingAs($cliente)->postJson('/usuarios', [
            'name' => 'Otro',
            'email' => 'otro@afuera.com',
        ]);

        $response->assertForbidden();
    }

    public function test_aceptar_la_invitacion_activa_la_cuenta_y_hace_login(): void
    {
        $usuario = User::factory()->create([
            'rol' => 'cliente',
            'password' => null,
            'invitacion_token' => 'token-de-prueba',
            'email_verified_at' => null,
        ]);

        $response = $this->post('/invitaciones/token-de-prueba', [
            'password' => 'contrasena-segura',
            'password_confirmation' => 'contrasena-segura',
        ]);

        $response->assertRedirect(route('landing'));
        $this->assertAuthenticatedAs($usuario->fresh());

        $usuario->refresh();
        $this->assertNull($usuario->invitacion_token); // token de un solo uso
        $this->assertNotNull($usuario->password);
        $this->assertNotNull($usuario->email_verified_at);
    }

    public function test_un_token_de_invitacion_invalido_no_deja_activar_nada(): void
    {
        $response = $this->post('/invitaciones/token-que-no-existe', [
            'password' => 'contrasena-segura',
            'password_confirmation' => 'contrasena-segura',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
    }

    public function test_un_cliente_no_puede_entrar_al_panel_de_cargar_datos(): void
    {
        $cliente = User::factory()->create(['rol' => 'cliente']);

        $this->actingAs($cliente)
            ->get('/pais/ecuador/importar')
            ->assertForbidden();
    }

    public function test_un_junior_puede_invitar_pero_queda_pendiente_de_aprobacion(): void
    {
        // "el junior sí puede dar permiso pero el director o gerente
        // tiene que aceptarlo" (pedido explícito 2026-09-23).
        $pa = Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
        $junior = User::factory()->create(['rol' => 'junior']);
        $junior->paises()->attach($pa->id);

        $response = $this->actingAs($junior)->postJson('/usuarios', [
            'name' => 'Cliente Nuevo',
            'email' => 'pendiente@afuera.com',
            'pais_ids' => [$pa->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('pendiente', true);

        $creado = User::where('email', 'pendiente@afuera.com')->firstOrFail();
        $this->assertFalse($creado->activo);
        $this->assertTrue($creado->estaPendienteDeAprobacion());
        $this->assertSame([$pa->id], $creado->pais_ids_propuestos);
        $this->assertCount(0, $creado->paises); // todavía no sincronizado
    }

    public function test_un_pendiente_de_aprobacion_no_puede_loguearse_aunque_acepte_la_invitacion(): void
    {
        $usuario = User::factory()->create([
            'rol' => 'cliente',
            'password' => null,
            'invitacion_token' => 'token-pendiente',
            'activo' => false,
            'pais_ids_propuestos' => [1],
        ]);

        $response = $this->post('/invitaciones/token-pendiente', [
            'password' => 'contrasena-segura',
            'password_confirmation' => 'contrasena-segura',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        $usuario->refresh();
        $this->assertNull($usuario->invitacion_token); // el token sí se consume
        $this->assertNotNull($usuario->password); // la contraseña sí quedó puesta
        $this->assertFalse($usuario->activo); // pero sigue sin poder entrar
    }

    public function test_un_gerente_puede_aprobar_una_invitacion_pendiente(): void
    {
        $pa = Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $gerente->paises()->attach($pa->id);

        $pendiente = User::factory()->create([
            'rol' => 'cliente',
            'activo' => false,
            'pais_ids_propuestos' => [$pa->id],
        ]);

        $response = $this->actingAs($gerente)->postJson("/usuarios/{$pendiente->id}/aprobar", [
            'pais_ids' => [$pa->id],
        ]);

        $response->assertOk();
        $pendiente->refresh();
        $this->assertTrue($pendiente->activo);
        $this->assertNull($pendiente->pais_ids_propuestos);
        $this->assertTrue($pendiente->paises()->where('paises.id', $pa->id)->exists());
    }

    public function test_un_junior_no_puede_aprobar_invitaciones(): void
    {
        $junior = User::factory()->create(['rol' => 'junior']);
        $pendiente = User::factory()->create(['rol' => 'cliente', 'activo' => false, 'pais_ids_propuestos' => []]);

        $this->actingAs($junior)
            ->postJson("/usuarios/{$pendiente->id}/aprobar", ['pais_ids' => []])
            ->assertForbidden();
    }
}
