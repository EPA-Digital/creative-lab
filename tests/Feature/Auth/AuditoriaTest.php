<?php

namespace Tests\Feature\Auth;

use App\Models\AuditoriaAcceso;
use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * auth-prompt.md Fase 5 -- eventos clave quedan en auditoria_accesos.
 * Nunca verifica CONTENIDO de contraseñas/tokens/códigos, solo que el
 * evento se registró.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_login_google_exitoso_queda_registrado(): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn('google-1');
        $socialiteUser->shouldReceive('getEmail')->andReturn('nueva@epa.digital');
        $socialiteUser->shouldReceive('getName')->andReturn('Nueva');
        $socialiteUser->shouldReceive('getRaw')->andReturn(['email_verified' => true, 'hd' => 'epa.digital']);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback');

        $this->assertDatabaseHas('auditoria_accesos', ['evento' => 'login.google.exito']);
    }

    public function test_un_login_google_rechazado_queda_registrado(): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn('google-1');
        $socialiteUser->shouldReceive('getEmail')->andReturn('cualquiera@gmail.com');
        $socialiteUser->shouldReceive('getName')->andReturn('Cualquiera');
        $socialiteUser->shouldReceive('getRaw')->andReturn(['email_verified' => true]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback');

        $this->assertDatabaseHas('auditoria_accesos', [
            'evento' => 'login.google.rechazado',
            'email_intentado' => 'cualquiera@gmail.com',
        ]);
    }

    public function test_crear_una_invitacion_queda_registrado(): void
    {
        $epa = User::factory()->create(['rol' => 'director']);

        $this->actingAs($epa)->postJson('/usuarios', [
            'name' => 'Cliente Nuevo',
            'email' => 'cliente@afuera.com',
        ]);

        $this->assertDatabaseHas('auditoria_accesos', ['evento' => 'invitacion.creada']);
    }

    public function test_desactivar_un_usuario_queda_registrado(): void
    {
        $director = User::factory()->create(['rol' => 'director']);
        $junior = User::factory()->create(['rol' => 'junior']);

        $this->actingAs($director)->deleteJson("/usuarios/{$junior->id}");

        $this->assertDatabaseHas('auditoria_accesos', ['evento' => 'usuario.desactivado', 'usuario_id' => $junior->id]);
    }

    public function test_la_primera_visita_a_un_pais_en_la_sesion_queda_registrada_una_sola_vez(): void
    {
        $ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $director = User::factory()->create(['rol' => 'director']);
        $director->paises()->attach($ec->id);

        $this->actingAs($director)->get('/pais/ecuador/analisis');
        $this->actingAs($director)->get('/pais/ecuador/analisis');

        $this->assertSame(1, AuditoriaAcceso::where('evento', 'pais.primera_visita')->count());
    }

    public function test_reactivar_un_usuario_queda_registrado(): void
    {
        $director = User::factory()->create(['rol' => 'director']);
        $junior = User::factory()->create(['rol' => 'junior', 'activo' => false]);

        $this->actingAs($director)->postJson("/usuarios/{$junior->id}/reactivar");

        $this->assertDatabaseHas('auditoria_accesos', ['evento' => 'usuario.reactivado', 'usuario_id' => $junior->id]);
    }

    public function test_eliminar_un_usuario_queda_registrado(): void
    {
        $superadmin = User::factory()->create(['rol' => 'superadmin']);
        $junior = User::factory()->create(['rol' => 'junior']);

        $this->actingAs($superadmin)->deleteJson("/usuarios/{$junior->id}/eliminar");

        $this->assertDatabaseHas('auditoria_accesos', ['evento' => 'usuario.eliminado', 'email_intentado' => $junior->email]);
    }

    public function test_el_logout_queda_registrado(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post('/logout');

        $this->assertDatabaseHas('auditoria_accesos', ['evento' => 'logout', 'usuario_id' => $usuario->id]);
    }
}
