<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Creativo extends Model
{
    protected $table = 'creativos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    // nombre_amigable viaja siempre en el JSON (no hace falta que el
    // frontend sepa de la relación correccion_nombre) -- lee la relación YA
    // CARGADA (los controllers hacen ->with('correccionNombre')), nunca
    // dispara una query por creativo.
    protected $appends = ['nombre_amigable'];

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

    // Granularidad diaria (2026-08-27, ver plan del selector de fecha) --
    // fuente fina alimentada por ImportadorDatosDiario, aparte de
    // resultados() mensual (que sigue siendo lo único que leen Creativos/
    // Inteligencia hoy).
    public function resultadosDiarios(): HasMany
    {
        return $this->hasMany(ResultadoDiario::class);
    }

    // Historial de encendido/apagado/etc. real (2026-08-28, ver
    // MetaEstadoAdsSyncService) -- vacío hasta que el sync corra al menos
    // una vez para este país; Meta no expone el pasado, así que un ad viejo
    // puede no tener eventos nunca.
    public function estadoEventos(): HasMany
    {
        return $this->hasMany(EstadoCreativoEvento::class, 'creativo_id')->orderBy('evento_en');
    }

    public function odt(): HasOne
    {
        return $this->hasOne(Odt::class);
    }

    // Sin FK real en BD (correcciones_nombres.ad_id no referencia
    // creativos.ad_id) -- es un join lógico por el mismo string de ad_id,
    // igual que ya asume el resto del proyecto (dashboard/shared/motor.js
    // aplicarCorreccionesNombres). Nombre "amigable" opcional por creativo,
    // nunca reemplaza nombre_comun/nombre_completo (arte técnico), solo se
    // muestra encima cuando existe (ver Creativo::nombreAmigable()).
    public function correccionNombre(): HasOne
    {
        return $this->hasOne(CorreccionNombre::class, 'ad_id', 'ad_id');
    }

    protected function nombreAmigable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->correccionNombre?->nombre_corregido,
        );
    }
}
