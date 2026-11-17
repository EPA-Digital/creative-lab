<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Paid / Daily BI (2026-11-17, pedido explícito; por ahora solo
 * Ecuador): cada mes puede tener un plan por tipo. Los planes que ya existen
 * quedan como "paid".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_planes', function (Blueprint $table) {
            $table->string('tipo', 10)->default('paid')->after('mes');
        });
        Schema::table('daily_planes', function (Blueprint $table) {
            // Primero el índice nuevo (también empieza con cuenta_id, que la
            // llave foránea necesita) y luego se quita el viejo.
            $table->unique(['cuenta_id', 'pais_id', 'mes', 'tipo'], 'daily_planes_mes_tipo_unique');
        });
        Schema::table('daily_planes', function (Blueprint $table) {
            $table->dropUnique(['cuenta_id', 'pais_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_planes', function (Blueprint $table) {
            $table->unique(['cuenta_id', 'pais_id', 'mes']);
        });
        Schema::table('daily_planes', function (Blueprint $table) {
            $table->dropUnique('daily_planes_mes_tipo_unique');
            $table->dropColumn('tipo');
        });
    }
};
