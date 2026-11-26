<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\FuenteAppsflyer;
use App\Models\Pais;
use App\Models\User;
use App\Services\Fuentes\PanelCanales;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Channel (2026-11-17): canales por media source desde AF data, con lo
 * capturado a mano en la hoja para los periodos que AF data no cubre.
 */
class ChannelTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'junior', string $codigo = 'EC', string $nombre = 'Ecuador'): User
    {
        $pais = Pais::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        $user = User::factory()->create(['rol' => $rol]);
        $user->paises()->attach($pais->id);

        return $user;
    }

    private function af(string $fecha, string $media, string $fuente, int $installs, int $nc, int $recompras): void
    {
        FuenteAppsflyer::create([
            'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'fecha' => $fecha, 'app' => 'TaDa',
            'media_source' => $media, 'campania' => 'X', 'installs' => $installs, 'nc' => $nc, 'recompras' => $recompras,
            'ordenes' => $nc + $recompras, 'fuente_final' => $fuente, 'canal' => $media,
        ]);
    }

    private function manual(int $anio, string $tipo, int $periodo, string $canal, array $v): void
    {
        DB::table('canales_historico')->insert([
            'cuenta_id' => CuentaActual::id(), 'pais_id' => Pais::where('codigo', 'EC')->value('id'), 'anio' => $anio,
            'tipo' => $tipo, 'periodo' => $periodo, 'canal' => $canal, 'installs' => $v[0], 'nc' => $v[1], 'ordenes' => $v[2],
        ]);
    }

    public function test_semanas_como_la_hoja(): void
    {
        // 2026: el 1 de enero es jueves → la semana 1 va del 1 al 4.
        $this->assertSame(1, PanelCanales::semana(CarbonImmutable::parse('2026-01-04')));
        $this->assertSame(2, PanelCanales::semana(CarbonImmutable::parse('2026-01-05')));
        $this->assertSame(['2026-01-05', '2026-01-11'], PanelCanales::periodos(2026, 'semana')[2]);
        $this->assertSame(['2026-12-28', '2026-12-31'], PanelCanales::periodos(2026, 'semana')[53]);
    }

    public function test_suma_por_media_source_y_af_data_gana_a_lo_capturado(): void
    {
        $user = $this->usuario();
        CuentaActual::fijar(Cuenta::where('slug', 'tada')->first());
        // Octubre 2025 tiene AF data completa: gana a lo capturado a mano.
        foreach (range(1, 31) as $d) {
            $this->af(sprintf('2025-10-%02d', $d), 'Facebook Ads', 'TADA PERFORMANCE', 1, 0, 0);
        }
        $this->af('2025-10-02', 'Facebook Ads', 'BI', 5, 1, 2);       // cualquier fuente cuenta
        $this->af('2025-10-02', 'jampp_int', 'TADA PERFORMANCE', 9, 9, 9); // Jampp no es canal
        $this->manual(2025, 'mes', 10, 'meta', [999, 99, 9]);
        $this->manual(2025, 'mes', 9, 'meta', [100, 10, 20]);           // sin AF data: se usa

        $this->actingAs($user)->get('/tada/pais/ecuador/channel')
            ->assertOk()
            ->assertInertia(function ($p) {
                $props = $p->toArray()['props'];
                $mes = $props['valores'][2025]['mes'];
                $this->assertSame([36, 1, 3], $mes[10]['meta']);
                $this->assertSame('af', $props['origen'][2025]['mes'][10]);
                $this->assertSame([100, 10, 20], $mes[9]['meta']);
                $this->assertSame('manual', $props['origen'][2025]['mes'][9]);
                $this->assertArrayNotHasKey('jampp', $mes[10]);
                // Por día, solo AF data: el 2 de octubre suma todas las fuentes.
                $this->assertSame([6, 1, 3], $props['valores'][2025]['dia']['10-02']['meta']);
                $this->assertArrayNotHasKey('09-30', $props['valores'][2025]['dia']);
                $this->assertSame(['meta', 'tiktok', 'asa', 'rtb'], array_column($props['canales'], 'id'));
            });
    }

    public function test_mexico_entra_y_cliente_no(): void
    {
        // México ya tiene reglas de AF data (2026-11-18).
        $this->actingAs($this->usuario('junior', 'MX', 'México'))->get('/tada/pais/mexico/channel')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Channel')->where('tieneAfData', true));
        $this->actingAs($this->usuario('cliente'))->get('/tada/pais/ecuador/channel')->assertForbidden();
    }
}
