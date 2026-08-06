<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogCambio extends Model
{
    protected $table = 'log_cambios';

    const CREATED_AT = 'fecha';

    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'tabla',
        'registro_id',
        'accion',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
