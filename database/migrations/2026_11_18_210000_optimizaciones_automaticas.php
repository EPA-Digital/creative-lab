<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimizaciones automáticas (2026-11-18, pedido explícito: "que se
 * actualice automáticamente como en el Google Sheets"), port del script
 * Optis_Optimizaciones.gs:
 *
 * - optimizaciones.externo: id del evento de origen (Meta Activity Log o
 *   cambio de TikTok) para no escribirlo dos veces.
 * - plataforma_snapshot: la "foto" de TikTok (status y presupuesto de
 *   campañas, ad groups y anuncios) contra la que se detectan cambios --
 *   TikTok no tiene historial de cambios en su API. Antes vivía en la hoja
 *   oculta _snapshot_tiktok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizaciones', function (Blueprint $table) {
            $table->string('externo', 190)->nullable()->after('origen');
            $table->unique(['cuenta_id', 'pais_id', 'externo'], 'optimizaciones_externo_unique');
        });

        Schema::create('plataforma_snapshot', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cuenta_publicitaria_id');
            $table->string('clave', 80); // campaign:123 | adgroup:456 | ad:789
            $table->string('tipo', 12);
            $table->string('nombre', 400)->nullable();
            $table->string('status', 40)->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->timestamp('actualizado_en')->nullable();

            $table->unique(['cuenta_publicitaria_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plataforma_snapshot');
        Schema::table('optimizaciones', function (Blueprint $table) {
            $table->dropUnique('optimizaciones_externo_unique');
            $table->dropColumn('externo');
        });
    }
};
