<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Con SESSION_DRIVER=database, desactivar a alguien no le corta la sesión
 * que ya tiene abierta en el navegador -- auth-prompt.md Fase 1 pide que
 * `activo = false` invalide también cualquier sesión viva.
 */
class SesionService
{
    public static function invalidarSesionesDe(User $usuario): void
    {
        DB::table('sessions')->where('user_id', $usuario->id)->delete();
    }
}
