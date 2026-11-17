<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily (2026-11-18, pedido explícito): la planeación del mes que el equipo
 * llenaba en morado en la pestaña daily del Daily Cuentas -- metas del mes y,
 * por campaña, status, funnel, nombre común, budget, daily de plataforma y
 * KPIs. Lo demás (costo, resultados, pacing) se calcula de data.medios y AF
 * data. Cada mes arranca copiando el anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_planes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->string('mes', 7);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedInteger('meta_installs')->nullable();
            $table->unsignedInteger('meta_nc')->nullable();
            $table->unsignedInteger('meta_orders')->nullable();
            $table->text('notas')->nullable();
            // Ajustes por día (2026-11-18): "Total a gastar" de cada día de la semana, [lun..dom].
            $table->json('total_dia')->nullable();
            $table->timestamp('actualizado_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->unique(['cuenta_id', 'pais_id', 'mes']);
        });

        Schema::create('daily_campanias', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->string('medio', 60);
            $table->string('status', 20)->default('ACTIVA');
            $table->string('funnel', 10)->nullable();
            $table->string('nombre_comun', 120)->nullable();
            $table->string('campania', 300);
            $table->string('campania_id', 64)->nullable();
            $table->string('region', 60)->nullable();
            $table->decimal('budget', 14, 2)->default(0);
            $table->decimal('daily_plataforma', 14, 2)->nullable();
            $table->unsignedInteger('kpi_installs')->nullable();
            $table->unsignedInteger('kpi_nc')->nullable();
            $table->unsignedInteger('kpi_recompras')->nullable();
            $table->unsignedInteger('kpi_orders')->nullable();
            // Ajuste de daily por día de la semana, [lun..dom] (null = sin ajuste).
            $table->json('ajustes')->nullable();
            $table->unsignedInteger('actualizado_por')->nullable();
            $table->timestamp('actualizado_en')->nullable();

            $table->foreign('plan_id')->references('id')->on('daily_planes')->cascadeOnDelete();
            $table->index(['plan_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_campanias');
        Schema::dropIfExists('daily_planes');
    }
};
