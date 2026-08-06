<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paises', function (Blueprint $table) {
            $table->increments('id');
            $table->string('codigo', 3)->unique();
            $table->string('nombre', 50);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paises');
    }
};
