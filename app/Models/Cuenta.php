<?php

namespace App\Models;

use App\Support\DiscoMedios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lo que se analiza en el dashboard (TaDa, Openbank, Chedraui Ecomm…). Todo
 * dato de negocio pertenece a exactamente una cuenta (ver
 * Concerns\PerteneceACuenta); se administra desde /cuentas.
 */
class Cuenta extends Model
{
    protected $table = 'cuentas';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    public const SERVICIOS = ['App', 'Ecommerce', 'Branding', 'Marketplace', 'Tienda física'];

    public const OBJETIVOS = ['Awareness', 'Consideración', 'Tráfico a sitio', 'Ventas', 'Instalaciones', 'Leads', 'Recompra', 'Visitas a tienda'];

    /** Medios del formulario; solo los de CONECTADOS traen datos hoy. */
    public const MEDIOS = [
        'meta' => 'Meta',
        'tiktok' => 'TikTok',
        'google_ads' => 'Google Ads',
        'youtube' => 'YouTube',
        'dv360' => 'DV360',
        'amazon_ads' => 'Amazon Ads',
        'mercado_libre' => 'Mercado Libre',
        'walmart_connect' => 'Walmart Connect',
        'spotify' => 'Spotify',
        'pinterest' => 'Pinterest',
        'apple_search_ads' => 'Apple Search Ads',
    ];

    public const MEDIOS_CONECTADOS = ['meta', 'tiktok'];

    public const VENTA_REAL = ['no', 'manual', 'auto'];

    /** Slug de la cuenta original del dashboard. */
    public const SLUG_TADA = 'tada';

    protected $fillable = [
        'cliente_id',
        'nombre',
        'slug',
        'color',
        'logo_url',
        'servicios',
        'objetivos',
        'medios',
        'fuente_ga4',
        'fuente_mmp',
        'venta_real',
        'multi_pais',
        'nomenclatura',
        'nomenclatura_ejemplos',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'servicios' => 'array',
            'objetivos' => 'array',
            'medios' => 'array',
            'fuente_ga4' => 'boolean',
            'fuente_mmp' => 'boolean',
            'multi_pais' => 'boolean',
            'nomenclatura' => 'array',
            'nomenclatura_ejemplos' => 'array',
            'activa' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function paises(): BelongsToMany
    {
        return $this->belongsToMany(Pais::class, 'cuenta_pais');
    }

    /** Sin el filtro global: las conexiones de ESTA cuenta, no las de la actual. */
    public function cuentasPublicitarias(): HasMany
    {
        return $this->hasMany(CuentaPublicitaria::class)->withoutGlobalScope('cuenta');
    }

    /** Sin el filtro global: las apps de ESTA cuenta, no las de la actual. */
    public function appsflyerApps(): HasMany
    {
        return $this->hasMany(AppsflyerApp::class)->withoutGlobalScope('cuenta');
    }

    public function credenciales(): HasMany
    {
        return $this->hasMany(CuentaCredencial::class);
    }

    public function credencial(string $proveedor): ?CuentaCredencial
    {
        return $this->credenciales()->where('proveedor', $proveedor)->first();
    }

    /** Disco del logo (ver DiscoMedios). */
    public static function discoLogos(): string
    {
        return DiscoMedios::nombre();
    }

    /** URL para mostrar el logo (null = iniciales con el color); ?v= evita la caché al cambiarlo. */
    public function logoSrc(): ?string
    {
        return $this->logo_url ? "/cuentas/{$this->slug}/logo?v=".($this->actualizado_en?->timestamp ?? 0) : null;
    }

    public static function tada(): self
    {
        return static::where('slug', self::SLUG_TADA)->firstOrFail();
    }
}
