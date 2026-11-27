<?php

namespace Tests\Feature;

use App\Http\Controllers\EnVivoController;
use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\TipoCambio;
use App\Models\User;
use App\Services\Divisas\Divisas;
use App\Services\Fuentes\SincronizadorCostosApi;
use App\Services\Ingesta\GoogleAdsApiClient;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Costos desde la API de Meta y TikTok (2026-11-26): detalle por anuncio,
 * medios_diario por campaña (la API gana sobre el CSV en sus campañas) y la
 * vista En vivo.
 */
class CostosApiTest extends TestCase
{
    use RefreshDatabase;

    private int $paisId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paisId = Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador'])->id;
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
    }

    private function cuenta(string $plataforma, string $id, ?string $moneda = 'USD'): CuentaPublicitaria
    {
        return CuentaPublicitaria::create(['pais_id' => $this->paisId, 'plataforma' => $plataforma, 'id_externo' => $id, 'nombre' => "EC {$plataforma}", 'moneda' => $moneda, 'activa' => true]);
    }

    private function sincronizador(): SincronizadorCostosApi
    {
        return new SincronizadorCostosApi(new MetaApiClient('t'), new TiktokApiClient('t'), app(Divisas::class));
    }

    private float $gastoMeta = 10;

    private function fakeMeta(float $gasto): void
    {
        $this->gastoMeta = $gasto;
        Http::fake(fn () => Http::response(['data' => [
            ['date_start' => '2026-11-26', 'campaign_id' => 'c1', 'campaign_name' => 'ECU_CAMP_A', 'adset_id' => 's1', 'adset_name' => 'Conjunto 1', 'ad_id' => 'a1', 'ad_name' => 'Anuncio 1', 'spend' => (string) $this->gastoMeta, 'impressions' => '1000', 'inline_link_clicks' => '20'],
            ['date_start' => '2026-11-26', 'campaign_id' => 'c1', 'campaign_name' => 'ECU_CAMP_A', 'adset_id' => 's1', 'adset_name' => 'Conjunto 1', 'ad_id' => 'a2', 'ad_name' => 'Anuncio 2', 'spend' => '5', 'impressions' => '500', 'inline_link_clicks' => '5'],
        ]]));
    }

    public function test_meta_guarda_anuncios_y_reemplaza_el_csv_de_sus_campanias(): void
    {
        $cp = $this->cuenta('meta', '111');
        $base = ['cuenta_id' => CuentaActual::id(), 'pais_id' => $this->paisId, 'fecha' => '2026-11-26', 'medio_original' => 'facebook', 'clicks' => 0, 'impresiones' => 0];
        // Del CSV: la misma campaña (se reemplaza) y una de marca que la API no trae (se queda).
        DB::table('medios_diario')->insert([
            [...$base, 'medio' => 'meta', 'campania' => 'ECU_CAMP_A', 'campania_id' => 'c1', 'costo' => 99],
            [...$base, 'medio' => 'meta', 'campania' => 'ECU_BRD_MARCA', 'campania_id' => 'b9', 'costo' => 40],
        ]);
        $this->fakeMeta(10);

        $r = $this->sincronizador()->sincronizar($this->paisId, '2026-11-24', '2026-11-26');

        $this->assertSame([], $r['errores']);
        $this->assertSame(2, $r['cuentas'][0]['anuncios']);
        $this->assertSame(2, DB::table('plataforma_anuncios_diario')->where('cuenta_publicitaria_id', $cp->id)->count());
        $medios = DB::table('medios_diario')->orderBy('campania')->get(['campania', 'origen', 'costo', 'impresiones', 'clicks']);
        $this->assertCount(2, $medios);
        $this->assertEquals(['ECU_BRD_MARCA', 'csv', 40], [$medios[0]->campania, $medios[0]->origen, (float) $medios[0]->costo]);
        $this->assertEquals(['ECU_CAMP_A', 'api', 15, 1500, 25], [$medios[1]->campania, $medios[1]->origen, (float) $medios[1]->costo, (int) $medios[1]->impresiones, (int) $medios[1]->clicks]);
    }

    public function test_guarda_lo_de_la_corrida_anterior_para_el_delta(): void
    {
        $this->cuenta('meta', '111');
        $this->fakeMeta(10);
        $this->sincronizador()->sincronizar($this->paisId, '2026-11-26', '2026-11-26');
        $this->fakeMeta(18);
        $this->sincronizador()->sincronizar($this->paisId, '2026-11-26', '2026-11-26');

        $a1 = DB::table('plataforma_anuncios_diario')->where('anuncio_id', 'a1')->first();
        $this->assertEquals([18, 10], [(float) $a1->costo, (float) $a1->costo_previo]);
        $this->assertSame(1, DB::table('medios_diario')->count());
    }

    public function test_tiktok_en_mxn_pasa_a_usd(): void
    {
        TipoCambio::create(['moneda' => 'MXN', 'vigente_desde' => '2026-01-01', 'por_usd' => 20]);
        $this->cuenta('tiktok', '222', 'MXN');
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0, 'data' => ['page_info' => ['total_page' => 1], 'list' => [
            ['dimensions' => ['ad_id' => 't1', 'stat_time_day' => '2026-11-26 00:00:00'], 'metrics' => ['spend' => '200', 'impressions' => '4000', 'clicks' => '40', 'campaign_id' => 'tc', 'campaign_name' => 'ECU_TT', 'adgroup_id' => 'tg', 'adgroup_name' => 'Grupo', 'ad_name' => 'Video']],
            ['dimensions' => ['ad_id' => 't2', 'stat_time_day' => '2026-11-26 00:00:00'], 'metrics' => ['spend' => '0', 'impressions' => '0', 'clicks' => '0', 'campaign_id' => 'tc', 'campaign_name' => 'ECU_TT', 'adgroup_id' => 'tg', 'adgroup_name' => 'Grupo', 'ad_name' => 'Sin gasto']],
        ]]])]);

        $this->sincronizador()->sincronizar($this->paisId, '2026-11-26', '2026-11-26');

        $fila = DB::table('plataforma_anuncios_diario')->sole();
        $this->assertEquals([200, 10, 'MXN', 'Grupo'], [(float) $fila->costo_original, (float) $fila->costo, $fila->moneda, $fila->conjunto]);
        $this->assertEquals(10, (float) DB::table('medios_diario')->value('costo'));
    }

    public function test_google_trae_anuncios_y_lo_que_falta_de_la_campania(): void
    {
        TipoCambio::create(['moneda' => 'MXN', 'vigente_desde' => '2026-01-01', 'por_usd' => 20]);
        $this->cuenta('google', '100', 'MXN');
        $consultas = [];
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'googleads.googleapis.com/*' => function ($request) use (&$consultas) {
                if ($request->method() === 'GET') {
                    return Http::response(['resourceNames' => ['customers/900']]);
                }
                $q = $request['query'];
                $consultas[] = [$request->header('login-customer-id')[0] ?? null, $q];
                if (str_contains($q, 'FROM customer_client')) {
                    return Http::response(['results' => [['customerClient' => ['id' => '100']]]]);
                }
                if (str_contains($q, 'FROM ad_group_ad')) {
                    return Http::response(['results' => [
                        ['segments' => ['date' => '2026-11-26'], 'campaign' => ['id' => '11', 'name' => 'MEX_APP'], 'adGroup' => ['id' => '22', 'name' => 'Grupo'],
                            'adGroupAd' => ['ad' => ['id' => '33']], 'metrics' => ['costMicros' => '120000000', 'impressions' => '1000', 'clicks' => '10']],
                    ]]);
                }

                // Campaña completa: 200 MXN; 80 no vienen por anuncio (ej. Performance Max).
                return Http::response(['results' => [
                    ['segments' => ['date' => '2026-11-26'], 'campaign' => ['id' => '11', 'name' => 'MEX_APP'], 'metrics' => ['costMicros' => '200000000', 'impressions' => '1500', 'clicks' => '12']],
                ]]);
            },
        ]);

        $r = (new SincronizadorCostosApi(null, null, app(Divisas::class), new GoogleAdsApiClient('fake', 'fake', 'fake', 'fake')))
            ->sincronizar($this->paisId, '2026-11-26', '2026-11-26');

        $this->assertSame([], $r['errores']);
        $filas = DB::table('plataforma_anuncios_diario')->orderBy('anuncio_id')->get();
        $this->assertEquals(['33', 'Anuncio 33', 'Grupo', 6.0], [$filas[0]->anuncio_id, $filas[0]->anuncio, $filas[0]->conjunto, (float) $filas[0]->costo]);
        $this->assertEquals(['campania-11', 'Sin detalle por anuncio', 4.0, 500, 2], [$filas[1]->anuncio_id, $filas[1]->anuncio, (float) $filas[1]->costo, (int) $filas[1]->impresiones, (int) $filas[1]->clicks]);
        $medio = DB::table('medios_diario')->sole();
        $this->assertEquals(['google', 'api', 10.0, 1500], [$medio->medio, $medio->origen, (float) $medio->costo, (int) $medio->impresiones]);
        // La cuenta cuelga de una MCC: las consultas van con su login-customer-id.
        $this->assertSame('900', end($consultas)[0]);
    }

    public function test_por_hora_y_datos_de_las_graficas(): void
    {
        $this->cuenta('meta', '111');
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'breakdowns=hourly')) {
                return Http::response(['data' => [
                    ['date_start' => '2026-11-26', 'campaign_id' => 'c1', 'campaign_name' => 'ECU_CAMP_A', 'hourly_stats_aggregated_by_advertiser_time_zone' => '09:00:00 - 09:59:59', 'spend' => '4', 'impressions' => '400', 'inline_link_clicks' => '8'],
                    ['date_start' => '2026-11-26', 'campaign_id' => 'c1', 'campaign_name' => 'ECU_CAMP_A', 'hourly_stats_aggregated_by_advertiser_time_zone' => '10:00:00 - 10:59:59', 'spend' => '6', 'impressions' => '600', 'inline_link_clicks' => '12'],
                    ['date_start' => '2026-11-25', 'campaign_id' => 'c1', 'campaign_name' => 'ECU_CAMP_A', 'hourly_stats_aggregated_by_advertiser_time_zone' => '09:00:00 - 09:59:59', 'spend' => '3', 'impressions' => '300', 'inline_link_clicks' => '3'],
                ]]);
            }

            return Http::response(['data' => [
                ['date_start' => '2026-11-26', 'campaign_id' => 'c1', 'campaign_name' => 'ECU_CAMP_A', 'adset_id' => 's1', 'adset_name' => 'Conjunto 1', 'ad_id' => 'a1', 'ad_name' => 'Anuncio 1', 'spend' => '10', 'impressions' => '1000', 'inline_link_clicks' => '20'],
            ]]);
        });
        DB::table('daily_planes')->insert(['id' => 7, 'cuenta_id' => CuentaActual::id(), 'pais_id' => $this->paisId, 'mes' => '2026-11', 'tipo' => 'paid', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30']);
        DB::table('daily_campanias')->insert(['plan_id' => 7, 'campania' => 'ECU_CAMP_A', 'medio' => 'meta', 'daily_plataforma' => 24]);

        $this->sincronizador()->sincronizar($this->paisId, '2026-11-25', '2026-11-26');

        $this->assertSame(3, DB::table('plataforma_campanias_hora')->count());
        $g = EnVivoController::graficas($this->paisId, 'hoy', '2026-11-26', '2026-11-26');
        $this->assertSame('horas', $g['modo']);
        $this->assertSame(10, $g['ultimaHora']);
        $this->assertEquals([[4.0, 400, 8], [6.0, 600, 12]], [$g['series']['meta'][9], $g['series']['meta'][10]]);
        $this->assertEquals([3.0, 300, 3], $g['comparacion']['meta'][9]);
        $this->assertEquals(['meta' => 24.0], $g['plan']);
        $this->assertEquals(6.0, $g['mini']['meta|c1'][10]);

        $dias = EnVivoController::graficas($this->paisId, '7d', '2026-11-20', '2026-11-26');
        $this->assertSame('dias', $dias['modo']);
        $this->assertEquals([10.0, 1000, 20], $dias['series']['meta'][6]);
    }

    public function test_el_csv_no_duplica_campanias_que_ya_vienen_de_la_api(): void
    {
        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach($this->paisId);
        DB::table('medios_diario')->insert([
            'cuenta_id' => CuentaActual::id(), 'pais_id' => $this->paisId, 'fecha' => '2026-11-18', 'medio' => 'meta', 'medio_original' => 'meta',
            'origen' => 'api', 'campania' => 'ECU_CAMP_A', 'campania_id' => 'c1', 'costo' => 15, 'clicks' => 0, 'impresiones' => 0,
        ]);
        $csv = "medio,date,campaign,campaignid,cost,clicks,impressions,views,completedviews,Marca\n"
            ."facebook,2026-11-18,ECU_CAMP_A,c1,99,0,0,0,0,\n"
            ."apple search ads,2026-11-18,ASA_CAMP,a1,7,0,0,0,0,\n";
        $archivo = UploadedFile::fake()->createWithContent('medios.csv', $csv);

        $this->actingAs($user)->post('/tada/pais/ecuador/medios-data/importar', ['archivo' => $archivo])->assertOk();

        $this->assertEquals(
            ['ASA_CAMP' => 7.0, 'ECU_CAMP_A' => 15.0],
            DB::table('medios_diario')->orderBy('campania')->pluck('costo', 'campania')->map(fn ($c) => (float) $c)->all(),
        );
    }

    public function test_estado_y_vista_en_vivo(): void
    {
        config(['services.meta.access_token' => 't', 'services.tiktok.access_token' => 't']);
        $this->cuenta('meta', '111');
        $this->fakeMeta(10);
        $this->artisan('costos:sincronizar-api', ['--desde' => '2026-11-26', '--hasta' => '2026-11-26'])->assertSuccessful();

        $estado = SincronizadorCostosApi::estado(CarbonImmutable::parse('2026-11-26 13:20', 'America/Mexico_City'));
        $this->assertSame('ok', $estado['estado']);
        $this->assertStringStartsWith('2026-11-26T14:10:00', $estado['proxima']);

        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach($this->paisId);
        $this->travelTo(CarbonImmutable::parse('2026-11-26 15:00', 'America/Mexico_City'));
        $this->actingAs($user)->get('/tada/pais/ecuador/en-vivo')->assertOk()
            ->assertInertia(fn ($p) => $p->component('EnVivo')
                ->where('campanias.0.nombre', 'ECU_CAMP_A')
                ->where('campanias.0.conjuntos.0.anuncios', fn ($a) => count($a) === 2)
                ->where('costosApi.estado', 'ok'));

        $cliente = User::factory()->create(['rol' => 'cliente']);
        $cliente->paises()->attach($this->paisId);
        $this->actingAs($cliente)->get('/tada/pais/ecuador/en-vivo')->assertForbidden();
    }
}
