<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de gasto (2026-11-18, pedido explícito): alertas marcadas como
 * revisadas por día (las ve todo el equipo) y el responsable de cada
 * campaña en el Daily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_revisiones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->date('fecha');
            // Campaña (nombre en minúsculas) o "medio:<id>".
            $table->string('clave', 320);
            $table->unsignedInteger('usuario_id')->nullable();
            $table->timestamp('creado_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->index(['cuenta_id', 'pais_id', 'fecha']);
        });

        Schema::table('daily_campanias', function (Blueprint $table) {
            $table->string('responsable', 80)->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('daily_campanias', function (Blueprint $table) {
            $table->dropColumn('responsable');
        });
        Schema::dropIfExists('control_revisiones');
    }
};
