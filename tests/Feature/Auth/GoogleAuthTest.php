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

    private function mockearRespuestaGoogle(string $email, string $googleId = 'google-123', string $nombre = 'Usuario Google'): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($googleId);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($nombre);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_un_correo_epa_digital_nuevo_se_auto_provisiona_con_acceso_completo(): void
    {
        $this->mockearRespuestaGoogle('nueva@epa.digital');

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticated();
        $response->assertRedirect(route('landing', absolute: false));

        $usuario = User::where('email', 'nueva@epa.digital')->firstOrFail();
        $this->assertTrue($usuario->esEpa());
        $this->assertTrue($usuario->activo);
        $this->assertNull($usuario->password);
    }

    public function test_un_correo_que_no_termina_en_epa_digital_es_rechazado_sin_crear_sesion_ni_usuario(): void
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
        $this->mockearRespuestaGoogle('exempleado@epa.digital');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }
}
