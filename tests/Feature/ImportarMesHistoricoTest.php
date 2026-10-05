<?php

namespace Tests\Feature;

use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importar meses de más de 2 meses atrás es exclusivo de superadmin
 * (pedido explícito 2026-10-01, ver ImportarDatosController::
 * rechazarMesHistorico). Sin appsflyer_apps cargadas, un import que SÍ pasa
 * la guardia termina en 422 ("no tiene apps de AppsFlyer configuradas")
 * antes de llamar a ninguna API -- eso distingue "pasó la guardia" (422)
 * de "la guardia lo frenó" (403) sin tocar la red.
 */
class ImportarMesHistoricoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');
    }

    private function usuario(string $rol): User
    {
        $mx = Pais::firstOrCreate(['codigo' => 'MX'], ['nombre' => 'México']);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($mx->id);

        return $user;
    }

    private function assertPasoLaGuardia($response): void
    {
        $response->assertStatus(422)->assertJsonPath('error', fn (string $e) => str_contains($e, 'apps de AppsFlyer'));
    }

    private function importarApi(User $user, string $mes)
    {
        return $this->actingAs($user)->postJson('/pais/mexico/importar/api', [
            'desde' => "{$mes}-01",
            'hasta' => "{$mes}-28",
        ]);
    }

    public function test_un_epa_puede_importar_el_mes_actual_y_los_dos_anteriores(): void
    {
        $junior = $this->usuario('junior');

        foreach (['2026-10', '2026-09', '2026-08'] as $mes) {
            $this->assertPasoLaGuardia($this->importarApi($junior, $mes));
        }
    }

    public function test_un_epa_no_puede_importar_un_mes_de_mas_de_dos_meses_atras(): void
    {
        foreach (['junior', 'senior', 'gerente', 'director'] as $rol) {
            $this->importarApi($this->usuario($rol), '2026-07')
                ->assertForbidden()
                ->assertJsonPath('error', fn (string $e) => str_contains($e, 'Solo superadmin'));
        }
    }

    public function test_superadmin_si_puede_importar_meses_historicos(): void
    {
        $this->assertPasoLaGuardia($this->importarApi($this->usuario('superadmin'), '2026-05'));
    }

    public function test_el_import_por_csv_tambien_respeta_el_limite(): void
    {
        $this->actingAs($this->usuario('gerente'))
            ->postJson('/pais/mexico/importar', [
                'token' => 'no-importa',
                'nombre_archivo' => 'mayo.csv',
                'desde' => '2026-05-01',
                'hasta' => '2026-05-31',
            ])
            ->assertForbidden();
    }

    public function test_la_pantalla_recibe_el_mes_minimo_solo_si_no_es_superadmin(): void
    {
        $this->actingAs($this->usuario('junior'))
            ->get('/pais/mexico/importar')
            ->assertInertia(fn ($page) => $page->where('mesMinimoImportable', '2026-08'));

        $this->actingAs($this->usuario('superadmin'))
            ->get('/pais/mexico/importar')
            ->assertInertia(fn ($page) => $page->where('mesMinimoImportable', null));
    }
}
