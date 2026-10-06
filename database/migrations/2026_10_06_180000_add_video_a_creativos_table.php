<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Videos reproducibles en el modal (pedido explícito 2026-10-06):
// - video_id: id del video en TikTok (se guarda al importar).
// - video_url: versión ligera (MP4 480p) guardada en el bucket -- queda
//   aunque TikTok borre el anuncio.
// - video_permalink: enlace permanente al video en Facebook (Meta no deja
//   bajar el archivo con el token actual).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->string('video_id', 80)->nullable()->after('imagen_url');
            $table->string('video_url', 500)->nullable()->after('video_id');
            $table->string('video_permalink', 500)->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->dropColumn(['video_id', 'video_url', 'video_permalink']);
        });
    }
};
