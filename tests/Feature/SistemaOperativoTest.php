<?php

namespace Tests\Feature;

use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\PanelSistemaOperativo;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SO (2026-11-18): métricas por sistema operativo, como so.2026 / ✅SO.
 */
class SistemaOperativoTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'junior'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador']);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function af(string $fecha, string $app, string $fuente, int $installs, int $nc, int $recompras, int $registros): void
    {
        FuenteAppsflyer::create([
            'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'fecha' => $fecha, 'app' => $app,
            'media_source' => 'Facebook Ads', 'campania' => 'X', 'installs' => $installs, 'nc' => $nc, 'recompras' => $recompras,
            'ordenes' => $nc + $recompras, 'otros' => ['Registration Submitted Successfully' => $registros], 'fuente_final' => $fuente, 'canal' => 'meta',
        ]);
    }

    private function medio(string $fecha, string $medio, string $campania, float $costo): void
    {
        DB::table('medios_diario')->insert([
            'cuenta_id' => CuentaActual::id(), 'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'fecha' => $fecha,
            'medio' => $medio, 'medio_original' => $medio, 'campania' => $campania, 'costo' => $costo, 'creado_en' => now(),
        ]);
    }

    public function test_sistema_operativo_de_la_app_y_de_la_campania(): void
    {
        $this->assertSame(0, PanelSistemaOperativo::soDeApp('TaDa Delivery de Bebidas EC - ec.com.fiestacerca'));
        $this->assertSame(1, PanelSistemaOperativo::soDeApp('TaDa Delivery de Bebidas EC - id1596944067'));
        $this->assertSame(1, PanelSistemaOperativo::soDeCampania('meta', 'ECU_DTC_IOS_FB-CONVERSION'));
        $this->assertSame(1, PanelSistemaOperativo::soDeCampania('asa', 'Brand'));
        $this->assertSame(2, PanelSistemaOperativo::soDeCampania('meta', 'ECU_WEB_TRAFICO'));
        $this->assertSame(0, PanelSistemaOperativo::soDeCampania('meta', 'ECU_DTC_AON_RATIOS')); // "RATIOS" no es iOS
        $this->assertSame(0, PanelSistemaOperativo::soDeCampania('tiktok', 'ECU_AND_TKT'));
    }

    public function test_arma_dias_por_so_y_paid_e_inversion(): void
    {
        $user = $this->usuario();
        $android = 'TaDa EC - ec.com.fiestacerca';
        $ios = 'TaDa EC - id1596944067';
        $this->af('2026-10-03', $android, 'TADA PERFORMANCE', 100, 10, 20, 5);
        $this->af('2026-10-03', $android, 'ORGANIC', 50, 4, 6, 2);
        $this->af('2026-10-03', $ios, 'TADA PERFORMANCE', 30, 3, 4, 1);
        $this->medio('2026-10-03', 'meta', 'ECU_DTC_AON_FB', 200);
        $this->medio('2026-10-03', 'meta', 'ECU_DTC_IOS_FB', 80);
        $this->medio('2026-10-03', 'asa', 'Brand', 20);

        $this->actingAs($user)->get('/tada/pais/ecuador/so')->assertOk()
            ->assertInertia(fn ($p) => $p->component('SistemaOperativo')
                ->where('tieneAfData', true)
                ->where('hasta', '2026-10-03')
                ->where('anios', [2026])
                ->where('dias', [
                    ['2026-10-03', 0, 0, 50, 4, 6, 2],
                    ['2026-10-03', 0, 1, 100, 10, 20, 5],
                    ['2026-10-03', 1, 1, 30, 3, 4, 1],
                ])
                ->where('inversion', [['2026-10-03', 0, 200], ['2026-10-03', 1, 100]]));

        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/so')->assertForbidden();
    }
}
