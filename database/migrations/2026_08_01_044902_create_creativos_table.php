<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creativos', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pais_id');
            $table->string('ad_id', 50);
            $table->string('nombre_comun', 255)->nullable();
            $table->string('nombre_completo', 500);
            $table->enum('plataforma', ['meta', 'tiktok']);
            $table->string('tipo', 10)->nullable();
            $table->string('formato', 10)->nullable();
            $table->string('medio', 5)->nullable();
            $table->enum('funnel', ['AWA', 'CONS', 'CNV', 'LOY'])->nullable();
            $table->enum('tipo_cuenta', ['DTC', 'BRD']);
            $table->string('producto', 50)->nullable();
            $table->string('influencer', 100)->nullable();
            $table->date('fecha_carga')->nullable();
            $table->date('fecha_termino')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->foreign('pais_id')->references('id')->on('paises')->cascadeOnDelete();
            $table->unique(['ad_id', 'pais_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creativos');
    }
};
