<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta de Meta (ad account, sin el prefijo "act_") o de TikTok
 * (advertiser_id) de la que se traen costos al importar. Un país puede
 * tener varias por plataforma (ej. Ecuador: la de TaDa + una de BRD).
 *
 * - activa: se consulta al importar.
 * - cuenta_en_venta_real: sus anuncios participan del reparto del total
 *   real tecleado (ver ImportadorDatos::procesarPlataforma). Una cuenta
 *   activa pero fuera del reparto sigue trayendo costo; sus creativos
 *   quedan con NC/órdenes null.
 */
class CuentaPublicitaria extends Model
{
    protected $table = 'cuentas_publicitarias';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    public const PLATAFORMAS = ['meta', 'tiktok'];

    public const TIPOS = ['tada', 'brd'];

    protected $fillable = [
        'pais_id',
        'plataforma',
        'cuenta_id',
        'nombre',
        'tipo',
        'activa',
        'cuenta_en_venta_real',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'cuenta_en_venta_real' => 'boolean',
        ];
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function scopeActivasPara(Builder $query, int $paisId, string $plataforma): Builder
    {
        return $query->where('pais_id', $paisId)->where('plataforma', $plataforma)->where('activa', true)->orderBy('id');
    }
}
