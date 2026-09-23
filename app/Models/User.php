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
     * Dominio de correo que entra vía Google con acceso completo (rol !==
     * 'cliente') -- ver GoogleAuthController. Cualquier otro dominio queda
     * rechazado en el callback de Google, nunca llega a crear sesión.
     */
    public const DOMINIO_EPA = 'epa.digital';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'google_id',
        'password',
        'rol',
        'creado_por',
        'invitacion_token',
        'activo',
        'email_verified_at',
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

    /**
     * true = EPA, acceso completo (director/gerente/senior/junior). false =
     * 'cliente', solo lectura -- ver Gate 'epa' en AppServiceProvider y su
     * uso en routes/web.php. No diferencia entre los 4 roles EPA todavía
     * (pedido explícito: "EPA puede todo de momento").
     */
    public function esEpa(): bool
    {
        return $this->rol !== 'cliente';
    }

    /**
     * superadmin/director pueden tocar usuarios/roles/países ajenos (Gate
     * 'gestionar-usuarios', ver AppServiceProvider y UsuariosController).
     * gerente/senior/junior tienen el mismo acceso completo al dashboard
     * que un director (ver esEpa()), pero no administran gente. "Puede
     * haber muchos directores" (pedido explícito 2026-09-23) -- por eso
     * existe superadmin arriba: único rol que puede asignarle 'director'
     * o 'superadmin' a alguien más (ver esSuperadmin() y
     * UsuariosController::actualizarRolYPaises).
     */
    public function puedeGestionarUsuarios(): bool
    {
        return in_array($this->rol, ['superadmin', 'director'], true);
    }

    /**
     * Único rol que puede otorgar 'director'/'superadmin' -- un director
     * normal administra gerente/senior/junior/cliente, pero no puede
     * crear otro director ni ascenderse a superadmin.
     */
    public function esSuperadmin(): bool
    {
        return $this->rol === 'superadmin';
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
