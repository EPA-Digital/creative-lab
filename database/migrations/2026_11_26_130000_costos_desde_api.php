<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costos desde la API (2026-11-26, pedido explícito): el gasto de Meta y
 * TikTok se trae solo, cada hora, por anuncio y día.
 *
 * - medios_diario.origen: 'csv' (data.medios) o 'api'. La API gana en las
 *   campañas que conoce; el CSV se queda con lo demás (ASA, Google, ...).
 * - plataforma_anuncios_diario: el detalle campaña → conjunto → anuncio para
 *   la vista En vivo, con los valores de la corrida anterior para ver cuánto
 *   se movió desde la última actualización.
 * - sincronizaciones_costos: cada corrida (última / próxima actualización).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medios_diario', function (Blueprint $table) {
            $table->string('origen', 10)->default('csv')->after('medio_original');
            $table->unsignedBigInteger('cuenta_publicitaria_id')->nullable()->after('origen');
        });

        Schema::create('plataforma_anuncios_diario', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->unsignedBigInteger('cuenta_publicitaria_id');
            $table->string('plataforma', 20);
            $table->date('fecha');
            $table->string('campania_id', 64);
            $table->string('campania', 300);
            $table->string('conjunto_id', 64)->nullable();
            $table->string('conjunto', 300)->nullable();
            $table->string('anuncio_id', 64);
            $table->string('anuncio', 400)->nullable();
            $table->string('moneda', 3)->nullable();
            $table->decimal('costo_original', 14, 2)->default(0);
            // En USD, como el resto del dashboard.
            $table->decimal('costo', 14, 2)->default(0);
            $table->unsignedBigInteger('impresiones')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            // Lo que había en la corrida anterior (null = anuncio nuevo en esta corrida).
            $table->decimal('costo_previo', 14, 2)->nullable();
            $table->unsignedBigInteger('impresiones_previo')->nullable();
            $table->unsignedBigInteger('clicks_previo')->nullable();
            $table->timestamp('actualizado_en')->nullable();

            $table->unique(['cuenta_publicitaria_id', 'fecha', 'anuncio_id'], 'pad_cuenta_fecha_anuncio');
            $table->index(['cuenta_id', 'pais_id', 'fecha'], 'pad_cuenta_pais_fecha');
        });

        Schema::create('sincronizaciones_costos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('terminado_en')->nullable();
            // ok | parcial (alguna cuenta falló) | error
            $table->string('estado', 10);
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->json('detalle')->nullable();

            $table->index(['cuenta_id', 'terminado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sincronizaciones_costos');
        Schema::dropIfExists('plataforma_anuncios_diario');
        Schema::table('medios_diario', function (Blueprint $table) {
            $table->dropColumn(['origen', 'cuenta_publicitaria_id']);
        });
    }
};
