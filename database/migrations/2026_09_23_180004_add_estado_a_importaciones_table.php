<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            // 'completado' default -- las filas ya existentes (y los
            // caminos que siguen síncronos: consola, "Por API") no cambian
            // de significado. Solo el import de CSV vía panel web arranca
            // en 'procesando' y lo actualiza el Job cuando termina.
            $table->string('estado', 20)->default('completado')->after('hasta');
            $table->text('error_mensaje')->nullable()->after('estado');
            // Contenido crudo del CSV mientras está en cola -- el worker
            // (Cloud Run Job aparte, sin acceso al disco local del request
            // que recibió el upload) lo lee de acá, nunca del filesystem.
            // Se limpia a null apenas el Job termina, no es almacenamiento
            // permanente.
            $table->longText('csv_contenido')->nullable()->after('error_mensaje');
            // No se persistían -- resumenPorArte async (estadoImportacion())
            // los necesita para pintar el mismo resumen que el flujo
            // síncrono de antes.
            $table->unsignedInteger('excluidos')->default(0)->after('problemas');
            $table->unsignedInteger('sin_actividad_descartados')->default(0)->after('excluidos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->dropColumn(['estado', 'error_mensaje', 'csv_contenido']);
        });
    }
};
