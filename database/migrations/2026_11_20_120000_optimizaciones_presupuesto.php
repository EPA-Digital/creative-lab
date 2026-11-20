<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambios de presupuesto del sync (2026-11-20, alertas de Control de gasto):
 * a qué campaña pertenecen (un ad set trae su propio nombre), si el
 * presupuesto es diario y el daily total de la campaña después del cambio,
 * para compararlo contra lo planeado en el Daily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizaciones', function (Blueprint $table) {
            $table->string('campania_padre', 300)->nullable()->after('campanias');
            $table->string('campania_padre_id', 64)->nullable()->after('campania_padre');
            $table->decimal('presupuesto_anterior', 14, 2)->nullable()->after('campania_padre_id');
            $table->decimal('presupuesto_nuevo', 14, 2)->nullable()->after('presupuesto_anterior');
            $table->boolean('presupuesto_diario')->nullable()->after('presupuesto_nuevo');
            // Daily total de la campaña después del cambio (suma de sus ad sets si el presupuesto vive ahí).
            $table->decimal('presupuesto_campania', 14, 2)->nullable()->after('presupuesto_diario');
        });
    }

    public function down(): void
    {
        Schema::table('optimizaciones', function (Blueprint $table) {
            $table->dropColumn(['campania_padre', 'campania_padre_id', 'presupuesto_anterior', 'presupuesto_nuevo', 'presupuesto_diario', 'presupuesto_campania']);
        });
    }
};
