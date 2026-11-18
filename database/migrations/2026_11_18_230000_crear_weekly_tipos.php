<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly (2026-11-18): el "tipo de campaña" de weekly.fer es el nombre corto
 * que el equipo le pone a cada campaña (ej. PAN_..._FB-AWARENESS-BROAD →
 * "Meta - Awareness Broad"). Aquí se guarda el que se edita a mano (sin
 * fila = nombre automático) y si la campaña se oculta de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_tipos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cuenta_id');
            $table->unsignedBigInteger('pais_id');
            $table->string('campania', 300);
            $table->string('tipo', 120)->nullable();
            $table->boolean('oculta')->default(false);
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamp('actualizado_en')->nullable();

            $table->unique(['cuenta_id', 'pais_id', 'campania']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_tipos');
    }
};
