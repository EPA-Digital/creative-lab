<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            // Campaign Name real de Meta/TikTok -- el mismo texto que
            // devuelve la API (verificado 2026-08-04: coincide EXACTO con la
            // columna Campaign del CSV de AppsFlyer, así que sale del cruce
            // ya calculado en CruceCostosAppsFlyer::cruzar(), no de una
            // llamada nueva a la API).
            $table->string('nombre_campania')->nullable()->after('nombre_completo');
            // Copy del creativo -- {titulo, texto}. Meta: creative.title/
            // creative.body (gratis, mismo batch que ya trae status/imagen).
            // TikTok: ad_text (gratis, mismo /ad/get/ que ya trae nombre/
            // video) -- vacío para los ads "Smart+ automatizados"
            // (campaign_automation_type=UPGRADED_SMART_PLUS_CREATIVE), que
            // son la mayoría: TikTok no expone el texto que genera/rota
            // automáticamente para esos vía esta API.
            $table->json('copy')->nullable()->after('nombre_campania');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->dropColumn(['nombre_campania', 'copy']);
        });
    }
};
