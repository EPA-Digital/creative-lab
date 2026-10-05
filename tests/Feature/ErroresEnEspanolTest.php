<?php

namespace Tests\Feature;

use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Errores en español (2026-10-06, pedido explícito) -- caso real: subir el
 * CSV de Perú mostraba "The archivo failed to upload." (PHP descartaba el
 * archivo por pasar de 2 MB, ver docker/php.ini).
 */
class ErroresEnEspanolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
        $this->user = User::factory()->create(['rol' => 'gerente']);
        $this->user->paises()->attach($pe->id);
    }

    public function test_la_app_esta_en_espanol_por_defecto(): void
    {
        $this->assertSame('es', config('app.locale'));
    }

    public function test_un_csv_de_mas_de_30_mb_se_rechaza_con_un_mensaje_claro(): void
    {
        $this->actingAs($this->user)
            ->postJson('/pais/peru/importar/previsualizar', ['archivo' => UploadedFile::fake()->create('peru.csv', 31 * 1024, 'text/csv')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El archivo pesa más de 30 MB. Exporta el CSV por un rango más corto o filtrado por país.');
    }

    public function test_un_csv_del_tamanio_de_mexico_si_pasa(): void
    {
        // México pesa ~4.2 MB -- antes de docker/php.ini fallaba en
        // producción (límite de fábrica de PHP: 2 MB).
        $this->actingAs($this->user)
            ->postJson('/pais/peru/importar/previsualizar', ['archivo' => UploadedFile::fake()->create('mexico.csv', 5 * 1024, 'text/csv')])
            ->assertOk()
            ->assertJsonStructure(['token', 'nombreArchivo']);
    }

    public function test_un_archivo_que_no_es_csv_se_rechaza_en_espanol(): void
    {
        $this->actingAs($this->user)
            ->postJson('/pais/peru/importar/previsualizar', ['archivo' => UploadedFile::fake()->create('peru.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'tiene que ser un CSV'));
    }

    public function test_los_mensajes_genericos_salen_en_espanol_con_nombres_legibles(): void
    {
        $this->actingAs($this->user)
            ->postJson('/pais/peru/importar/api', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.desde.0', 'El campo fecha de inicio es obligatorio.');
    }
}
