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

    public function test_alertas_de_la_cuenta(): void
    {
        $this->usuario();
        User::factory()->create(['name' => 'Ana Torres', 'email' => 'ana@epa.digital']);
        $paisId = $this->datos();
        $planId = DB::table('daily_planes')->insertGetId(['cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'mes' => '2026-11', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30']);
        // Lleva 250 en 15 días: cerraría en 500 contra 300 planeados (+67%); el daily de 20 da 600.
        DB::table('daily_campanias')->insert(['plan_id' => $planId, 'medio' => 'tiktok', 'campania' => self::CAMP, 'budget' => 300, 'daily_plataforma' => 20, 'status' => 'ACTIVA']);
        $opti = fn (string $tipo, string $responsable, string $descripcion, string $campania = 'ECU_NUEVA') => DB::table('optimizaciones')->insertGetId([
            'cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'fecha' => '2026-11-12', 'medio' => 'meta', 'campanias' => json_encode([$campania]),
            'tipo' => $tipo, 'descripcion' => $descripcion, 'responsable' => $responsable, 'origen' => 'meta',
        ]);
        $externa = $opti('creacion_campania', 'Juan Cliente', 'Se creó campaña: ECU_NUEVA');
        $opti('creacion_campania', 'Ana Torres Ruiz', 'Se creó campaña: ECU_EQUIPO', 'ECU_EQUIPO');
        $subida = $opti('ajuste_dinero', 'Ana Torres', 'Se ajusta ppto de $70 a $120 (+71%)');
        $opti('ajuste_dinero', 'Ana Torres', 'Se ajusta ppto de $70 a $80 (+14%)');

        $r = app(ControlGasto::class)->armar($paisId, 'ecuador', null, CarbonImmutable::parse('2026-11-18'));
        $porClave = collect($r['alertasCuenta'])->keyBy('clave');

        $this->assertCount(3, $porClave);
        $this->assertSame('externo', $porClave["cuenta:opt:{$externa}"]['tipo']);
        $this->assertSame('Juan Cliente', $porClave["cuenta:opt:{$externa}"]['quien']);
        $this->assertSame('Subió la base +71%', $porClave["cuenta:opt:{$subida}"]['titulo']);
        $plan = $porClave['cuenta:plan:'.mb_strtolower(self::CAMP)];
        $this->assertStringContainsString('cerraría en $500 (+67%)', $plan['detalle']);
        $this->assertStringContainsString('da $600 en el mes (+100%)', $plan['detalle']);
    }

    public function test_alerta_cuando_el_presupuesto_queda_sobre_lo_planeado(): void
    {
        $this->usuario();
        $paisId = $this->datos();
        $planId = DB::table('daily_planes')->insertGetId(['cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'mes' => '2026-11', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30']);
        // 2,400 en el mes = 80/día planeados.
        DB::table('daily_campanias')->insert(['plan_id' => $planId, 'medio' => 'meta', 'campania' => 'ECU_CAMP_A', 'campania_id' => 'c1', 'budget' => 2400, 'status' => 'ACTIVA']);
        $cambio = fn (float $antes, float $despues, float $campania) => DB::table('optimizaciones')->insertGetId([
            'cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'fecha' => '2026-11-17', 'medio' => 'meta', 'campanias' => json_encode(['ADSET_A']),
            'tipo' => 'ajuste_dinero', 'descripcion' => 'x', 'responsable' => 'Ana Torres', 'origen' => 'meta',
            'campania_padre' => 'Otro nombre', 'campania_padre_id' => 'c1', 'presupuesto_anterior' => $antes, 'presupuesto_nuevo' => $despues,
            'presupuesto_diario' => true, 'presupuesto_campania' => $campania,
        ]);
        // Del equipo y solo +30% vs el anterior, pero la campaña queda en 130/día (+62% vs 80).
        $sobre = $cambio(100, 130, 130);
        // Dentro de lo planeado: no alerta.
        $cambio(60, 70, 70);

        $r = app(ControlGasto::class)->armar($paisId, 'ecuador', null, CarbonImmutable::parse('2026-11-18'));
        $alertas = collect($r['alertasCuenta'])->where('tipo', '!=', 'plan')->values();

        $this->assertCount(1, $alertas);
        $this->assertSame("cuenta:opt:{$sobre}", $alertas[0]['clave']);
        $this->assertSame('sobre_plan', $alertas[0]['tipo']);
        $this->assertSame('Presupuesto sobre lo planeado +63%', $alertas[0]['titulo']);
        $this->assertStringContainsString('$130/día contra $80/día planeados', $alertas[0]['detalle']);
    }
}
