<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACuenta;
use Illuminate\Database\Eloquent\Model;

/** Fila limpia del export de AppsFlyer por canal (vista Fuentes, 2026-11-13). */
class FuenteAppsflyer extends Model
{
    use PerteneceACuenta;

    protected $table = 'fuentes_appsflyer';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'otros' => 'array',
        ];
    }
}
