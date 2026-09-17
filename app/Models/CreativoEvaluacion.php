<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreativoEvaluacion extends Model
{
    protected $table = 'creativo_evaluaciones';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'creativo_id',
        'mes',
        'modo',
        'score',
        'desglose',
        'resultado',
        'modelo',
    ];

    protected function casts(): array
    {
        return [
            'desglose' => 'array',
        ];
    }

    public function creativo(): BelongsTo
    {
        return $this->belongsTo(Creativo::class);
    }
}
