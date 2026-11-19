<?php

use App\Services\Ingesta\ClasificadorNombres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Artes guardados con el nombre del archivo de TikTok
 * ("...-LAL_ABNIXWH9.MOV"): se limpian igual que en las importaciones nuevas
 * (ClasificadorNombres::limpiarArchivo). Solo toca nombres que terminan en
 * .mp4/.mov; no se puede deshacer, pero el nombre crudo sigue en la API.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['nombre_comun', 'nombre_completo'] as $columna) {
            DB::table('creativos')
                ->where(fn ($q) => $q->where($columna, 'like', '%.mov')->orWhere($columna, 'like', '%.mp4'))
                ->orderBy('id')
                ->get(['id', $columna])
                ->each(fn ($c) => DB::table('creativos')->where('id', $c->id)->update([
                    $columna => ClasificadorNombres::limpiarArchivo($c->{$columna}),
                ]));
        }
    }

    public function down(): void {}
};
