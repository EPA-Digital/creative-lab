<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Acceso por cuenta (multi-cuenta, entrega 2) -- mismo criterio que
// usuario_pais: aplica a todos los roles, se asigna en /usuarios y nadie
// otorga una cuenta que él mismo no tiene. Para ver /{cuenta}/pais/{pais}
// hacen falta las dos cosas: la cuenta y el país (ver EnsureAccesoPais).
//
// Hasta hoy todo el dashboard era TaDa: todos los usuarios existentes
// quedan con acceso a TaDa, así nadie pierde lo que ya veía.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_cuenta', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_id');
            $table->foreign('usuario_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedInteger('cuenta_id');
            $table->foreign('cuenta_id')->references('id')->on('cuentas')->cascadeOnDelete();
            $table->primary(['usuario_id', 'cuenta_id']);
        });

        // Igual que pais_ids_propuestos: lo que propone un junior/senior al
        // invitar, hasta que alguien lo aprueba.
        Schema::table('users', function (Blueprint $table) {
            $table->json('cuenta_ids_propuestos')->nullable()->after('pais_ids_propuestos');
        });

        $tada = DB::table('cuentas')->where('slug', 'tada')->value('id');
        if ($tada) {
            DB::table('usuario_cuenta')->insertUsing(
                ['usuario_id', 'cuenta_id'],
                DB::table('users')->select('id', DB::raw((int) $tada)),
            );
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('cuenta_ids_propuestos'));
        Schema::dropIfExists('usuario_cuenta');
    }
};
