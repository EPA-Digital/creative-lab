<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convierte la columna real (ya creada como ENUM en producción, ver
 * 2026_08_01_044901_add_campos_negocio_to_users_table.php) a VARCHAR --
 * mismo motivo que esa migración ya explica: la lista de roles crece
 * (agregado 'superadmin' acá) y un enum exige este mismo ALTER cada vez.
 * Solo MySQL -- una instalación sqlite fresca ya nace con VARCHAR desde
 * la migración original editada, no hay nada que convertir ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY rol VARCHAR(20) NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY rol ENUM('director','gerente','senior','junior','cliente') NULL");
        }
    }
};
