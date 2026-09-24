<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (auth-prompt.md Fase 5) -- se crea solo vía
 * App\Services\Auditoria::registrar(), nunca se actualiza ni se borra.
 */
class AuditoriaAcceso extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'email_intentado',
        'evento',
        'ip',
        'user_agent',
        'detalle',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'creado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
