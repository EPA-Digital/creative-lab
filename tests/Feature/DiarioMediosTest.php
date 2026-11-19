<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\LectorDataMedios;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * data.medios + Diario medio / Diario campaña (2026-11-18). La muestra es el
 * 1 de noviembre de Ecuador tal como exporta la pestaña data.medios.
 */
class DiarioMediosTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'junior', string $codigo = 'EC', string $nombre = 'Ecuador'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function muestra(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('data.medios.csv', file_get_contents(base_path('tests/Fixtures/data_medios_ecuador_muestra.csv')));
    }

    private function af(string $media, string $fuente, string $campania, int $installs, int $nc, int $recompras): void
    {
        FuenteAppsflyer::create([
            'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'fecha' => '2026-11-01', 'app' => 'TaDa',
            'media_source' => $media, 'campania' => $campania, 'installs' => $installs, 'nc' => $nc, 'recompras' => $recompras,
            'ordenes' => $nc + $recompras, 'fuente_final' => $fuente, 'canal' => $media,
        ]);
    }

    public function test_importa_data_medios_y_reemplaza_los_dias(): void
    {
        $user = $this->usuario();
        $this->actingAs($user)->post('/tada/pais/ecuador/medios-data/importar', ['archivo' => $this->muestra()])
            ->assertOk()
            ->assertJson(['filas' => 23, 'desde' => '2026-11-01', 'hasta' => '2026-11-01', 'medios' => ['meta' => 6, 'tiktok' => 16, 'asa' => 1], 'mes' => '2026-11']);
        $this->actingAs($user)->post('/tada/pais/ecuador/medios-data/importar', ['archivo' => $this->muestra()])->assertOk();

        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $this->assertSame(23, DB::table('medios_diario')->count());
        // Meta del 1 nov = DTC + BRD, igual que diario.medio de la hoja.
        $this->assertEqualsWithDelta(788.54, (float) DB::table('medios_diario')->where('medio', 'meta')->sum('costo'), 0.001);

        $this->actingAs($user)->get('/tada/pais/ecuador/medios-data?mes=2026-11')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('MediosData')->where('desdeDataMedios', true));
    }

    public function test_lee_columnas_por_nombre_con_adset_y_cost_usd(): void
    {
        // Panamá / Perú: adset y adsetid antes de cost; segunda fila de encabezado y bloque crudo a la derecha.
        $panama = "medio,date,campaign,campaignid,adset,adsetid,cost,clicks,impressions,views,completedviews,so,week,,medio,date,cost\n"
            ."medio,Date,Campaign name,Campaign ID,Ad set name,Ad set ID,Cost,Link clicks,Impressions,Three-second,Video,so,week,,medio,Date,Cost\n"
            ."facebook,3/11/2026,PAN_X,111,AS_1,9,\"$1,200.50\",10,5000,1,0,AND,45,,facebook,3/11/2026,999\n"
            ."facebook,3/11/2026,PAN_X,111,AS_2,8,$99.50,5,1000,0,0,IOS,45,,,,\n";
        $r = (new LectorDataMedios('panama'))->leer($panama);
        $this->assertCount(2, $r['filas']);
        $this->assertSame([1200.5, 99.5], array_column($r['filas'], 'costo'));
        $this->assertSame([5000, 1000], array_column($r['filas'], 'impresiones'));
        $this->assertSame(['2026-11-03', '2026-11-03'], array_column($r['filas'], 'fecha'));

        // México: Meta en USD y TikTok en MXN -- se usa "cost USD".
        $mexico = "medio,date,campaign,campaignid,adset,adsetid,cost,clicks,impressions,views,completedviews,cost USD,Weekly Fer\n"
            ."tiktok,3/11/2026,MEX_T,1,AS,2,1800,4,900,0,0,100,x\n";
        $r = (new LectorDataMedios('mexico'))->leer($mexico);
        $this->assertSame(100.0, $r['filas'][0]['costo']);
        $this->assertSame('tiktok', $r['filas'][0]['medio']);
    }

    public function test_avisa_si_no_es_data_medios(): void
    {
        $archivo = UploadedFile::fake()->createWithContent('otro.csv', "Install Day,App Name\n2026-11-01,TaDa\n");
        $this->actingAs($this->usuario())->post('/tada/pais/ecuador/medios-data/importar', ['archivo' => $archivo])
            ->assertStatus(422)->assertJsonFragment(['message' => 'No encontré el encabezado medio | date | campaign | campaignid ... cost. ¿Es el CSV de la pestaña data.medios?']);
    }

    public function test_diario_junta_costos_y_af_data_por_medio_y_campania(): void
    {
        $user = $this->usuario();
        $this->actingAs($user)->post('/tada/pais/ecuador/medios-data/importar', ['archivo' => $this->muestra()])->assertOk();
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $broad = 'ECU_DTC_TAD_AON_AON_TAD_AWA_EPA_FB-AWARENESS-BROAD-V2';
        $this->af('Facebook Ads', 'TADA PERFORMANCE', $broad, 30, 5, 10);
        $this->af('Facebook Ads', 'BI', 'ECU_BRD_TAD_AON_AON_TAD_CON_EPA_FB-ADVANTAGE-INSTALL-AND-VOLUME-INT', 20, 2, 3);
        $this->af('Organic', 'ORGANIC', 'None', 100, 40, 50);

        $this->actingAs($user)->get('/tada/pais/ecuador/diario-medio')
            ->assertOk()
            ->assertInertia(function ($p) {
                $p->component('Diario')->where('modo', 'medio')->where('mes', '2026-11');
                $dia = $p->toArray()['props']['dias']['2026-11-01'];
                // [inversión, impresiones, clics, installs, nc, purchase]
                $this->assertEqualsWithDelta(788.54, $dia['meta'][0], 0.001);
                $this->assertSame([50, 7, 13], array_slice($dia['meta'], 3));
                // Total: toda la inversión y todo AF data (también orgánico).
                $this->assertEqualsWithDelta(1989.64, $dia['total'][0], 0.001);
                $this->assertSame([150, 47, 63], array_slice($dia['total'], 3));
            });

        $this->actingAs($user)->get('/tada/pais/ecuador/diario-campania')
            ->assertOk()
            ->assertInertia(function ($p) use ($broad) {
                $camp = collect($p->toArray()['props']['campanias'])->firstWhere('nombre', $broad);
                $this->assertSame('meta', $camp['medio']);
                $this->assertEqualsWithDelta(91.73, $camp['dias']['2026-11-01'][0], 0.001);
                $this->assertSame([30, 5, 10], array_slice($camp['dias']['2026-11-01'], 3));
                // La campaña "None" de orgánico no entra.
                $this->assertNull(collect($p->toArray()['props']['campanias'])->firstWhere('nombre', 'None'));
            });
    }

    public function test_mexico_entra_y_cliente_no(): void
    {
        $this->actingAs($this->usuario('junior', 'MX', 'México'))->get('/tada/pais/mexico/diario-medio')->assertOk();
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/diario-campania')->assertForbidden();
    }
}
