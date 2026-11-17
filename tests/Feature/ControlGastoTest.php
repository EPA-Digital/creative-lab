<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\ControlGasto;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Control de gasto (2026-11-18): series diarias por campaña hasta el último
 * día con datos completos, datos del Daily y alertas revisadas.
 */
class ControlGastoTest extends TestCase
{
    use RefreshDatabase;

    private const CAMP = 'ECU_DTC_TAD_AON_AON_TAD_CNV_EPA_TKT-CONVERSION-AND-SMART';

    private function usuario(string $rol = 'junior', string $codigo = 'EC', string $nombre = 'Ecuador'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function datos(): int
    {
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $paisId = Pais::where('codigo', 'EC')->value('id');
        foreach (['2026-11-14' => 100, '2026-11-15' => 150, '2026-11-16' => 999] as $fecha => $costo) {
            DB::table('medios_diario')->insert([
                'cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'fecha' => $fecha, 'medio' => 'tiktok', 'medio_original' => 'tiktok',
                'campania' => self::CAMP, 'campania_id' => '1856676171514098', 'costo' => $costo, 'clicks' => 0, 'impresiones' => 0,
            ]);
        }
        foreach (['2026-11-14' => 40, '2026-11-15' => 60] as $fecha => $installs) {
            FuenteAppsflyer::create([
                'pais_id' => $paisId, 'fecha' => $fecha, 'app' => 'TaDa', 'media_source' => 'tiktokglobal_int', 'campania' => self::CAMP,
                'installs' => $installs, 'nc' => 5, 'recompras' => 10, 'ordenes' => 15, 'fuente_final' => 'TADA PERFORMANCE', 'canal' => 'TikTok',
            ]);
        }
        // Orgánico: no es campaña de medios.
        FuenteAppsflyer::create([
            'pais_id' => $paisId, 'fecha' => '2026-11-15', 'app' => 'TaDa', 'media_source' => 'Organic', 'campania' => 'Organico',
            'installs' => 500, 'nc' => 50, 'recompras' => 50, 'ordenes' => 100, 'fuente_final' => 'ORGANIC', 'canal' => 'Organic',
        ]);

        return $paisId;
    }

    public function test_series_hasta_el_ultimo_dia_completo_con_datos_del_daily(): void
    {
        $this->usuario();
        $paisId = $this->datos();
        $planId = DB::table('daily_planes')->insertGetId(['cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'mes' => '2026-11', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30', 'meta_installs' => 1000]);
        DB::table('daily_campanias')->insert(['plan_id' => $planId, 'medio' => 'tiktok', 'campania' => self::CAMP, 'budget' => 3000, 'kpi_installs' => 500, 'status' => 'ACTIVA', 'responsable' => 'Ana']);

        $r = app(ControlGasto::class)->armar($paisId, 'ecuador', null, CarbonImmutable::parse('2026-11-18'));

        // Costos hasta el 16, AF data hasta el 15: el corte es el 15.
        $this->assertSame('2026-11-15', $r['corte']);
        $this->assertCount(ControlGasto::DIAS, $r['fechas']);
        $this->assertSame('2026-11-15', end($r['fechas']));
        $this->assertCount(1, $r['campanias']);
        $c = $r['campanias'][0];
        $this->assertSame(['tiktok', 'CNV', 'Ana', 3000.0], [$c['medio'], $c['funnel'], $c['responsable'], $c['budget']]);
        $this->assertSame([150.0, 60, 5, 10, 15], end($c['serie']));
        $this->assertSame([100.0, 40, 5, 10, 15], $c['serie'][ControlGasto::DIAS - 2]);
        $this->assertSame(1000, $r['plan']['metas'][1]);
    }

    public function test_marca_y_desmarca_revisada(): void
    {
        $user = $this->usuario();
        $this->datos();
        $clave = mb_strtolower(self::CAMP);
        $this->actingAs($user)->post('/tada/pais/ecuador/control-gasto/revisar', ['fecha' => '2026-11-15', 'clave' => $clave, 'revisada' => true])->assertOk();

        $this->actingAs($user)->get('/tada/pais/ecuador/control-gasto?al=2026-11-15')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('ControlGasto')->where("revisadas.{$clave}", $user->name));

        $this->actingAs($user)->post('/tada/pais/ecuador/control-gasto/revisar', ['fecha' => '2026-11-15', 'clave' => $clave, 'revisada' => false])->assertOk();
        $this->assertSame(0, DB::table('control_revisiones')->count());
    }

    public function test_solo_campanias_con_gasto_propio_y_quitar_con_historial(): void
    {
        $user = $this->usuario();
        $paisId = $this->datos();
        // Campaña que solo existe en AF data (sin gasto nuestro): no se muestra.
        FuenteAppsflyer::create([
            'pais_id' => $paisId, 'fecha' => '2026-11-15', 'app' => 'TaDa', 'media_source' => 'Facebook Ads', 'campania' => 'BI_AGENCIA_X',
            'installs' => 80, 'nc' => 5, 'recompras' => 5, 'ordenes' => 10, 'fuente_final' => 'BI', 'canal' => 'BI',
        ]);
        $r = app(ControlGasto::class)->armar($paisId, 'ecuador', '2026-11-15', CarbonImmutable::parse('2026-11-18'));
        $this->assertSame([mb_strtolower(self::CAMP)], array_column($r['campanias'], 'k'));
        $this->assertSame(1, $r['sinGastoPropio']);

        $this->actingAs($user)->post('/tada/pais/ecuador/control-gasto/excluir', ['campania' => self::CAMP, 'medio' => 'tiktok', 'motivo' => 'No es nuestra'])->assertOk();
        $r = app(ControlGasto::class)->armar($paisId, 'ecuador', '2026-11-15', CarbonImmutable::parse('2026-11-18'));
        $this->assertSame([], $r['campanias']);
        $this->assertSame([self::CAMP, 'No es nuestra', $user->name, null], [$r['historialExcluidas'][0]['campania'], $r['historialExcluidas'][0]['motivo'], $r['historialExcluidas'][0]['por'], $r['historialExcluidas'][0]['restaurada_en']]);

        $this->actingAs($user)->post('/tada/pais/ecuador/control-gasto/restaurar/'.$r['historialExcluidas'][0]['id'])->assertOk();
        $this->actingAs($user)->post('/tada/pais/ecuador/control-gasto/restaurar/'.$r['historialExcluidas'][0]['id'])->assertNotFound();
        $r = app(ControlGasto::class)->armar($paisId, 'ecuador', '2026-11-15', CarbonImmutable::parse('2026-11-18'));
        $this->assertCount(1, $r['campanias']);
        $this->assertSame($user->name, $r['historialExcluidas'][0]['restauro']);
    }

    public function test_mexico_entra_y_cliente_no(): void
    {
        $this->actingAs($this->usuario('junior', 'MX', 'México'))->get('/tada/pais/mexico/control-gasto')->assertOk();
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/control-gasto')->assertForbidden();
    }
}
