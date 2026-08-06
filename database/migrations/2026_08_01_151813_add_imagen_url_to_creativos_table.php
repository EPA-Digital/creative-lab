<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Guarda la ruta pública del archivo ya cacheado por ImagenCacheService
// (ej. /creative-images/meta-costo-{ad_id}.jpg) -- no una URL remota de
// Meta/TikTok, esas expiran. Mismo tipo/tamaño que odt.imagen_url (v2) para
// consistencia, pero es una columna DISTINTA -- odt.imagen_url no se toca.
// Nullable: un creativo sin imagen resoluble (sin image_url, sin hash de
// asset_feed_spec, sin video_id) queda en NULL, nunca con un string vacío
// ni un placeholder inventado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->string('imagen_url', 500)->nullable()->after('nombre_completo');
        });
    }

    public function down(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->dropColumn('imagen_url');
        });
    }
};
