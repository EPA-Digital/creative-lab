<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odt', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('creativo_id');
            $table->text('texto')->nullable();
            $table->string('titulo', 255)->nullable();
            $table->string('descripcion', 500)->nullable();
            $table->string('cta', 50)->nullable();
            $table->string('deeplink', 500)->nullable();
            $table->string('imagen_url', 500)->nullable();
            $table->string('campana_destino', 255)->nullable();
            $table->enum('estado', ['pendiente', 'cargado', 'prendido', 'apagado']);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('creativo_id')->references('id')->on('creativos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odt');
    }
};
