<?php

namespace Tests\Feature;

use App\Models\Pais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Usuarios es transversal a países, pero el rail necesita un país de
 * contexto. Caso real (2026-10-06): estando en Perú, entrar a Usuarios
 * "cambiaba" a Ecuador (primer país habilitado). Ahora usa el último país
 * visitado en la sesión.
 */
class PaisDeContextoTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        $ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $pe = Pais::create(['codigo' => 'PE', 'nombre' => 'Perú']);
        $this->gerente = User::factory()->create(['rol' => 'gerente']);
        $this->gerente->paises()->attach([$ec->id, $pe->id]);
    }

    public function test_usuarios_mantiene_el_ultimo_pais_visitado(): void
    {
        $this->actingAs($this->gerente)->get('/pais/peru/analisis')->assertOk();

        $this->get('/usuarios')
            ->assertInertia(fn ($page) => $page->component('Usuarios/Index')->where('pais', 'peru'));
    }

    public function test_sin_pais_visitado_usa_el_primero_habilitado_al_que_tiene_acceso(): void
    {
        $soloPeru = User::factory()->create(['rol' => 'gerente']);
        $soloPeru->paises()->attach(Pais::where('codigo', 'PE')->value('id'));

        $this->actingAs($soloPeru)->get('/usuarios')
            ->assertInertia(fn ($page) => $page->where('pais', 'peru'));
    }

    public function test_si_ya_no_tiene_acceso_al_ultimo_pais_no_lo_usa(): void
    {
        $this->actingAs($this->gerente)->get('/pais/peru/analisis')->assertOk();
        $this->gerente->paises()->detach(Pais::where('codigo', 'PE')->value('id'));

        $this->get('/usuarios')->assertInertia(fn ($page) => $page->where('pais', 'ecuador'));
    }
}
