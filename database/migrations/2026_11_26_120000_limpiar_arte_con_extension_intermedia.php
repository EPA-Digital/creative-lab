<?php

use App\Services\Ingesta\ClasificadorNombres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Artes de TikTok guardados con el nombre del archivo seguido del nombre del
 * anuncio ("...-PROMOS.MP4_VID_MUL_10_AON_..."; Ecuador, 2026-11-26): se
 * dejan en lo que va antes de la extensión, igual que en las importaciones
 * nuevas (ClasificadorNombres::limpiarArchivo), para que se sumen con el
 * mismo video en otras campañas del mismo funnel. No se puede deshacer; el
 * nombre crudo sigue en la plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['nombre_comun', 'nombre_completo'] as $columna) {
            DB::table('creativos')
                ->where(fn ($q) => $q->where($columna, 'like', '%.mp4\_%')->orWhere($columna, 'like', '%.mov\_%'))
                ->orderBy('id')
                ->get(['id', $columna])
                ->each(fn ($c) => DB::table('creativos')->where('id', $c->id)->update([
                    $columna => ClasificadorNombres::limpiarArchivo($c->{$columna}),
                ]));
        }
    }

    public function down(): void {}
};
