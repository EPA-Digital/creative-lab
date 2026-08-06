<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tiene_meta -- señal real de motor.js: !!costos, es decir, si este Ad ID
// tuvo match en el cruce contra costosLimpias (el export de costos de la
// API de Meta o TikTok Ads -- el nombre viene de Node, aplica igual a las
// dos plataformas). false significa que el creativo viene SOLO de
// AppsFlyer, sin costo/CTR/CPM real. Se calcula en CruceCostosAppsFlyer al
// mismo punto donde ya existe la variable $costos, ANTES del `?? 0` que la
// pisa al persistir cost/impressions/clicks -- no es un proxy, es la misma
// señal guardada antes de perderse.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->boolean('tiene_meta')->default(false)->after('tiene_venta_real');
        });
    }

    public function down(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->dropColumn('tiene_meta');
        });
    }
};
