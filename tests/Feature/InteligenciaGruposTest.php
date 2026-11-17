<?php

namespace Tests\Feature;

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\User;
use App\Services\ImagenFirmadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inteligencia por grupos (2026-11-17): búsqueda + mes contra el benchmark de su mes. */
class InteligenciaGruposTest extends TestCase
{
    use RefreshDatabase;

    private function creativo(int $paisId, string $nombre, string $mes, float $cost, int $nc, string $funnel = 'CNV'): void
    {
        $c = Creativo::create([
            'pais_id' => $paisId,
            'ad_id' => $nombre.'-'.$mes,
            'nombre_comun' => $nombre,
            'nombre_completo' => $nombre,
            'plataforma' => 'meta',
            'tipo_cuenta' => 'DTC',
            'formato' => 'IMAGEN',
            'funnel' => $funnel,
        ]);
        Resultado::create([
            'creativo_id' => $c->id, 'fecha_inicio' => "{$mes}-01", 'fecha_fin' => "{$mes}-28", 'mes' => $mes,
            'cost' => $cost, 'impressions' => 10000, 'clicks' => 100, 'installs' => 50, 'nc' => $nc, 'orders' => $nc, 'reorders' => 0,
        ]);
    }

    public function test_compara_grupos_contra_el_benchmark_de_su_mes(): void
    {
        $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->creativo($pais->id, 'Corona Playa', '2026-08', 200, 10);   // CAC 20
        $this->creativo($pais->id, 'Pilsener Futbol', '2026-08', 100, 10); // CAC 10
        $this->creativo($pais->id, 'Coróna Sunset', '2026-10', 100, 20);  // CAC 5 (con acento: igual coincide)
        $this->creativo($pais->id, 'Club Verano', '2026-10', 300, 20);     // CAC 15
        $epa = User::factory()->create(['rol' => 'analista']);
        $epa->paises()->attach($pais->id);

        $r = $this->actingAs($epa)->getJson('/tada/pais/ecuador/inteligencia/grupos?'.http_build_query([
            'grupos' => [['q' => 'corona', 'mes' => '2026-08'], ['q' => 'CORONA', 'mes' => '2026-10']],
        ]))->assertOk()->json('grupos');

        $this->assertSame(1, $r[0]['creativos']);
        $this->assertSame(1, $r[1]['creativos']);
        $cacAgo = $r[0]['etapas'][0]['kpis'][0];
        $cacOct = $r[1]['etapas'][0]['kpis'][0];
        $this->assertSame('cac', $cacAgo['campo']);
        // Agosto: 20 vs media del mes 300/20 = 15 → peor 33%.
        $this->assertEqualsWithDelta(20.0, $cacAgo['valor'], 0.001);
        $this->assertEqualsWithDelta(15.0, $cacAgo['benchmark'], 0.001);
        $this->assertSame('peor', $cacAgo['estado']);
        // Octubre: 5 vs 400/40 = 10 → mejor 50%.
        $this->assertSame(['mejor', 50], [$cacOct['estado'], $cacOct['pct']]);
        $this->assertSame('Coróna Sunset', $r[1]['etapas'][0]['top'][0]['nombre']);
    }

    public function test_el_json_trae_los_creativos_de_cada_grupo_con_su_benchmark(): void
    {
        $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->creativo($pais->id, 'Corona Playa', '2026-08', 200, 10);
        $this->creativo($pais->id, 'Pilsener Futbol', '2026-08', 100, 10);
        $this->creativo($pais->id, 'Corona Sunset', '2026-10', 100, 20);
        $epa = User::factory()->create(['rol' => 'analista']);
        $epa->paises()->attach($pais->id);

        $r = $this->actingAs($epa)->getJson('/tada/pais/ecuador/inteligencia/grupos?'.http_build_query([
            'grupos' => [['q' => 'corona', 'mes' => '2026-08'], ['q' => 'corona', 'mes' => '2026-10']],
        ]))->assertOk()->json('grupos');

        $this->assertSame('Corona Playa', $r[0]['lista'][0]['nombre_comun']);
        $this->assertSame('2026-08', $r[0]['lista'][0]['resultados'][0]['mes']);
        $this->assertSame(1, $r[0]['lista'][0]['benchmark']['comparables']);
        $this->assertSame('cac', $r[0]['lista'][0]['benchmark']['kpis'][0]['campo']);
        $this->assertArrayNotHasKey('filas', $r[0]);
    }

    public function test_pdf_de_la_comparacion_solo_epa(): void
    {
        $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->creativo($pais->id, 'Corona Playa', '2026-08', 200, 10);
        $this->creativo($pais->id, 'Corona Sunset', '2026-10', 100, 20);
        $query = http_build_query(['grupos' => [['q' => 'corona', 'mes' => '2026-08'], ['q' => 'corona', 'mes' => '2026-10']]]);
        $epa = User::factory()->create(['rol' => 'analista']);
        $epa->paises()->attach($pais->id);
        $cliente = User::factory()->create(['rol' => 'cliente']);
        $cliente->paises()->attach($pais->id);

        $this->actingAs($epa)->get("/tada/pais/ecuador/inteligencia/grupos/pdf?{$query}")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($cliente)->get("/tada/pais/ecuador/inteligencia/grupos/pdf?{$query}")->assertForbidden();
    }

    public function test_inteligencia_manda_las_imagenes_firmadas(): void
    {
        $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->creativo($pais->id, 'Corona Playa', '2026-08', 200, 10);
        Creativo::query()->update(['imagen_url' => 'https://storage.googleapis.com/bucket/creative-images/x.jpg']);
        $this->mock(ImagenFirmadaService::class, fn ($m) => $m->shouldReceive('firmar')->andReturnUsing(fn ($u) => $u ? "{$u}?firmada=1" : null));
        $epa = User::factory()->create(['rol' => 'analista']);
        $epa->paises()->attach($pais->id);

        $this->actingAs($epa)->get('/tada/pais/ecuador/inteligencia?mes=2026-08')
            ->assertInertia(fn ($page) => $page->where('creativos.0.imagen_url', 'https://storage.googleapis.com/bucket/creative-images/x.jpg?firmada=1'));

        $r = $this->actingAs($epa)->getJson('/tada/pais/ecuador/inteligencia/grupos?'.http_build_query([
            'grupos' => [['q' => 'corona', 'mes' => '2026-08'], ['q' => 'playa', 'mes' => '2026-08']],
        ]))->json('grupos');
        $this->assertStringEndsWith('?firmada=1', $r[0]['lista'][0]['imagen_url']);
    }

    public function test_pide_al_menos_dos_grupos(): void
    {
        $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $epa = User::factory()->create(['rol' => 'analista']);
        $epa->paises()->attach($pais->id);

        $this->actingAs($epa)->getJson('/tada/pais/ecuador/inteligencia/grupos?'.http_build_query([
            'grupos' => [['q' => 'corona', 'mes' => '2026-08']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('grupos');
    }
}
