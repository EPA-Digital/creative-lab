<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Importacion extends Model
{
    protected $table = 'importaciones';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'pais_id',
        'origen',
        'nombre_archivo',
        'desde',
        'hasta',
        'nc_total_real_meta',
        'orders_total_real_meta',
        'nc_total_real_tiktok',
        'orders_total_real_tiktok',
        'es_rango_parcial',
        'creativos_tocados',
        'resultados_tocados',
        'tiene_meta_true',
        'problemas',
        'nc_preservados',
        'nc_recalculados',
        'orders_preservados',
        'orders_recalculados',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'date',
            'hasta' => 'date',
            'nc_total_real_meta' => 'decimal:2',
            'orders_total_real_meta' => 'decimal:2',
            'nc_total_real_tiktok' => 'decimal:2',
            'orders_total_real_tiktok' => 'decimal:2',
            'es_rango_parcial' => 'boolean',
        ];
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }
}
