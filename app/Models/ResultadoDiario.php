<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Granularidad diaria (2026-08-27, ver plan del selector de fecha) --
 * fuente de verdad fina alimentada por ImportadorDatosDiario (export de
 * Snowflake). nc_real/orders_real llegan ya prorrateados por fila desde el
 * CSV, nunca se recalculan acá.
 */
class ResultadoDiario extends Model
{
    protected $table = 'resultados_diarios';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'creativo_id',
        'fecha',
        'cost',
        'impressions',
        'clicks',
        'installs',
        'nc',
        'repurchases',
        'orders',
        'nc_real',
        'orders_real',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'cost' => 'decimal:2',
            'nc_real' => 'decimal:2',
            'orders_real' => 'decimal:2',
        ];
    }

    public function creativo(): BelongsTo
    {
        return $this->belongsTo(Creativo::class);
    }
}
