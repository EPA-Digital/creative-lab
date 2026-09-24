<?php

namespace Tests\Feature;

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-24: un 'cliente' (solo lectura) no debe ver
 * creativos "sin clasificar" (funnel null) -- ver
 * AnalisisCreativoController::index(). EPA sigue viéndolos igual que
 * siempre, los necesita para clasificarlos (ver GestionNombresController).
 */
class AnalisisCreativoClienteFiltroTest extends TestCase
{
    use RefreshDatabase;

    private function crearCreativoConResultado(int $paisId, string $adId, ?string $funnel): void
    {
        $creativo = Creativo::create([
            'pais_id' => $paisId,
            'ad_id' => $adId,
            'nombre_comun' => 'Arte '.$adId,
            'nombre_completo' => 'Arte '.$adId,
            'plataforma' => 'meta',
            'tipo_cuenta' => 'DTC',
            'formato' => 'IMAGEN',
            'funnel' => $funnel,
        ]);

        Resultado::create([
            'creativo_id' => $creativo->id,
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-31',
            'mes' => '2026-07',
            'cost' => 10,
            'impressions' => 100,
            'clicks' => 5,
            'installs' => 2,
            'nc' => 0,
            'orders' => 0,
            'reorders' => 0,
        ]);
    }

    public function test_un_cliente_no_ve_creativos_sin_clasificar(): void
    {
        $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->crearCreativoConResultado($pais->id, 'clasificado-1', 'AWA');
        $this->crearCreativoConResultado($pais->id, 'sin-clasificar-1', null);

        $cliente = User::factory()->create(['rol' => 'cliente']);
        $cliente->paises()->attach($pais->id);

        $response = $this->actingAs($cliente)->get('/pais/ecuador/analisis');

        $response->assertInertia(fn ($page) => $page
            ->has('creativos', 1)
            ->where('creativos.0.ad_id', 'clasificado-1'));
    }

    public function test_un_epa_si_ve_creativos_sin_clasificar(): void
    {
        $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->crearCreativoConResultado($pais->id, 'clasificado-1', 'AWA');
        $this->crearCreativoConResultado($pais->id, 'sin-clasificar-1', null);

        $director = User::factory()->create(['rol' => 'director']);
        $director->paises()->attach($pais->id);

        $response = $this->actingAs($director)->get('/pais/ecuador/analisis');

        $response->assertInertia(fn ($page) => $page->has('creativos', 2));
    }
}
