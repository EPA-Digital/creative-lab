<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimizaciones (2026-11-18, pedido explícito): la bitácora de lo que el
 * equipo hace en las plataformas (crear, pautar, ajustar dinero o anuncios,
 * apagar...). Antes vivía en la pestaña optimizaciones.2026 / ✅OPTIS de
 * cada Daily Cuentas. Una optimización puede tocar varias campañas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimizaciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_id');
            $table->unsignedInteger('pais_id');
            $table->date('fecha');
            // Canal de config/canales.php (meta, tiktok...) o el medio tal cual.
            $table->string('medio', 60)->nullable();
            // Lista de nombres de campaña.
            $table->json('campanias')->nullable();
            $table->string('tipo', 40);
            $table->text('descripcion')->nullable();
            $table->text('comentarios')->nullable();
            $table->string('responsable', 160)->nullable();
            // Quién la capturó en el dashboard (null = importada de la hoja).
            $table->unsignedInteger('usuario_id')->nullable();
            $table->string('origen', 12)->default('dashboard');
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('actualizado_en')->nullable();

            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->index(['cuenta_id', 'pais_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimizaciones');
    }
};
