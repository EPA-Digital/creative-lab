<?php

namespace Tests\Feature;

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Datos por mes" en Cargar datos (pedido explícito 2026-10-05): ver la
 * data importada de un mes tipo Excel (por arte o por anuncio) y
 * eliminarla -- eliminar es solo superadmin.
 */
class DatosMensualesTest extends TestCase
{
    use RefreshDatabase;

    private Pais $ec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($this->ec->id);

        return $user;
    }

    private function resultado(Pais $pais, string $adId, string $arte, string $mes, array $valores): Resultado
    {
        $creativo = Creativo::firstOrCreate(
            ['ad_id' => $adId, 'pais_id' => $pais->id],
            ['nombre_comun' => $arte, 'nombre_completo' => $arte, 'plataforma' => 'tiktok', 'funnel' => 'CNV', 'tipo_cuenta' => 'DTC'],
        );

        return Resultado::create([
            'creativo_id' => $creativo->id,
            'mes' => $mes,
            'fecha_inicio' => "{$mes}-01",
            'fecha_fin' => "{$mes}-28",
            'cost' => 0, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'reorders' => 0,
            'tiene_meta' => true,
            ...$valores,
        ]);
    }

    private function sembrar(): void
    {
        $this->resultado($this->ec, '1', 'VID-SYMPHONY2', '2026-09', ['cost' => 1224.79, 'installs' => 335, 'nc' => 113, 'orders' => 900]);
        $this->resultado($this->ec, '2', 'VID-SYMPHONY2', '2026-09', ['cost' => 598.43, 'installs' => 147, 'nc' => 55, 'orders' => 502]);
        $this->resultado($this->ec, '3', 'VID-OTRO', '2026-09', ['cost' => 100, 'installs' => 10, 'nc' => null, 'orders' => null]);
        $this->resultado($this->ec, '1', 'VID-SYMPHONY2', '2026-08', ['cost' => 50, 'installs' => 5, 'nc' => 2, 'orders' => 4]);
    }

    public function test_por_arte_suma_los_anuncios_y_recalcula_las_eficiencias(): void
    {
        $this->sembrar();

        $r = $this->actingAs($this->usuario('junior'))
            ->getJson('/pais/ecuador/importar/mensual?mes=2026-09')
            ->assertOk()
            ->assertJsonPath('mes', '2026-09')
            ->assertJsonPath('meses.0.mes', '2026-09')
            ->assertJsonPath('meses.1.mes', '2026-08')
            ->assertJsonCount(2, 'filas');

        $symphony = collect($r->json('filas'))->firstWhere('nombreComun', 'VID-SYMPHONY2');
        $this->assertSame(2, $symphony['anuncios']);
        $this->assertEqualsWithDelta(1823.22, $symphony['cost'], 0.001);
        $this->assertSame(168, $symphony['nc']);
        $this->assertSame(1402, $symphony['orders']);
        $this->assertEqualsWithDelta(10.85, $symphony['cac'], 0.01);
        $this->assertEqualsWithDelta(3.78, $symphony['cpi'], 0.01);

        // Sin venta real cargada: null, nunca 0.
        $this->assertNull(collect($r->json('filas'))->firstWhere('nombreComun', 'VID-OTRO')['nc']);
        $this->assertSame(168, $r->json('totales.nc'));
    }

    public function test_por_anuncio_devuelve_una_fila_por_ad_id(): void
    {
        $this->sembrar();

        $this->actingAs($this->usuario('junior'))
            ->getJson('/pais/ecuador/importar/mensual?mes=2026-09&agrupar=anuncio')
            ->assertOk()
            ->assertJsonCount(3, 'filas')
            ->assertJsonPath('filas.0.adId', '1');
    }

    public function test_sin_mes_elige_el_mas_reciente(): void
    {
        $this->sembrar();

        $this->actingAs($this->usuario('junior'))->getJson('/pais/ecuador/importar/mensual')->assertJsonPath('mes', '2026-09');
    }

    public function test_un_cliente_no_puede_ver_la_data_importada(): void
    {
        $this->actingAs($this->usuario('cliente'))->getJson('/pais/ecuador/importar/mensual')->assertForbidden();
    }

    public function test_superadmin_elimina_solo_ese_mes_de_ese_pais(): void
    {
        $this->sembrar();
        $mx = Pais::create(['codigo' => 'MX', 'nombre' => 'México']);
        $this->resultado($mx, '99', 'VID-MX', '2026-09', ['cost' => 10]);

        $this->actingAs($this->usuario('superadmin'))
            ->deleteJson('/pais/ecuador/importar/mensual/2026-09', ['confirmacion' => '2026-09'])
            ->assertOk()
            ->assertJsonPath('resultados', 3);

        $this->assertSame(0, Resultado::where('mes', '2026-09')->whereHas('creativo', fn ($q) => $q->where('pais_id', $this->ec->id))->count());
        $this->assertSame(1, Resultado::where('mes', '2026-08')->count(), 'Otros meses no se tocan.');
        $this->assertSame(1, Resultado::where('mes', '2026-09')->whereHas('creativo', fn ($q) => $q->where('pais_id', $mx->id))->count(), 'Otros países no se tocan.');
        $this->assertSame(3, Creativo::where('pais_id', $this->ec->id)->count(), 'Los creativos se conservan.');
        $this->assertDatabaseHas('auditoria_accesos', ['evento' => 'datos.mes_eliminado']);
    }

    public function test_eliminar_exige_escribir_el_mes(): void
    {
        $this->sembrar();

        $this->actingAs($this->usuario('superadmin'))
            ->deleteJson('/pais/ecuador/importar/mensual/2026-09', ['confirmacion' => 'si'])
            ->assertStatus(422);

        $this->assertSame(3, Resultado::where('mes', '2026-09')->count());
    }

    public function test_solo_superadmin_puede_eliminar_un_mes(): void
    {
        $this->sembrar();

        foreach (['junior', 'gerente', 'director'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->deleteJson('/pais/ecuador/importar/mensual/2026-09', ['confirmacion' => '2026-09'])
                ->assertForbidden();
        }

        $this->assertSame(3, Resultado::where('mes', '2026-09')->count());
    }

    public function test_la_pantalla_sabe_si_puede_eliminar(): void
    {
        $this->actingAs($this->usuario('gerente'))->get('/pais/ecuador/importar')
            ->assertInertia(fn ($page) => $page->where('puedeEliminarMes', false));
        $this->actingAs($this->usuario('superadmin'))->get('/pais/ecuador/importar')
            ->assertInertia(fn ($page) => $page->where('puedeEliminarMes', true));
    }

    public function test_peru_esta_habilitado(): void
    {
        $this->assertTrue(config('paises.peru.habilitado'));
    }
}
