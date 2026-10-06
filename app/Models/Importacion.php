<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Importacion extends Model
{
    protected $table = 'importaciones';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $fillable = [
        'pais_id',
        'origen',
        'nombre_archivo',
        'desde',
        'hasta',
        'nc_total_real_meta',
        'orders_total_real_meta',
        'nc_total_real_tiktok',
        'orders_total_real_tiktok',
        'es_rango_parcial',
        'creativos_tocados',
        'resultados_tocados',
        'tiene_meta_true',
        'problemas',
        'nc_preservados',
        'nc_recalculados',
        'orders_preservados',
        'orders_recalculados',
        'estado',
        'error_mensaje',
        'csv_contenido',
        'excluidos',
        'sin_actividad_descartados',
        'conciliacion',
        'progreso',
        'etapa',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'date',
            'hasta' => 'date',
            'nc_total_real_meta' => 'decimal:2',
            'orders_total_real_meta' => 'decimal:2',
            'nc_total_real_tiktok' => 'decimal:2',
            'orders_total_real_tiktok' => 'decimal:2',
            'es_rango_parcial' => 'boolean',
            'conciliacion' => 'array',
        ];
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    /**
     * fn (int $porcentaje, string $etapa) que guarda el avance de una
     * importación en curso (ver ImportadorDatos::importar/importarDesdeApi)
     * -- el panel lo lee al hacer polling. Como mucho una escritura por
     * segundo, salvo cambio de etapa (ej. de Meta a TikTok), para no
     * martillar la BD guardando creativos.
     */
    public static function reporteroDeProgreso(int $importacionId): \Closure
    {
        $ultimaEscritura = 0.0;
        $ultimaEtapaBase = null;

        return function (int $porcentaje, string $etapa) use ($importacionId, &$ultimaEscritura, &$ultimaEtapaBase): void {
            // "Guardando creativos de Meta: 40 de 790" -> "Guardando creativos de Meta"
            $etapaBase = preg_replace('/:\s.*$/', '', $etapa);
            $ahora = microtime(true);
            if ($etapaBase === $ultimaEtapaBase && $ahora - $ultimaEscritura < 1.0) {
                return;
            }
            static::whereKey($importacionId)->update(['progreso' => $porcentaje, 'etapa' => mb_substr($etapa, 0, 160)]);
            $ultimaEscritura = $ahora;
            $ultimaEtapaBase = $etapaBase;
        };
    }
}
