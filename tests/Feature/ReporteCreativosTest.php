<?php

namespace Tests\Feature;

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\User;
use App\Services\ImagenFirmadaService;
use App\Services\Reportes\ReporteCreativosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Reporte de creativos del mes en Excel/PDF con imágenes (pedido explícito
 * 2026-10-07).
 */
class ReporteCreativosTest extends TestCase
{
    use RefreshDatabase;

    private Pais $ec;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ec = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);
        $this->user = User::factory()->create(['rol' => 'gerente']);
        $this->user->paises()->attach($this->ec->id);

        // Imágenes "del bucket": se firman y se descargan (simulado).
        $firmas = $this->createMock(ImagenFirmadaService::class);
        $firmas->method('firmar')->willReturnCallback(fn ($url) => "{$url}?firma");
        $this->app->instance(ImagenFirmadaService::class, $firmas);
        Http::fake(['storage.googleapis.com/*' => Http::response(self::png(), 200, ['Content-Type' => 'image/png'])]);
    }

    private static function png(): string
    {
        $img = imagecreatetruecolor(400, 700);
        imagefill($img, 0, 0, imagecolorallocate($img, 145, 132, 217));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    private function anuncio(string $adId, string $arte, string $plataforma, float $costo, array $extra = []): void
    {
        $c = Creativo::create([
            'pais_id' => $this->ec->id, 'ad_id' => $adId, 'nombre_comun' => $arte, 'nombre_completo' => $arte,
            'plataforma' => $plataforma, 'funnel' => 'CNV', 'tipo_cuenta' => 'DTC', 'formato' => 'VIDEO',
            'imagen_url' => "https://storage.googleapis.com/test-bucket/creative-images/{$plataforma}-costo-{$adId}.jpg",
            ...$extra,
        ]);
        $c->resultados()->create(['mes' => '2026-09', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30', 'cost' => $costo, 'impressions' => 1000, 'clicks' => 10, 'installs' => 20, 'reorders' => 0, 'nc' => 5, 'orders' => 40]);
    }

    private function sembrar(): void
    {
        $this->anuncio('1', 'VID-SYMPHONY2', 'tiktok', 1224.79, ['nombre_campania' => 'TKT-CONVERSION-AND-SMART', 'copy' => ['titulo' => null, 'texto' => 'Copy del de mayor gasto']]);
        $this->anuncio('2', 'VID-SYMPHONY2', 'tiktok', 598.43, ['nombre_campania' => 'TKT-SHOPPING', 'copy' => ['titulo' => null, 'texto' => 'Otro copy']]);
        $this->anuncio('3', 'SP-PROMO', 'meta', 100, ['nombre_campania' => 'FB-CONVERSION']);
    }

    public function test_por_arte_suma_anuncios_y_toma_imagen_y_copy_del_de_mayor_gasto(): void
    {
        $this->sembrar();

        $filas = app(ReporteCreativosService::class)->filas($this->ec->id, '2026-09', 'arte');

        $this->assertCount(2, $filas);
        $this->assertSame('VID-SYMPHONY2', $filas[0]['nombre']);
        $this->assertSame(2, $filas[0]['anuncios']);
        $this->assertEqualsWithDelta(1823.22, $filas[0]['cost'], 0.001);
        $this->assertSame(['TKT-CONVERSION-AND-SMART', 'TKT-SHOPPING'], $filas[0]['campanias']);
        $this->assertSame('Copy del de mayor gasto', $filas[0]['copyTexto']);
        $this->assertSame(['1', '2'], $filas[0]['adIds']);
        $this->assertEqualsWithDelta(1823.22 / 10, $filas[0]['cac'], 0.001);
    }

    public function test_respeta_los_filtros_de_la_tabla(): void
    {
        $this->sembrar();
        $servicio = app(ReporteCreativosService::class);

        $this->assertCount(1, $servicio->filas($this->ec->id, '2026-09', 'arte', ['plataforma' => 'meta']));
        $this->assertCount(2, $servicio->filas($this->ec->id, '2026-09', 'anuncio', ['q' => 'symphony']));
    }

    public function test_la_miniatura_queda_chica_y_en_jpeg(): void
    {
        $mini = ReporteCreativosService::miniatura(self::png());
        [$ancho, $alto, $tipo] = getimagesizefromstring($mini);

        $this->assertSame(IMAGETYPE_JPEG, $tipo);
        $this->assertLessThanOrEqual(160, $ancho);
        $this->assertLessThanOrEqual(200, $alto);
        $this->assertNull(ReporteCreativosService::miniatura('no es una imagen'));
    }

    public function test_descarga_el_excel_con_las_imagenes_incrustadas(): void
    {
        $this->sembrar();

        $respuesta = $this->actingAs($this->user)->get('/pais/ecuador/importar/mensual/2026-09/exportar?formato=xlsx&agrupar=arte');

        $respuesta->assertOk()->assertDownload('reporte-creativos-ecuador-2026-09.xlsx');
        $hoja = IOFactory::load($respuesta->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertCount(2, $hoja->getDrawingCollection());
        $this->assertSame('VID-SYMPHONY2', $hoja->getCell('B5')->getValue());
        $this->assertSame('Total', $hoja->getCell('B7')->getValue());
    }

    public function test_descarga_el_pdf(): void
    {
        $this->sembrar();

        $respuesta = $this->actingAs($this->user)->get('/pais/ecuador/importar/mensual/2026-09/exportar?formato=pdf');

        $respuesta->assertOk()->assertDownload('reporte-creativos-ecuador-2026-09.pdf');
        $this->assertStringStartsWith('%PDF', file_get_contents($respuesta->baseResponse->getFile()->getPathname()));
    }

    public function test_un_cliente_no_puede_descargar_el_reporte(): void
    {
        $cliente = User::factory()->create(['rol' => 'cliente']);
        $cliente->paises()->attach($this->ec->id);

        $this->actingAs($cliente)->get('/pais/ecuador/importar/mensual/2026-09/exportar?formato=xlsx')->assertForbidden();
    }
}
