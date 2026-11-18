<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Optimizaciones (2026-11-18, pedido explícito): la bitácora de lo que el
 * equipo hace en las plataformas. Lee el CSV de la pestaña
 * optimizaciones.2026 / ✅OPTIS de un Daily Cuentas, unifica tipos y medios
 * y lista lo guardado.
 *
 * Formato de la hoja: encabezado "Fecha | Medio | Campaña | Tipo de
 * Optimizacion / Ajuste | Descripción... | [Comentarios/Documento] |
 * Responsable", filas de separador por mes (ENERO, FEBRERO...) y, en
 * Campaña, varias campañas separadas por salto de línea.
 */
class Optimizaciones
{
    private const MESES = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    /** Tipo unificado (config/optimizaciones.php) de lo que viene escrito en la hoja. */
    public static function tipo(?string $texto): string
    {
        $t = Str::of((string) $texto)->ascii()->lower()->squish()->toString();
        if ($t === '') {
            return 'otro';
        }
        foreach (config('optimizaciones.tipos') as $clave => $tipo) {
            foreach ($tipo['claves'] as $c) {
                if (str_contains($t, $c)) {
                    return $clave;
                }
            }
        }

        return 'otro';
    }

    /** "Fer, Diana" → "Fernanda Ochoa, Diana" (alias de config/optimizaciones.php). */
    public static function responsable(?string $texto): ?string
    {
        $t = trim((string) $texto);
        if ($t === '' || $t === '-') {
            return null;
        }
        $alias = config('optimizaciones.responsables', []);
        $nombres = array_map(function ($n) use ($alias) {
            $k = Str::of($n)->ascii()->lower()->squish()->toString();

            return $alias[$k] ?? trim($n);
        }, preg_split('/\s*(?:,|\/|&|\by\b)\s*/u', $t));

        return implode(', ', array_values(array_unique(array_filter($nombres))));
    }

    /** Medio como canal de config/canales.php (meta, tiktok, asa...) o el nombre tal cual en minúsculas. */
    public static function medio(string $pais, ?string $texto): ?string
    {
        $t = trim((string) $texto);
        if ($t === '' || $t === '-') {
            return null;
        }

        return (new LectorDataMedios($pais))->canal($t);
    }

    /** "Uno\nDos" → ["Uno", "Dos"] (sin vacíos ni "-"). @return list<string> */
    public static function campanias(?string $texto): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n]+/', (string) $texto)),
            fn ($c) => $c !== '' && $c !== '-' && mb_strtolower($c) !== 'none',
        ));
    }

    /**
     * @return array{filas: list<array<string, mixed>>, desde: string, hasta: string, sinFecha: int}
     */
    public function leer(string $pais, string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);

        $col = null;
        $filas = [];
        $sinFecha = 0;
        while (($c = fgetcsv($h, escape: '')) !== false) {
            $c = array_map(fn ($v) => trim((string) $v), $c);
            if ($col === null) {
                $idx = fn (string $re) => collect($c)->search(fn ($v) => preg_match($re, Str::ascii(mb_strtolower($v))));
                if ($idx('/^fecha$/') !== false && $idx('/^tipo/') !== false) {
                    $col = [
                        'fecha' => $idx('/^fecha$/'), 'medio' => $idx('/^medio/'), 'campania' => $idx('/^campa/'),
                        'tipo' => $idx('/^tipo/'), 'descripcion' => $idx('/^descrip/'), 'comentarios' => $idx('/^comentario/'),
                        'responsable' => $idx('/^responsable/'),
                    ];
                }

                continue;
            }
            $v = fn (string $k) => $col[$k] === false ? '' : ($c[$col[$k]] ?? '');
            if ($v('tipo') === '' && $v('descripcion') === '' && $v('campania') === '') {
                continue; // separador de mes o renglón vacío
            }
            $fecha = self::fecha($v('fecha'));
            if (! $fecha) {
                $sinFecha++;

                continue;
            }
            $comentarios = $v('comentarios');
            $filas[] = [
                'fecha' => $fecha,
                'medio' => self::medio($pais, $v('medio')),
                'campanias' => self::campanias($v('campania')),
                'tipo' => self::tipo($v('tipo')),
                'descripcion' => $v('descripcion') !== '' ? $v('descripcion') : null,
                'comentarios' => $comentarios !== '' && $comentarios !== '-' ? $comentarios : null,
                'responsable' => self::responsable(mb_substr($v('responsable'), 0, 160)),
            ];
        }
        fclose($h);

        if ($col === null) {
            throw new RuntimeException('No encontré el encabezado Fecha | Medio | Campaña | Tipo... ¿Es el CSV de la pestaña de optimizaciones?');
        }
        if ($filas === []) {
            throw new RuntimeException('El archivo no trae optimizaciones con fecha.');
        }
        $fechas = array_column($filas, 'fecha');

        return ['filas' => $filas, 'desde' => min($fechas), 'hasta' => max($fechas), 'sinFecha' => $sinFecha];
    }

    /** "5/1/2026", "01/07/2026" (día/mes/año), "9 ene 2026" o "2026-01-05". */
    public static function fecha(string $v): ?string
    {
        $v = trim(mb_strtolower($v));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{1,2})\s+([a-z]{3})[a-z.]*\s+(\d{4})$/', Str::ascii($v), $m) && isset(self::MESES[$m[2]])) {
            $mes = self::MESES[$m[2]];

            return checkdate($mes, (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $mes, $m[1]) : null;
        }

        return null;
    }

    /**
     * Optimizaciones entre dos fechas (la vista y el histórico de Control de gasto).
     *
     * @return list<array<string, mixed>>
     */
    public function entre(int $paisId, string $desde, string $hasta): array
    {
        return DB::table('optimizaciones')
            ->leftJoin('users', 'users.id', '=', 'optimizaciones.usuario_id')
            ->where('optimizaciones.cuenta_id', CuentaActual::id())->where('optimizaciones.pais_id', $paisId)
            ->whereBetween('optimizaciones.fecha', [$desde, $hasta])
            ->orderByDesc('optimizaciones.fecha')->orderByDesc('optimizaciones.id')
            ->get(['optimizaciones.*', 'users.name as capturo'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'fecha' => substr((string) $o->fecha, 0, 10),
                'medio' => $o->medio,
                'campanias' => json_decode((string) $o->campanias, true) ?: [],
                'tipo' => $o->tipo,
                'descripcion' => $o->descripcion,
                'comentarios' => $o->comentarios,
                'responsable' => self::responsable($o->responsable ?: $o->capturo),
                'origen' => $o->origen,
            ])->all();
    }

    /** Rango de fechas guardado (para el selector de meses). @return list<string> meses Y-m, el más reciente primero */
    public function meses(int $paisId): array
    {
        return DB::table('optimizaciones')->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)
            ->pluck('fecha')->map(fn ($f) => substr((string) $f, 0, 7))->unique()->sortDesc()->values()->all();
    }

    /** @return array<string, int> */
    public static function contarPorTipo(array $lista): array
    {
        $c = [];
        foreach ($lista as $o) {
            $c[$o['tipo']] = ($c[$o['tipo']] ?? 0) + 1;
        }
        arsort($c);

        return $c;
    }

    public static function fechaHoy(): string
    {
        return Carbon::today()->toDateString();
    }
}
