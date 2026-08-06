<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'creado_por',
        'invitacion_token',
        'activo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function paises(): BelongsToMany
    {
        return $this->belongsToMany(Pais::class, 'usuario_pais', 'usuario_id', 'pais_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function usuariosCreados(): HasMany
    {
        return $this->hasMany(User::class, 'creado_por');
    }

    public function correccionesNombres(): HasMany
    {
        return $this->hasMany(CorreccionNombre::class, 'corregido_por');
    }

    public function ventaRealCapturada(): HasMany
    {
        return $this->hasMany(VentaReal::class, 'capturado_por');
    }

    public function logCambios(): HasMany
    {
        return $this->hasMany(LogCambio::class, 'usuario_id');
    }
}
