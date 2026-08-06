<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_cambios', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('tabla', 50);
            $table->unsignedInteger('registro_id');
            $table->enum('accion', ['crear', 'editar', 'eliminar']);
            $table->json('detalle')->nullable();
            $table->timestamp('fecha')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_cambios');
    }
};
