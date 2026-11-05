<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Clasificación propia de cada cuenta (2026-11-04): cada equipo enseña sus
// siglas de performance/branding y sus etapas de funnel (ver
// ReglasNomenclatura). `funnel` sigue siendo la escala común (AWA/CONS/
// CNV/LOY); funnel_propio es cómo la llama la cuenta ("Do"). tipo_campania
// es el eje común (performance/branding/otro) y tipo_campania_nombre el
// nombre propio. TaDa no los usa (null): su DTC/BRD sigue en tipo_cuenta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->string('nombre_conjunto', 255)->nullable()->after('nombre_campania');
            $table->string('funnel_propio', 40)->nullable()->after('funnel');
            $table->string('tipo_campania', 20)->nullable()->after('tipo_cuenta');
            $table->string('tipo_campania_nombre', 40)->nullable()->after('tipo_campania');
        });
    }

    public function down(): void
    {
        Schema::table('creativos', fn (Blueprint $table) => $table->dropColumn(['nombre_conjunto', 'funnel_propio', 'tipo_campania', 'tipo_campania_nombre']));
    }
};
