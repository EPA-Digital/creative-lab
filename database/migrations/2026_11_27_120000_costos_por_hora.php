<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gasto por hora y campaña (2026-11-27, gráficas de En vivo): lo trae la
 * misma corrida de cada hora (hoy y ayer) del desglose por hora de Meta,
 * TikTok y Google. fecha y hora son las de la zona horaria de la cuenta en
 * la plataforma (como se ven allá).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plataforma_campanias_hora', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->unsignedInteger('cuenta_publicitaria_id');
            $table->string('plataforma', 10);
            $table->date('fecha');
            $table->unsignedTinyInteger('hora');
            $table->string('campania_id', 64);
            $table->string('campania', 300)->nullable();
            $table->string('moneda', 3)->nullable();
            $table->decimal('costo_original', 14, 2)->default(0);
            $table->decimal('costo', 14, 2)->default(0);
            $table->unsignedBigInteger('impresiones')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamp('actualizado_en')->nullable();

            $table->unique(['cuenta_publicitaria_id', 'fecha', 'hora', 'campania_id'], 'pch_unica');
            $table->index(['cuenta_id', 'pais_id', 'fecha'], 'pch_pais_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plataforma_campanias_hora');
    }
};
