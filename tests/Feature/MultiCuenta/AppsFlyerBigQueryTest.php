<?php

namespace Tests\Feature\MultiCuenta;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\CuentaCredencial;
use App\Models\Pais;
use App\Models\User;
use App\Services\Ingesta\BigQueryClient;
use App\Services\Ingesta\EnriquecedorAppsFlyerBigQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AppsFlyer desde BigQuery (Data Locker de EPA, 2026-11-05) -- listo para
 * cuando la cuenta de servicio del dashboard tenga lector en el dataset.
 * BigQuery se simula con Http::fake (API REST).
 */
class AppsFlyerBigQueryTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $openbank;

    private const AJUSTES = [
        'origen' => 'bigquery',
        'bq_proyecto' => 'bdd-epa-digital',
        'bq_dataset' => 'appsflyeropenbank',
        'evento_principal' => 'onb_success_n2',
        'evento_secundario' => 'onb_success_tdc',
        'zona_horaria' => 'America/Mexico_City',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(BigQueryClient::class, fn () => new BigQueryClient(fn () => 'token-de-prueba', 'epa-turing'));
        $this->openbank = Cuenta::create([
            'cliente_id' => Cliente::create(['nombre' => 'Openbank'])->id,
            'nombre' => 'Openbank', 'slug' => 'openbank', 'fuente_mmp' => true,
            'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta', 'tiktok'],
        ]);
        CuentaCredencial::create(['cuenta_id' => $this->openbank->id, 'proveedor' => 'appsflyer', 'ajustes' => self::AJUSTES]);
    }

    private function respuestaConsulta(array $filas): array
    {
        $campos = ['fecha', 'af_ad_id', 'campaign', 'ad', 'conjunto', 'installs', 'nc', 'orders'];

        return [
            'jobComplete' => true,
            'jobReference' => ['jobId' => 'job_1', 'location' => 'us-central1'],
            'schema' => ['fields' => array_map(fn ($c) => ['name' => $c], $campos)],
            'rows' => array_map(fn ($f) => ['f' => array_map(fn ($c) => ['v' => $f[$c] ?? null], $campos)], $filas),
        ];
    }

    public function test_lee_appsflyer_de_bigquery_con_la_misma_forma_que_la_api(): void
    {
        Http::fake(['bigquery.googleapis.com/bigquery/v2/projects/epa-turing/queries' => Http::response($this->respuestaConsulta([
            ['fecha' => '2026-10-01', 'af_ad_id' => '120251056123310309', 'campaign' => 'fb_obmx_lead_do', 'ad' => 'fb_obmx_outfit-match', 'conjunto' => 'fb_obmx_lal', 'installs' => '12', 'nc' => '3', 'orders' => '1'],
            ['fecha' => '2026-10-02', 'af_ad_id' => '120251056123310309', 'campaign' => 'fb_obmx_lead_do', 'ad' => 'fb_obmx_outfit-match', 'conjunto' => 'fb_obmx_lal', 'installs' => '5', 'nc' => '2', 'orders' => '0'],
            ['fecha' => '2026-10-01', 'af_ad_id' => null, 'campaign' => 'organic', 'ad' => null, 'conjunto' => null, 'installs' => '40', 'nc' => '9', 'orders' => '2'],
        ]))]);

        $r = EnriquecedorAppsFlyerBigQuery::paraCuenta($this->openbank->fresh())->enriquecer(['mx.openbank.modelbank', 'id1631175342'], '2026-10-01', '2026-10-31');

        $this->assertCount(1, $r['limpias']);
        $ad = $r['limpias'][0];
        $this->assertSame(['120251056123310309', 'fb_obmx_lead_do', 'fb_obmx_outfit-match', 'fb_obmx_lal'], [$ad['adId'], $ad['campaign'], $ad['adRaw'], $ad['conjunto']]);
        $this->assertSame([17, 5, 1], [array_sum(array_column($ad['serie'], 'installs')), array_sum(array_column($ad['serie'], 'newCustomers')), array_sum(array_column($ad['serie'], 'orders'))]);
        $this->assertSame('01-10-2026', $ad['serie'][0]['fecha']);
        $this->assertSame(40, $r['organico'][0]['installs']);
        $this->assertSame('AppsFlyer BigQuery: onb_success_n2', $r['columnaNC']);

        Http::assertSent(function ($req) {
            $sql = $req['query'] ?? '';
            $params = collect($req['queryParameters'] ?? [])->keyBy('name');

            return $req->hasHeader('Authorization', 'Bearer token-de-prueba')
                // Reglas del script de Openbank.
                && str_contains($sql, "= 're-attribution'")
                && str_contains($sql, 'is_primary_attribution')
                && str_contains($sql, '`bdd-epa-digital.appsflyeropenbank.inapps`')
                && str_contains($sql, '`bdd-epa-digital.appsflyeropenbank.installsretargeting`')
                // Los valores van como parámetros, nunca pegados en el SQL.
                && ! str_contains($sql, 'onb_success_n2')
                && $params['principal']['parameterValue']['value'] === 'onb_success_n2'
                && count($params['apps']['parameterValue']['arrayValues']) === 2
                && $req['maximumBytesBilled'] !== null;
        });
    }

    public function test_las_tablas_de_retargeting_son_opcionales(): void
    {
        $this->openbank->credencial('appsflyer')->update(['ajustes' => [...self::AJUSTES, 'bq_tablas' => ['installs_rt' => '', 'eventos_rt' => '']]]);
        Http::fake(['*/queries' => Http::response($this->respuestaConsulta([]))]);

        EnriquecedorAppsFlyerBigQuery::paraCuenta($this->openbank->fresh())->enriquecer(['app'], '2026-10-01', '2026-10-31');

        Http::assertSent(fn ($req) => ! str_contains($req['query'] ?? '', 'retargeting'));
    }

    public function test_nombres_de_tabla_raros_se_rechazan(): void
    {
        $this->openbank->credencial('appsflyer')->update(['ajustes' => [...self::AJUSTES, 'bq_tablas' => ['eventos' => 'inapps`; DROP TABLE x; --']]]);
        Http::fake();

        $this->expectExceptionMessage('Revisa la tabla eventos');
        EnriquecedorAppsFlyerBigQuery::paraCuenta($this->openbank->fresh())->enriquecer(['app'], '2026-10-01', '2026-10-31');
    }

    public function test_probar_conexion_revisa_tablas_y_columnas(): void
    {
        $columnasEventos = ['app_id', 'event_time', 'event_name', 'af_ad_id', 'af_ad', 'af_adset', 'campaign', 'is_primary_attribution', 'retargeting_conversion_type'];
        Http::fake([
            '*/datasets/appsflyeropenbank/tables?*' => Http::response(['tables' => [
                ['tableReference' => ['tableId' => 'inapps']], ['tableReference' => ['tableId' => 'inappsretargeting']], ['tableReference' => ['tableId' => 'installs']],
            ]]),
            '*/tables/installs' => Http::response(['schema' => ['fields' => array_map(fn ($c) => ['name' => $c], ['app_id', 'install_time', 'af_ad_id', 'campaign'])]]),
            '*/tables/inapps*' => Http::response(['schema' => ['fields' => array_map(fn ($c) => ['name' => $c], $columnasEventos)]]),
        ]);

        $user = User::factory()->create(['rol' => 'junior']);
        $this->actingAs($user)->postJson('/cuentas/fuentes/bigquery/probar', ['bq_proyecto' => 'bdd-epa-digital', 'bq_dataset' => 'appsflyeropenbank'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('tablas.0.tabla', 'installs')
            ->assertJsonPath('tablas.0.faltan', ['af_ad', 'af_adset'])
            ->assertJsonPath('tablas.1.existe', false)          // installsretargeting: opcional
            ->assertJsonPath('tablas.2.faltan', [])
            ->assertJsonPath('tablas.3.faltan', []);
    }

    public function test_sin_permiso_explica_que_pedir(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Access Denied: Dataset bdd-epa-digital:appsflyeropenbank']], 403)]);

        $this->actingAs(User::factory()->create(['rol' => 'junior']))
            ->postJson('/cuentas/fuentes/bigquery/probar', ['bq_proyecto' => 'bdd-epa-digital', 'bq_dataset' => 'appsflyeropenbank'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'lector en el dataset') && str_contains($m, 'Job User'));
    }

    public function test_un_dataset_que_no_se_ve_avisa_que_puede_ser_permiso(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Not found: Dataset bdd-epa-digital:appsflyeropenbank']], 404)]);

        $this->actingAs(User::factory()->create(['rol' => 'junior']))
            ->postJson('/cuentas/fuentes/bigquery/probar', ['bq_proyecto' => 'bdd-epa-digital', 'bq_dataset' => 'appsflyeropenbank'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'no tiene permiso de verlo'));
    }

    public function test_el_formulario_guarda_el_origen_bigquery_sin_token(): void
    {
        $user = User::factory()->create(['rol' => 'junior']);
        $user->cuentas()->attach($this->openbank->id);
        $user->paises()->attach(Pais::create(['codigo' => 'MX', 'nombre' => 'México'])->id);

        $this->actingAs($user)->putJson('/cuentas/openbank', [
            'nombre' => 'Openbank', 'cliente' => 'Openbank', 'color' => '#ff8f84',
            'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta'], 'fuente_mmp' => true,
            'venta_real' => 'no', 'multi_pais' => false, 'paises' => ['MX'],
            'appsflyer_origen' => 'bigquery', 'appsflyer_bq_proyecto' => 'bdd-epa-digital', 'appsflyer_bq_dataset' => 'appsflyeropenbank',
            'appsflyer_bq_tablas' => ['installs' => 'installs', 'installs_rt' => '', 'eventos' => 'inapps', 'eventos_rt' => 'inappsretargeting'],
            'appsflyer_evento_principal' => 'onb_success_n2',
        ])->assertOk();

        $cred = $this->openbank->fresh()->credencial('appsflyer');
        $this->assertSame('bigquery', $cred->origen());
        $this->assertTrue($cred->listaParaImportar());
        $this->assertFalse($cred->tieneToken());
        $this->assertSame('', $cred->ajustes['bq_tablas']['installs_rt']);

        // Sin dataset no se guarda.
        $this->actingAs($user)->putJson('/cuentas/openbank', [
            'nombre' => 'Openbank', 'cliente' => 'Openbank', 'color' => '#ff8f84',
            'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta'], 'fuente_mmp' => true,
            'venta_real' => 'no', 'multi_pais' => false, 'paises' => ['MX'],
            'appsflyer_origen' => 'bigquery', 'appsflyer_bq_dataset' => '', 'appsflyer_evento_principal' => 'onb_success_n2',
        ])->assertStatus(422)->assertJsonValidationErrors(['appsflyer_bq_dataset']);
    }

    public function test_la_importacion_diaria_no_salta_una_cuenta_con_bigquery(): void
    {
        $this->artisan('importar:appsflyer-api', ['--cuenta' => 'openbank'])
            ->doesntExpectOutputToContain('no tiene token de AppsFlyer ni BigQuery')
            ->assertSuccessful();
    }
}
