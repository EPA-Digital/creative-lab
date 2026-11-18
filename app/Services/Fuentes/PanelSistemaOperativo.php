<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Illuminate\Support\Facades\DB;

/**
 * SO (2026-11-18, pedido explícito): réplica de so.2026 / ✅SO / SO ✅ de cada
 * Daily Cuentas -- installs, new customers, recompras y registros por sistema
 * operativo (Android / iOS), del negocio completo y de Paid, por mes o semana.
 *
 * - Sistema operativo: por la app de AF data ("... - id123" = iOS, el
 *   paquete Android = Android).
 * - Paid = la fuente final paid del país (TADA PERFORMANCE), confirmado.
 * - Inversión (solo Paid): data.medios repartido por nombre de campaña
 *   (dice IOS → iOS, WEB → Web, el resto Android; Apple Search Ads = iOS),
 *   confirmado.
 *
 * El servidor manda días; la página suma por mes o semana.
 */
class PanelSistemaOperativo
{
    /** Encabezados del pivot donde vive el registro (cada país lo nombra distinto). */
    private const REGISTRO = ['Registration Submitted Successfully', 'af_complete_registration', 'Registration', 'Registro'];

    /**
     * dias: [fecha, so (0 android | 1 ios), paid (0|1), installs, nc, recompras, registros].
     * inversion: [fecha, so (0 android | 1 ios | 2 web), costo].
     *
     * @return array{dias: list<list<int|string>>, inversion: list<list<int|string|float>>, anios: list<int>, hasta: ?string}
     */
    public function armar(int $paisId, ?string $fuentePaid): array
    {
        $registro = 'COALESCE('.implode(', ', array_map(fn ($c) => "JSON_EXTRACT(otros, '$.\"{$c}\"')", self::REGISTRO)).', 0)';
        $filas = DB::table('fuentes_appsflyer')
            ->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)
            ->selectRaw("fecha, app, fuente_final = ? AS paid, SUM(installs) i, SUM(nc) nc, SUM(recompras) r, SUM({$registro}) reg", [(string) $fuentePaid])
            ->groupBy('fecha', 'app', 'paid')
            ->orderBy('fecha')
            ->get();

        $dias = [];
        foreach ($filas as $f) {
            $dias[] = [substr((string) $f->fecha, 0, 10), self::soDeApp((string) $f->app), (int) $f->paid, (int) $f->i, (int) $f->nc, (int) $f->r, (int) $f->reg];
        }

        $inversion = [];
        $medios = DB::table('medios_diario')
            ->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)
            ->selectRaw('fecha, medio, campania, SUM(costo) c')
            ->groupBy('fecha', 'medio', 'campania')
            ->get();
        $acc = [];
        foreach ($medios as $m) {
            $k = substr((string) $m->fecha, 0, 10).'|'.self::soDeCampania((string) $m->medio, (string) $m->campania);
            $acc[$k] = ($acc[$k] ?? 0) + (float) $m->c;
        }
        ksort($acc);
        foreach ($acc as $k => $c) {
            [$fecha, $so] = explode('|', $k);
            $inversion[] = [$fecha, (int) $so, round($c, 2)];
        }

        $fechas = array_merge(array_column($dias, 0), array_column($inversion, 0));
        $anios = array_values(array_unique(array_map(fn ($f) => (int) substr($f, 0, 4), $fechas)));
        rsort($anios);

        return ['dias' => $dias, 'inversion' => $inversion, 'anios' => $anios ?: [(int) date('Y')], 'hasta' => $dias ? max(array_column($dias, 0)) : null];
    }

    /** 0 = Android, 1 = iOS (las apps de iOS en AF terminan en "id" + número). */
    public static function soDeApp(string $app): int
    {
        return preg_match('/(^|[\s\-_])id\d{6,}\s*$/i', trim($app)) ? 1 : 0;
    }

    /** 0 = Android, 1 = iOS, 2 = Web, por el nombre de la campaña (Apple Search Ads siempre iOS). */
    public static function soDeCampania(string $medio, string $campania): int
    {
        $m = mb_strtolower($medio);
        if ($m === 'asa' || str_contains($m, 'apple')) {
            return 1;
        }
        if (preg_match('/(^|[^A-Z])IOS([^A-Z]|$)/', mb_strtoupper($campania))) {
            return 1;
        }
        if (preg_match('/(^|[^A-Z])WEB([^A-Z]|$)/', mb_strtoupper($campania))) {
            return 2;
        }

        return 0;
    }
}
