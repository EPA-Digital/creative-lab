<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Editar perfil" del nav (PerfilController): nombre, puesto, color del
 * avatar y foto, visibles para el equipo.
 */
class PerfilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.disks.gcs.bucket' => null]);
    }

    public function test_cada_quien_edita_su_nombre_puesto_y_color(): void
    {
        $user = User::factory()->create(['rol' => 'junior', 'name' => 'Superadmin']);

        $this->actingAs($user)->patchJson('/perfil', ['name' => ' Fer ', 'puesto' => 'Analista de performance', 'avatar_color' => '#59e0bd'])
            ->assertOk()
            ->assertJsonPath('usuario.name', 'Fer')
            ->assertJsonPath('usuario.puesto', 'Analista de performance');

        $this->assertSame('#59e0bd', $user->fresh()->avatar_color);

        // El nav lo recibe en auth.user.
        $this->actingAs($user)->get('/cuentas')
            ->assertInertia(fn ($page) => $page->where('auth.user.puesto', 'Analista de performance')->where('auth.user.avatar_color', '#59e0bd'));
    }

    public function test_valida_nombre_y_color(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/perfil', ['name' => '', 'avatar_color' => '#000000'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Escribe tu nombre.')
            ->assertJsonValidationErrors(['avatar_color']);
    }

    public function test_sube_cambia_y_quita_su_foto(): void
    {
        $user = User::factory()->create();

        $r = $this->actingAs($user)->post('/perfil/foto', ['foto' => UploadedFile::fake()->image('yo.jpg', 200, 200)])->assertOk();
        $this->assertStringStartsWith("/usuarios/{$user->id}/foto?v=", $r->json('usuario.foto'));
        $primera = $user->fresh()->foto_path;

        $this->actingAs($user)->post('/perfil/foto', ['foto' => UploadedFile::fake()->image('yo2.png', 100, 100)])->assertOk();
        Storage::disk('local')->assertMissing($primera);

        // Cualquiera del equipo con sesión la ve.
        $this->actingAs(User::factory()->create(['rol' => 'cliente']))->get("/usuarios/{$user->id}/foto")->assertOk();

        $this->actingAs($user)->deleteJson('/perfil/foto')->assertOk()->assertJsonPath('usuario.foto', null);
        $this->actingAs($user)->get("/usuarios/{$user->id}/foto")->assertNotFound();
    }

    public function test_la_foto_no_acepta_svg_ni_sin_sesion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/perfil/foto', ['foto' => UploadedFile::fake()->create('yo.svg', 3, 'image/svg+xml')])
            ->assertStatus(422)->assertJsonPath('errors.foto.0', 'La foto debe ser PNG, JPG o WebP.');

        auth()->logout();
        $this->get("/usuarios/{$user->id}/foto")->assertRedirect('/login');
    }
}
