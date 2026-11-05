<?php

namespace Tests\Feature\MultiCuenta;

use App\Models\Cliente;
use App\Models\Creativo;
use App\Models\Cuenta;
use App\Models\Pais;
use App\Models\User;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entrega 2 multi-cuenta: URLs /{cuenta}/pais/{pais}/..., acceso por cuenta
 * (usuario_cuenta) además de por país, selector de cuenta al entrar y
 * asignación de cuentas desde Usuarios.
 */
class AccesoPorCuentaTest extends TestCase
{
    use RefreshDatabase;

    private Pais $mx;

    private Pais $ec;

    private Cuenta $openbank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mx = Pais::create(['codigo' => 'MX', 'nombre' => 'México']);
        $this->ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->openbank = Cuenta::create([
            'cliente_id' => Cliente::create(['nombre' => 'Openbank'])->id,
            'nombre' => 'Openbank',
            'slug' => 'openbank',
            'servicios' => ['App'],
            'objetivos' => ['Instalaciones'],
            'medios' => ['meta', 'tiktok'],
        ]);
        $this->openbank->paises()->attach($this->mx->id);
    }

    private function usuario(string $rol, array $cuentas = [], ?array $paises = null): User
    {
        $user = User::factory()->create(['rol' => $rol]);
        $user->cuentas()->syncWithoutDetaching(collect($cuentas)->map(fn (Cuenta $c) => $c->id)->all());
        $user->paises()->attach($paises ?? [$this->mx->id, $this->ec->id]);

        return $user;
    }

    public function test_sin_la_cuenta_asignada_no_entra_aunque_tenga_el_pais(): void
    {
        $this->actingAs($this->usuario('director'))
            ->get('/openbank/pais/mexico/analisis')
            ->assertForbidden();
    }

    public function test_con_la_cuenta_y_el_pais_entra_y_la_pagina_sabe_en_que_cuenta_esta(): void
    {
        $this->actingAs($this->usuario('junior', [$this->openbank]))
            ->get('/openbank/pais/mexico/analisis')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pais', 'mexico')
                ->where('cuentaActual.slug', 'openbank')
                ->where('cuentaActual.otras', 1));
    }

    public function test_un_pais_donde_la_cuenta_no_opera_da_404(): void
    {
        $this->actingAs($this->usuario('junior', [$this->openbank]))
            ->get('/openbank/pais/ecuador/analisis')
            ->assertNotFound();
    }

    public function test_una_cuenta_desactivada_no_se_puede_ver(): void
    {
        $this->openbank->update(['activa' => false]);

        $this->actingAs($this->usuario('junior', [$this->openbank]))
            ->get('/openbank/pais/mexico/analisis')
            ->assertForbidden();
    }

    public function test_una_cuenta_que_no_existe_da_404(): void
    {
        $this->actingAs($this->usuario('junior'))
            ->get('/coppel/pais/mexico/analisis')
            ->assertNotFound();
    }

    public function test_las_urls_de_antes_van_a_tada_con_su_query(): void
    {
        $this->actingAs($this->usuario('junior'))
            ->get('/pais/ecuador/importar/mensual?mes=2026-10')
            ->assertRedirect('/tada/pais/ecuador/importar/mensual?mes=2026-10');
    }

    public function test_un_creativo_de_otra_cuenta_no_se_alcanza_por_la_url(): void
    {
        $deTada = CuentaActual::usar(Cuenta::tada(), fn () => Creativo::create([
            'ad_id' => '111', 'pais_id' => $this->mx->id, 'nombre_comun' => 'ARTE-TADA', 'nombre_completo' => 'ARTE-TADA',
            'plataforma' => 'meta', 'funnel' => 'CNV', 'tipo_cuenta' => 'DTC',
        ]));

        $this->actingAs($this->usuario('junior', [$this->openbank]))
            ->get("/openbank/pais/mexico/creativos/{$deTada->id}/video")
            ->assertNotFound();
    }

    public function test_con_varias_cuentas_el_landing_pide_elegir_cuenta(): void
    {
        $this->actingAs($this->usuario('junior', [$this->openbank], [$this->mx->id]))
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Landing')
                ->has('cuentas', 2)
                ->where('cuentas.0.slug', 'openbank')
                ->has('cuentas.0.paises', 1)
                ->where('cuentas.1.slug', 'tada'));
    }

    public function test_con_una_sola_cuenta_y_un_pais_salta_directo(): void
    {
        $user = $this->usuario('cliente', [$this->openbank], [$this->mx->id]);
        $user->cuentas()->detach(Cuenta::tada()->id);

        $this->actingAs($user)->get('/')->assertRedirect('/openbank/pais/mexico/analisis');
    }

    public function test_quien_crea_una_cuenta_la_ve(): void
    {
        $user = $this->usuario('junior');

        $this->actingAs($user)->postJson('/cuentas', [
            'nombre' => 'Chedraui Ecomm', 'cliente' => 'Chedraui', 'color' => '#ff8f84',
            'servicios' => ['Ecommerce'], 'objetivos' => ['Ventas'], 'medios' => ['meta'],
            'venta_real' => 'no', 'multi_pais' => false, 'paises' => ['MX'],
        ])->assertCreated();

        $this->assertTrue($user->cuentas()->where('slug', 'chedraui-ecomm')->exists());
    }

    public function test_una_cuenta_no_puede_tomar_el_slug_de_una_ruta(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', [
            'nombre' => 'Pais', 'cliente' => 'Prueba', 'color' => '#ff8f84',
            'servicios' => ['Branding'], 'objetivos' => ['Awareness'], 'medios' => ['meta'],
            'venta_real' => 'no', 'multi_pais' => false, 'paises' => ['MX'],
        ])->assertCreated()->assertJsonPath('slug', 'pais-2');
    }

    public function test_nadie_otorga_una_cuenta_que_no_tiene(): void
    {
        $gerente = $this->usuario('gerente');
        $junior = $this->usuario('junior');

        $this->actingAs($gerente)->patchJson("/usuarios/{$junior->id}", [
            'rol' => 'junior', 'pais_ids' => [$this->mx->id], 'cuenta_ids' => [$this->openbank->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['cuenta_ids.0']);
    }

    public function test_guardar_conserva_las_cuentas_que_quien_edita_no_ve(): void
    {
        $gerente = $this->usuario('gerente');
        $junior = $this->usuario('junior', [$this->openbank]);

        // El gerente solo tiene TaDa: le quita TaDa, Openbank se queda.
        $this->actingAs($gerente)->patchJson("/usuarios/{$junior->id}", [
            'rol' => 'junior', 'pais_ids' => [$this->mx->id], 'cuenta_ids' => [],
        ])->assertOk();

        $this->assertSame(['openbank'], $junior->cuentas()->pluck('slug')->all());
    }

    public function test_invitar_con_cuentas_las_asigna(): void
    {
        $director = $this->usuario('director', [$this->openbank]);

        $r = $this->actingAs($director)->postJson('/usuarios', [
            'name' => 'Cliente Openbank', 'email' => 'cliente@openbank.mx',
            'pais_ids' => [$this->mx->id], 'cuenta_ids' => [$this->openbank->id],
            'metodo' => 'password', 'motivo' => 'Cliente sin Google',
        ])->assertCreated();

        $this->assertSame(['openbank'], User::find($r->json('usuario.id'))->cuentas()->pluck('slug')->all());
    }

    public function test_la_sincronizacion_programada_salta_cuentas_sin_token(): void
    {
        $this->openbank->update(['fuente_mmp' => true]);

        $this->artisan('importar:appsflyer-api')
            ->expectsOutputToContain('Openbank no tiene token de AppsFlyer')
            ->assertSuccessful();
    }
}
