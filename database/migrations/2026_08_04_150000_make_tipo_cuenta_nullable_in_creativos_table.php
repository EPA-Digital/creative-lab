<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// tipo_cuenta pasa a nullable -- NULL representa "Sin clasificar" de
// verdad (pedido explícito 2026-08-04): un ad que el API confirma
// eliminado (TikTok) o sin actividad hace 4+ meses (Meta, no hay señal de
// "eliminado" directa ahí) ya no debe mostrarse como DTC/BRD solo porque
// el nombre traía ese segmento -- el negocio ya no puede confiar en esa
// clasificación si el ad ni siquiera sigue vivo/activo. Antes del pedido,
// tipo_cuenta SIEMPRE caía a 'DTC' como último recurso -- ese fallback
// para casos SIN señal de eliminación/inactividad sigue igual, esto solo
// agrega el caso NUEVO de "confirmado muerto/inactivo".
// Raw SQL en vez de Schema::table()->change() -- evita depender de
// doctrine/dbal (no instalado) solo para este ALTER puntual de un enum.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE creativos MODIFY tipo_cuenta ENUM('DTC','BRD') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE creativos MODIFY tipo_cuenta ENUM('DTC','BRD') NOT NULL DEFAULT 'DTC'");
    }
};
