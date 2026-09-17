<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Score de arte (2026-08-26, rediseño vía Claude Design) -- evaluarPorArte
// ahora le pide a Claude un desglose estructurado (4 categorías de 25 pts,
// mismo formato que el score de rendimiento) además del texto narrativo.
// Nullable: evaluarPorMetricas sigue siendo solo narrativa -- el score de
// rendimiento ya es gratis y determinístico, pedirle a la IA que re-puntúe
// lo mismo sería redundante.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creativo_evaluaciones', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->after('modo');
            $table->json('desglose')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('creativo_evaluaciones', function (Blueprint $table) {
            $table->dropColumn(['score', 'desglose']);
        });
    }
};
