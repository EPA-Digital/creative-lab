<?php

namespace App\Services\Fuentes;

use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lee el CSV de la pestaña data.medios de un Daily Cuentas (2026-11-18): el
 * export de Supermetrics por medio, día y campaña. La pestaña trae varios
 * bloques lado a lado (el combinado primero y luego el crudo de cada
 * plataforma); solo se toma el combinado:
 *
 *   medio | date | campaign | campaignid | cost | clicks | impressions | views | completedviews
 *
 * El medio se traduce al canal de config/canales.php (facebook → meta,
 * ASA → asa...); uno que no esté ahí se guarda con su propio nombre.
 */
class LectorDataMedios
{
    private const ENCABEZADO = ['medio', 'date', 'campaign', 'campaignid', 'cost'];

    /** @var array<string, string> alias (minúsculas) → canal */
    private array $canalDe = [];

    public function __construct(string $pais)
    {
        foreach (config("canales.{$pais}", []) as $c) {
            foreach ([$c['id'], $c['nombre'], ...($c['datos'] ?? [])] as $alias) {
                $this->canalDe[mb_strtolower(trim($alias))] = $c['id'];
            }
        }
    }

    /**
     * @return array{filas: list<array<string, mixed>>, desde: string, hasta: string, medios: array<string, int>}
     */
    public function leer(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);

        $enDatos = false;
        $filas = [];
        $medios = [];
        while (($c = fgetcsv($h, escape: '')) !== false) {
            $c = array_map(fn ($v) => trim((string) $v), $c);
            if (! $enDatos) {
                $enDatos = array_map('mb_strtolower', array_slice($c, 0, 5)) === self::ENCABEZADO;

                continue;
            }
            // La pestaña repite el encabezado en la segunda fila.
            if (($c[0] ?? '') === '' || ($c[1] ?? '') === '' || ($c[2] ?? '') === '' || mb_strtolower($c[1]) === 'date') {
                continue;
            }
            $medio = $this->canal($c[0]);
            $filas[] = [
                'fecha' => self::fecha($c[1]),
                'medio' => $medio,
                'medio_original' => mb_substr($c[0], 0, 60),
                'campania' => mb_substr($c[2], 0, 300),
                'campania_id' => ($c[3] ?? '') === '' ? null : mb_substr($c[3], 0, 64),
                'costo' => self::numero($c[4] ?? ''),
                'clicks' => (int) self::numero($c[5] ?? ''),
                'impresiones' => (int) self::numero($c[6] ?? ''),
                'views' => (int) self::numero($c[7] ?? ''),
                'views_completas' => (int) self::numero($c[8] ?? ''),
            ];
            $medios[$medio] = ($medios[$medio] ?? 0) + 1;
        }
        fclose($h);

        if (! $enDatos) {
            throw new RuntimeException('No encontré el encabezado medio | date | campaign | campaignid | cost. ¿Es el CSV de la pestaña data.medios?');
        }
        if ($filas === []) {
            throw new RuntimeException('El archivo no trae filas de datos.');
        }
        $fechas = array_column($filas, 'fecha');

        return ['filas' => $filas, 'desde' => min($fechas), 'hasta' => max($fechas), 'medios' => $medios];
    }

    public function canal(string $medio): string
    {
        $m = mb_strtolower(trim($medio));

        return $this->canalDe[$m] ?? Str::slug($m) ?: 'otro';
    }

    private static function numero(string $v): float
    {
        $v = str_replace(['$', ',', ' ', '%'], '', $v);

        return is_numeric($v) ? round((float) $v, 2) : 0.0;
    }

    /** "2026-01-01" o "20/8/2026" (día/mes/año, como exporta la hoja). */
    private static function fecha(string $v): string
    {
        foreach (['Y-m-d', 'j/n/Y', 'd/m/Y'] as $formato) {
            try {
                $f = Carbon::createFromFormat('!'.$formato, $v);
                if ($f !== false && $f->format($formato) === $v) {
                    return $f->toDateString();
                }
            } catch (\Throwable) {
                // siguiente formato
            }
        }
        throw new RuntimeException("Fecha no reconocida en data.medios: \"{$v}\".");
    }
}
