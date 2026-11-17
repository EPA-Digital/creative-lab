<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de gasto (2026-11-17, pedido explícito): campañas que el equipo
 * quita porque no son suyas. Cada fila es un movimiento del historial
 * (quién la quitó, cuándo y por qué; y si alguien la restauró). Una campaña
 * está quitada mientras tenga una fila sin restaurar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_excluidas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->string('clave', 320);
            $table->string('campania', 300);
            $table->string('medio', 60)->nullable();
            $table->string('motivo', 300)->nullable();
            $table->unsignedInteger('excluida_por')->nullable();
            $table->timestamp('excluida_en')->nullable();
            $table->unsignedInteger('restaurada_por')->nullable();
            $table->timestamp('restaurada_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->index(['cuenta_id', 'pais_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_excluidas');
    }
};
