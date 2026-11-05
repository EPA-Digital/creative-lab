<?php

namespace Tests\Feature\MultiCuenta;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\User;
use App\Services\Ingesta\EnriquecedorAppsFlyerApi;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alta y edición de cuentas (CuentasController): cualquier persona de EPA
 * da de alta; las conexiones nacen en la cuenta nueva, nunca en TaDa; una
 * cuenta publicitaria no puede estar en dos cuentas.
 */
class CuentasCrudTest extends TestCase
{
    use RefreshDatabase;

    private Pais $mx;

    private bool $metaSinAcceso = false;

    private bool $appsflyerSinAcceso = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.access_token' => 'token-meta', 'services.tiktok.access_token' => 'token-tiktok']);
        $this->mx = Pais::create(['codigo' => 'MX', 'nombre' => 'México']);
        Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        Http::fake(fn ($request) => match (true) {
            str_contains($request->url(), 'graph.facebook.com') => $this->metaSinAcceso
                ? Http::response(['error' => ['message' => 'sin permiso']], 403)
                : Http::response(['name' => 'Openbank MX · Performance', 'id' => 'act_777']),
            str_contains($request->url(), 'appsflyer.com') => $this->appsflyerSinAcceso
                ? Http::response('Unauthorized', 401)
                : Http::response("Media Source (pid),Installs\nOrganic,3\n"),
            default => null,
        });
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($this->mx->id);

        return $user;
    }

    private function datos(array $extra = []): array
    {
        return [
            'nombre' => 'Openbank',
            'cliente' => 'Openbank',
            'color' => '#ff8f84',
            'servicios' => ['App'],
            'objetivos' => ['Instalaciones'],
            'medios' => ['meta', 'tiktok'],
            'fuente_ga4' => false,
            'fuente_mmp' => true,
            'venta_real' => 'no',
            'multi_pais' => false,
            'paises' => ['MX'],
            'conexiones_nuevas' => [['pais' => 'MX', 'plataforma' => 'meta', 'id_externo' => 'act_777', 'nombre' => '']],
            'apps_nuevas' => [['pais' => 'MX', 'plataforma' => 'ios', 'app_id' => 'id999', 'nombre' => 'Openbank iOS']],
            ...$extra,
        ];
    }

    public function test_una_persona_de_epa_crea_una_cuenta_y_sus_conexiones_nacen_en_ella(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())
            ->assertCreated()
            ->assertJsonPath('slug', 'openbank');

        $openbank = Cuenta::where('slug', 'openbank')->firstOrFail();
        $this->assertSame('Openbank', $openbank->cliente->nombre);
        $this->assertSame(['MX'], $openbank->paises->pluck('codigo')->all());

        $conexion = $openbank->cuentasPublicitarias()->firstOrFail();
        $this->assertSame('777', $conexion->id_externo);
        $this->assertSame('Openbank MX · Performance', $conexion->nombre);
        $this->assertSame(1, $openbank->appsflyerApps()->count());

        // Nada quedó en TaDa.
        CuentaActual::usar(Cuenta::tada(), fn () => $this->assertSame(0, CuentaPublicitaria::count()));
    }

    public function test_un_cliente_no_puede_ver_ni_crear_cuentas(): void
    {
        $cliente = $this->usuario('cliente');

        $this->actingAs($cliente)->get('/cuentas')->assertForbidden();
        $this->actingAs($cliente)->postJson('/cuentas', $this->datos())->assertForbidden();
    }

    public function test_la_lista_muestra_las_cuentas_agrupables_por_cliente(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())->assertCreated();

        $this->actingAs($this->usuario('junior'))->get('/cuentas')
            ->assertInertia(fn ($page) => $page->component('Cuentas/Index')
                ->has('cuentas', 2)
                ->where('cuentas.0.nombre', 'Openbank')
                ->where('cuentas.0.conexiones', 1)
                ->where('cuentas.1.nombre', 'TaDa'));
    }

    public function test_no_se_puede_conectar_una_cuenta_publicitaria_que_ya_esta_en_otra_cuenta(): void
    {
        CuentaActual::usar(Cuenta::tada(), fn () => CuentaPublicitaria::create(['pais_id' => $this->mx->id, 'plataforma' => 'meta', 'id_externo' => '777', 'tipo' => 'tada']));

        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['conexiones_nuevas.0.id_externo']);

        $this->assertFalse(Cuenta::where('slug', 'openbank')->exists());
    }

    public function test_si_la_api_no_da_acceso_no_se_crea_nada(): void
    {
        $this->metaSinAcceso = true;

        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['conexiones_nuevas.0.id_externo']);

        $this->assertFalse(Cuenta::where('slug', 'openbank')->exists());
    }

    public function test_instalaciones_y_appsflyer_requieren_el_servicio_app(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos(['servicios' => ['Ecommerce']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objetivos', 'fuente_mmp']);
    }

    public function test_multi_pais_exige_al_menos_dos_paises(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos(['multi_pais' => true, 'paises' => ['MX']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paises']);
    }

    public function test_editar_actualiza_la_cuenta_y_apaga_una_conexion(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())->assertCreated();
        $openbank = Cuenta::where('slug', 'openbank')->firstOrFail();
        $conexion = $openbank->cuentasPublicitarias()->firstOrFail();

        $this->actingAs($this->usuario('junior'))->putJson('/cuentas/openbank', $this->datos([
            'objetivos' => ['Instalaciones', 'Leads'],
            'venta_real' => 'manual',
            'conexiones_nuevas' => [],
            'apps_nuevas' => [],
            'conexiones_activas' => [$conexion->id => false],
        ]))->assertOk();

        $openbank->refresh();
        $this->assertSame(['Instalaciones', 'Leads'], $openbank->objetivos);
        $this->assertSame('manual', $openbank->venta_real);
        $this->assertFalse($conexion->fresh()->activa);
    }

    public function test_editar_no_puede_tocar_conexiones_de_otra_cuenta(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())->assertCreated();
        $deTada = CuentaActual::usar(Cuenta::tada(), fn () => CuentaPublicitaria::create(['pais_id' => $this->mx->id, 'plataforma' => 'meta', 'id_externo' => '1', 'tipo' => 'tada', 'activa' => true]));

        $this->actingAs($this->usuario('junior'))->putJson('/cuentas/openbank', $this->datos([
            'conexiones_nuevas' => [], 'apps_nuevas' => [], 'conexiones_activas' => [$deTada->id => false],
        ]))->assertOk();

        $this->assertTrue(CuentaPublicitaria::withoutGlobalScope('cuenta')->find($deTada->id)->activa);
    }

    public function test_desactivar_es_de_director_y_pide_el_nombre_exacto(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())->assertCreated();

        $this->actingAs($this->usuario('junior'))->postJson('/cuentas/openbank/desactivar', ['confirmacion' => 'Openbank'])->assertForbidden();
        $this->actingAs($this->usuario('director'))->postJson('/cuentas/openbank/desactivar', ['confirmacion' => 'Open'])->assertStatus(422);
        $this->actingAs($this->usuario('director'))->postJson('/cuentas/openbank/desactivar', ['confirmacion' => 'Openbank'])->assertOk();

        $this->assertFalse(Cuenta::where('slug', 'openbank')->first()->activa);
    }

    public function test_el_cliente_existente_se_reutiliza(): void
    {
        Cliente::create(['nombre' => 'Coppel']);

        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos([
            'nombre' => 'Coppel Branding', 'cliente' => 'Coppel', 'servicios' => ['Branding'], 'objetivos' => ['Awareness'],
            'fuente_mmp' => false, 'conexiones_nuevas' => [], 'apps_nuevas' => [],
        ]))->assertCreated()->assertJsonPath('slug', 'coppel-branding');

        $this->assertSame(1, Cliente::where('nombre', 'Coppel')->count());
    }

    public function test_cada_cuenta_guarda_su_propio_token_de_appsflyer_cifrado_y_sin_mostrarlo(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos([
            'appsflyer_token' => 'token-openbank-1234',
            'appsflyer_evento_principal' => 'onb_success_n2',
            'appsflyer_evento_secundario' => 'onb_success_tdc',
        ]))->assertCreated();

        $openbank = Cuenta::where('slug', 'openbank')->firstOrFail();
        $credencial = $openbank->credencial('appsflyer');
        $this->assertSame('token-openbank-1234', $credencial->token);
        $this->assertSame('1234', $credencial->ultimos4);
        $this->assertNotNull($credencial->validado_en);
        $this->assertSame('onb_success_n2', $credencial->ajustes['evento_principal']);
        // En la base va cifrado.
        $this->assertStringNotContainsString('token-openbank', DB::table('cuenta_credenciales')->where('id', $credencial->id)->value('token'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'appsflyer.com/api/master-agg-data/v4/app/id999') && $r->hasHeader('Authorization', 'Bearer token-openbank-1234'));

        $this->actingAs($this->usuario('junior'))->get('/cuentas/openbank/editar')
            ->assertInertia(fn ($page) => $page->where('cuenta.credenciales.appsflyer.tiene_token', true)
                ->where('cuenta.credenciales.appsflyer.ultimos4', '1234')
                ->missing('cuenta.credenciales.appsflyer.token'))
            ->assertDontSee('token-openbank-1234');
    }

    public function test_un_token_sin_acceso_a_las_apps_no_se_guarda(): void
    {
        $this->appsflyerSinAcceso = true;

        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos([
            'appsflyer_token' => 'token-de-otro-cliente', 'appsflyer_evento_principal' => 'onb_success_n2',
        ]))->assertStatus(422)->assertJsonValidationErrors(['appsflyer_token']);

        $this->assertFalse(Cuenta::where('slug', 'openbank')->exists());
    }

    public function test_con_token_el_evento_principal_es_obligatorio(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos(['appsflyer_token' => 'token-openbank-1234']))
            ->assertStatus(422)->assertJsonValidationErrors(['appsflyer_evento_principal']);
    }

    public function test_sin_token_la_lista_lo_marca_y_editar_sin_token_conserva_el_guardado(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())->assertCreated();
        $this->actingAs($this->usuario('junior'))->get('/cuentas')
            ->assertInertia(fn ($page) => $page->where('cuentas.0.nombre', 'Openbank')->where('cuentas.0.sin_token_appsflyer', true));

        $sinCambios = ['conexiones_nuevas' => [], 'apps_nuevas' => [], 'appsflyer_evento_principal' => 'onb_success_n2'];
        $this->actingAs($this->usuario('junior'))->putJson('/cuentas/openbank', $this->datos([...$sinCambios, 'appsflyer_token' => 'token-openbank-1234']))->assertOk();
        $this->actingAs($this->usuario('junior'))->putJson('/cuentas/openbank', $this->datos([...$sinCambios, 'appsflyer_token' => null]))->assertOk();
        $this->assertSame('token-openbank-1234', Cuenta::where('slug', 'openbank')->first()->credencial('appsflyer')->token);

        $this->actingAs($this->usuario('junior'))->putJson('/cuentas/openbank', $this->datos([...$sinCambios, 'appsflyer_quitar_token' => true]))->assertOk();
        $this->assertFalse(Cuenta::where('slug', 'openbank')->first()->credencial('appsflyer')->tieneToken());
    }

    public function test_importar_por_api_usa_el_token_y_los_eventos_de_la_cuenta(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos([
            'appsflyer_token' => 'token-openbank-1234', 'appsflyer_evento_principal' => 'onb_success_n2', 'appsflyer_evento_secundario' => 'onb_success_tdc',
        ]))->assertCreated();

        EnriquecedorAppsFlyerApi::paraCuenta(Cuenta::where('slug', 'openbank')->firstOrFail())->enriquecer(['id999'], '2026-11-01', '2026-11-03');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'groupings=')
            && $r->hasHeader('Authorization', 'Bearer token-openbank-1234')
            && $r['kpis'] === 'installs,event_counter_onb_success_n2,event_counter_onb_success_tdc');
    }

    public function test_importar_por_api_sin_token_falla_con_un_mensaje_claro(): void
    {
        $this->actingAs($this->usuario('junior'))->postJson('/cuentas', $this->datos())->assertCreated();
        $openbank = Cuenta::where('slug', 'openbank')->firstOrFail();

        $this->expectExceptionMessage('Openbank no tiene token de AppsFlyer');
        EnriquecedorAppsFlyerApi::paraCuenta($openbank->fresh());
    }
}
