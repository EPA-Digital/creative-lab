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
            'invitacion_token' => hash('sha256', 'token-de-prueba'),
            'invitacion_expira_en' => now()->addHours(72),
            'email_verified_at' => null,
        ]);

        $response = $this->post('/invitaciones/token-de-prueba', [
            'password' => 'ContraseñaSegura2026',
            'password_confirmation' => 'ContraseñaSegura2026',
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
            'password' => 'ContraseñaSegura2026',
            'password_confirmation' => 'ContraseñaSegura2026',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
    }

    public function test_una_invitacion_expirada_no_deja_activar_nada(): void
    {
        User::factory()->create([
            'rol' => 'cliente',
            'password' => null,
            'invitacion_token' => hash('sha256', 'token-vencido'),
            'invitacion_expira_en' => now()->subHour(),
            'email_verified_at' => null,
        ]);

        $response = $this->post('/invitaciones/token-vencido', [
            'password' => 'ContraseñaSegura2026',
            'password_confirmation' => 'ContraseñaSegura2026',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
    }

    public function test_un_epa_no_puede_invitar_con_metodo_password_a_un_correo_epa_digital(): void
    {
        $epa = $this->epaUser();

        $response = $this->actingAs($epa)->postJson('/usuarios', [
            'name' => 'Empleado',
            'email' => 'empleado@epa.digital',
            'metodo' => 'password',
            'motivo' => 'Sin cuenta de Google corporativa',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'empleado@epa.digital']);
    }

    public function test_invitar_con_metodo_password_exige_un_motivo(): void
    {
        $epa = $this->epaUser();

        $response = $this->actingAs($epa)->postJson('/usuarios', [
            'name' => 'Cliente Sin Google',
            'email' => 'sinmotivo@afuera.com',
            'metodo' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('motivo');
    }

    public function test_invitar_con_metodo_password_guarda_el_motivo_y_el_metodo(): void
    {
        $epa = $this->epaUser();

        $response = $this->actingAs($epa)->postJson('/usuarios', [
            'name' => 'Cliente Sin Google',
            'email' => 'conmotivo@afuera.com',
            'metodo' => 'password',
            'motivo' => 'No tiene cuenta de Google corporativa',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'conmotivo@afuera.com',
            'metodo_auth' => 'password',
            'motivo_password' => 'No tiene cuenta de Google corporativa',
        ]);
    }

    public function test_un_cliente_no_puede_entrar_al_panel_de_cargar_datos(): void
    {
        $cliente = User::factory()->create(['rol' => 'cliente']);

        $this->actingAs($cliente)
            ->get('/pais/ecuador/importar')
            ->assertForbidden();
    }

    public function test_un_junior_no_puede_invitar_ni_ver_la_lista_de_usuarios(): void
    {
        // Antes un junior podía invitar y quedaba pendiente de aprobación
        // (pedido explícito 2026-09-23) -- pedido explícito 2026-09-24 lo
        // reemplaza: junior/senior ya no pueden ni invitar ni ver la
        // lista, punto. Solo gerente/director/superadmin llegan a
        // /usuarios (ver Gate 'ver-usuarios').
        $pa = Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
        $junior = User::factory()->create(['rol' => 'junior']);
        $junior->paises()->attach($pa->id);

        $this->actingAs($junior)->get('/usuarios')->assertForbidden();

        $this->actingAs($junior)->postJson('/usuarios', [
            'name' => 'Cliente Nuevo',
            'email' => 'pendiente@afuera.com',
            'pais_ids' => [$pa->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'pendiente@afuera.com']);
    }

    public function test_un_senior_no_puede_invitar_ni_ver_la_lista_de_usuarios(): void
    {
        $senior = User::factory()->create(['rol' => 'senior']);

        $this->actingAs($senior)->get('/usuarios')->assertForbidden();

        $this->actingAs($senior)->postJson('/usuarios', [
            'name' => 'Cliente Nuevo',
            'email' => 'otro@afuera.com',
        ])->assertForbidden();
    }

    public function test_un_gerente_si_puede_invitar_y_ver_la_lista_de_usuarios(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);

        $this->actingAs($gerente)->get('/usuarios')->assertOk();

        $response = $this->actingAs($gerente)->postJson('/usuarios', [
            'name' => 'Cliente Nuevo',
            'email' => 'nuevo@afuera.com',
        ]);

        // Gerente ya puede aprobar (puedeAprobarInvitaciones()), así que
        // ahora que solo gerente+ llega acá, una invitación nunca queda
        // pendiente en la práctica -- queda activa de una.
        $response->assertStatus(201);
        $response->assertJsonPath('pendiente', false);
    }

    public function test_un_pendiente_de_aprobacion_no_puede_loguearse_aunque_acepte_la_invitacion(): void
    {
        $usuario = User::factory()->create([
            'rol' => 'cliente',
            'password' => null,
            'invitacion_token' => hash('sha256', 'token-pendiente'),
            'invitacion_expira_en' => now()->addHours(72),
            'activo' => false,
            'pais_ids_propuestos' => [1],
        ]);

        $response = $this->post('/invitaciones/token-pendiente', [
            'password' => 'ContraseñaSegura2026',
            'password_confirmation' => 'ContraseñaSegura2026',
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
