<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Granularidad diaria (2026-08-27, ver plan del selector de fecha) --
 * fuente de verdad fina alimentada por el export de Snowflake (costo/
 * impresiones/clicks de Meta ya cruzado con AppsFlyer, por día y por
 * anuncio). `resultados` (mensual) NO se toca -- Ecuador/México siguen con
 * el pipeline viejo sin cambios; ImportadorDatosDiario recalcula el
 * `Resultado` mensual correspondiente sumando estas filas después de cada
 * corrida, así que Creativos/Inteligencia siguen leyendo `resultados` sin
 * saber que esta tabla existe.
 *
 * nc_real/orders_real llegan YA prorrateados por fila desde el CSV (el
 * propio sheet del usuario hace ese cálculo) -- nunca se reprorratea en
 * PHP, por eso son decimal (pueden traer fracción) y no comparten el
 * mismo entero que nc/orders crudos de AppsFlyer.
 */
return new class extends Migration
{
    public function up(): void
    {
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

            $table->foreign('creativo_id')->references('id')->on('creativos')->cascadeOnDelete();
            $table->unique(['creativo_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_diarios');
    }
};
