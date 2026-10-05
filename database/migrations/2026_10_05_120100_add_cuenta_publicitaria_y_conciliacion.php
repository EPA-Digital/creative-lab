<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// De qué cuenta publicitaria vino el costo de cada creativo, y la
// conciliación ("double check") que genera cada importación -- ver
// ImportadorDatos::procesarPlataforma().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creativos', function (Blueprint $table) {
            $table->unsignedInteger('cuenta_publicitaria_id')->nullable()->after('pais_id');
            $table->foreign('cuenta_publicitaria_id')->references('id')->on('cuentas_publicitarias')->nullOnDelete();
        });

        Schema::table('importaciones', function (Blueprint $table) {
            $table->json('conciliacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->dropColumn('conciliacion');
        });

        Schema::table('creativos', function (Blueprint $table) {
            $table->dropForeign(['cuenta_publicitaria_id']);
            $table->dropColumn('cuenta_publicitaria_id');
        });
    }
};
