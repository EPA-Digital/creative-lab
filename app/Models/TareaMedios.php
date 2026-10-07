<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Imágenes/videos que se están guardando en background para un país y mes
 * (ver CachearImagenesCreativos, GuardarVideoCreativo, RecuperarImagenesMes)
 * -- el panel la consulta para avisar cuando todo está listo, o mostrar el
 * error real si algo falló.
 */
class TareaMedios extends Model
{
    protected $table = 'tareas_medios';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'pais_id',
        'mes',
        'origen',
        'importacion_id',
        'total',
        'listos',
        'fallidos',
        'estado',
        'error_mensaje',
    ];

    /**
     * Suma avance de forma atómica (varios jobs pueden reportar a la misma
     * tarea a la vez: imágenes + cada video) y la cierra como "listo"
     * cuando ya se procesó todo. Una tarea en error no vuelve atrás.
     */
    public static function avanzar(?int $id, int $listos = 0, int $fallidos = 0): void
    {
        if (! $id) {
            return;
        }

        DB::table('tareas_medios')->where('id', $id)->update([
            'listos' => DB::raw("listos + {$listos}"),
            'fallidos' => DB::raw("fallidos + {$fallidos}"),
            'estado' => DB::raw("CASE WHEN estado = 'error' THEN 'error' ELSE 'procesando' END"),
            'actualizado_en' => now(),
        ]);
        DB::table('tareas_medios')
            ->where('id', $id)
            ->where('estado', 'procesando')
            ->whereRaw('listos + fallidos >= total')
            ->update(['estado' => 'listo', 'actualizado_en' => now()]);
    }

    public static function fallar(?int $id, string $mensaje): void
    {
        if ($id) {
            static::whereKey($id)->update(['estado' => 'error', 'error_mensaje' => mb_substr($mensaje, 0, 1000)]);
        }
    }
}
