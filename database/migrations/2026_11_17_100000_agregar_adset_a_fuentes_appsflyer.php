<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perú (2026-11-17): su pivot de AppsFlyer trae Adset y Adset ID. Columnas
 * opcionales (Ecuador y Panamá no las traen); no toca filas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuentes_appsflyer', function (Blueprint $table) {
            $table->string('adset', 300)->nullable()->after('campania_id');
            $table->string('adset_id', 64)->nullable()->after('adset');
        });
    }

    public function down(): void
    {
        Schema::table('fuentes_appsflyer', function (Blueprint $table) {
            $table->dropColumn(['adset', 'adset_id']);
        });
    }
};
