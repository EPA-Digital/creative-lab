<?php

namespace Tests\Feature\MultiCuenta;

use App\Models\Cliente;
use App\Models\Creativo;
use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\User;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use RuntimeException;
use Tests\TestCase;

/**
 * Plan multi-cuenta: ningún dato de una cuenta se ve, se mezcla ni se
 * procesa junto con el de otra. Cubre el filtro global (PerteneceACuenta),
 * las consultas directas a la base y el alta de cuentas publicitarias.
 */
class AislamientoCuentasTest extends TestCase
{
    use RefreshDatabase;

    private Pais $mx;

    private Cuenta $tada;

    private Cuenta $openbank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mx = Pais::create(['codigo' => 'MX', 'nombre' => 'México']);
        $this->tada = Cuenta::tada();
        $this->openbank = Cuenta::create([
            'cliente_id' => Cliente::create(['nombre' => 'Openbank'])->id,
            'nombre' => 'Openbank',
            'slug' => 'openbank',
            'servicios' => ['App'],
            'objetivos' => ['Instalaciones'],
            'medios' => ['meta', 'tiktok'],
        ]);
    }

    private function creativo(Cuenta $cuenta, string $adId, string $arte, float $costo): Creativo
    {
        return CuentaActual::usar($cuenta, function () use ($adId, $arte, $costo) {
            $creativo = Creativo::create([
                'ad_id' => $adId, 'pais_id' => $this->mx->id, 'nombre_comun' => $arte, 'nombre_completo' => $arte,
                'plataforma' => 'meta', 'funnel' => 'CNV', 'tipo_cuenta' => 'DTC',
            ]);
            Resultado::create([
                'creativo_id' => $creativo->id, 'mes' => '2026-10', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31',
                'cost' => $costo, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'reorders' => 0, 'tiene_meta' => true,
            ]);

            return $creativo;
        });
    }

    public function test_cada_cuenta_solo_ve_sus_creativos(): void
    {
        $this->creativo($this->tada, '111', 'ARTE-TADA', 100);
        $this->creativo($this->openbank, '222', 'ARTE-OPENBANK', 900);

        CuentaActual::usar($this->tada, fn () => $this->assertSame(['ARTE-TADA'], Creativo::pluck('nombre_comun')->all()));
        CuentaActual::usar($this->openbank, fn () => $this->assertSame(['ARTE-OPENBANK'], Creativo::pluck('nombre_comun')->all()));
    }

    public function test_un_registro_nuevo_nace_en_la_cuenta_actual(): void
    {
        $creativo = $this->creativo($this->openbank, '222', 'ARTE-OPENBANK', 900);

        $this->assertSame($this->openbank->id, $creativo->cuenta_id);
    }

    public function test_sin_cuenta_actual_no_se_consulta_nada(): void
    {
        Context::flush();

        $this->expectException(RuntimeException::class);
        Creativo::count();
    }

    public function test_el_mismo_ad_id_puede_existir_en_dos_cuentas(): void
    {
        $this->creativo($this->tada, '555', 'ARTE-TADA', 100);
        $this->creativo($this->openbank, '555', 'ARTE-OPENBANK', 900);

        $this->assertSame(2, Creativo::withoutGlobalScope('cuenta')->where('ad_id', '555')->count());
    }

    public function test_un_creativo_de_otra_cuenta_no_se_resuelve_por_id(): void
    {
        $ajeno = $this->creativo($this->openbank, '222', 'ARTE-OPENBANK', 900);

        CuentaActual::usar($this->tada, fn () => $this->assertNull(Creativo::find($ajeno->id)));
    }

    public function test_los_datos_por_mes_de_tada_no_incluyen_los_de_otra_cuenta(): void
    {
        $this->creativo($this->tada, '111', 'ARTE-TADA', 100);
        $this->creativo($this->openbank, '222', 'ARTE-OPENBANK', 900);
        $user = User::factory()->create(['rol' => 'junior']);
        $user->paises()->attach($this->mx->id);

        $r = $this->actingAs($user)->getJson('/tada/pais/mexico/importar/mensual?mes=2026-10')->assertOk();

        $this->assertSame(['ARTE-TADA'], collect($r->json('filas'))->pluck('nombreComun')->all());
        $this->assertEqualsWithDelta(100, $r->json('meses.0.costo'), 0.001);
    }

    public function test_la_importacion_solo_lee_las_cuentas_publicitarias_de_su_cuenta(): void
    {
        CuentaActual::usar($this->tada, fn () => CuentaPublicitaria::create(['pais_id' => $this->mx->id, 'plataforma' => 'meta', 'id_externo' => '1', 'tipo' => 'tada']));
        CuentaActual::usar($this->openbank, fn () => CuentaPublicitaria::create(['pais_id' => $this->mx->id, 'plataforma' => 'meta', 'id_externo' => '2', 'tipo' => 'tada']));

        CuentaActual::usar($this->tada, fn () => $this->assertSame(['1'], CuentaPublicitaria::activasPara($this->mx->id, 'meta')->pluck('id_externo')->all()));
    }

    public function test_no_se_puede_registrar_una_cuenta_publicitaria_que_ya_es_de_otra_cuenta(): void
    {
        CuentaActual::usar($this->openbank, fn () => CuentaPublicitaria::create(['pais_id' => $this->mx->id, 'plataforma' => 'meta', 'id_externo' => '999', 'tipo' => 'tada']));
        $superadmin = User::factory()->create(['rol' => 'superadmin']);
        $superadmin->paises()->attach($this->mx->id);

        $this->actingAs($superadmin)->postJson('/tada/pais/mexico/ajustes/cuentas-publicitarias', [
            'pais_id' => $this->mx->id, 'plataforma' => 'meta', 'id_externo' => 'act_999', 'tipo' => 'tada', 'cuenta_en_venta_real' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'Esa cuenta publicitaria ya está registrada en otra cuenta del dashboard.');

        CuentaActual::usar($this->tada, fn () => $this->assertSame(0, CuentaPublicitaria::count()));
    }
}
