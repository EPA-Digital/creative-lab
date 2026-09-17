<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pais extends Model
{
    protected $table = 'paises';

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    public function creativos(): HasMany
    {
        return $this->hasMany(Creativo::class);
    }

    public function ventaReal(): HasMany
    {
        return $this->hasMany(VentaReal::class);
    }

    public function appsflyerApps(): HasMany
    {
        return $this->hasMany(AppsflyerApp::class);
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_pais', 'pais_id', 'usuario_id');
    }
}
