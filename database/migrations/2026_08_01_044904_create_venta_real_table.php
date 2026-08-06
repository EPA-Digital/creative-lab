<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_real', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pais_id');
            $table->enum('plataforma', ['meta', 'tiktok']);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('mes', 7);
            $table->unsignedInteger('nc_total_real');
            $table->unsignedInteger('orders_total_real');
            $table->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('capturado_en')->useCurrent();

            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->unique(['pais_id', 'plataforma', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_real');
    }
};
