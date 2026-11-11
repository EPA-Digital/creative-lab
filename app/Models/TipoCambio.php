<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuántas unidades de `moneda` equivalen a 1 USD, desde `vigente_desde`
 * (2026-11-11). Global, no por cuenta: el tipo de cambio es el mismo para
 * todos. Se actualiza en Ajustes (hoy, una vez al año).
 */
class TipoCambio extends Model
{
    protected $table = 'tipos_cambio';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $fillable = ['moneda', 'vigente_desde', 'por_usd', 'actualizado_por'];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'por_usd' => 'float',
        ];
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
