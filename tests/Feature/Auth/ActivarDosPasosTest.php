<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Activar el 2FA estando ya logueado (ver ActivarDosPasosController) y que
 * después el login con Google también lo pida.
 */
class ActivarDosPasosTest extends TestCase
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

    private function mockearGoogle(string $email): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn('google-123');
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn('Cliente');
        $socialiteUser->shouldReceive('getRaw')->andReturn(['email_verified' => true, 'hd' => null]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_sin_2fa_el_front_recibe_el_aviso(): void
    {
        $usuario = $this->clienteGoogle();

        $this->actingAs($usuario)->get('/')
            ->assertInertia(fn ($page) => $page->where('auth.tiene_2fa', false));
    }

    public function test_un_usuario_logueado_activa_el_2fa_y_recibe_sus_codigos(): void
    {
        $usuario = $this->clienteGoogle();

        $this->actingAs($usuario)->get('/seguridad/2fa')
            ->assertInertia(fn ($page) => $page->component('Auth/EnrolarTotp')->has('qr')->where('obligatorio', false));

        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->fresh()->totp_secret);

        $this->actingAs($usuario)->post('/seguridad/2fa', ['codigo' => $codigo])
            ->assertInertia(fn ($page) => $page->component('Auth/CodigosRecuperacion')->has('codigos', 8));

        $this->assertTrue($usuario->fresh()->tieneTotpConfirmado());
        $this->actingAs($usuario->fresh())->get('/')
            ->assertInertia(fn ($page) => $page->where('auth.tiene_2fa', true));
    }

    public function test_un_codigo_invalido_no_activa_el_2fa(): void
    {
        $usuario = $this->clienteGoogle();
        $this->actingAs($usuario)->get('/seguridad/2fa');

        $this->actingAs($usuario)->post('/seguridad/2fa', ['codigo' => '000000'])->assertSessionHasErrors('codigo');

        $this->assertFalse($usuario->fresh()->tieneTotpConfirmado());
    }

    public function test_quien_ya_tiene_2fa_no_puede_volver_a_generar_el_qr(): void
    {
        $secreto = app(Google2FA::class)->generateSecretKey();
        $usuario = $this->clienteGoogle(['totp_secret' => $secreto, 'totp_confirmed_at' => now()]);

        $this->actingAs($usuario)->get('/seguridad/2fa')->assertRedirect(route('landing'));

        $this->assertSame($secreto, $usuario->fresh()->totp_secret);
    }

    public function test_sin_sesion_no_se_puede_activar(): void
    {
        $this->get('/seguridad/2fa')->assertRedirect(route('login'));
    }

    public function test_con_2fa_activo_el_login_con_google_pide_el_codigo(): void
    {
        $usuario = $this->clienteGoogle([
            'totp_secret' => app(Google2FA::class)->generateSecretKey(),
            'totp_confirmed_at' => now(),
        ]);
        $this->mockearGoogle($usuario->email);

        $this->get('/auth/google/callback')->assertRedirect(route('2fa.verificar'));
        $this->assertGuest();

        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->totp_secret);
        $this->post('/2fa/verificar', ['codigo' => $codigo]);

        $this->assertAuthenticatedAs($usuario->fresh());
    }
}
