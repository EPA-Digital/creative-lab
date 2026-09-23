<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Invitación de un junior/senior (ver
            // UsuariosController::store()) -- países propuestos que
            // todavía no pasaron por aprobación de gerente/director/
            // superadmin (User::puedeAprobarInvitaciones()). null =
            // nunca estuvo pendiente (invitado directo por alguien con
            // rango, o cuenta EPA vía Google). Se limpia al aprobar.
            $table->json('pais_ids_propuestos')->nullable()->after('activo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pais_ids_propuestos');
        });
    }
};
