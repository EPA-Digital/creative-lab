<?php

namespace Tests\Feature;

use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cuentas publicitarias en Ajustes (pedido explícito 2026-10-05): solo
 * superadmin agrega/edita, y cada alta se verifica contra la API real
 * antes de guardarse.
 */
class CuentasPublicitariasAjustesTest extends TestCase
{
    use RefreshDatabase;

    private Pais $ec;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.access_token' => 'token-meta', 'services.tiktok.access_token' => 'token-tiktok']);
        $this->ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($this->ec->id);

        return $user;
    }

    private function alta(User $user, array $datos = [])
    {
        return $this->actingAs($user)->postJson('/pais/ecuador/ajustes/cuentas-publicitarias', [
            'pais_id' => $this->ec->id,
            'plataforma' => 'meta',
            'cuenta_id' => 'act_123456',
            'nombre' => '',
            'tipo' => 'brd',
            'cuenta_en_venta_real' => true,
            ...$datos,
        ]);
    }

    public function test_superadmin_agrega_una_cuenta_verificada_y_toma_el_nombre_de_la_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ecuador BRD', 'id' => 'act_123456'])]);

        $this->alta($this->usuario('superadmin'))
            ->assertCreated()
            ->assertJsonPath('cuenta_id', '123456')
            ->assertJsonPath('nombre', 'Ecuador BRD')
            ->assertJsonPath('tipo', 'brd');

        $this->assertDatabaseHas('cuentas_publicitarias', ['cuenta_id' => '123456', 'plataforma' => 'meta', 'activa' => true]);
    }

    public function test_si_la_api_no_da_acceso_no_se_guarda(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported get request']], 400)]);

        $this->alta($this->usuario('superadmin'))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'No se pudo leer la cuenta 123456'));

        $this->assertDatabaseCount('cuentas_publicitarias', 0);
    }

    public function test_valida_tiktok_con_ad_get_y_usa_el_nombre_tecleado_o_uno_por_defecto(): void
    {
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0, 'data' => ['list' => [], 'page_info' => ['total_page' => 0]]])]);
        $superadmin = $this->usuario('superadmin');

        $this->alta($superadmin, ['plataforma' => 'tiktok', 'cuenta_id' => '7550000000000000001', 'nombre' => 'TaDa EC nueva'])
            ->assertCreated()
            ->assertJsonPath('nombre', 'TaDa EC nueva');

        $this->alta($superadmin, ['plataforma' => 'tiktok', 'cuenta_id' => '7550000000000000002'])
            ->assertCreated()
            ->assertJsonPath('nombre', 'TikTok 7550000000000000002');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ad/get/'));
    }

    public function test_tiktok_sin_acceso_no_se_guarda(): void
    {
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40001, 'message' => 'Permission error'])]);

        $this->alta($this->usuario('superadmin'), ['plataforma' => 'tiktok', 'cuenta_id' => '7550000000000000003'])
            ->assertStatus(422);

        $this->assertDatabaseCount('cuentas_publicitarias', 0);
    }

    public function test_no_permite_cargar_la_misma_cuenta_dos_veces(): void
    {
        CuentaPublicitaria::create(['pais_id' => $this->ec->id, 'plataforma' => 'meta', 'cuenta_id' => '123456', 'tipo' => 'tada']);

        $this->alta($this->usuario('superadmin'))->assertStatus(422)->assertJsonPath('message', 'Esa cuenta ya está cargada.');
    }

    public function test_solo_superadmin_puede_agregar_o_editar_cuentas(): void
    {
        $cuenta = CuentaPublicitaria::create(['pais_id' => $this->ec->id, 'plataforma' => 'meta', 'cuenta_id' => '999', 'tipo' => 'tada']);

        foreach (['junior', 'gerente', 'director'] as $rol) {
            $user = $this->usuario($rol);
            $this->alta($user)->assertForbidden();
            $this->actingAs($user)
                ->patchJson("/pais/ecuador/ajustes/cuentas-publicitarias/{$cuenta->id}", ['cuenta_en_venta_real' => false])
                ->assertForbidden();
        }
    }

    public function test_superadmin_cambia_los_interruptores_de_una_cuenta(): void
    {
        $cuenta = CuentaPublicitaria::create(['pais_id' => $this->ec->id, 'plataforma' => 'tiktok', 'cuenta_id' => '777', 'tipo' => 'tada']);

        $this->actingAs($this->usuario('superadmin'))
            ->patchJson("/pais/ecuador/ajustes/cuentas-publicitarias/{$cuenta->id}", ['cuenta_en_venta_real' => false, 'tipo' => 'brd'])
            ->assertOk()
            ->assertJsonPath('cuenta_en_venta_real', false)
            ->assertJsonPath('tipo', 'brd');
    }

    public function test_ajustes_muestra_las_cuentas_y_si_puede_gestionarlas(): void
    {
        CuentaPublicitaria::create(['pais_id' => $this->ec->id, 'plataforma' => 'meta', 'cuenta_id' => '1', 'tipo' => 'tada']);

        $this->actingAs($this->usuario('gerente'))->get('/pais/ecuador/ajustes')
            ->assertInertia(fn ($page) => $page->has('cuentasPublicitarias', 1)->where('puedeGestionarCuentas', false));

        $this->actingAs($this->usuario('superadmin'))->get('/pais/ecuador/ajustes')
            ->assertInertia(fn ($page) => $page->where('puedeGestionarCuentas', true));
    }

    public function test_los_totales_de_venta_real_deben_ser_enteros(): void
    {
        $this->actingAs($this->usuario('superadmin'))
            ->postJson('/pais/ecuador/importar/api', [
                'desde' => now()->startOfMonth()->toDateString(),
                'hasta' => now()->endOfMonth()->toDateString(),
                'nc_total_real_tiktok' => '1.039',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'sin separador de miles'));
    }
}
