<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (auth-prompt.md Fase 5) -- ningún controller la
        // actualiza ni la borra, solo Auditoria::registrar() inserta.
        Schema::create('auditoria_accesos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email_intentado')->nullable();
            $table->string('evento');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('detalle')->nullable();
            $table->timestamp('creado_en');

            $table->index(['evento', 'creado_en']);
            $table->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_accesos');
    }
};
