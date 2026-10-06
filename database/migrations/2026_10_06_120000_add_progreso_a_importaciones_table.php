<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Progreso de una importación en curso (pedido explícito 2026-10-06) --
// el Job lo va actualizando (ver ImportadorDatos::ejecutarPipeline) y el
// panel "Cargar datos" lo lee al hacer polling de estadoImportacion().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->unsignedTinyInteger('progreso')->default(0)->after('estado');
            $table->string('etapa', 160)->nullable()->after('progreso');
        });
    }

    public function down(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->dropColumn(['progreso', 'etapa']);
        });
    }
};
