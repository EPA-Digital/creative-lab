<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nomenclatura por cuenta (pedido explícito 2026-11-04): cada cuenta nombra
// sus campañas y anuncios distinto (TaDa "..._FB-CONVERSION-AND-RMK_VID-...",
// Openbank "fb_obmx_lead_do_..."). La cuenta guarda sus reglas (ver
// ReglasNomenclatura) y los ejemplos de los que salieron, para poder volver
// a pedirle a la IA que las proponga. Sin reglas = clasificador de TaDa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->json('nomenclatura')->nullable()->after('multi_pais');
            $table->json('nomenclatura_ejemplos')->nullable()->after('nomenclatura');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropColumn(['nomenclatura', 'nomenclatura_ejemplos']);
        });
    }
};
