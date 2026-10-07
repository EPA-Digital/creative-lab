<?php

namespace Tests\Feature;

use App\Jobs\CachearImagenesCreativos;
use App\Jobs\RecuperarImagenesMes;
use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Models\Importacion;
use App\Models\Pais;
use App\Models\TareaMedios;
use App\Models\User;
use App\Services\Ingesta\ImagenCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Seguimiento de imágenes/videos en background (pedido explícito
 * 2026-10-07): el panel avisa cuando están listos o muestra el error real,
 * y "Recuperar imágenes" vuelve a pedirlas para un mes.
 */
class TareaMediosTest extends TestCase
{
    use RefreshDatabase;

    private Pais $ec;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.access_token' => 't']);
        $this->ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->user = User::factory()->create(['rol' => 'gerente']);
        $this->user->paises()->attach($this->ec->id);
    }

    private function creativoDelMes(string $adId, ?string $imagen = null): Creativo
    {
        $c = Creativo::create(['pais_id' => $this->ec->id, 'ad_id' => $adId, 'nombre_completo' => 'X', 'plataforma' => 'meta', 'tipo_cuenta' => 'DTC', 'imagen_url' => $imagen]);
        $c->resultados()->create(['mes' => '2026-09', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30', 'cost' => 1, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'reorders' => 0]);

        return $c;
    }

    private function tarea(int $total): TareaMedios
    {
        return TareaMedios::create(['pais_id' => $this->ec->id, 'mes' => '2026-09', 'origen' => 'importacion', 'total' => $total]);
    }

    public function test_la_tarea_queda_lista_cuando_se_proceso_todo_y_un_error_no_se_pisa(): void
    {
        $t = $this->tarea(3);
        TareaMedios::avanzar($t->id, 2);
        $this->assertSame('procesando', $t->fresh()->estado);
        TareaMedios::avanzar($t->id, 0, 1);
        $this->assertSame(['listo', 2, 1], [$t->fresh()->estado, $t->fresh()->listos, $t->fresh()->fallidos]);

        $otra = $this->tarea(5);
        TareaMedios::fallar($otra->id, 'sin permisos');
        TareaMedios::avanzar($otra->id, 5);
        $this->assertSame('error', $otra->fresh()->estado);
    }

    public function test_el_job_de_imagenes_reporta_su_avance(): void
    {
        Storage::fake('gcs');
        Http::fake(['cdn.meta/*' => Http::response('jpg', 200, ['Content-Type' => 'image/jpeg'])]);
        $c = $this->creativoDelMes('1');
        $t = $this->tarea(1);

        (new CachearImagenesCreativos([$c->id => ['url' => 'https://cdn.meta/1.jpg', 'nombre' => 'meta-costo-1']], $t->id))->handle(app(ImagenCacheService::class));

        $this->assertSame(['listo', 1], [$t->fresh()->estado, $t->fresh()->listos]);
    }

    public function test_el_estado_de_la_tarea_solo_se_ve_desde_su_pais(): void
    {
        $t = $this->tarea(2);
        $mx = Pais::create(['codigo' => 'MX', 'nombre' => 'México']);
        $this->user->paises()->attach($mx->id);

        $this->actingAs($this->user)->getJson("/pais/ecuador/medios/tareas/{$t->id}")
            ->assertOk()->assertJson(['estado' => 'pendiente', 'total' => 2, 'mes' => '2026-09']);
        $this->getJson("/pais/mexico/medios/tareas/{$t->id}")->assertNotFound();
    }

    public function test_la_importacion_terminada_devuelve_su_tarea_de_medios(): void
    {
        $imp = Importacion::create(['pais_id' => $this->ec->id, 'origen' => 'csv', 'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'estado' => 'completado']);
        $t = TareaMedios::create(['pais_id' => $this->ec->id, 'mes' => '2026-09', 'origen' => 'importacion', 'importacion_id' => $imp->id, 'total' => 4]);

        $this->actingAs($this->user)->getJson("/pais/ecuador/importar/estado/{$imp->id}")
            ->assertOk()->assertJsonPath('tareaMediosId', $t->id);
    }

    public function test_recuperar_imagenes_crea_la_tarea_y_encola_el_job(): void
    {
        Queue::fake();
        $this->creativoDelMes('1');
        $this->creativoDelMes('2', 'https://storage.googleapis.com/test-bucket/creative-images/meta-costo-2.jpg');

        $r = $this->actingAs($this->user)->postJson('/pais/ecuador/importar/mensual/2026-09/recuperar-imagenes')
            ->assertStatus(202)->assertJsonPath('total', 1);

        $this->assertSame(1, TareaMedios::find($r->json('tareaMediosId'))->total);
        Queue::assertPushed(RecuperarImagenesMes::class);
        $this->getJson('/pais/ecuador/importar/mensual?mes=2026-09')->assertJsonPath('sinImagen', 1);
    }

    public function test_si_no_falta_ninguna_imagen_lo_dice(): void
    {
        $this->creativoDelMes('2', 'https://storage.googleapis.com/test-bucket/creative-images/meta-costo-2.jpg');

        $this->actingAs($this->user)->postJson('/pais/ecuador/importar/mensual/2026-09/recuperar-imagenes')
            ->assertStatus(422)->assertJsonPath('message', 'Todos los creativos de ese mes ya tienen imagen.');
    }

    public function test_recuperar_imagenes_las_guarda_y_cuenta_las_que_no_aparecen(): void
    {
        Storage::fake('gcs');
        CuentaPublicitaria::create(['pais_id' => $this->ec->id, 'plataforma' => 'meta', 'cuenta_id' => '913', 'tipo' => 'tada']);
        $conImagen = $this->creativoDelMes('10');
        $this->creativoDelMes('11'); // Meta no lo devuelve (borrado)
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'cdn.meta')) {
                return Http::response('jpg', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(['data' => [['id' => '10', 'effective_status' => 'ARCHIVED', 'creative' => ['image_url' => 'https://cdn.meta/10.jpg']]]]);
        });
        $t = TareaMedios::create(['pais_id' => $this->ec->id, 'mes' => '2026-09', 'origen' => 'recuperacion', 'total' => 2]);

        (new RecuperarImagenesMes($t->id))->handle(app(ImagenCacheService::class));

        $this->assertSame('https://storage.googleapis.com/test-bucket/creative-images/meta-costo-10.jpg', $conImagen->fresh()->imagen_url);
        $this->assertSame(['listo', 1, 1], [$t->fresh()->estado, $t->fresh()->listos, $t->fresh()->fallidos]);
    }
}
