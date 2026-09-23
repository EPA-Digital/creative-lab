<?php

use App\Models\Creativo;
use App\Models\Pais;
use App\Services\Ingesta\ImportadorDatosDiario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Cubre el pedido explícito del usuario (2026-08-28): una imagen curada a
 * mano contra el Drive real de creativos (ver scratchpad/panama-images,
 * guardada como /creative-images/drive-{hash}.png) NUNCA debe perderse si
 * ImportadorDatosDiario::importar() vuelve a correr para el mismo ad_id --
 * antes de este fix, cada corrida pisaba imagen_url incondicionalmente con
 * el thumbnail borroso que trae esa corrida (ver
 * ImagenCacheService::esImagenCurada).
 *
 * NO usa RefreshDatabase/migrate -- mismo motivo que
 * EvaluadorCreativoServiceTest (la migración make_tipo_cuenta_nullable usa
 * `ALTER TABLE ... MODIFY`, raw MySQL, incompatible con sqlite:memory):
 * arma a mano el subset de esquema sqlite-compatible que necesita.
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
        $table->string('nombre_campania', 255)->nullable();
        $table->string('imagen_url', 500)->nullable();
        $table->string('plataforma', 10);
        $table->string('formato', 20)->nullable();
        $table->string('funnel', 10)->nullable();
        $table->string('tipo_cuenta', 10)->nullable();
        $table->timestamp('creado_en')->useCurrent();
    });
    Schema::create('resultados_diarios', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('creativo_id');
        $table->date('fecha');
        $table->decimal('cost', 12, 2)->default(0);
        $table->unsignedBigInteger('impressions')->default(0);
        $table->unsignedInteger('clicks')->default(0);
        $table->unsignedInteger('installs')->default(0);
        $table->unsignedInteger('nc')->default(0);
        $table->unsignedInteger('repurchases')->default(0);
        $table->unsignedInteger('orders')->default(0);
        $table->decimal('nc_real', 10, 2)->nullable();
        $table->decimal('orders_real', 10, 2)->nullable();
        $table->timestamp('creado_en')->useCurrent();
        // Sin unique(creativo_id, fecha) a propósito: el cast 'date' de
        // ResultadoDiario serializa con hora (Carbon "Y-m-d H:i:s") al
        // guardar, pero el where() de updateOrCreate usa el string crudo
        // sin hora -- en MySQL la columna DATE real normaliza ambos lados
        // igual (funciona en producción, confirmado), pero sqlite:memory
        // no tiene tipado real y los ve como texto distinto, rompiendo el
        // upsert. No es lo que este test cubre (ver arte del test: imagen
        // curada), así que se omite acá en vez de enmascarar con un
        // cast/columna que no reproduciría el comportamiento real de MySQL.
    });
    Schema::create('resultados', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('creativo_id');
        $table->date('fecha_inicio')->nullable();
        $table->date('fecha_fin')->nullable();
        $table->string('mes', 7);
        $table->decimal('cost', 12, 2)->default(0);
        $table->unsignedBigInteger('impressions')->default(0);
        $table->unsignedInteger('clicks')->default(0);
        $table->unsignedInteger('installs')->default(0);
        $table->decimal('cpi', 10, 2)->nullable();
        $table->unsignedInteger('nc')->nullable();
        $table->decimal('cac', 10, 2)->nullable();
        $table->unsignedInteger('orders')->nullable();
        $table->unsignedInteger('reorders')->default(0);
        $table->decimal('cpo', 10, 2)->nullable();
        $table->boolean('tiene_venta_real')->default(false);
        $table->boolean('tiene_meta')->default(false);
        $table->decimal('frequency', 6, 2)->nullable();
        $table->decimal('ctr', 6, 2)->nullable();
        $table->decimal('cpm', 10, 2)->nullable();
        $table->timestamp('creado_en')->useCurrent();
        $table->unique(['creativo_id', 'mes']);
    });

    Pais::create(['codigo' => 'PA', 'nombre' => 'Panamá']);
});

afterEach(function () {
    Schema::dropIfExists('resultados');
    Schema::dropIfExists('resultados_diarios');
    Schema::dropIfExists('creativos');
    Schema::dropIfExists('paises');
});

function csvSnowflakeParaAd(string $adId, string $fecha, string $thumbUrl): string
{
    $header = 'Date,Campaign name,Campaign ID,Ad name,Ad ID,Impressions,Link clicks,Cost,INSTALLS,NEW CUSTOMERS,REPURCHASES,ORDERS,NC REALES PAID,ORDENES REALES PAID,creative thumbnail';
    $fila = "{$fecha},PAN_DTC_TAD_A_SOC_FBK_FB-CONVERSION-MP-ARTE-PRUEBA,120227919262,CARO_MUL_STA_,{$adId},1000,50,\"\$5.00\",2,1,0,1,1,1,{$thumbUrl}";

    return $header."\n".$fila;
}

it('NO pisa una imagen curada a mano (drive-*) al reimportar el mismo ad_id', function () {
    Storage::fake('gcs');
    Http::fake([
        'cdn.ejemplo.com/*' => Http::response('contenido-de-imagen', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $archivo = tempnam(sys_get_temp_dir(), 'snowflake-test-').'.csv';
    file_put_contents($archivo, csvSnowflakeParaAd('999888777', '2026-06-01', 'https://cdn.ejemplo.com/thumb.jpg'));

    // 1ra corrida: no existe el creativo todavía -> se cachea el thumbnail
    // borroso de rutina.
    ImportadorDatosDiario::importar($archivo, 'panama');
    $creativo = Creativo::where('ad_id', '999888777')->firstOrFail();
    expect($creativo->imagen_url)->toBe('https://storage.googleapis.com/test-bucket/creative-images/snowflake-999888777.jpg');

    // Simula el match manual contra el Drive real (mismo efecto que
    // update_imagenes.php): reemplaza la imagen borrosa por la curada, EN
    // EL MISMO registro. Formato viejo (ruta local) a propósito -- confirma
    // que esImagenCurada() detecta la marca "/creative-images/drive-" sin
    // importar si lo que la rodea es una ruta local o una URL de bucket.
    $creativo->update(['imagen_url' => '/creative-images/drive-abc123def456.png']);
    $idOriginal = $creativo->id;

    // 2da corrida: mismo ad_id, mismo archivo (simula un reimport de rutina
    // del mismo mes/CSV). La imagen curada debe sobrevivir intacta.
    ImportadorDatosDiario::importar($archivo, 'panama');

    @unlink($archivo);

    expect(Creativo::count())->toBe(1); // nunca se crea un registro nuevo
    $creativo->refresh();
    expect($creativo->id)->toBe($idOriginal); // mismo registro, no uno nuevo
    expect($creativo->imagen_url)->toBe('/creative-images/drive-abc123def456.png');
});

it('SÍ cachea el thumbnail de rutina cuando todavía no hay ninguna imagen curada', function () {
    Storage::fake('gcs');
    Http::fake([
        'cdn.ejemplo.com/*' => Http::response('contenido-de-imagen', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $archivo = tempnam(sys_get_temp_dir(), 'snowflake-test-').'.csv';
    file_put_contents($archivo, csvSnowflakeParaAd('111222333', '2026-06-01', 'https://cdn.ejemplo.com/thumb.jpg'));

    ImportadorDatosDiario::importar($archivo, 'panama');
    ImportadorDatosDiario::importar($archivo, 'panama'); // reimport sin curación de por medio

    @unlink($archivo);

    $creativo = Creativo::where('ad_id', '111222333')->firstOrFail();
    expect($creativo->imagen_url)->toBe('https://storage.googleapis.com/test-bucket/creative-images/snowflake-111222333.jpg');
});
