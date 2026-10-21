<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * "¿Olvidaste tu contraseña?" sin correo -- la identidad se prueba con el
 * TOTP o un código de recuperación (ver RecuperarPasswordController).
 */
class RecuperarPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NUEVA = 'OtraContraseñaSegura2026';

    private function usuarioConPassword(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'rol' => 'cliente',
            'email' => 'cliente@afuera.com',
            'metodo_auth' => User::METODO_PASSWORD,
            'password' => 'ContraseñaVieja2026',
            'activo' => true,
            'totp_secret' => app(Google2FA::class)->generateSecretKey(),
            'totp_confirmed_at' => now(),
        ], $overrides));
    }

    private function recuperar(string $email, string $codigo)
    {
        return $this->post('/recuperar-contrasena', [
            'email' => $email,
            'codigo' => $codigo,
            'password' => self::NUEVA,
            'password_confirmation' => self::NUEVA,
        ]);
    }

    public function test_la_pantalla_se_muestra_y_el_login_tiene_el_link(): void
    {
        $this->get('/recuperar-contrasena')->assertOk();

        $this->get('/login')->assertInertia(fn ($page) => $page->where('canResetPassword', true));
    }

    public function test_con_totp_valido_cambia_la_contrasena_sin_abrir_sesion(): void
    {
        $usuario = $this->usuarioConPassword();
        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->totp_secret);

        $response = $this->recuperar($usuario->email, $codigo);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertGuest();
        $this->assertTrue(Hash::check(self::NUEVA, $usuario->fresh()->password));
    }

    public function test_con_codigo_de_recuperacion_cambia_la_contrasena_y_lo_consume(): void
    {
        $totp = app(TotpService::class);
        $codigos = $totp->generarCodigosRecuperacion();
        $usuario = $this->usuarioConPassword(['totp_recovery_codes' => $totp->hashearCodigosRecuperacion($codigos)]);

        $this->recuperar($usuario->email, $codigos[0])->assertRedirect(route('login'));

        $usuario->refresh();
        $this->assertTrue(Hash::check(self::NUEVA, $usuario->password));
        $this->assertCount(count($codigos) - 1, $usuario->totp_recovery_codes);
    }

    public function test_codigo_invalido_no_cambia_la_contrasena(): void
    {
        $usuario = $this->usuarioConPassword();

        $this->recuperar($usuario->email, '000000')->assertSessionHasErrors('codigo');

        $this->assertTrue(Hash::check('ContraseñaVieja2026', $usuario->fresh()->password));
    }

    public function test_correo_inexistente_da_el_mismo_error(): void
    {
        $this->recuperar('nadie@afuera.com', '123456')->assertSessionHasErrors([
            'codigo' => 'El correo o el código no son válidos.',
        ]);
    }

    public function test_sin_totp_confirmado_no_se_puede_recuperar(): void
    {
        $usuario = $this->usuarioConPassword(['totp_confirmed_at' => null]);
        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->totp_secret);

        $this->recuperar($usuario->email, $codigo)->assertSessionHasErrors('codigo');

        $this->assertTrue(Hash::check('ContraseñaVieja2026', $usuario->fresh()->password));
    }

    public function test_despues_de_recuperar_se_puede_entrar_con_la_nueva(): void
    {
        $usuario = $this->usuarioConPassword();
        $this->recuperar($usuario->email, app(Google2FA::class)->getCurrentOtp($usuario->totp_secret));

        $this->post('/login', ['email' => $usuario->email, 'password' => self::NUEVA])
            ->assertRedirect(route('2fa.verificar'));
    }

    public function test_se_bloquea_tras_cinco_intentos_fallidos(): void
    {
        $usuario = $this->usuarioConPassword();

        foreach (range(1, 5) as $_) {
            $this->recuperar($usuario->email, '000000');
        }

        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->totp_secret);
        $this->recuperar($usuario->email, $codigo)->assertSessionHasErrors('codigo');

        $this->assertTrue(Hash::check('ContraseñaVieja2026', $usuario->fresh()->password));
    }
}
