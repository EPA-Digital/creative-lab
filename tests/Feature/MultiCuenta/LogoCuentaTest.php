<?php

namespace Tests\Feature\MultiCuenta;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Logo propio de cada cuenta (LogoCuentaController): lo sube EPA, lo ve
 * quien tiene la cuenta, y solo acepta imágenes rasterizadas.
 */
class LogoCuentaTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $openbank;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.disks.gcs.bucket' => null]);
        $this->openbank = Cuenta::create([
            'cliente_id' => Cliente::create(['nombre' => 'Openbank'])->id,
            'nombre' => 'Openbank', 'slug' => 'openbank',
            'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta'],
        ]);
    }

    public function test_epa_sube_el_logo_y_se_sirve_desde_el_dashboard(): void
    {
        $epa = User::factory()->create(['rol' => 'junior']);

        $r = $this->actingAs($epa)->post('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->image('openbank.png', 200, 200)])
            ->assertOk();

        $this->assertStringStartsWith('/cuentas/openbank/logo?v=', $r->json('logo'));
        $ruta = $this->openbank->fresh()->logo_url;
        Storage::disk('local')->assertExists($ruta);

        $this->actingAs($epa)->get('/cuentas/openbank/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_reemplazar_borra_el_anterior_y_quitar_lo_borra(): void
    {
        $epa = User::factory()->create(['rol' => 'junior']);
        $this->actingAs($epa)->post('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->image('a.png', 64, 64)]);
        $anterior = $this->openbank->fresh()->logo_url;

        $this->actingAs($epa)->post('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->image('b.jpg', 64, 64)])->assertOk();
        Storage::disk('local')->assertMissing($anterior);

        $nuevo = $this->openbank->fresh()->logo_url;
        $this->actingAs($epa)->deleteJson('/cuentas/openbank/logo')->assertOk()->assertJsonPath('logo', null);
        Storage::disk('local')->assertMissing($nuevo);
        $this->assertNull($this->openbank->fresh()->logo_url);
    }

    public function test_solo_imagenes_rasterizadas_de_hasta_1_mb(): void
    {
        $epa = User::factory()->create(['rol' => 'junior']);

        $this->actingAs($epa)->postJson('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])
            ->assertStatus(422)->assertJsonPath('errors.logo.0', 'El logo debe ser PNG, JPG o WebP.');
        $this->actingAs($epa)->postJson('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->image('grande.png', 400, 400)->size(2048)])
            ->assertStatus(422)->assertJsonValidationErrors(['logo']);
    }

    public function test_un_cliente_ve_el_logo_de_su_cuenta_pero_no_el_de_otras_ni_puede_subir(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'junior']))
            ->post('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->image('a.png', 64, 64)]);

        $cliente = User::factory()->create(['rol' => 'cliente']); // tiene TaDa, no Openbank
        $this->actingAs($cliente)->get('/cuentas/openbank/logo')->assertForbidden();
        $this->actingAs($cliente)->post('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->image('a.png', 64, 64)])->assertForbidden();

        $cliente->cuentas()->attach($this->openbank->id);
        $this->actingAs($cliente)->get('/cuentas/openbank/logo')->assertOk();
    }

    public function test_el_nav_recibe_el_logo_de_cada_cuenta(): void
    {
        $epa = User::factory()->create(['rol' => 'junior']);
        $epa->cuentas()->attach($this->openbank->id);
        $this->actingAs($epa)->post('/cuentas/openbank/logo', ['logo' => UploadedFile::fake()->image('a.png', 64, 64)]);

        $this->actingAs($epa)->get('/cuentas')
            ->assertInertia(fn ($page) => $page
                ->where('misCuentas.0.slug', 'openbank')
                ->where('misCuentas.0.logo', fn ($logo) => str_starts_with($logo, '/cuentas/openbank/logo?v='))
                ->where('misCuentas.1.logo', null));
    }
}
