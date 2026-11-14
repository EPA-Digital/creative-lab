<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura de la vista Fuentes (2026-11-13): el total real del negocio
 * (órdenes y new customers) por periodo -- la venta mensual y la semanal se
 * capturan por separado, no se derivan una de otra. (La inversión de los
 * medios sin API vive en medios_gasto_manual.) El desglose por canal se calcula:
 * AppsFlyer del canal × (real ÷ AppsFlyer) del mismo periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuentes_venta_real', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            // 'mes' (clave 2026-10) o 'semana' (clave = primer día, 2026-10-26).
            $table->string('tipo', 6);
            $table->string('clave', 10);
            $table->unsignedInteger('ordenes')->nullable();
            $table->unsignedInteger('nc')->nullable();
            $table->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actualizado_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->unique(['cuenta_id', 'pais_id', 'tipo', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuentes_venta_real');
    }
};
