<?php

namespace Tests\Feature;

use App\Models\Creativo;
use App\Models\Cuenta;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\User;
use App\Services\Fuentes\PanelFuentes;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fuentes (2026-11-13): venta real del mes → canales, con la inversión de
 * performance (sin BRD) y semanas cortadas por mes.
 */
class FuentesTest extends TestCase
{
    use RefreshDatabase;

    private Pais $ec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
    }

    private function af(string $fecha, string $canal, int $installs, int $nc, int $ordenes): void
    {
        DB::table('fuentes_appsflyer')->insert([
            'cuenta_id' => CuentaActual::id(), 'pais_id' => $this->ec->id, 'fecha' => $fecha,
            'app' => 'app', 'media_source' => $canal, 'campania' => 'x', 'installs' => $installs,
            'nc' => $nc, 'recompras' => $ordenes - $nc, 'ordenes' => $ordenes,
            'fuente_final' => 'TADA PERFORMANCE', 'canal' => $canal,
        ]);
    }

    private function gasto(string $adId, string $plataforma, ?string $tipo, string $funnel, float $costo): void
    {
        $c = Creativo::create(['ad_id' => $adId, 'pais_id' => $this->ec->id, 'nombre_comun' => $adId, 'nombre_completo' => $adId, 'plataforma' => $plataforma, 'funnel' => $funnel, 'tipo_cuenta' => $tipo]);
        Resultado::create(['creativo_id' => $c->id, 'mes' => '2026-10', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31', 'cost' => $costo, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'reorders' => 0, 'tiene_meta' => true]);
    }

    public function test_arma_meses_semanas_cortadas_e_inversion_sin_brd(): void
    {
        $this->af('2026-10-30', 'Facebook Ads', 10, 2, 20);
        $this->af('2026-10-31', 'Organic', 5, 1, 10);
        $this->af('2026-11-01', 'Facebook Ads', 4, 1, 8);
        $this->gasto('1', 'meta', 'DTC', 'CNV', 300);
        $this->gasto('2', 'tiktok', 'DTC', 'AWA', 100);
        $this->gasto('3', 'meta', 'BRD', 'CNV', 999); // BI: no entra

        $r = app(PanelFuentes::class)->armar($this->ec->id);

        $oct = $r['meses'][0];
        $this->assertSame('2026-10', $oct['clave']);
        $this->assertFalse($oct['parcial']);
        $this->assertSame(['installs' => 10, 'nc' => 2, 'ordenes' => 20], $oct['af']['Facebook Ads']);
        $this->assertEquals(['Facebook Ads' => 300, 'TikTok' => 100], $oct['inversion']['medio']);
        $this->assertEquals(400, $oct['inversion']['total']);
        $this->assertTrue($r['meses'][1]['parcial']);

        // Lunes 26 oct – sábado 31 oct (cortada por el mes) y domingo 1 nov aparte.
        $this->assertSame(['2026-10-26', '2026-11-01'], array_column($r['semanas'], 'clave'));
        $this->assertSame(44, $r['semanas'][1]['semana']);
        $this->assertSame(1, $r['semanas'][1]['dias']);
    }

    public function test_guarda_la_venta_real_del_mes(): void
    {
        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach($this->ec->id);

        $this->actingAs($user)->postJson('/tada/pais/ecuador/fuentes/venta-real', ['tipo' => 'mes', 'clave' => '2026-10', 'ordenes' => 41000, 'nc' => null])->assertOk();
        $this->actingAs($user)->postJson('/tada/pais/ecuador/fuentes/venta-real', ['tipo' => 'mes', 'clave' => '2026-10', 'ordenes' => 41998, 'nc' => 3314])->assertOk();

        // La semanal es otra captura: no pisa ni sale de la mensual.
        $this->actingAs($user)->postJson('/tada/pais/ecuador/fuentes/venta-real', ['tipo' => 'semana', 'clave' => '2026-10-26', 'ordenes' => 9000, 'nc' => 800])->assertOk();

        $fila = DB::table('fuentes_venta_real')->where('tipo', 'mes')->where('clave', '2026-10')->get();
        $this->assertCount(1, $fila);
        $this->assertSame([41998, 3314], [(int) $fila[0]->ordenes, (int) $fila[0]->nc]);
        $this->assertSame(9000, (int) DB::table('fuentes_venta_real')->where('tipo', 'semana')->where('clave', '2026-10-26')->value('ordenes'));

        $this->af('2026-10-27', 'Organic', 1, 1, 1);
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $r = app(PanelFuentes::class)->armar($this->ec->id);
        $this->assertSame(['ordenes' => 41998, 'nc' => 3314], $r['meses'][0]['ventaReal']);
        $this->assertSame(['ordenes' => 9000, 'nc' => 800], $r['semanas'][0]['ventaReal']);
    }

    public function test_el_gasto_de_medios_data_suma_a_su_medio_y_etapa_por_fecha(): void
    {
        $this->af('2026-10-26', 'Apple Search Ads', 10, 2, 20);
        $this->af('2026-11-02', 'Apple Search Ads', 10, 2, 20);
        $this->gasto('1', 'meta', 'DTC', 'CNV', 300);
        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach($this->ec->id);

        $this->actingAs($user)->postJson('/tada/pais/ecuador/medios-data/gasto', ['medio' => 'Apple Search Ads', 'fecha' => '2026-10-27', 'gasto' => 853])
            ->assertOk()->assertJson(['mes' => '2026-10']);
        $this->actingAs($user)->postJson('/tada/pais/ecuador/medios-data/gasto', ['medio' => 'Apple Search Ads', 'fecha' => '2026-11-02', 'gasto' => 100])->assertOk();
        $this->actingAs($user)->postJson('/tada/pais/ecuador/medios-data/gasto', ['medio' => 'Inventado', 'fecha' => '2026-10-27', 'gasto' => 5])->assertUnprocessable();

        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $r = app(PanelFuentes::class)->armar($this->ec->id, ['Facebook Ads', 'Apple Search Ads', 'TikTok'], config('fuentes.ecuador.medios_manuales'));
        $oct = $r['meses'][0];
        $this->assertEquals(853, $oct['inversion']['medio']['Apple Search Ads']);
        $this->assertEquals(853, $oct['inversion']['funnel']['CONS']);
        $this->assertEquals(1153, $oct['inversion']['total']);

        // En la semana entra exacto por fecha (no prorrateado).
        $semanas = collect($r['semanas'])->keyBy('clave');
        $this->assertEquals(853, $semanas['2026-10-26']['inversion']['medio']['Apple Search Ads']);
        $this->assertEquals(100, $semanas['2026-11-02']['inversion']['medio']['Apple Search Ads']);
    }

    public function test_medios_data_junta_api_y_captura_manual(): void
    {
        $this->gasto('1', 'meta', 'DTC', 'CNV', 300);
        $this->gasto('2', 'meta', 'BRD', 'CNV', 50);
        $id = DB::table('medios_gasto_manual')->insertGetId(['cuenta_id' => CuentaActual::id(), 'pais_id' => $this->ec->id, 'medio' => 'Apple Search Ads', 'fecha' => '2026-10-15', 'gasto' => 853]);
        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach($this->ec->id);

        $this->actingAs($user)->get('/tada/pais/ecuador/medios-data')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('MediosData')
                ->where('mes', '2026-10')
                ->has('filas', 3)
                ->where('filas.0.costo', 853)
                ->where('filas.0.origen', 'manual')
                ->where('filas.0.fecha', '2026-10-15')
                ->where('filas.0.funnel', 'CONS'));

        $this->actingAs($user)->deleteJson("/tada/pais/ecuador/medios-data/gasto/{$id}")->assertOk();
        $this->assertSame(0, DB::table('medios_gasto_manual')->count());
    }

    public function test_cliente_no_entra(): void
    {
        $user = User::factory()->create(['rol' => 'cliente']);
        $user->paises()->attach($this->ec->id);

        $this->actingAs($user)->get('/tada/pais/ecuador/fuentes')->assertForbidden();
    }
}
