<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Cliente invitado con metodo_auth=google -> correo/contraseña + Google
 * Authenticator (ver UsuariosController::activarPassword()).
 */
class ActivarPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function clienteGoogle(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'rol' => 'cliente',
            'email' => 'cliente@gmail.com',
            'metodo_auth' => User::METODO_GOOGLE,
            'google_id' => 'google-123',
            'activo' => true,
        ], $overrides));
    }

    private function linkToken(string $link): string
    {
        return basename(parse_url($link, PHP_URL_PATH));
    }

    public function test_un_gerente_pasa_a_un_cliente_de_google_a_password_y_completa_el_2fa(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $cliente = $this->clienteGoogle();

        $response = $this->actingAs($gerente)->postJson("/usuarios/{$cliente->id}/activar-password");

        $response->assertOk();
        $cliente->refresh();
        $this->assertSame(User::METODO_PASSWORD, $cliente->metodo_auth);
        $this->assertNull($cliente->google_id);
        $this->assertNotNull($cliente->motivo_password);

        $this->post('/logout');
        $token = $this->linkToken($response->json('linkInvitacion'));

        $this->post("/invitaciones/{$token}", [
            'password' => 'ContraseñaSegura2026',
            'password_confirmation' => 'ContraseñaSegura2026',
        ])->assertRedirect(route('2fa.enrolar'));

        $this->get('/2fa/enrolar')->assertOk();
        $secreto = $cliente->fresh()->totp_secret;

        $this->post('/2fa/enrolar', ['codigo' => app(Google2FA::class)->getCurrentOtp($secreto)])
            ->assertInertia(fn ($page) => $page->component('Auth/CodigosRecuperacion')->has('codigos'));

        $this->assertTrue($cliente->fresh()->tieneTotpConfirmado());
    }

    public function test_un_junior_no_puede_cambiar_el_metodo(): void
    {
        $junior = User::factory()->create(['rol' => 'junior']);
        $cliente = $this->clienteGoogle();

        $this->actingAs($junior)->postJson("/usuarios/{$cliente->id}/activar-password")->assertForbidden();

        $this->assertSame(User::METODO_GOOGLE, $cliente->fresh()->metodo_auth);
    }

    public function test_una_cuenta_epa_digital_no_se_puede_pasar_a_password(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $epa = $this->clienteGoogle(['email' => 'alguien@epa.digital', 'rol' => 'junior']);

        $this->actingAs($gerente)->postJson("/usuarios/{$epa->id}/activar-password")->assertStatus(422);
    }

    public function test_un_usuario_que_ya_usa_password_no_se_vuelve_a_activar(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $cliente = $this->clienteGoogle(['metodo_auth' => User::METODO_PASSWORD, 'google_id' => null]);

        $this->actingAs($gerente)->postJson("/usuarios/{$cliente->id}/activar-password")->assertStatus(422);
    }
}
