<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Post de TikTok detrás de cada anuncio (tiktok_item_id, 2026-11-05): en
// un Spark Ad el "nombre del anuncio" es el texto del post, así que el post
// es lo que identifica al creativo. Varios anuncios del mismo post son el
// mismo creativo -- la cola "Sin creativo" se agrupa por aquí.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->string('post_id', 40)->nullable()->after('video_id');
            $table->index(['cuenta_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->dropIndex(['cuenta_id', 'post_id']);
            $table->dropColumn('post_id');
        });
    }
};
