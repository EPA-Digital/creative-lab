<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correcciones_nombres', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ad_id', 50)->unique();
            $table->string('nombre_corregido', 255);
            $table->foreignId('corregido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corregido_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correcciones_nombres');
    }
};
