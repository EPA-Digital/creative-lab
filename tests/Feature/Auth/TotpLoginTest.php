<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * auth-prompt.md Fase 3: login en dos pasos para metodo_auth=password
 * (contraseña, después TOTP), anti-replay, códigos de recuperación de un
 * solo uso, throttle, y que resetear la contraseña no toque el TOTP.
 */
class TotpLoginTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRASENA = 'ContraseñaSegura2026';

    private function usuarioConPassword(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'rol' => 'cliente',
            'email' => 'cliente@afuera.com',
            'metodo_auth' => User::METODO_PASSWORD,
            'password' => self::CONTRASENA,
            'activo' => true,
            'totp_secret' => app(Google2FA::class)->generateSecretKey(),
            'totp_confirmed_at' => now(),
        ], $overrides));
    }

    public function test_contrasena_correcta_sin_totp_no_abre_sesion_ni_deja_ver_rutas_protegidas(): void
    {
        $usuario = $this->usuarioConPassword();

        $response = $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);

        $this->assertGuest();
        $response->assertRedirect(route('2fa.verificar'));
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_completo_con_totp_valido_abre_sesion(): void
    {
        $usuario = $this->usuarioConPassword();
        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);

        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->totp_secret);
        $response = $this->post('/2fa/verificar', ['codigo' => $codigo]);

        $this->assertAuthenticatedAs($usuario->fresh());
        $response->assertRedirect(route('landing', absolute: false));
    }

    public function test_un_codigo_totp_invalido_es_rechazado(): void
    {
        $usuario = $this->usuarioConPassword();
        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);

        $response = $this->post('/2fa/verificar', ['codigo' => '000000']);

        $this->assertGuest();
        $response->assertSessionHasErrors('codigo');
    }

    public function test_un_codigo_totp_no_se_puede_reutilizar(): void
    {
        $usuario = $this->usuarioConPassword();
        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->totp_secret);

        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);
        $this->post('/2fa/verificar', ['codigo' => $codigo]);
        $this->post('/logout');

        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);
        $response = $this->post('/2fa/verificar', ['codigo' => $codigo]);

        $this->assertGuest();
        $response->assertSessionHasErrors('codigo');
    }

    public function test_el_estado_pendiente_de_2fa_expira_a_los_5_minutos(): void
    {
        $usuario = $this->usuarioConPassword();
        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);

        $this->travel(6)->minutes();

        $codigo = app(Google2FA::class)->getCurrentOtp($usuario->totp_secret);
        $response = $this->post('/2fa/verificar', ['codigo' => $codigo]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_un_codigo_de_recuperacion_funciona_una_sola_vez(): void
    {
        $totp = app(TotpService::class);
        $codigos = $totp->generarCodigosRecuperacion();
        $usuario = $this->usuarioConPassword(['totp_recovery_codes' => $totp->hashearCodigosRecuperacion($codigos)]);

        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);
        $this->post('/2fa/verificar', ['codigo' => $codigos[0]]);
        $this->assertAuthenticatedAs($usuario->fresh());
        $this->post('/logout');

        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);
        $segundoIntento = $this->post('/2fa/verificar', ['codigo' => $codigos[0]]);

        $this->assertGuest();
        $segundoIntento->assertSessionHasErrors('codigo');
    }

    public function test_el_throttle_bloquea_la_verificacion_totp_despues_de_varios_intentos(): void
    {
        $usuario = $this->usuarioConPassword();
        $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/2fa/verificar', ['codigo' => '000000']);
        }

        $response = $this->post('/2fa/verificar', ['codigo' => '000000']);

        $this->assertGuest();
        $response->assertSessionHasErrors('codigo');
    }

    public function test_un_usuario_con_metodo_auth_google_no_puede_loguearse_por_password(): void
    {
        $usuario = User::factory()->create([
            'email' => 'clientegoogle@afuera.com',
            'rol' => 'cliente',
            'metodo_auth' => User::METODO_GOOGLE,
            'password' => self::CONTRASENA,
            'activo' => true,
        ]);

        $response = $this->post('/login', ['email' => $usuario->email, 'password' => self::CONTRASENA]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_resetear_password_no_desactiva_el_totp(): void
    {
        Notification::fake();
        $usuario = $this->usuarioConPassword();
        $secretoOriginal = $usuario->totp_secret;

        $this->post('/forgot-password', ['email' => $usuario->email]);

        Notification::assertSentTo($usuario, ResetPassword::class, function ($notification) use ($usuario) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $usuario->email,
                'password' => 'OtraClaveNueva2026',
                'password_confirmation' => 'OtraClaveNueva2026',
            ]);

            return true;
        });

        $usuario->refresh();
        $this->assertNotNull($usuario->totp_confirmed_at);
        $this->assertSame($secretoOriginal, $usuario->totp_secret);
    }

    public function test_un_gerente_puede_resetear_el_totp_de_un_cliente(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $usuario = $this->usuarioConPassword();

        $response = $this->actingAs($gerente)->postJson("/usuarios/{$usuario->id}/resetear-totp");

        $response->assertOk();
        $usuario->refresh();
        $this->assertNull($usuario->totp_secret);
        $this->assertNull($usuario->totp_confirmed_at);
    }

    public function test_un_junior_no_puede_resetear_totp(): void
    {
        $junior = User::factory()->create(['rol' => 'junior']);
        $usuario = $this->usuarioConPassword();

        $this->actingAs($junior)
            ->postJson("/usuarios/{$usuario->id}/resetear-totp")
            ->assertForbidden();
    }

    public function test_aceptar_una_invitacion_con_metodo_password_manda_a_enrolar_totp_en_vez_de_loguear(): void
    {
        $usuario = User::factory()->create([
            'rol' => 'cliente',
            'password' => null,
            'metodo_auth' => User::METODO_PASSWORD,
            'invitacion_token' => hash('sha256', 'token-password'),
            'invitacion_expira_en' => now()->addHours(72),
            'activo' => true,
        ]);

        $response = $this->post('/invitaciones/token-password', [
            'password' => self::CONTRASENA,
            'password_confirmation' => self::CONTRASENA,
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('2fa.enrolar'));
        $this->assertTrue(session()->has('2fa.enrolando_user_id'));
    }

    public function test_enrolar_totp_con_codigo_valido_activa_la_cuenta_y_loguea(): void
    {
        $usuario = User::factory()->create([
            'rol' => 'cliente',
            'metodo_auth' => User::METODO_PASSWORD,
            'password' => self::CONTRASENA,
            'activo' => true,
        ]);
        $this->withSession(['2fa.enrolando_user_id' => $usuario->id])->get('/2fa/enrolar');

        $secreto = $usuario->fresh()->totp_secret;
        $this->assertNotNull($secreto);

        $codigo = app(Google2FA::class)->getCurrentOtp($secreto);
        $response = $this->withSession(['2fa.enrolando_user_id' => $usuario->id])
            ->post('/2fa/enrolar', ['codigo' => $codigo]);

        $this->assertAuthenticatedAs($usuario->fresh());
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/CodigosRecuperacion')
            ->has('codigos', 8)
            ->where('continuarA', route('landing')));
        $this->assertNotNull($usuario->fresh()->totp_confirmed_at);
        $this->assertCount(8, $usuario->fresh()->totp_recovery_codes);
    }

    public function test_enrolar_totp_con_codigo_invalido_no_activa_nada(): void
    {
        $usuario = User::factory()->create([
            'rol' => 'cliente',
            'metodo_auth' => User::METODO_PASSWORD,
            'password' => self::CONTRASENA,
            'activo' => true,
        ]);
        $this->withSession(['2fa.enrolando_user_id' => $usuario->id])->get('/2fa/enrolar');

        $response = $this->withSession(['2fa.enrolando_user_id' => $usuario->id])
            ->post('/2fa/enrolar', ['codigo' => '000000']);

        $this->assertGuest();
        $response->assertSessionHasErrors('codigo');
        $this->assertNull($usuario->fresh()->totp_confirmed_at);
    }

    public function test_un_gerente_puede_resetear_la_contrasena_de_un_cliente(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $usuario = $this->usuarioConPassword();

        $response = $this->actingAs($gerente)->postJson("/usuarios/{$usuario->id}/resetear-password");

        $response->assertOk();
        $response->assertJsonStructure(['linkInvitacion']);
        $usuario->refresh();
        $this->assertNull($usuario->password);
        $this->assertNotNull($usuario->invitacion_token);
        $this->assertNotNull($usuario->invitacion_expira_en);
        // El TOTP existente no se toca -- solo la contraseña se resetea.
        $this->assertNotNull($usuario->totp_confirmed_at);
    }

    public function test_un_junior_no_puede_resetear_contrasenas(): void
    {
        $junior = User::factory()->create(['rol' => 'junior']);
        $usuario = $this->usuarioConPassword();

        $this->actingAs($junior)
            ->postJson("/usuarios/{$usuario->id}/resetear-password")
            ->assertForbidden();
    }

    public function test_no_se_puede_resetear_password_de_un_usuario_metodo_google(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $usuario = User::factory()->create(['rol' => 'cliente', 'metodo_auth' => User::METODO_GOOGLE]);

        $this->actingAs($gerente)
            ->postJson("/usuarios/{$usuario->id}/resetear-password")
            ->assertStatus(422);
    }

    public function test_aceptar_el_link_de_reset_pide_totp_de_nuevo_sin_reenrolar(): void
    {
        $gerente = User::factory()->create(['rol' => 'gerente']);
        $usuario = $this->usuarioConPassword();
        $secretoOriginal = $usuario->totp_secret;

        $link = $this->actingAs($gerente)
            ->postJson("/usuarios/{$usuario->id}/resetear-password")
            ->json('linkInvitacion');
        $token = basename(parse_url($link, PHP_URL_PATH));
        $this->post('/logout');

        $response = $this->post("/invitaciones/{$token}", [
            'password' => 'OtraClaveNueva2026',
            'password_confirmation' => 'OtraClaveNueva2026',
        ]);

        // Nunca abre sesión directo -- sigue pidiendo el segundo factor,
        // igual que un login normal, nunca re-enrola (el TOTP ya estaba
        // confirmado).
        $this->assertGuest();
        $response->assertRedirect(route('2fa.verificar'));
        $this->assertSame($secretoOriginal, $usuario->fresh()->totp_secret);

        $codigo = app(Google2FA::class)->getCurrentOtp($secretoOriginal);
        $this->post('/2fa/verificar', ['codigo' => $codigo]);

        $this->assertAuthenticatedAs($usuario->fresh());
    }
}
