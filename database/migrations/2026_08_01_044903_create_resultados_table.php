<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('creativo_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('mes', 7);
            $table->decimal('cost', 12, 2);
            $table->unsignedBigInteger('impressions');
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('installs');
            $table->decimal('cpi', 10, 2)->nullable();
            $table->unsignedInteger('nc');
            $table->decimal('cac', 10, 2)->nullable();
            $table->unsignedInteger('orders');
            $table->unsignedInteger('reorders');
            $table->decimal('cpo', 10, 2)->nullable();
            $table->boolean('tiene_venta_real')->default(false);
            $table->decimal('frequency', 6, 2)->nullable();
            $table->decimal('ctr', 6, 2)->nullable();
            $table->decimal('cpm', 10, 2)->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->foreign('creativo_id')->references('id')->on('creativos')->cascadeOnDelete();
            $table->unique(['creativo_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados');
    }
};
