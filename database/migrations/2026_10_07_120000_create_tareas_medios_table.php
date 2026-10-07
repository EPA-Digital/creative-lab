<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Seguimiento de imágenes/videos que se guardan en background (pedido
// explícito 2026-10-07): después de una importación (o de "Recuperar
// imágenes") el panel avisa cuando están listos -- o muestra el error real
// si algo falla, en vez de que simplemente no aparezcan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tareas_medios', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pais_id');
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->string('mes', 7);
            $table->string('origen', 20); // importacion | recuperacion
            $table->unsignedBigInteger('importacion_id')->nullable()->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('listos')->default(0);
            $table->unsignedInteger('fallidos')->default(0);
            $table->string('estado', 20)->default('pendiente'); // pendiente | procesando | listo | error
            $table->text('error_mensaje')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tareas_medios');
    }
};
