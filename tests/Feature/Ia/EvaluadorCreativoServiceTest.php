<?php

use App\Models\Creativo;
use App\Models\CreativoEvaluacion;
use App\Models\Pais;
use App\Models\Resultado;
use App\Services\Ia\AnthropicApiClient;
use App\Services\Ia\EvaluadorCreativoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Cubre el contrato de caché de EvaluadorCreativoService (2026-08-26, ver
 * plan) -- clickear "Evaluar por métricas"/"Evaluar por arte" dos veces
 * para el mismo creativo+mes+modo NUNCA debe llamar a Anthropic dos veces
 * (costo real por llamada), y "Evaluar por arte" sin imagen cacheada nunca
 * debe llegar a golpear la API.
 *
 * NO usa RefreshDatabase/migrate: la migración
 * make_tipo_cuenta_nullable_in_creativos_table usa `ALTER TABLE ... MODIFY`
 * (raw MySQL, a propósito según su propio comentario, para no depender de
 * doctrine/dbal) -- eso rompe CUALQUIER test que corra el set de
 * migraciones completo sobre el sqlite:memory de phpunit.xml, no solo
 * este. En vez de tocar esa migración (fuera de alcance de esta feature),
 * este test arma a mano el subset de esquema sqlite-compatible que
 * necesita.
 */
beforeEach(function () {
    Schema::create('paises', function (Blueprint $table) {
        $table->increments('id');
        $table->string('codigo', 3)->unique();
        $table->string('nombre', 50);
    });
    Schema::create('creativos', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('pais_id');
        $table->string('ad_id', 50);
        $table->string('nombre_comun', 255)->nullable();
        $table->string('nombre_completo', 500);
        $table->string('imagen_url', 500)->nullable();
        $table->string('plataforma', 10);
        $table->string('funnel', 10)->nullable();
        $table->string('tipo_cuenta', 10)->nullable();
        $table->timestamp('creado_en')->useCurrent();
    });
    Schema::create('resultados', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('creativo_id');
        $table->date('fecha_inicio');
        $table->date('fecha_fin');
        $table->string('mes', 7);
        $table->decimal('cost', 12, 2);
        $table->unsignedBigInteger('impressions');
        $table->unsignedInteger('clicks');
        $table->unsignedInteger('installs');
        $table->decimal('cpi', 10, 2)->nullable();
        $table->unsignedInteger('nc')->nullable();
        $table->decimal('cac', 10, 2)->nullable();
        $table->unsignedInteger('orders')->nullable();
        $table->unsignedInteger('reorders');
        $table->decimal('cpo', 10, 2)->nullable();
        $table->decimal('ctr', 6, 2)->nullable();
        $table->decimal('cpm', 10, 2)->nullable();
        $table->timestamp('creado_en')->useCurrent();
    });
    Schema::create('creativo_evaluaciones', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('creativo_id');
        $table->string('mes', 7);
        $table->string('modo', 10);
        $table->unsignedTinyInteger('score')->nullable();
        $table->json('desglose')->nullable();
        $table->text('resultado');
        $table->string('modelo', 60);
        $table->timestamp('creado_en')->useCurrent();
        $table->unique(['creativo_id', 'mes', 'modo']);
    });
});

afterEach(function () {
    Schema::dropIfExists('creativo_evaluaciones');
    Schema::dropIfExists('resultados');
    Schema::dropIfExists('creativos');
    Schema::dropIfExists('paises');
});

function creativoDePrueba(array $overrides = []): Creativo
{
    $pais = Pais::create(['codigo' => 'EC', 'nombre' => 'Ecuador']);

    return Creativo::create(array_merge([
        'pais_id' => $pais->id,
        'ad_id' => '123456',
        'nombre_comun' => 'Creativo de prueba',
        'nombre_completo' => 'Creativo de prueba (nombre completo)',
        'plataforma' => 'meta',
        'funnel' => 'CNV',
        'tipo_cuenta' => 'DTC',
    ], $overrides));
}

function resultadoDePrueba(Creativo $creativo, string $mes = '2026-08'): Resultado
{
    return Resultado::create([
        'creativo_id' => $creativo->id,
        'fecha_inicio' => '2026-08-01',
        'fecha_fin' => '2026-08-31',
        'mes' => $mes,
        'cost' => 100,
        'impressions' => 10000,
        'clicks' => 200,
        'installs' => 50,
        'nc' => 10,
        'cac' => 10,
        'orders' => 12,
        'reorders' => 2,
        'cpo' => 8.33,
        'ctr' => 2,
        'cpm' => 10,
    ]);
}

it('cachea evaluarPorMetricas -- un segundo click no vuelve a llamar a Anthropic', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Rinde bien en CAC.']],
        ], 200),
    ]);

    $creativo = creativoDePrueba();
    resultadoDePrueba($creativo);
    $servicio = new EvaluadorCreativoService(new AnthropicApiClient('key-de-prueba', 'claude-sonnet-5'));

    $primera = $servicio->evaluarPorMetricas($creativo, '2026-08');
    $segunda = $servicio->evaluarPorMetricas($creativo, '2026-08');

    expect($primera->resultado)->toBe('Rinde bien en CAC.');
    expect($segunda->id)->toBe($primera->id);
    expect(CreativoEvaluacion::count())->toBe(1);
    Http::assertSentCount(1);
});

it('evaluarPorArte sin imagen no llama a Anthropic y devuelve 422', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([], 200)]);

    $creativo = creativoDePrueba(['ad_id' => '999999', 'imagen_url' => null]);
    resultadoDePrueba($creativo);
    $servicio = new EvaluadorCreativoService(new AnthropicApiClient('key-de-prueba', 'claude-sonnet-5'));

    expect(fn () => $servicio->evaluarPorArte($creativo, '2026-08'))
        ->toThrow(HttpException::class);

    Http::assertNothingSent();
});

it('evaluarPorArte parsea el JSON de Claude y guarda score+desglose', function () {
    $ruta = public_path('creative-images/test-arte.jpg');
    @mkdir(dirname($ruta), recursive: true);
    file_put_contents($ruta, 'contenido-de-imagen-de-prueba');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'categorias' => ['color' => 20, 'composicion' => 18, 'texto' => 15, 'gancho' => 22],
                'resultado' => 'Paleta cálida y buen contraste.',
            ])]],
        ], 200),
    ]);

    $creativo = creativoDePrueba(['ad_id' => '777777', 'imagen_url' => '/creative-images/test-arte.jpg']);
    resultadoDePrueba($creativo);
    $servicio = new EvaluadorCreativoService(new AnthropicApiClient('key-de-prueba', 'claude-sonnet-5'));

    $evaluacion = $servicio->evaluarPorArte($creativo, '2026-08');

    expect($evaluacion->score)->toBe(75);
    expect($evaluacion->desglose)->toBe(['color' => 20, 'composicion' => 18, 'texto' => 15, 'gancho' => 22]);
    expect($evaluacion->resultado)->toBe('Paleta cálida y buen contraste.');

    @unlink($ruta);
});

it('evaluarPorArte degrada sin error si Claude no devuelve JSON válido', function () {
    $ruta = public_path('creative-images/test-arte-malformado.jpg');
    @mkdir(dirname($ruta), recursive: true);
    file_put_contents($ruta, 'contenido-de-imagen-de-prueba');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Se ve bien, colores vibrantes.']],
        ], 200),
    ]);

    $creativo = creativoDePrueba(['ad_id' => '888888', 'imagen_url' => '/creative-images/test-arte-malformado.jpg']);
    resultadoDePrueba($creativo);
    $servicio = new EvaluadorCreativoService(new AnthropicApiClient('key-de-prueba', 'claude-sonnet-5'));

    $evaluacion = $servicio->evaluarPorArte($creativo, '2026-08');

    expect($evaluacion->score)->toBeNull();
    expect($evaluacion->desglose)->toBeNull();
    expect($evaluacion->resultado)->toBe('Se ve bien, colores vibrantes.');

    @unlink($ruta);
});
