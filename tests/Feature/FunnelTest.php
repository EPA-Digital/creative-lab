<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Support\CuentaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Funnel (2026-11-17): eventos de AF data por día, fuente y medio paid,
 * como las hojas funnel.2026 / funnel.medio.2026.
 */
class FunnelTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'junior', string $codigo = 'EC', string $nombre = 'Ecuador'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function fila(string $fecha, string $fuente, string $media, array $v, array $otros): void
    {
        FuenteAppsflyer::create([
            'pais_id' => Pais::where('codigo', 'EC')->value('id'),
            'fecha' => $fecha,
            'app' => 'TaDa',
            'media_source' => $media,
            'campania' => 'X',
            'installs' => $v[0],
            'nc' => $v[1],
            'recompras' => $v[2],
            'ordenes' => $v[1] + $v[2],
            'otros' => $otros,
            'fuente_final' => $fuente,
            'canal' => $media,
        ]);
    }

    public function test_agrupa_por_dia_fuente_y_medio_paid(): void
    {
        $user = $this->usuario();
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        $ev = ['App Opened' => 100, 'Activity Sessions' => 80, 'Product Details Viewed' => 40, 'Product Added' => 20, 'Payment Method Selected' => 10];
        $this->fila('2026-10-01', 'TADA PERFORMANCE', 'Facebook Ads', [10, 2, 5], $ev);
        $this->fila('2026-10-01', 'TADA PERFORMANCE', 'Facebook Ads', [5, 1, 1], $ev);
        $this->fila('2026-10-01', 'TADA PERFORMANCE', 'tiktokglobal_int', [3, 0, 2], $ev);
        $this->fila('2026-10-01', 'ORGANIC', 'Organic', [7, 1, 0], ['App Opened' => 9, 'Sessions' => 6]);
        $this->fila('2026-10-02', 'ORGANIC', 'Organic', [1, 0, 0], []);

        $this->actingAs($user)->get('/tada/pais/ecuador/funnel')
            ->assertOk()
            ->assertInertia(function ($p) {
                $p->component('Funnel')->where('fuentePaid', 'TADA PERFORMANCE')->has('dias', 4);
                $props = $p->toArray()['props'];
                $fuentes = $props['fuentes'];
                $medios = collect($props['medios'])->keyBy('id');
                // Facebook primero (orden de la config) y con su nombre de canal.
                $this->assertSame(['Facebook Ads', 'tiktokglobal_int'], array_column($props['medios'], 'id'));
                $this->assertSame('TikTok', $medios['tiktokglobal_int']['label']);

                $fb = collect($props['dias'])->first(fn ($d) => $d[2] === $medios['Facebook Ads']['i']);
                // [fecha, fuente, medio, installs, app opened, sessions, viewed, added, payment, order submitted, first order, total order]
                $this->assertSame(['2026-10-01', array_search('TADA PERFORMANCE', $fuentes), $medios['Facebook Ads']['i'], 15, 200, 160, 80, 40, 20, 6, 3, 9], $fb);

                // Orgánico: "Sessions" también cuenta como sesiones; fuera de Paid no se separa por medio.
                $org = collect($props['dias'])->first(fn ($d) => $d[0] === '2026-10-01' && $d[1] === array_search('ORGANIC', $fuentes));
                $this->assertSame([-1, 7, 9, 6], [$org[2], $org[3], $org[4], $org[5]]);
            });
    }

    public function test_sin_datos_de_otra_cuenta(): void
    {
        $user = $this->usuario();
        $otra = Cuenta::create(['cliente_id' => Cliente::create(['nombre' => 'Otra'])->id, 'nombre' => 'Otra', 'slug' => 'otra', 'servicios' => ['App'], 'objetivos' => ['Instalaciones'], 'medios' => ['meta']]);
        CuentaActual::fijar($otra);
        $this->fila('2026-10-01', 'TADA PERFORMANCE', 'Facebook Ads', [10, 2, 5], []);

        $this->actingAs($user)->get('/tada/pais/ecuador/funnel')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Funnel')->has('dias', 0));
    }

    public function test_cliente_no_entra_y_mexico_ya_tiene_funnel(): void
    {
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/funnel')->assertForbidden();
        // México se habilitó el 2026-11-18 (ya tiene reglas de AF data).
        $this->actingAs($this->usuario('junior', 'MX', 'México'))->get('/tada/pais/mexico/funnel')->assertOk();
    }
}
