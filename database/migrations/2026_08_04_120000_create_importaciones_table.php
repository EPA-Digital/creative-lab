<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Registro de cada corrida de importación (CSV + rango + totales tecleados
// a mano) -- antes esos valores solo vivían en la memoria de quien corría
// `importar:csv` por consola; ahora quedan en la BD para poder re-importar
// sin adivinar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pais_id');
            $table->string('nombre_archivo', 255);
            $table->date('desde');
            $table->date('hasta');
            // Meta y TikTok son negocios/cuentas distintas -- cada uno
            // reparte SU PROPIO total tecleado, nunca un total compartido.
            $table->decimal('nc_total_real_meta', 12, 2)->nullable();
            $table->decimal('orders_total_real_meta', 12, 2)->nullable();
            $table->decimal('nc_total_real_tiktok', 12, 2)->nullable();
            $table->decimal('orders_total_real_tiktok', 12, 2)->nullable();
            $table->boolean('es_rango_parcial')->default(false);
            $table->unsignedInteger('creativos_tocados')->default(0);
            $table->unsignedInteger('resultados_tocados')->default(0);
            $table->unsignedInteger('tiene_meta_true')->default(0);
            $table->unsignedInteger('problemas')->default(0);
            $table->timestamp('creado_en')->useCurrent();

            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones');
    }
};
