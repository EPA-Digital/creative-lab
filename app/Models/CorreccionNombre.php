<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorreccionNombre extends Model
{
    protected $table = 'correcciones_nombres';

    const CREATED_AT = 'corregido_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'ad_id',
        'nombre_corregido',
        'corregido_por',
    ];

    public function corregidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corregido_por');
    }
}
