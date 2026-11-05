<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACuenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppsflyerApp extends Model
{
    use PerteneceACuenta;

    protected $table = 'appsflyer_apps';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'pais_id',
        'plataforma',
        'app_id',
        'nombre',
    ];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }
}
