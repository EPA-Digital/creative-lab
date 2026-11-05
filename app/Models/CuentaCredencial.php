<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de un proveedor externo para UNA cuenta (hoy solo AppsFlyer). No hay
 * token general: si la cuenta no tiene el suyo, no importa por API. El token
 * va cifrado y nunca sale al frontend -- solo `ultimos4`.
 */
class CuentaCredencial extends Model
{
    protected $table = 'cuenta_credenciales';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    public const PROVEEDORES = ['appsflyer'];

    protected $fillable = [
        'cuenta_id',
        'proveedor',
        'token',
        'ultimos4',
        'ajustes',
        'validado_en',
        'actualizado_por',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'ajustes' => 'array',
            'validado_en' => 'datetime',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function tieneToken(): bool
    {
        return filled($this->token);
    }

    /** AppsFlyer: "api" (Master API con token) o "bigquery" (Data Locker de EPA). */
    public function origen(): string
    {
        return ($this->ajustes['origen'] ?? 'api') === 'bigquery' ? 'bigquery' : 'api';
    }

    /** ¿Se puede importar AppsFlyer de esta cuenta? Token o BigQuery configurado. */
    public function listaParaImportar(): bool
    {
        return $this->origen() === 'bigquery'
            ? filled($this->ajustes['bq_proyecto'] ?? null) && filled($this->ajustes['bq_dataset'] ?? null)
            : $this->tieneToken();
    }
}
