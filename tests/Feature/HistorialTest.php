<?php

namespace Tests\Feature;

use App\Models\AuditoriaAcceso;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\HistorialCambios;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Historial de cambios (2026-11-17): eventos del país y la cuenta, en frases.
 */
class HistorialTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'junior'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => 'EC'], ['nombre' => 'Ecuador']);
        $user = User::factory()->create(['rol' => $rol, 'name' => 'Ana']);
        $user->paises()->attach($pais->id);

        return $user;
    }

    public function test_lista_los_cambios_del_pais_y_cuenta_en_frases(): void
    {
        $user = $this->usuario();
        $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-11/iniciar', ['desde' => 'vacio'])->assertOk();
        $this->actingAs($user)->post('/tada/pais/ecuador/daily/2026-11/campanias', ['medio' => 'meta', 'campania' => 'CAMP_X'])->assertOk();
        $id = \DB::table('daily_campanias')->value('id');
        $this->actingAs($user)->patch("/tada/pais/ecuador/daily/campanias/{$id}", ['budget' => 500, 'kpi_installs' => 800])->assertOk();
        $this->actingAs($user)->post('/tada/pais/ecuador/control-gasto/excluir', ['campania' => 'CAMP_Y', 'motivo' => 'No es nuestra'])->assertOk();
        // De otro país y de otra cuenta: no salen.
        AuditoriaAcceso::create(['usuario_id' => $user->id, 'evento' => 'daily.iniciado', 'detalle' => ['pais' => 'mexico', 'mes' => '2026-11', 'desde' => 'vacio'], 'creado_en' => now()]);
        $otra = Cuenta::create(['cliente_id' => Cliente::create(['nombre' => 'Otra'])->id, 'nombre' => 'Otra', 'slug' => 'otra', 'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta']]);
        AuditoriaAcceso::create(['usuario_id' => $user->id, 'evento' => 'daily.iniciado', 'detalle' => ['pais' => 'ecuador', 'cuenta_id' => $otra->id, 'mes' => '2026-11', 'desde' => 'vacio'], 'creado_en' => now()]);
        // Login: no es un cambio de negocio.
        AuditoriaAcceso::create(['usuario_id' => $user->id, 'evento' => 'login.exito', 'detalle' => ['pais' => 'ecuador'], 'creado_en' => now()]);

        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $r = app(HistorialCambios::class)->listar('ecuador', 'EC');

        $this->assertSame([
            'Quitó CAMP_Y de Control de gasto: "No es nuestra".',
            'Cambió el budget a $500.00, el KPI de installs a 800 de CAMP_X en el Daily.',
            'Agregó CAMP_X al Daily de noviembre 2026.',
            'Arrancó el Daily de noviembre 2026 vacío.',
        ], array_column($r['eventos'], 'texto'));
        $this->assertSame(['Ana'], array_unique(array_column($r['eventos'], 'quien')));
        $this->assertSame(['control', 'daily', 'daily', 'daily'], array_column($r['eventos'], 'area'));

        $this->assertCount(1, app(HistorialCambios::class)->listar('ecuador', 'EC', ['area' => 'control'])['eventos']);

        $this->actingAs($user)->get('/tada/pais/ecuador/historial?area=daily')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Historial')->where('area', 'daily')->has('eventos', 3));
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/historial')->assertForbidden();
    }
}
