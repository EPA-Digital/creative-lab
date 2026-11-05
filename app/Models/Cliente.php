<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El grupo dueño de una o más cuentas (ej. Coppel → Coppel Branding y
 * Coppel Ecommerce; AB InBev → TaDa).
 */
class Cliente extends Model
{
    protected $table = 'clientes';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = ['nombre'];

    public function cuentas(): HasMany
    {
        return $this->hasMany(Cuenta::class);
    }
}
