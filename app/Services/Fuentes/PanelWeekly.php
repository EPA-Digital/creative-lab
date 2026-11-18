<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Weekly (2026-11-18, pedido explícito): réplica de weekly.fer (Ecuador y
 * Panamá). Verificado contra la hoja de Ecuador, semana 45:
 *
 * - Global semanal Paid: gasto = data.medios sin campañas _BRD_; primeras
 *   compras, órdenes e installs = AF data de la fuente paid (TADA
 *   PERFORMANCE). CPO = gasto / órdenes, CAC = gasto / NC, onboarding =
 *   NC / installs, CPI = gasto / installs.
 * - Por funnel, cada etapa con su indicador: Awareness = impresiones / CPM
 *   (data.medios), Consideration = installs / CPI, Conversion = primeras
 *   compras / CAC, Loyalty = órdenes / CPO (AF data).
 * - Por tipo de campaña: cada campaña con gasto (también _BRD_) con su
 *   nombre corto; NC y órdenes de AF data cruzadas por nombre de campaña
 *   (todas las fuentes, como el Daily).
 *
 * El servidor manda días; la página suma por semana, mes o tipo.
 */
class PanelWeekly
{
    /** Siglas que se dejan en mayúsculas en el nombre automático. */
    private const SIGLAS = ['AND', 'RMK', 'LAL', 'CPA', 'CPM', 'NC', 'BI', 'ASA', 'RTB', 'MGM', 'CRM', 'SMART', 'V2', 'V3', 'V4'];

    /**
     * global: [fecha, gasto, nc, ordenes, installs] (Paid).
     * campanias: [{id, campania, medio, tipo, auto, oculta}].
     * porCampania: [fecha, idx de campanias, gasto, nc, ordenes, installs, impresiones].
     *
     * @return array<string, mixed>
     */
    public function armar(int $paisId, string $pais, int $anio): array
    {
        $cuentaId = CuentaActual::id();
        $desde = "{$anio}-01-01";
        $hasta = "{$anio}-12-31";
        $fuentePaid = config("fuentes.{$pais}.fuente_paid", 'TADA PERFORMANCE');

        // --- Global Paid por día -----------------------------------------------------
        $global = [];
        foreach (DB::table('medios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->whereBetween('fecha', [$desde, $hasta])
            ->whereRaw("UPPER(campania) NOT LIKE ? ESCAPE '!'", ['%!_BRD!_%'])
            ->selectRaw('fecha, SUM(costo) c')->groupBy('fecha')->get() as $f) {
            $global[substr((string) $f->fecha, 0, 10)] = [round((float) $f->c, 2), 0, 0, 0];
        }
        foreach (DB::table('fuentes_appsflyer')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->whereBetween('fecha', [$desde, $hasta])
            ->where('fuente_final', $fuentePaid)
            ->selectRaw('fecha, SUM(nc) nc, SUM(ordenes) o, SUM(installs) i')->groupBy('fecha')->get() as $f) {
            $d = substr((string) $f->fecha, 0, 10);
            $global[$d] = [$global[$d][0] ?? 0, (int) $f->nc, (int) $f->o, (int) $f->i];
        }
        ksort($global);

        // --- Por campaña ---------------------------------------------------------------
        $gastos = DB::table('medios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('fecha, medio, campania, SUM(costo) c, SUM(impresiones) imp')->groupBy('fecha', 'medio', 'campania')->orderBy('campania')->orderBy('fecha')->get();
        $campanias = [];
        $indice = [];
        $porCampania = [];
        foreach ($gastos as $g) {
            $clave = mb_strtolower(trim((string) $g->campania));
            if (! isset($indice[$clave])) {
                $indice[$clave] = count($campanias);
                $campanias[] = ['campania' => trim((string) $g->campania), 'medio' => $g->medio];
            }
            $porCampania[substr((string) $g->fecha, 0, 10).'|'.$indice[$clave]] = [round((float) $g->c, 2), 0, 0, 0, (int) $g->imp];
        }
        if ($indice) {
            foreach (DB::table('fuentes_appsflyer')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->whereBetween('fecha', [$desde, $hasta])
                ->selectRaw('fecha, LOWER(TRIM(campania)) c, SUM(nc) nc, SUM(ordenes) o, SUM(installs) i')->groupBy('fecha', 'c')->get() as $a) {
                if (! isset($indice[$a->c])) {
                    continue;
                }
                $k = substr((string) $a->fecha, 0, 10).'|'.$indice[$a->c];
                $porCampania[$k] = [$porCampania[$k][0] ?? 0, (int) $a->nc, (int) $a->o, (int) $a->i, $porCampania[$k][4] ?? 0];
            }
        }
        ksort($porCampania);

        $editados = DB::table('weekly_tipos')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->get()
            ->keyBy(fn ($t) => mb_strtolower($t->campania));
        $canales = collect(config("canales.{$pais}", []))->keyBy('id');
        foreach ($campanias as $i => $c) {
            $e = $editados[mb_strtolower($c['campania'])] ?? null;
            $auto = self::nombreCorto($c['campania'], $canales[$c['medio']]['nombre'] ?? Str::title((string) $c['medio']));
            $campanias[$i] += [
                'id' => $i,
                'auto' => $auto,
                'tipo' => $e?->tipo ?: $auto,
                'editado' => (bool) $e?->tipo,
                'oculta' => (bool) ($e?->oculta ?? false),
                'color' => $canales[$c['medio']]['color'] ?? 'text-faint',
                // Etapa por el token del nombre (_AWA_ / _CON_ / _CNV_ / _LOY_), como el Daily.
                'funnel' => PanelDaily::funnelDe($c['campania']) ?? 'SIN',
            ];
        }

        $hastaMedios = DB::table('medios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->max('fecha');
        $hastaAf = DB::table('fuentes_appsflyer')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->max('fecha');
        $corte = $hastaMedios && $hastaAf ? min(substr((string) $hastaMedios, 0, 10), substr((string) $hastaAf, 0, 10)) : null;

        $anios = collect([DB::table('medios_diario')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->min('fecha'), $hastaMedios])
            ->filter()->map(fn ($f) => (int) substr((string) $f, 0, 4));

        return [
            'anio' => $anio,
            'anios' => $anios->isEmpty() ? [$anio] : range($anios->max(), $anios->min()),
            'global' => array_map(fn ($d, $v) => [$d, ...$v], array_keys($global), $global),
            'campanias' => $campanias,
            'porCampania' => array_map(function ($k, $v) {
                [$d, $i] = explode('|', $k);

                return [$d, (int) $i, ...$v];
            }, array_keys($porCampania), $porCampania),
            'corte' => $corte,
            'fuentePaid' => $fuentePaid,
        ];
    }

    /**
     * "PAN_..._EPA_FB-AWARENESS-BROAD" + "Meta" → "Meta - Awareness Broad".
     * Toma lo que va después de _EPA_ (o el último bloque) y quita el
     * prefijo de plataforma (FB-, TKT-, IG-...).
     */
    public static function nombreCorto(string $campania, string $medio): string
    {
        $c = trim($campania);
        $pos = strripos($c, '_EPA_');
        $resto = $pos !== false ? substr($c, $pos + 5) : (str_contains($c, '_') ? substr($c, strrpos($c, '_') + 1) : $c);
        $resto = preg_replace('/^(FB|META|IG|TKT|TIKTOK|GG|GOOGLE|ASA|TW|X)[\-_]/i', '', $resto);
        $palabras = array_filter(preg_split('/[\-_\s]+/', $resto));
        $texto = implode(' ', array_map(function ($p) {
            $u = mb_strtoupper($p);
            if ($u === 'IOS') {
                return 'iOS';
            }

            return in_array($u, self::SIGLAS, true) || preg_match('/\d/', $p) ? $u : Str::ucfirst(mb_strtolower($p));
        }, $palabras));

        return mb_substr(trim("{$medio} - {$texto}", ' -'), 0, 120);
    }
}
