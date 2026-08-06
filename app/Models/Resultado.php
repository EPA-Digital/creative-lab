<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resultado extends Model
{
    protected $table = 'resultados';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'creativo_id',
        'fecha_inicio',
        'fecha_fin',
        'mes',
        'cost',
        'impressions',
        'clicks',
        'installs',
        'cpi',
        'nc',
        'cac',
        'orders',
        'reorders',
        'cpo',
        'tiene_venta_real',
        'tiene_meta',
        'frequency',
        'ctr',
        'cpm',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'cost' => 'decimal:2',
            'cpi' => 'decimal:2',
            'cac' => 'decimal:2',
            'cpo' => 'decimal:2',
            'frequency' => 'decimal:2',
            'ctr' => 'decimal:2',
            'cpm' => 'decimal:2',
            'tiene_venta_real' => 'boolean',
            'tiene_meta' => 'boolean',
        ];
    }

    public function creativo(): BelongsTo
    {
        return $this->belongsTo(Creativo::class);
    }
}
