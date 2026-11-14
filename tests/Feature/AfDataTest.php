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
