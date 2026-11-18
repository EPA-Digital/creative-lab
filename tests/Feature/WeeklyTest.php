<?php

namespace Tests\Feature;

use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\PanelWeekly;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Weekly (2026-11-18): weekly.fer, solo Ecuador y Panamá.
 */
class WeeklyTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'junior', string $codigo = 'EC', string $nombre = 'Ecuador'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function af(string $fecha, string $campania, string $fuente, int $installs, int $nc, int $ordenes): void
    {
        FuenteAppsflyer::create([
            'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'fecha' => $fecha, 'app' => 'TaDa',
            'media_source' => 'Facebook Ads', 'campania' => $campania, 'installs' => $installs, 'nc' => $nc, 'recompras' => $ordenes - $nc,
            'ordenes' => $ordenes, 'fuente_final' => $fuente, 'canal' => 'meta',
        ]);
    }

    private function medio(string $fecha, string $medio, string $campania, float $costo): void
    {
        DB::table('medios_diario')->insert([
            'cuenta_id' => CuentaActual::id(), 'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'fecha' => $fecha,
            'medio' => $medio, 'medio_original' => $medio, 'campania' => $campania, 'costo' => $costo, 'impresiones' => 1000, 'creado_en' => now(),
        ]);
    }

    public function test_nombre_corto_como_la_hoja(): void
    {
        $this->assertSame('Meta - Awareness Broad', PanelWeekly::nombreCorto('PAN_DTC_TAD_AON_AON_TAD_AWA_EPA_FB-AWARENESS-BROAD', 'Meta'));
        $this->assertSame('TikTok - Conversion iOS Broad Tiktok Only', PanelWeekly::nombreCorto('PAN_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-CONVERSION-IOS-BROAD-TIKTOK-ONLY', 'TikTok'));
        $this->assertSame('Meta - Conversion AND RMK Install Checkbox V2', PanelWeekly::nombreCorto('ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONVERSION-AND-RMK-INSTALL-CHECKBOX-V2', 'Meta'));
    }

    public function test_global_paid_y_por_campania(): void
    {
        $user = $this->usuario();
        $rmk = 'ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_FB-CONVERSION-AND-RMK';
        $bi = 'ECU_BRD_MLM_AON_AON_NON_CON_EPA_FB-BI-SHOP';
        $this->medio('2026-11-03', 'meta', $rmk, 100);
        $this->medio('2026-11-03', 'meta', $bi, 40);
        $this->af('2026-11-03', $rmk, 'TADA PERFORMANCE', 50, 10, 30);
        $this->af('2026-11-03', 'ecu_dtc_tad_aon_aon_tad_cnv_epa_fb-conversion-and-rmk', 'TADA PERFORMANCE', 5, 1, 2); // mismo nombre en minúsculas
        $this->af('2026-11-03', $bi, 'BI', 20, 4, 6);
        $this->af('2026-11-03', 'Organic', 'ORGANIC', 99, 9, 9);

        $this->actingAs($user)->get('/tada/pais/ecuador/weekly?anio=2026')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Weekly')
                ->where('corte', '2026-11-03')
                // Gasto sin _BRD_; NC / órdenes / installs solo de TADA PERFORMANCE.
                ->where('global', [['2026-11-03', 100, 11, 32, 55]])
                ->where('campanias.0.campania', $bi)
                ->where('campanias.1.tipo', 'Meta - Conversion AND RMK')
                // Funnel por el token del nombre: las tablas se dividen por etapa.
                ->where('campanias.1.funnel', 'CNV')->where('campanias.0.funnel', 'CONS')
                ->where('porCampania', [['2026-11-03', 0, 40, 4, 6, 20, 1000], ['2026-11-03', 1, 100, 11, 32, 55, 1000]]));

        $this->actingAs($user)->patch('/tada/pais/ecuador/weekly/tipo', ['campania' => $rmk, 'tipo' => 'Meta - Remarketing', 'oculta' => false])->assertOk();
        $this->actingAs($user)->patch('/tada/pais/ecuador/weekly/tipo', ['campania' => $bi, 'tipo' => null, 'oculta' => true])->assertOk();
        $this->actingAs($user)->get('/tada/pais/ecuador/weekly?anio=2026')
            ->assertInertia(fn ($p) => $p->where('campanias.1.tipo', 'Meta - Remarketing')->where('campanias.1.editado', true)->where('campanias.0.oculta', true));
    }

    public function test_solo_ecuador_y_panama(): void
    {
        $this->actingAs($this->usuario('junior', 'PE', 'Perú'))->get('/tada/pais/peru/weekly')->assertNotFound();
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/weekly')->assertForbidden();
    }
}
