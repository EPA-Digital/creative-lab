<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Caché de evaluaciones IA (Anthropic Claude) del modal de detalle de
// creativo -- "Evaluar por métricas"/"Evaluar por arte" (2026-08-26, ver
// plan). Única por (creativo_id, mes, modo): un click repetido para el
// mismo creativo+mes+modo nunca vuelve a llamar a la API, solo lee esta
// fila.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creativo_evaluaciones', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('creativo_id');
            $table->string('mes', 7);
            $table->enum('modo', ['metricas', 'arte']);
            $table->text('resultado');
            $table->string('modelo', 60);
            $table->timestamp('creado_en')->useCurrent();

            $table->foreign('creativo_id')->references('id')->on('creativos')->cascadeOnDelete();
            $table->unique(['creativo_id', 'mes', 'modo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creativo_evaluaciones');
    }
};
