<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Diario medio / Diario campaña (2026-11-18, pedido explícito): réplica de
 * diario.medio.2026 y diario.campaña.2026. Por día:
 *
 * - Inversión, impresiones y clics: data.medios (medios_diario).
 * - Installs, First Purchase (NC) y Purchase (recompras): AF data por media
 *   source (medio) o por nombre de campaña (campaña), sin filtro de fuente
 *   final -- igual que la hoja (Meta del 1 nov: 48 + 19 NC de TADA
 *   PERFORMANCE + BI = 67, como diario.medio).
 * - Total: la inversión de todos los medios y AF data completa (todo el
 *   negocio, también orgánico), como el bloque TOTAL MEDIOS de la hoja.
 *
 * Cada valor es [inversión, impresiones, clics, installs, nc, purchase]; las
 * tasas se calculan en la página.
 */
class DiarioMedios
{
    /** @return list<string> meses (Y-m) con datos, el más reciente primero */
    public function meses(int $paisId): array
    {
        $cuentaId = CuentaActual::id();
        $de = fn (string $tabla) => DB::table($tabla)->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->distinct()->pluck('fecha')->map(fn ($f) => substr((string) $f, 0, 7));
        $meses = $de('medios_diario')->merge($de('fuentes_appsflyer'))->unique()->sortDesc()->values()->all();

        return $meses;
    }

    /**
     * @return array{medios: list<array{id: string, nombre: string, color: ?string}>, dias: array<string, array<string, list<float|int>>>, campanias: list<array<string, mixed>>, hasta: ?string}
     */
    public function mes(int $paisId, string $pais, string $mes): array
    {
        $cuentaId = CuentaActual::id();
        $desde = "{$mes}-01";
        $hasta = date('Y-m-t', strtotime($desde));
        $canales = config("canales.{$pais}", []);

        $canalDeMedia = [];
        foreach ($canales as $c) {
            foreach ($c['media'] as $m) {
                $canalDeMedia[mb_strtolower($m)] = $c['id'];
            }
        }

        $dias = [];
        $campanias = [];
        $extra = [];
        $ultimo = null;
        $clave = fn (string $nombre) => mb_strtolower(trim($nombre));

        // Costos (data.medios).
        $costos = DB::table('medios_diario')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('fecha', 'medio', 'campania')
            ->selectRaw('fecha, medio, campania, SUM(costo) AS costo, SUM(impresiones) AS impresiones, SUM(clicks) AS clicks')
            ->get();
        foreach ($costos as $f) {
            $fecha = substr((string) $f->fecha, 0, 10);
            $ultimo = max($ultimo ?? $fecha, $fecha);
            $v = [round((float) $f->costo, 2), (int) $f->impresiones, (int) $f->clicks, 0, 0, 0];
            self::sumar($dias[$fecha][$f->medio], $v);
            self::sumar($dias[$fecha]['total'], $v);
            $k = $clave($f->campania);
            $campanias[$k] ??= ['nombre' => $f->campania, 'medio' => $f->medio, 'dias' => []];
            self::sumar($campanias[$k]['dias'][$fecha], $v);
            if (! collect($canales)->contains('id', $f->medio)) {
                $extra[$f->medio] = true;
            }
        }

        // Resultados (AF data).
        $af = DB::table('fuentes_appsflyer')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('fecha', 'media_source', 'campania')
            ->selectRaw('fecha, media_source, campania, SUM(installs) AS installs, SUM(nc) AS nc, SUM(recompras) AS recompras')
            ->get();
        foreach ($af as $f) {
            $fecha = substr((string) $f->fecha, 0, 10);
            $ultimo = max($ultimo ?? $fecha, $fecha);
            $v = [0, 0, 0, (int) $f->installs, (int) $f->nc, (int) $f->recompras];
            self::sumar($dias[$fecha]['total'], $v);
            $medio = $canalDeMedia[mb_strtolower($f->media_source)] ?? null;
            if ($medio) {
                self::sumar($dias[$fecha][$medio], $v);
            }
            $k = $clave($f->campania);
            // Una campaña de AF data entra si tiene costo o si es de un medio paid.
            if (isset($campanias[$k]) || ($medio && $f->campania !== 'None')) {
                $campanias[$k] ??= ['nombre' => $f->campania, 'medio' => $medio, 'dias' => []];
                self::sumar($campanias[$k]['dias'][$fecha], $v);
            }
        }

        $medios = array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'color' => $c['color']], $canales);
        foreach (array_keys($extra) as $m) {
            $medios[] = ['id' => $m, 'nombre' => Str::headline($m), 'color' => null];
        }
        ksort($dias);
        $lista = array_values($campanias);
        usort($lista, fn ($a, $b) => self::costo($b) <=> self::costo($a) ?: strcmp($a['nombre'], $b['nombre']));

        return [
            'medios' => $medios,
            'dias' => (object) $dias,
            'campanias' => array_map(fn ($c) => [...$c, 'dias' => (object) $c['dias']], $lista),
            'hasta' => $ultimo,
        ];
    }

    private static function costo(array $c): float
    {
        return array_sum(array_map(fn ($d) => $d[0], $c['dias']));
    }

    /** @param list<float|int>|null $acc @param list<float|int> $v */
    private static function sumar(?array &$acc, array $v): void
    {
        $acc ??= [0, 0, 0, 0, 0, 0];
        foreach ($v as $i => $x) {
            $acc[$i] += $x;
        }
        $acc[0] = round($acc[0], 2);
    }
}
