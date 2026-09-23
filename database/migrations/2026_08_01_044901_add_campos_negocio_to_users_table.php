<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // string, no enum -- la lista de roles válidos creció una vez
            // ya (2026-09-23, agregado 'superadmin') y va a seguir
            // creciendo; un enum de MySQL exige una migración con ALTER
            // MODIFY cada vez (ver 2026_09_23_000000_convert_rol_a_string_
            // en_users_table.php) y encima rompe sqlite:memory sin
            // doctrine/dbal (no instalado). La validez del valor se
            // enforce en la app (UsuariosController::ROLES), no en el
            // esquema.
            $table->string('rol', 20)
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
