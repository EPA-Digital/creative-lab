<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// excluidos/sin_actividad_descartados se agregaron a
// 2026_09_23_180004_add_estado_a_importaciones_table DESPUÉS de que esa
// migración ya había corrido en algunas bases locales -- quedaban marcadas
// como "Ran" pero sin las dos columnas, y la importación fallaba al guardar
// el resumen (caso real 2026-10-05). En producción ya existen: esta
// migración no hace nada ahí.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('importaciones', 'excluidos')) {
                $table->unsignedInteger('excluidos')->default(0)->after('problemas');
            }
            if (! Schema::hasColumn('importaciones', 'sin_actividad_descartados')) {
                $table->unsignedInteger('sin_actividad_descartados')->default(0);
            }
        });
    }

    public function down(): void
    {
        // Sin down: no se sabe si las columnas las creó esta migración o la
        // original -- borrarlas acá rompería las bases que ya las tenían.
    }
};
