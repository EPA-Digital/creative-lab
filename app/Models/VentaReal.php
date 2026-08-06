<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaReal extends Model
{
    protected $table = 'venta_real';

    const CREATED_AT = 'capturado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'pais_id',
        'plataforma',
        'fecha_inicio',
        'fecha_fin',
        'mes',
        'nc_total_real',
        'orders_total_real',
        'capturado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturado_por');
    }
}
