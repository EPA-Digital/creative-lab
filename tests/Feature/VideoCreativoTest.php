<?php

namespace Tests\Feature;

use App\Jobs\GuardarVideoCreativo;
use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Models\User;
use App\Services\ImagenFirmadaService;
use App\Services\Ingesta\ImportadorDatos;
use App\Services\VideoCreativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Videos de los creativos en el modal (pedido explícito 2026-10-06): TikTok
 * se reproduce (guardado en el bucket o directo de TikTok mientras se
 * guarda), Meta da el enlace a Facebook.
 */
class VideoCreativoTest extends TestCase
{
    use RefreshDatabase;

    private Pais $ec;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.access_token' => 't', 'services.tiktok.access_token' => 't']);
        $this->ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        CuentaPublicitaria::create(['pais_id' => $this->ec->id, 'plataforma' => 'tiktok', 'cuenta_id' => '733', 'tipo' => 'tada']);
        $this->user = User::factory()->create(['rol' => 'cliente']);
        $this->user->paises()->attach($this->ec->id);
    }

    private function creativo(array $atributos): Creativo
    {
        return Creativo::create(['pais_id' => $this->ec->id, 'nombre_completo' => 'X', 'tipo_cuenta' => 'DTC', 'formato' => 'VIDEO', ...$atributos]);
    }

    public function test_un_video_ya_guardado_se_devuelve_firmado(): void
    {
        $firmas = $this->createMock(ImagenFirmadaService::class);
        $firmas->method('firmar')->willReturn('https://storage.googleapis.com/test-bucket/creative-videos/x.mp4?firma');
        $this->app->instance(ImagenFirmadaService::class, $firmas);
        $c = $this->creativo(['ad_id' => '1', 'plataforma' => 'tiktok', 'video_url' => 'https://storage.googleapis.com/test-bucket/creative-videos/x.mp4']);

        $this->actingAs($this->user)->getJson("/pais/ecuador/creativos/{$c->id}/video")
            ->assertOk()
            ->assertJson(['tipo' => 'archivo', 'url' => 'https://storage.googleapis.com/test-bucket/creative-videos/x.mp4?firma']);
    }

    public function test_tiktok_sin_guardar_se_reproduce_directo_y_se_encola_guardarlo_una_vez(): void
    {
        Queue::fake();
        Http::fake(['business-api.tiktok.com/*/file/video/ad/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['video_id' => 'v1', 'preview_url' => 'https://v16.tiktokcdn.com/v1.mp4']]]])]);
        $c = $this->creativo(['ad_id' => '2', 'plataforma' => 'tiktok', 'video_id' => 'v1']);

        $this->actingAs($this->user)->getJson("/pais/ecuador/creativos/{$c->id}/video")
            ->assertOk()->assertJson(['tipo' => 'directo', 'url' => 'https://v16.tiktokcdn.com/v1.mp4']);
        $this->getJson("/pais/ecuador/creativos/{$c->id}/video")->assertOk();

        Queue::assertPushed(GuardarVideoCreativo::class, 1);
    }

    public function test_tiktok_sin_video_id_lo_busca_por_ad_id_y_lo_guarda(): void
    {
        Queue::fake();
        Http::fake(function (Request $r) {
            return str_contains($r->url(), '/ad/get/')
                ? Http::response(['code' => 0, 'data' => ['list' => [['ad_id' => '3', 'video_id' => 'v3']]]])
                : Http::response(['code' => 0, 'data' => ['list' => [['video_id' => 'v3', 'preview_url' => 'https://v16.tiktokcdn.com/v3.mp4']]]]);
        });
        $c = $this->creativo(['ad_id' => '3', 'plataforma' => 'tiktok']);

        $this->actingAs($this->user)->getJson("/pais/ecuador/creativos/{$c->id}/video")->assertJsonPath('tipo', 'directo');
        $this->assertSame('v3', $c->fresh()->video_id);
    }

    public function test_meta_devuelve_el_enlace_a_facebook_y_lo_recuerda(): void
    {
        Http::fake(function (Request $r) {
            return str_contains($r->url(), '/444')
                ? Http::response(['creative' => ['video_id' => '999']])
                : Http::response(['permalink_url' => '/reel/999/']);
        });
        $c = $this->creativo(['ad_id' => '444', 'plataforma' => 'meta']);

        $this->actingAs($this->user)->getJson("/pais/ecuador/creativos/{$c->id}/video")
            ->assertOk()->assertJson(['tipo' => 'enlace', 'url' => 'https://www.facebook.com/reel/999/']);
        $this->assertSame('https://www.facebook.com/reel/999/', $c->fresh()->video_permalink);
    }

    public function test_si_la_plataforma_ya_no_tiene_el_video_responde_404_con_mensaje(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['list' => []]])]);
        $c = $this->creativo(['ad_id' => '5', 'plataforma' => 'tiktok']);

        $this->actingAs($this->user)->getJson("/pais/ecuador/creativos/{$c->id}/video")
            ->assertNotFound()->assertJsonPath('message', 'Este video ya no está disponible en la plataforma.');
    }

    public function test_no_se_puede_pedir_el_video_de_un_creativo_de_otro_pais(): void
    {
        $mx = Pais::create(['codigo' => 'MX', 'nombre' => 'México']);
        $ajeno = Creativo::create(['pais_id' => $mx->id, 'ad_id' => '6', 'nombre_completo' => 'X', 'plataforma' => 'tiktok', 'tipo_cuenta' => 'DTC']);

        $this->actingAs($this->user)->getJson("/pais/ecuador/creativos/{$ajeno->id}/video")->assertNotFound();
    }

    public function test_sin_ffmpeg_no_intenta_guardar(): void
    {
        Process::fake(['ffmpeg -version' => Process::result(exitCode: 127)]);
        Http::fake();
        $c = $this->creativo(['ad_id' => '7', 'plataforma' => 'tiktok', 'video_id' => 'v7']);

        $this->assertFalse(app(VideoCreativoService::class)->guardar($c));
        Http::assertNothingSent();
    }

    public function test_la_conversion_deja_el_lado_corto_en_480_con_audio_y_faststart(): void
    {
        $comando = implode(' ', VideoCreativoService::comandoConversion('in', 'out.mp4'));

        $this->assertStringContainsString("scale='if(gt(iw,ih),-2,480)':'if(gt(iw,ih),480,-2)'", $comando);
        $this->assertStringContainsString('-c:a aac', $comando);
        $this->assertStringContainsString('+faststart', $comando);
    }

    public function test_despues_de_importar_se_encolan_los_videos_de_mayor_gasto_sin_guardar(): void
    {
        Queue::fake();
        config(['videos.top_por_mes' => 2]);
        foreach ([['a', 50, null], ['b', 300, null], ['c', 200, 'https://storage.googleapis.com/test-bucket/creative-videos/c.mp4'], ['d', 100, null]] as [$adId, $costo, $url]) {
            $c = $this->creativo(['ad_id' => $adId, 'plataforma' => 'tiktok', 'video_id' => "v-{$adId}", 'video_url' => $url]);
            $c->resultados()->create(['mes' => '2026-09', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30', 'cost' => $costo, 'impressions' => 0, 'clicks' => 0, 'installs' => 0, 'reorders' => 0]);
        }

        (new ReflectionMethod(ImportadorDatos::class, 'encolarVideosConMasGasto'))->invoke(null, $this->ec, '2026-09');

        $encolados = Queue::pushed(GuardarVideoCreativo::class)->map(fn ($job) => Creativo::find($job->creativoId)->ad_id)->all();
        $this->assertSame(['b', 'd'], $encolados, 'los 2 de mayor gasto que todavía no están guardados');
    }
}
