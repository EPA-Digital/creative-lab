<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dimensiones propias de cada cuenta (nomenclatura, 2026-11-04): lo que su
// nombre de campaña/anuncio dice además de plataforma/funnel/formato -- ej.
// Openbank {producto: "TDD", sistema_operativo: "Android"}. Las define cada
// cuenta en sus reglas (ReglasNomenclatura); TaDa no las usa (null).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->json('dimensiones')->nullable()->after('formato');
        });
    }

    public function down(): void
    {
        Schema::table('creativos', fn (Blueprint $table) => $table->dropColumn('dimensiones'));
    }
};
