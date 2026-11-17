<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diario medio / Diario campaña (2026-11-18, pedido explícito): el costo,
 * clics e impresiones por medio, día y campaña -- la pestaña data.medios de
 * los Daily Cuentas (export de Supermetrics), subida como CSV desde Medios
 * data. Reimportar un periodo reemplaza sus días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medios_diario', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->date('fecha');
            // Canal de config/canales.php (meta, tiktok, asa...) o el medio tal cual si no está.
            $table->string('medio', 60);
            $table->string('medio_original', 60);
            $table->string('campania', 300);
            $table->string('campania_id', 64)->nullable();
            $table->decimal('costo', 14, 2)->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impresiones')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('views_completas')->default(0);
            $table->timestamp('creado_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->index(['cuenta_id', 'pais_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medios_diario');
    }
};
