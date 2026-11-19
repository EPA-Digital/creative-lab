<?php

namespace App\Services\Fuentes;

use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lee el CSV de la pestaña data.medios de un Daily Cuentas (2026-11-18): el
 * export de Supermetrics por medio, día y campaña. La pestaña trae varios
 * bloques lado a lado (el combinado primero y luego el crudo de cada
 * plataforma); solo se toma el combinado. Cada país trae columnas distintas
 * (2026-11-19), así que se leen POR NOMBRE, no por posición:
 *
 *   Ecuador: medio | date | campaign | campaignid | cost | clicks | impressions | views | completedviews | Marca
 *   Panamá:  medio | date | campaign | campaignid | adset | adsetid | cost | clicks | ... | so | week
 *   Perú:    medio | date | campaign | campaignid | adset | adsetid | cost | clicks | ...
 *   México:  ... | cost | ... | cost USD -- Meta en USD y TikTok en MXN: si
 *            existe "cost USD" se usa esa (el dashboard va en dólares).
 *
 * Con ad set, cada campaña trae varias filas por día; se guardan tal cual
 * y todo lo que lee medios_diario suma por campaña.
 *
 * El medio se traduce al canal de config/canales.php (facebook → meta,
 * ASA → asa...); uno que no esté ahí se guarda con su propio nombre.
 */
class LectorDataMedios
{
    /** El bloque combinado arranca así en todos los países. */
    private const INICIO = ['medio', 'date', 'campaign', 'campaignid'];

    /** Columna del archivo → campo (minúsculas, sin espacios dobles). */
    private const COLUMNAS = [
        'medio' => 'medio', 'date' => 'fecha', 'campaign' => 'campania', 'campaignid' => 'campania_id',
        'cost' => 'costo', 'cost usd' => 'costo_usd', 'clicks' => 'clicks', 'impressions' => 'impresiones',
        'views' => 'views', 'completedviews' => 'views_completas',
    ];

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

        $col = null; // campo → índice de columna
        $filas = [];
        $medios = [];
        while (($c = fgetcsv($h, escape: '')) !== false) {
            $c = array_map(fn ($v) => trim((string) $v), $c);
            if ($col === null) {
                $col = self::columnas($c);

                continue;
            }
            $v = fn (string $campo) => isset($col[$campo]) ? ($c[$col[$campo]] ?? '') : '';
            // La pestaña repite el encabezado en la segunda fila.
            if ($v('medio') === '' || $v('fecha') === '' || $v('campania') === '' || mb_strtolower($v('fecha')) === 'date') {
                continue;
            }
            $medio = $this->canal($v('medio'));
            $filas[] = [
                'fecha' => self::fecha($v('fecha')),
                'medio' => $medio,
                'medio_original' => mb_substr($v('medio'), 0, 60),
                'campania' => mb_substr($v('campania'), 0, 300),
                'campania_id' => $v('campania_id') === '' ? null : mb_substr($v('campania_id'), 0, 64),
                'costo' => self::numero(isset($col['costo_usd']) ? $v('costo_usd') : $v('costo')),
                'clicks' => (int) self::numero($v('clicks')),
                'impresiones' => (int) self::numero($v('impresiones')),
                'views' => (int) self::numero($v('views')),
                'views_completas' => (int) self::numero($v('views_completas')),
            ];
            $medios[$medio] = ($medios[$medio] ?? 0) + 1;
        }
        fclose($h);

        if ($col === null) {
            throw new RuntimeException('No encontré el encabezado medio | date | campaign | campaignid ... cost. ¿Es el CSV de la pestaña data.medios?');
        }
        if ($filas === []) {
            throw new RuntimeException('El archivo no trae filas de datos.');
        }
        $fechas = array_column($filas, 'fecha');

        return ['filas' => $filas, 'desde' => min($fechas), 'hasta' => max($fechas), 'medios' => $medios];
    }

    /**
     * Si la fila es el encabezado del bloque combinado, el índice de cada campo
     * (solo hasta la primera columna vacía: lo que sigue son los bloques crudos
     * de cada plataforma). Null si no es el encabezado.
     *
     * @param  list<string>  $c
     * @return array<string, int>|null
     */
    private static function columnas(array $c): ?array
    {
        $nombres = array_map(fn ($v) => preg_replace('/\s+/', ' ', mb_strtolower($v)), $c);
        if (array_slice($nombres, 0, count(self::INICIO)) !== self::INICIO) {
            return null;
        }
        $col = [];
        foreach ($nombres as $i => $n) {
            if ($n === '') {
                break;
            }
            if (isset(self::COLUMNAS[$n]) && ! isset($col[self::COLUMNAS[$n]])) {
                $col[self::COLUMNAS[$n]] = $i;
            }
        }

        return isset($col['costo']) || isset($col['costo_usd']) ? $col : null;
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
