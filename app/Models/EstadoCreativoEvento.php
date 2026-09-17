<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial real de estado de un ad (2026-08-28, ver migración
 * create_estado_creativo_eventos_table y MetaEstadoAdsSyncService). Cada
 * fila es UN cambio real reportado por la Activity Log de Meta -- nunca un
 * snapshot inventado ni interpolado entre dos eventos.
 */
class EstadoCreativoEvento extends Model
{
    protected $table = 'estado_creativo_eventos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'pais_id',
        'ad_id',
        'creativo_id',
        'estado_anterior',
        'estado_nuevo',
        'evento_en',
        'actor_name',
        'extra_data',
    ];

    protected function casts(): array
    {
        return [
            'evento_en' => 'datetime',
            'extra_data' => 'array',
        ];
    }

    public function creativo(): BelongsTo
    {
        return $this->belongsTo(Creativo::class);
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }
}
