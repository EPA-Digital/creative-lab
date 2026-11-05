<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Perfil visible para el equipo (diseño 2026-11-05): puesto, color del
// avatar y foto. Lo edita cada quien desde el nav ("Editar perfil").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('puesto', 80)->nullable()->after('name');
            $table->string('avatar_color', 7)->nullable()->after('puesto');
            $table->string('foto_path')->nullable()->after('avatar_color');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['puesto', 'avatar_color', 'foto_path']));
    }
};
