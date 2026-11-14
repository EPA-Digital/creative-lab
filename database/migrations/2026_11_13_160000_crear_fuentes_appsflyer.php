<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuentes (2026-11-13, pedido explícito): el export del pivot de AppsFlyer
 * por día / app / media source / campaña ya limpio y clasificado por canal
 * (Fuente final), para la vista de canales: venta real → canales → anuncios.
 * Una fila por fila real del export (sin subtotales). Reimportar un periodo
 * reemplaza sus días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuentes_appsflyer', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->date('fecha');
            $table->string('app', 160);
            $table->string('agency', 120)->nullable();
            $table->string('media_source', 120);
            $table->string('campania', 300);
            $table->string('campania_id', 64)->nullable();
            $table->unsignedInteger('installs')->default(0);
            // NC = primer pedido; recompras = pedidos siguientes; órdenes = NC + recompras.
            $table->unsignedInteger('nc')->default(0);
            $table->unsignedInteger('recompras')->default(0);
            $table->unsignedInteger('ordenes')->default(0);
            // El resto de columnas del export (eventos, sesiones), tal cual.
            $table->json('otros')->nullable();
            $table->string('fuente_final', 60);
            $table->string('canal', 60);
            $table->timestamp('creado_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->index(['cuenta_id', 'pais_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuentes_appsflyer');
    }
};
