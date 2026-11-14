<?php

namespace App\Services\Fuentes;

use Carbon\Carbon;
use RuntimeException;

/**
 * Limpia el export del pivot de AppsFlyer de un país (vista Fuentes,
 * 2026-11-13) -- lo que hoy el equipo hace a mano en la hoja:
 *
 * 1. Quita subtotales: el pivot trae un renglón de suma por cada nivel
 *    (día, app, media source, campaña) con las columnas de agrupación
 *    vacías. Solo cuenta la fila con fecha, app, agency, media source,
 *    campaña y campaign ID llenos.
 * 2. NC y recompras salen de las columnas de eventos del país; órdenes =
 *    NC + recompras.
 * 3. Fuente final por reglas (config/fuentes.php) y canal: dentro de la
 *    fuente paid el canal es la media source; fuera, la fuente.
 */
class LimpiadorFuentes
{
    private const AGRUPACION = ['Install Day', 'App Name', 'Agency', 'Media Source', 'Campaign', 'Campaign ID'];

    private const INSTALLS = 'Installs';

    /** @param array<string, mixed> $config config("fuentes.{pais}") */
    public function __construct(private readonly array $config) {}

    public static function paraPais(string $pais): ?self
    {
        $config = config("fuentes.{$pais}");

        return $config ? new self($config) : null;
    }

    /**
     * @return array{filas: list<array<string, mixed>>, subtotales: int, desde: ?string, hasta: ?string}
     */
    public function limpiar(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r", '', $csv));
        $lineas = array_values(array_filter(explode("\n", $csv), fn ($l) => trim($l) !== ''));
        if ($lineas === []) {
            throw new RuntimeException('El archivo está vacío.');
        }

        $encabezados = array_map(fn ($h) => self::sinSum($h), str_getcsv(array_shift($lineas), escape: ''));
        $indice = array_flip($encabezados);
        $faltan = array_diff([...self::AGRUPACION, self::INSTALLS, $this->config['columnas']['nc'], $this->config['columnas']['recompras']], $encabezados);
        if ($faltan !== []) {
            throw new RuntimeException('Al archivo le faltan columnas: '.implode(', ', $faltan).'. ¿Es el export del pivot de AppsFlyer de este país?');
        }
        $conocidas = [...self::AGRUPACION, self::INSTALLS, $this->config['columnas']['nc'], $this->config['columnas']['recompras']];

        $filas = [];
        $subtotales = 0;
        foreach ($lineas as $linea) {
            $c = str_getcsv($linea, escape: '');
            $valor = fn (string $col) => trim(html_entity_decode($c[$indice[$col]] ?? '', ENT_QUOTES | ENT_HTML5));

            $agrupacion = array_map($valor, self::AGRUPACION);
            if (in_array('', $agrupacion, true)) {
                $subtotales++;

                continue;
            }
            [$dia, $app, $agency, $media, $campania, $campaniaId] = $agrupacion;

            $nc = self::numero($valor($this->config['columnas']['nc']));
            $recompras = self::numero($valor($this->config['columnas']['recompras']));
            $otros = [];
            foreach ($encabezados as $col) {
                if (! in_array($col, $conocidas, true)) {
                    $otros[self::etiqueta($col)] = self::numero($valor($col));
                }
            }
            $fuente = $this->fuente($media, $campania);

            $filas[] = [
                'fecha' => self::fecha($dia),
                'app' => $app,
                'agency' => $agency === 'None' ? null : $agency,
                'media_source' => $media,
                'campania' => $campania,
                'campania_id' => $campaniaId === 'None' ? null : $campaniaId,
                'installs' => self::numero($valor(self::INSTALLS)),
                'nc' => $nc,
                'recompras' => $recompras,
                'ordenes' => $nc + $recompras,
                'otros' => $otros,
                'fuente_final' => $fuente,
                'canal' => $this->canal($fuente, $media),
            ];
        }

        $fechas = array_column($filas, 'fecha');

        return [
            'filas' => $filas,
            'subtotales' => $subtotales,
            'desde' => $fechas ? min($fechas) : null,
            'hasta' => $fechas ? max($fechas) : null,
        ];
    }

    public function fuente(string $media, string $campania): string
    {
        foreach ($this->config['reglas'] as $regla) {
            $cumple = true;
            foreach ($regla['si'] as [$campo, $operador, $esperado]) {
                $texto = mb_strtoupper($campo === 'media' ? $media : $campania);
                $esperado = mb_strtoupper($esperado);
                $ok = match ($operador) {
                    'empieza' => str_starts_with($texto, $esperado),
                    'contiene' => str_contains($texto, $esperado),
                    'igual' => $texto === $esperado,
                };
                if (! $ok) {
                    $cumple = false;
                    break;
                }
            }
            if ($cumple) {
                return $regla['fuente'];
            }
        }

        return $this->config['por_defecto'];
    }

    public function canal(string $fuente, string $media): string
    {
        if ($fuente === $this->config['fuente_paid']) {
            return $this->config['canales_paid'][$media] ?? $media;
        }

        return $this->config['nombres_canal'][$fuente] ?? $fuente;
    }

    /** Canales que maneja EPA (paid), en el orden de la config. @return list<string> */
    public function canalesPaid(): array
    {
        return array_values(array_unique($this->config['canales_paid']));
    }

    private static function sinSum(string $h): string
    {
        return trim(preg_replace('/\s*\(sum\)\s*$/i', '', trim($h)));
    }

    /** "Activity - Event Counter - App Opened" → "App Opened". */
    private static function etiqueta(string $col): string
    {
        return trim(preg_replace('/^Activity - (Event Counter - )?/i', '', $col));
    }

    private static function numero(string $v): int
    {
        $v = str_replace(',', '', $v);

        return is_numeric($v) ? (int) round((float) $v) : 0;
    }

    /** "Oct 01, 2026" (export) o "2026-10-01". */
    private static function fecha(string $v): string
    {
        foreach (['M d, Y', 'Y-m-d', 'd/m/Y'] as $formato) {
            try {
                $f = Carbon::createFromFormat('!'.$formato, $v);
                if ($f !== false && $f->format($formato) === $v) {
                    return $f->toDateString();
                }
            } catch (\Throwable) {
                // siguiente formato
            }
        }
        throw new RuntimeException("Fecha no reconocida en el archivo: \"{$v}\".");
    }
}
