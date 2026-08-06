<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Creativo extends Model
{
    protected $table = 'creativos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'pais_id',
        'ad_id',
        'nombre_comun',
        'nombre_completo',
        'nombre_campania',
        'copy',
        'imagen_url',
        'plataforma',
        'tipo',
        'formato',
        'medio',
        'funnel',
        'tipo_cuenta',
        'producto',
        'influencer',
        'fecha_carga',
        'fecha_termino',
    ];

    protected function casts(): array
    {
        return [
            'fecha_carga' => 'date',
            'fecha_termino' => 'date',
            'copy' => 'array',
        ];
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(Resultado::class);
    }

    public function odt(): HasOne
    {
        return $this->hasOne(Odt::class);
    }
}
