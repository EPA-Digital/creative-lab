<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gasto de los medios sin API (Apple Search Ads, RTB House, Google Ads,
 * Jampp), capturado en Medios data (2026-11-13, pedido explícito): medio,
 * fecha y gasto en USD, como la pestaña data.medios de la hoja. Fuentes lo
 * suma igual que el gasto de Facebook y TikTok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medios_gasto_manual', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->string('medio', 60);
            $table->date('fecha');
            $table->string('campania', 300)->nullable();
            $table->decimal('gasto', 14, 2);
            $table->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('creado_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->index(['cuenta_id', 'pais_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medios_gasto_manual');
    }
};
