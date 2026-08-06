<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Odt extends Model
{
    protected $table = 'odt';

    public $timestamps = false;

    protected $fillable = [
        'creativo_id',
        'texto',
        'titulo',
        'descripcion',
        'cta',
        'deeplink',
        'imagen_url',
        'campana_destino',
        'estado',
        'subido_por',
        'revisado_por',
    ];

    public function creativo(): BelongsTo
    {
        return $this->belongsTo(Creativo::class);
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
