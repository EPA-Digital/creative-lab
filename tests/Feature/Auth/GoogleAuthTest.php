<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Único camino de acceso completo (rol !== 'cliente') -- restringido al
 * dominio epa.digital, ver GoogleAuthController y User::DOMINIO_EPA. Mockea
 * Socialite (sin llamar a Google real) siguiendo el patrón documentado del
 * paquete.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function mockearRespuestaGoogle(
        string $email,
        string $googleId = 'google-123',
        string $nombre = 'Usuario Google',
        bool $emailVerificado = true,
        ?string $hd = null,
    ): void {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($googleId);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($nombre);
        $socialiteUser->shouldReceive('getRaw')->andReturn([
            'email_verified' => $emailVerificado,
            'hd' => $hd,
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_un_correo_epa_digital_nuevo_se_auto_provisiona_con_acceso_completo(): void
    {
        $this->mockearRespuestaGoogle('nueva@epa.digital', hd: 'epa.digital');

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticated();
        $response->assertRedirect(route('landing', absolute: false));

        $usuario = User::where('email', 'nueva@epa.digital')->firstOrFail();
        $this->assertTrue($usuario->esEpa());
        $this->assertTrue($usuario->activo);
        $this->assertNull($usuario->password);
        $this->assertSame(User::METODO_GOOGLE, $usuario->metodo_auth);
    }

    public function test_un_correo_que_no_termina_en_epa_digital_y_sin_invitacion_es_rechazado(): void
    {
        $this->mockearRespuestaGoogle('cualquiera@gmail.com');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'cualquiera@gmail.com']);
    }

    public function test_un_usuario_epa_desactivado_no_puede_entrar_por_google(): void
    {
        User::factory()->create(['email' => 'exempleado@epa.digital', 'rol' => 'director', 'activo' => false]);
        $this->mockearRespuestaGoogle('exempleado@epa.digital', hd: 'epa.digital');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_un_correo_epa_digital_sin_claim_hd_es_rechazado(): void
    {
        $this->mockearRespuestaGoogle('sospechoso@epa.digital', hd: null);

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'sospechoso@epa.digital']);
    }

    public function test_un_correo_con_email_verified_false_es_rechazado(): void
    {
        $this->mockearRespuestaGoogle('nueva@epa.digital', hd: 'epa.digital', emailVerificado: false);

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'nueva@epa.digital']);
    }

    public function test_un_cliente_invitado_y_activo_con_metodo_google_entra_sin_auto_provisionarse(): void
    {
        $cliente = User::factory()->create([
            'email' => 'cliente@afuera.com',
            'rol' => 'cliente',
            'metodo_auth' => User::METODO_GOOGLE,
            'activo' => true,
            'google_id' => null,
        ]);
        $this->mockearRespuestaGoogle('cliente@afuera.com', googleId: 'google-cliente-1');

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($cliente->fresh());
        $response->assertRedirect(route('landing', absolute: false));
        $this->assertSame('google-cliente-1', $cliente->fresh()->google_id);
    }

    public function test_un_cliente_sin_invitacion_previa_no_se_auto_provisiona_por_google(): void
    {
        $this->mockearRespuestaGoogle('desconocido@afuera.com');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'desconocido@afuera.com']);
    }

    public function test_un_cliente_con_google_id_distinto_al_guardado_es_rechazado(): void
    {
        User::factory()->create([
            'email' => 'cliente@afuera.com',
            'rol' => 'cliente',
            'metodo_auth' => User::METODO_GOOGLE,
            'activo' => true,
            'google_id' => 'google-original',
        ]);
        $this->mockearRespuestaGoogle('cliente@afuera.com', googleId: 'google-suplantador');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_un_usuario_con_metodo_auth_password_no_puede_entrar_por_google(): void
    {
        User::factory()->create([
            'email' => 'cliente@afuera.com',
            'rol' => 'cliente',
            'metodo_auth' => User::METODO_PASSWORD,
            'activo' => true,
            'password' => 'hash-cualquiera',
        ]);
        $this->mockearRespuestaGoogle('cliente@afuera.com');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }
}
