<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipo en Meta (2026-11-24, pedido explícito): Meta solo da el nombre de
 * quien crea una campaña. Aquí se guardan los nombres que el equipo marcó
 * como suyos desde Control de gasto, opcionalmente ligados a un usuario del
 * dashboard, para que no salgan como "Fuera del equipo". Por cuenta, no por
 * país: la misma persona trabaja en varios países.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipo_meta', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cuenta_id');
            $table->string('nombre', 200);
            $table->string('nombre_normalizado', 200);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamp('creado_en')->nullable();

            $table->unique(['cuenta_id', 'nombre_normalizado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipo_meta');
    }
};
