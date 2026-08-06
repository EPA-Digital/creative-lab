<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('rol', ['director', 'gerente', 'senior', 'junior', 'cliente'])
                ->nullable()
                ->after('email');
            $table->foreignId('creado_por')
                ->nullable()
                ->after('rol')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('invitacion_token')->nullable()->after('creado_por');
            $table->boolean('activo')->default(true)->after('invitacion_token');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['creado_por']);
            $table->dropColumn(['rol', 'creado_por', 'invitacion_token', 'activo']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
