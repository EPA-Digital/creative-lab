<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// nc/orders pasan a guardar el NC/Orders REAL (repartido por % share sobre
// el total tecleado a mano, ver VentaRealYAgrupacion::calcularVentaReal),
// NO el crudo de AppsFlyer -- decisión de negocio (2026-08-01): guardar el
// crudo y calcular CAC/CPO desde ahí tiraría a la basura la lógica de venta
// real (todo el sistema existe porque AppsFlyer no captura el 100%). Sin
// totales tecleados para esa plataforma/rango, o en rango parcial (el costo
// de la API no se puede prorratear a un sub-rango), quedan NULL -- de ahí
// que ahora tengan que ser nullable, antes eran NOT NULL asumiendo que
// siempre había un crudo de AppsFlyer con qué llenarlos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->unsignedInteger('nc')->nullable()->change();
            $table->unsignedInteger('orders')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->unsignedInteger('nc')->nullable(false)->change();
            $table->unsignedInteger('orders')->nullable(false)->change();
        });
    }
};
