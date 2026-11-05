<?php

namespace App\Models\Concerns;

use App\Models\Cuenta;
use App\Support\CuentaActual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Separación de datos por cuenta (plan multi-cuenta): toda consulta del
 * modelo se filtra por la cuenta actual y todo registro nuevo nace en ella.
 * Sin cuenta actual, CuentaActual::id() lanza -- nunca se devuelven filas
 * de todas las cuentas por omisión.
 *
 * Cruzar cuentas a propósito (ej. validar que una cuenta publicitaria no
 * esté registrada en otra) exige ->withoutGlobalScope('cuenta') explícito.
 */
trait PerteneceACuenta
{
    protected static function bootPerteneceACuenta(): void
    {
        static::addGlobalScope('cuenta', function (Builder $query) {
            $query->where($query->qualifyColumn('cuenta_id'), CuentaActual::id());
        });

        static::creating(function ($modelo) {
            $modelo->cuenta_id ??= CuentaActual::id();
        });
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }
}
