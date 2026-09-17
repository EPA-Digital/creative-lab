<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extiende `importaciones` para el comando futuro `importar:appsflyer-api`
// (fuente de AppsFlyer vía API en vez de archivo CSV subido a mano) y para
// el fix de "no pisar venta_real cuando no se pasan totales" (ver
// ImportadorDatos::resolverNcOrdersFinal) -- necesitamos saber, por corrida,
// cuántas filas preservaron su nc/orders de una corrida anterior vs cuántas
// se recalcularon con totales nuevos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->enum('origen', ['csv', 'appsflyer_api'])->default('csv')->after('pais_id');
            $table->unsignedInteger('nc_preservados')->default(0)->after('tiene_meta_true');
            $table->unsignedInteger('nc_recalculados')->default(0)->after('nc_preservados');
            $table->unsignedInteger('orders_preservados')->default(0)->after('nc_recalculados');
            $table->unsignedInteger('orders_recalculados')->default(0)->after('orders_preservados');
        });

        Schema::table('importaciones', function (Blueprint $table) {
            $table->string('nombre_archivo', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->dropColumn(['origen', 'nc_preservados', 'nc_recalculados', 'orders_preservados', 'orders_recalculados']);
        });

        Schema::table('importaciones', function (Blueprint $table) {
            $table->string('nombre_archivo', 255)->nullable(false)->change();
        });
    }
};
