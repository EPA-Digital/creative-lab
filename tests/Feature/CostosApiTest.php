<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\TipoCambio;
use App\Models\User;
use App\Services\Divisas\Divisas;
use App\Services\Fuentes\SincronizadorCostosApi;
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
