<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\LimpiadorFuentes;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Fuentes (2026-11-13): limpieza del pivot de AppsFlyer de Ecuador con las
 * reglas del equipo, y la importación a la tabla tipo Excel.
 */
class AfDataTest extends TestCase
{
    use RefreshDatabase;

    private const ENCABEZADO = 'Install Day,App Name,Agency,Media Source,Campaign,Campaign ID,Installs (sum),Activity - Event Counter - First Order (sum),Activity - Event Counter - Order Submitted (sum),Activity - Event Counter - App Opened (sum),Activity Sessions (sum)';

    private function csv(array $filas): string
    {
        return "\xEF\xBB\xBF".self::ENCABEZADO."\n".implode("\n", $filas)."\n";
    }

    private function ejemplo(): string
    {
        $and = 'TaDa Delivery de Bebidas EC - ec.com.fiestacerca';
        $ios = 'TaDa Delivery de Bebidas EC - id1596944067';

        return $this->csv([
            // Subtotales del pivot: columnas de agrupación vacías.
            '"Oct 01, 2026",,,,,,435,60,704,"5,607","9,222"',
            "\"Oct 01, 2026\",{$and},,,,,378,58,607,\"4,830\",\"7,624\"",
            "\"Oct 01, 2026\",{$and},None,Facebook Ads,,,10,1,2,3,4",
            // Filas reales.
            "\"Oct 01, 2026\",{$and},None,Facebook Ads,ECU_DTC_TAD_AON_AON_TAD_LOY_EPA_FB-CONVERSION,120238243702510661,7,4,233,\"1,070\",\"1,022\"",
            "\"Oct 01, 2026\",{$and},None,Facebook Ads,ECU_BRD_MLM_AON_AON_NON_CNV_EPA_FB-ADVANTAGE,120249440545330789,13,5,38,408,650",
            "\"Oct 01, 2026\",{$and},None,Facebook Ads,None,0,1,0,0,4,10",
            "\"Oct 01, 2026\",{$and},None,Email_BRD,CRM_BRD-EMAIL-PIL-EC-INSTALL,None,1,0,0,0,3",
            "\"Oct 01, 2026\",{$and},None,QR_code,QR_ACTIVACIÓN_26MAR,None,0,0,0,0,1",
            "\"Oct 01, 2026\",{$and},None,Social_instagram,QR PILSENER RASPADITAS,None,0,0,0,0,4",
            "\"Oct 01, 2026\",{$and},None,tiktokglobal_int,TOPVIEW-20260616-Q,1868077243671553,0,0,0,0,1",
            "\"Oct 01, 2026\",{$and},None,google_organic_seo,None,None,0,0,0,2,0",
            "\"Oct 02, 2026\",{$ios},None,Organic,None,None,44,2,43,426,\"1,028\"",
            "\"Oct 02, 2026\",{$ios},None,MGM,MGM_DTC-TADA_EC,None,0,0,0,0,1",
            "\"Oct 02, 2026\",{$ios},None,Apple Search Ads,MAZ_ECU_LANZAMIENTO-NA-DTC_TAD_COP-API_CPI_ASA-TADA-EC,1014095543,0,0,0,1,1",
        ]);
    }

    public function test_quita_subtotales_y_clasifica_con_las_reglas_de_ecuador(): void
    {
        $r = LimpiadorFuentes::paraPais('ecuador')->limpiar($this->ejemplo());

        $this->assertSame(3, $r['subtotales']);
        $this->assertCount(11, $r['filas']);
        $this->assertSame(['2026-10-01', '2026-10-02'], [$r['desde'], $r['hasta']]);

        $porCampania = collect($r['filas'])->keyBy('campania');
        $esperado = [
            'ECU_DTC_TAD_AON_AON_TAD_LOY_EPA_FB-CONVERSION' => ['TADA PERFORMANCE', 'Facebook Ads'],
            'ECU_BRD_MLM_AON_AON_NON_CNV_EPA_FB-ADVANTAGE' => ['BI', 'BI'],      // BRD gana a EPA
            'CRM_BRD-EMAIL-PIL-EC-INSTALL' => ['CRM', 'CRM'],                    // CRM gana a BRD
            'QR_ACTIVACIÓN_26MAR' => ['CRM', 'CRM'],                             // QR_code siempre CRM
            'QR PILSENER RASPADITAS' => ['OTHER', 'Other'],                      // QR en el nombre no basta
            'TOPVIEW-20260616-Q' => ['TADA PERFORMANCE', 'TikTok'],
            'MGM_DTC-TADA_EC' => ['MGM', 'MGM'],
            'MAZ_ECU_LANZAMIENTO-NA-DTC_TAD_COP-API_CPI_ASA-TADA-EC' => ['OTHER', 'Other'], // sin EPA
        ];
        foreach ($esperado as $campania => [$fuente, $canal]) {
            $this->assertSame([$fuente, $canal], [$porCampania[$campania]['fuente_final'], $porCampania[$campania]['canal']], $campania);
        }

        $none = collect($r['filas'])->where('campania', 'None');
        $this->assertSame('ORGANIC', $none->firstWhere('media_source', 'Facebook Ads')['fuente_final']);
        $this->assertSame('ORGANIC', $none->firstWhere('media_source', 'Organic')['fuente_final']);
        $this->assertSame('OTHER', $none->firstWhere('media_source', 'google_organic_seo')['fuente_final']);

        $fila = $porCampania['ECU_DTC_TAD_AON_AON_TAD_LOY_EPA_FB-CONVERSION'];
        $this->assertSame([7, 4, 233, 237], [$fila['installs'], $fila['nc'], $fila['recompras'], $fila['ordenes']]);
        $this->assertSame(['App Opened' => 1070, 'Activity Sessions' => 1022], $fila['otros']);
    }

    public function test_panama_filtra_por_geo_y_usa_sus_reglas(): void
    {
        $and = 'TaDa Drinks Delivery - com.abi.bees.tada';
        $csv = "Install Day,App Name,GEO,Agency,Media Source,Campaign,Campaign ID,Installs (sum),Activity - Event Counter - First Order (sum),Activity - Event Counter - Order Submitted (sum),Activity Sessions (sum)\n"
            ."\"Nov 12, 2026\",,,,,,,567,122,\"1,480\",9\n"                                   // subtotal del día
            ."\"Nov 12, 2026\",{$and},Argentina,,,,,0,0,0,1\n"                                // subtotal de otro país
            ."\"Nov 12, 2026\",{$and},Bolivia,None,Organic,None,None,50,5,9,100\n"            // otro país: fuera
            ."\"Nov 12, 2026\",{$and},Panama,None,Facebook Ads,PAN_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONVERSION,120,3,1,2,4\n"
            ."\"Nov 12, 2026\",{$and},Panama,None,Facebook Ads,PAN_BRD_COR_THISISLIVING_ABTEST,121,1,0,0,1\n"
            ."\"Nov 12, 2026\",{$and},Panama,None,Social_Organic,PAN_DTC_TAD_SOCIALORGANIC_PRECOMPRA,None,0,0,0,1\n"
            ."\"Nov 12, 2026\",{$and},Panama,None,QR_code,Corona Sunset CH,None,0,0,0,1\n"
            ."\"Nov 12, 2026\",{$and},Panama,None,Email,OB2_NEW_USER_1ST_PURCHASE_FIRST_NAME_AUTOMATED,None,0,0,1,1\n"
            ."\"Nov 12, 2026\",{$and},Panama,None,google_organic_seo,None,None,0,0,0,1\n";

        $r = LimpiadorFuentes::paraPais('panama')->limpiar($csv);

        $this->assertSame(2, $r['subtotales']);
        $this->assertSame(1, $r['otrosPaises']);
        $this->assertSame(
            ['TADA PERFORMANCE', 'BRANDS', 'ORGANIC', 'OTRO', 'CRM', 'ORGANIC'],
            array_column($r['filas'], 'fuente_final'),
        );
        $this->assertSame(['Facebook Ads', 'BI', 'Organic', 'Other', 'CRM', 'Organic'], array_column($r['filas'], 'canal'));
        $this->assertArrayNotHasKey('GEO', $r['filas'][0]['otros']);
    }

    public function test_peru_agrupa_por_adset_y_usa_sus_reglas(): void
    {
        $app = 'TaDa Delivery de Bebidas - pe.com.backusya';
        $csv = "Install Day,App Name,Agency,Media Source,Campaign,Campaign ID,Adset,Adset ID,Installs (sum),Activity - Event Counter - First Order (sum),Activity - Event Counter - Order Submitted (sum),Activity Sessions (sum)\n"
            ."\"Nov 12, 2026\",{$app},None,Facebook Ads,PER_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONV,123,,,9,1,1,1\n"   // subtotal de campaña (sin adset)
            ."\"Nov 12, 2026\",{$app},None,Facebook Ads,PER_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONV,123,AS-LIMA,987,9,1,1,1\n"
            ."\"Nov 12, 2026\",{$app},moblin,mintegral_int,PER_BRD_PTF_AON_PERFORMANCE_NON_API_ZNT_ZOOMD,ss,Pilsen_icon,2274,0,0,0,1\n"
            ."\"Nov 12, 2026\",{$app},None,jampp_int,UA_App-Installs_(CPI)_PE_Android_Corona,55,None,None,1,0,0,1\n"
            ."\"Nov 12, 2026\",{$app},None,duckduckgo_organic_seo,None,None,None,None,0,0,0,1\n"
            ."\"Nov 12, 2026\",{$app},None,Social_instagram,None,None,None,None,0,0,0,1\n"
            ."\"Nov 12, 2026\",{$app},None,Facebook Ads,None,0,None,0,11,0,0,1\n";      // Perú lo quita

        $r = LimpiadorFuentes::paraPais('peru')->limpiar($csv);

        $this->assertSame(1, $r['subtotales']);
        $this->assertSame(1, $r['descartadas']);
        $this->assertSame(['TADA PERFORMANCE', 'BRANDS', 'JAMPP', 'ORGANIC', 'ORGANIC'], array_column($r['filas'], 'fuente_final'));
        $this->assertSame(['Facebook Ads', 'BI', 'Jampp (sin EPA)', 'Organic', 'Organic'], array_column($r['filas'], 'canal'));
        $this->assertSame(['AS-LIMA', '987'], [$r['filas'][0]['adset'], $r['filas'][0]['adset_id']]);
        $this->assertNull($r['filas'][2]['adset']);
    }

    public function test_mexico_usa_las_reglas_de_su_hoja(): void
    {
        $app = 'TaDa Delivery de Bebidas MX - mx.com.modelorama.now';
        $fila = fn (string $agency, string $media, string $campania) => "\"Nov 12, 2026\",{$app},{$agency},{$media},{$campania},1,AS,1,1,0,0,1\n";
        $csv = "Install Day,App Name,Agency,Media Source,Campaign,Campaign ID,Adset,Adset ID,Installs (sum),Activity - Event Counter - First Order (sum),Activity - Event Counter - Order Submitted (sum),Activity Sessions (sum)\n"
            .$fila('None', 'Facebook Ads', 'MEX_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-ADVANTAGE-AND-SALES')  // _EPA_
            .$fila('None', 'Social_facebook', 'MEX_DTC_TAD_AON_ORGANICVKNG_TAD_AWA_VKNG_TADA')        // MEX_DTC_ sin _EPA_
            .$fila('None', 'dv360_int', 'SOC_GGL_NA_NQNP_MNTNAC_A18PA_A18PA_CPM_AW-AWA')             // nomenclatura vieja
            .$fila('None', 'Email', 'MX_CO_PROMOPAPA_BICRM__13-06-2023')                             // dice CRM
            .$fila('None', 'LuisMiguelSMS', 'LuisMiguelSMS')
            .$fila('None', 'Email', 'RWD_EML_MX_AUTOMATED_L1_RET_ALL_NA_CLUBTADA')                    // Email sin CRM: OTRO
            .$fila('clearpierperfor', 'wingsapps_int', 'TaDa_CPA_Mexico_Android_RTGT')                // afiliado: OTRO
            .$fila('None', 'corona_campaign', 'None')                                                 // sin campaña: orgánico
            .$fila('None', 'MGM', 'MGM_DTC-TADA_MX');

        $r = LimpiadorFuentes::paraPais('mexico')->limpiar($csv);

        $this->assertSame(
            ['TADA PERFORMANCE', 'TADA PERFORMANCE', 'TADA PERFORMANCE', 'CRM', 'CRM', 'OTRO', 'OTRO', 'ORGANIC', 'MGM'],
            array_column($r['filas'], 'fuente_final'),
        );
        $this->assertSame('Facebook Ads', $r['filas'][0]['canal']);
        $this->assertSame(['Other', 'Organic'], [$r['filas'][5]['canal'], $r['filas'][7]['canal']]);
    }

    public function test_avisa_si_no_es_el_export_esperado(): void
    {
        $this->expectExceptionMessage('Al archivo le faltan columnas');
        LimpiadorFuentes::paraPais('ecuador')->limpiar("Fecha,Campaña\n2026-10-01,X\n");
    }

    private function usuario(string $rol = 'junior'): User
    {
        $ec = Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador']);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($ec->id);

        return $user;
    }

    public function test_importa_reemplaza_los_dias_y_la_vista_los_muestra(): void
    {
        $user = $this->usuario();
        $archivo = fn () => UploadedFile::fake()->createWithContent('Data.csv', $this->ejemplo());

        $this->actingAs($user)->post('/tada/pais/ecuador/af-data/importar', ['archivo' => $archivo()])
            ->assertOk()->assertJson(['filas' => 11, 'subtotales' => 3, 'mes' => '2026-10']);
        // Volver a subir el mismo periodo no duplica.
        $this->actingAs($user)->post('/tada/pais/ecuador/af-data/importar', ['archivo' => $archivo()])->assertOk();

        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $this->assertSame(11, FuenteAppsflyer::count());

        $this->actingAs($user)->get('/tada/pais/ecuador/af-data?mes=2026-10')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('AfData')->where('mes', '2026-10')->has('filas', 11)->where('tieneReglas', true));
    }

    public function test_cliente_no_entra(): void
    {
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/af-data')->assertForbidden();
    }
}
