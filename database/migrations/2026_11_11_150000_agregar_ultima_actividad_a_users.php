<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presencia en Usuarios (2026-11-11): la última vez que alguien usó el
 * dashboard, para mostrar "última vez hace 3 h" aunque ya haya cerrado
 * sesión (al cerrarla se borra su fila de `sessions`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('ultima_actividad_en')->nullable()->after('foto_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ultima_actividad_en');
        });
    }
};
