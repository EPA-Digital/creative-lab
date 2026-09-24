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
use Illuminate\Support\Str;
use InvalidArgumentException;

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

    public const METODO_GOOGLE = 'google';

    public const METODO_PASSWORD = 'password';

    /**
     * Jerarquía completa (2026-09-23, pedido explícito) -- cada rol
     * administra/ve SOLO a los que están estrictamente por debajo, nunca a
     * su mismo nivel ni arriba (ver Gate 'gestionar-usuarios' y
     * UsuariosController::index()). 'cliente' es el piso -- nadie
     * administra a otro 'cliente' por jerarquía, eso sigue siendo
     * exclusivo de quien puede tocar usuarios en general.
     */
    private const NIVELES_JERARQUIA = [
        'cliente' => 0,
        'junior' => 1,
        'senior' => 2,
        'gerente' => 3,
        'director' => 4,
        'superadmin' => 5,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'google_id',
        'metodo_auth',
        'password',
        'rol',
        'creado_por',
        'invitacion_token',
        'activo',
        'email_verified_at',
        'pais_ids_propuestos',
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
            'pais_ids_propuestos' => 'array',
        ];
    }

    /**
     * true = EPA, acceso completo (director/gerente/senior/junior). false =
     * 'cliente', solo lectura -- ver Gate 'epa' en AppServiceProvider y su
     * uso en routes/web.php. No diferencia entre los 4 roles EPA todavía
     * (pedido explícito: "EPA puede todo de momento").
     */
    /**
     * Se registra una vez, en el modelo, para que ningún punto de entrada
     * (Google, invitación, perfil) pueda dejar pasar un correo con
     * mayúsculas/espacios ni una cuenta @epa.digital con metodo_auth
     * distinto de 'google' -- regla dura pedida por auth-prompt.md Fase 1.
     */
    protected static function booted(): void
    {
        static::saving(function (self $usuario): void {
            if ($usuario->isDirty('email') && $usuario->email !== null) {
                $usuario->attributes['email'] = Str::lower(trim($usuario->email));
            }

            // null = todavía no se fijó explícitamente -> se resuelve al
            // default de columna ('google'), no hace falta bloquearlo acá.
            if ($usuario->esCorreoEpa() && $usuario->metodo_auth === self::METODO_PASSWORD) {
                throw new InvalidArgumentException('Una cuenta @'.self::DOMINIO_EPA.' solo puede tener metodo_auth = google.');
            }
        });
    }

    public function esCorreoEpa(): bool
    {
        return $this->email !== null && str_ends_with(Str::lower($this->email), '@'.self::DOMINIO_EPA);
    }

    public function esEpa(): bool
    {
        return $this->rol !== 'cliente';
    }

    public function nivelJerarquia(): int
    {
        return self::NIVELES_JERARQUIA[$this->rol] ?? -1;
    }

    /**
     * Gate 'gestionar-usuarios' (ver AppServiceProvider) -- true solo si
     * $this está estrictamente por encima de $objetivo en la jerarquía.
     * Nunca del mismo nivel ni hacia arriba, ni siquiera un director sobre
     * otro director. gerente/senior/junior SÍ pueden administrar a quien
     * tengan debajo con esto (a diferencia del viejo esquema donde solo
     * director/superadmin administraban a cualquiera).
     */
    public function puedeGestionarA(self $objetivo): bool
    {
        return $this->nivelJerarquia() > $objetivo->nivelJerarquia();
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

    /**
     * Desactivar cualquier usuario queda reservado a director/superadmin
     * (pedido explícito 2026-09-23) -- más estricto que puedeGestionarA(),
     * que sí dejaría a un gerente desactivar a un junior. Deactivar es la
     * única acción que NO sigue la jerarquía general.
     */
    public function puedeDesactivarUsuarios(): bool
    {
        return in_array($this->rol, ['superadmin', 'director'], true);
    }

    /**
     * Aprobar una invitación pendiente (ver
     * UsuariosController::store()/aprobar()) -- gerente/director/
     * superadmin. Un junior/senior puede INVITAR y proponer países, pero
     * el usuario queda con activo=false (ni siquiera puede loguearse,
     * pedido explícito) hasta que alguien de este nivel lo apruebe.
     */
    public function puedeAprobarInvitaciones(): bool
    {
        return in_array($this->rol, ['gerente', 'director', 'superadmin'], true);
    }

    /**
     * true = esta cuenta fue invitada por un junior/senior y todavía no la
     * aprobó nadie con puedeAprobarInvitaciones() -- ver
     * UsuariosController::store(). No puede loguearse (activo=false)
     * hasta ese momento.
     */
    public function estaPendienteDeAprobacion(): bool
    {
        return ! $this->activo && $this->pais_ids_propuestos !== null;
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
