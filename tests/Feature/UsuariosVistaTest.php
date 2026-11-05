<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Datos de la vista de Usuarios (diseño 2026-11-05): lo que necesitan la
 * tabla y el modal de editar, con los permisos de siempre.
 */
class UsuariosVistaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_lista_trae_acceso_2fa_equipo_y_si_se_puede_editar(): void
    {
        $director = User::factory()->create(['rol' => 'director', 'email' => 'dir@epa.digital']);
        $cliente = User::factory()->create(['rol' => 'cliente', 'email' => 'ana@cliente.com', 'metodo_auth' => 'password', 'puesto' => 'Marketing']);

        $this->actingAs($director)->get('/usuarios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Usuarios/Index')
                ->where('usuarios.0.email', 'ana@cliente.com')
                ->where('usuarios.0.editable', true)
                ->where('usuarios.0.es_dominio_epa', false)
                ->where('usuarios.0.tiene_2fa', false)
                ->where('usuarios.0.puesto', 'Marketing')
                ->where('usuarios.0.cuentas.0.slug', 'tada')
                // Su propia fila aparece, pero no se autoadministra.
                ->where('usuarios.1.id', $director->id)
                ->where('usuarios.1.editable', false)
                ->where('usuarios.1.es_dominio_epa', true)
                ->has('cuentas', 1)
                ->has('paisesOtorgables'));
    }

    public function test_junior_sigue_sin_ver_la_lista(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'junior']))->get('/usuarios')->assertForbidden();
    }
}
