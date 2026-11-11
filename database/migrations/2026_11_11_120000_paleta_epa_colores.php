<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paleta EPA (2026-11-11): el morado de marca (#9184d9) sale de la paleta.
 * Los avatares y las cuentas que lo tenían guardado pasan al azul EPA, y el
 * color por defecto de una cuenta nueva también.
 */
return new class extends Migration
{
    private const MORADO = '#9184d9';

    private const AZUL_AVATAR = '#7fa2ff';

    private const AZUL_CUENTA = '#003ad6';

    public function up(): void
    {
        DB::table('users')->where('avatar_color', self::MORADO)->update(['avatar_color' => self::AZUL_AVATAR]);
        DB::table('cuentas')->where('color', self::MORADO)->update(['color' => self::AZUL_CUENTA]);
        Schema::table('cuentas', fn ($table) => $table->string('color', 7)->default(self::AZUL_CUENTA)->change());
    }

    public function down(): void
    {
        Schema::table('cuentas', fn ($table) => $table->string('color', 7)->default(self::MORADO)->change());
        DB::table('cuentas')->where('color', self::AZUL_CUENTA)->update(['color' => self::MORADO]);
        DB::table('users')->where('avatar_color', self::AZUL_AVATAR)->update(['avatar_color' => self::MORADO]);
    }
};
