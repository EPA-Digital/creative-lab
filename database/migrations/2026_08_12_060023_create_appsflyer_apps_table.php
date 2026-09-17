<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reemplaza config/paises.php['appsflyer_app_ids'] (2026-08-11) -- cada país
// puede tener hasta 2 apps trackeadas por separado en AppsFlyer (iOS +
// Android), y una tabla permite agregar/corregir un país sin deploy.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appsflyer_apps', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pais_id');
            $table->enum('plataforma', ['ios', 'android']);
            $table->string('app_id', 100);
            $table->timestamp('creado_en')->useCurrent();

            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->unique(['pais_id', 'plataforma']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appsflyer_apps');
    }
};
