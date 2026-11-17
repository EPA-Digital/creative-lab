<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\PanelDaily;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Daily (2026-11-18): planeación editable del mes + pacing calculado con
 * data.medios y AF data.
 */
class DailyTest extends TestCase
{
    use RefreshDatabase;

    private const CAMP = 'ECU_DTC_TAD_AON_AON_TAD_AWA_EPA_FB-AWARENESS-BROAD-V2';

    private function usuario(string $rol = 'junior'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador']);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function datos(): void
    {
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $paisId = Pais::where('codigo', 'EC')->value('id');
        foreach (['2026-11-01' => 100, '2026-11-02' => 50, '2026-11-20' => 999] as $fecha => $costo) {
            DB::table('medios_diario')->insert([
                'cuenta_id' => CuentaActual::id(), 'pais_id' => $paisId, 'fecha' => $fecha, 'medio' => 'meta', 'medio_original' => 'facebook',
                'campania' => self::CAMP, 'campania_id' => '120237019589620661', 'costo' => $costo, 'clicks' => 10, 'impresiones' => 1000,
            ]);
        }
        FuenteAppsflyer::create([
            'pais_id' => $paisId, 'fecha' => '2026-11-02', 'app' => 'TaDa', 'media_source' => 'Facebook Ads',
            'campania' => self::CAMP, 'campania_id' => '120237019589620661', 'installs' => 30, 'nc' => 3, 'recompras' => 12, 'ordenes' => 15,
            'fuente_final' => 'TADA PERFORMANCE', 'canal' => 'Facebook Ads',
        ]);
    }

    public function test_empieza_con_las_campanias_con_gasto_y_calcula_el_pacing(): void
    {
        $user = $this->usuario();
        $this->datos();
        $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-11/iniciar', ['desde' => 'datos'])->assertOk();

        $fila = DB::table('daily_campanias')->first();
        $this->assertSame('meta', $fila->medio);
        $this->assertSame('AWA', $fila->funnel);
        $this->actingAs($user)->patch("/tada/pais/ecuador/daily/campanias/{$fila->id}", ['budget' => 600, 'kpi_installs' => 100])->assertOk();

        // "Hoy" = 4 nov: ayer = 3 nov, 3 de 30 días transcurridos (10% ideal).
        $r = app(PanelDaily::class)->armar(Pais::where('codigo', 'EC')->value('id'), 'ecuador', '2026-11', CarbonImmutable::parse('2026-11-04'));
        $c = $r['campanias'][0];
        $this->assertSame(150.0, $c['costo']);          // el 20 nov queda fuera del periodo
        $this->assertSame(30, $c['installs']);
        $this->assertSame(15, $c['orders']);
        $this->assertEqualsWithDelta(0.25, $c['consumido'], 1e-9);
        $this->assertEqualsWithDelta(0.15, $c['dif'], 1e-9);
        $this->assertEqualsWithDelta(450 / 27, $c['daily_ideal'], 0.01);
        $this->assertSame(['ayer' => '2026-11-03', 'restantes' => 27], ['ayer' => $r['fechas']['ayer'], 'restantes' => $r['fechas']['restantes']]);
    }

    public function test_ajustes_por_dia_de_la_semana(): void
    {
        $user = $this->usuario();
        $this->datos();
        $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-11/iniciar', ['desde' => 'datos'])->assertOk();
        $id = DB::table('daily_campanias')->value('id');

        $this->actingAs($user)->patch("/tada/pais/ecuador/daily/campanias/{$id}", ['ajuste' => ['dia' => 0, 'valor' => 80]])->assertOk();
        $this->actingAs($user)->patch("/tada/pais/ecuador/daily/campanias/{$id}", ['ajuste' => ['dia' => 4, 'valor' => '120.5']])->assertOk();
        $this->actingAs($user)->patch("/tada/pais/ecuador/daily/campanias/{$id}", ['ajuste' => ['dia' => 0, 'valor' => null]])->assertOk();
        $this->actingAs($user)->patch("/tada/pais/ecuador/daily/campanias/{$id}", ['ajuste' => ['dia' => 7, 'valor' => 1]])->assertSessionHasErrors('ajuste.dia');
        $this->actingAs($user)->patch('/tada/pais/ecuador/daily/2026-11/plan', ['total_dia' => ['dia' => 6, 'valor' => 950]])->assertOk();

        $r = app(PanelDaily::class)->armar(Pais::where('codigo', 'EC')->value('id'), 'ecuador', '2026-11', CarbonImmutable::parse('2026-11-04'));
        $this->assertSame([null, null, null, null, 120.5, null, null], $r['campanias'][0]['ajustes']);
        $this->assertSame([null, null, null, null, null, null, 950.0], $r['plan']['total_dia']);
    }

    public function test_copia_el_mes_anterior_sin_las_finalizadas(): void
    {
        $user = $this->usuario();
        $this->datos();
        $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-10/iniciar', ['desde' => 'vacio'])->assertOk();
        foreach (['A' => 'ACTIVA', 'B' => 'FINALIZADA', 'C' => 'EN PAUSA'] as $nombre => $status) {
            $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-10/campanias', ['medio' => 'meta', 'campania' => $nombre, 'budget' => 100])->assertOk();
            DB::table('daily_campanias')->where('campania', $nombre)->update(['status' => $status]);
        }
        $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-11/iniciar', ['desde' => 'anterior'])->assertOk();
        $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-11/iniciar', ['desde' => 'anterior'])->assertStatus(422);

        $plan = DB::table('daily_planes')->where('mes', '2026-11')->value('id');
        $this->assertSame(['A', 'C'], DB::table('daily_campanias')->where('plan_id', $plan)->orderBy('orden')->pluck('campania')->all());
    }

    public function test_no_edita_campanias_de_otra_cuenta_y_cliente_no_entra(): void
    {
        $user = $this->usuario();
        $otra = Cuenta::create(['cliente_id' => Cliente::create(['nombre' => 'Otra'])->id, 'nombre' => 'Otra', 'slug' => 'otra', 'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta']]);
        $planId = DB::table('daily_planes')->insertGetId(['cuenta_id' => $otra->id, 'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'mes' => '2026-11', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30']);
        $id = DB::table('daily_campanias')->insertGetId(['plan_id' => $planId, 'medio' => 'meta', 'campania' => 'X', 'budget' => 1]);

        $this->actingAs($user)->patch("/tada/pais/ecuador/daily/campanias/{$id}", ['budget' => 5])->assertNotFound();
        $this->actingAs($user)->get('/tada/pais/ecuador/daily')->assertOk()->assertInertia(fn ($p) => $p->component('Daily')->where('plan', null));
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/daily')->assertForbidden();
    }
}
