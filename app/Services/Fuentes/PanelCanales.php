<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Channel (2026-11-17, pedido explícito): installs, new customers y órdenes
 * por canal (config/canales.php), por mes o por semana, año contra año --
 * como channel.monthly.2026 / channel.weekly.2026.
 *
 * - Cada canal suma AF data por media source. Lo que el equipo capturó a
 *   mano (canales_historico, hoy 2025) llena los periodos que AF data no
 *   cubre; si después se sube AF data de esos periodos, gana AF data.
 * - Por día solo hay AF data (la hoja no captura días).
 * - Semanas como en la hoja: la 1 va del 1 de enero al primer domingo; de
 *   ahí, lunes a domingo, cortadas en el 31 de diciembre.
 */
class PanelCanales
{
    /**
     * @param  list<array{id: string, media: list<string>}>  $canales
     * @return array{anios: list<int>, valores: object, origen: object, desde: ?string, hasta: ?string}
     */
    public function armar(int $paisId, array $canales): array
    {
        $cuentaId = CuentaActual::id();

        // media source (minúsculas) → canal
        $canalDe = [];
        foreach ($canales as $c) {
            foreach ($c['media'] as $m) {
                $canalDe[mb_strtolower($m)] = $c['id'];
            }
        }

        $afVal = [];
        $desde = null;
        $hasta = null;

        $af = DB::table('fuentes_appsflyer')
            ->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)
            ->groupBy('fecha', 'media_source')
            ->selectRaw('fecha, media_source, SUM(installs) AS installs, SUM(nc) AS nc, SUM(ordenes) AS ordenes')
            ->get();
        foreach ($af as $f) {
            $canal = $canalDe[mb_strtolower($f->media_source)] ?? null;
            $fecha = substr((string) $f->fecha, 0, 10);
            $desde = min($desde ?? $fecha, $fecha);
            $hasta = max($hasta ?? $fecha, $fecha);
            if (! $canal) {
                continue;
            }
            $dia = CarbonImmutable::parse($fecha);
            $suma = [(int) $f->installs, (int) $f->nc, (int) $f->ordenes];
            self::sumar($afVal[$dia->year]['mes'][$dia->month][$canal], $suma);
            self::sumar($afVal[$dia->year]['semana'][self::semana($dia)][$canal], $suma);
            self::sumar($afVal[$dia->year]['dia'][$dia->format('m-d')][$canal], $suma);
        }

        // Lo que el equipo capturó a mano en la hoja (hoy 2025).
        $manual = [];
        foreach (DB::table('canales_historico')->where('cuenta_id', $cuentaId)->where('pais_id', $paisId)->get() as $h) {
            $manual[$h->anio][$h->tipo][$h->periodo][$h->canal] = [(int) $h->installs, (int) $h->nc, (int) $h->ordenes];
        }

        // Por periodo: AF data si la carga lo cubre desde su inicio (así,
        // al subir AF data de un año viejo, reemplaza a lo capturado a mano);
        // si no, lo capturado a mano; si tampoco hay, lo que haya de AF data.
        $anios = array_unique([
            ...array_keys($manual),
            ...($desde ? range((int) substr($desde, 0, 4), (int) substr($hasta, 0, 4)) : []),
            (int) now()->year, // el año en curso se ve aunque no tenga datos
        ]);
        sort($anios);
        $valores = [];
        $origen = [];
        foreach ($anios as $anio) {
            foreach (['mes', 'semana'] as $tipo) {
                foreach (self::periodos($anio, $tipo) as $p => [$inicio, $fin]) {
                    $tocaAf = $desde && $inicio <= $hasta && $fin >= $desde;
                    $cubreAf = $tocaAf && $desde <= $inicio;
                    $aMano = $manual[$anio][$tipo][$p] ?? null;
                    if ($cubreAf || ($tocaAf && ! $aMano)) {
                        $valores[$anio][$tipo][$p] = (object) ($afVal[$anio][$tipo][$p] ?? []);
                        $origen[$anio][$tipo][$p] = $fin > $hasta ? 'parcial' : 'af';
                    } elseif ($aMano) {
                        $valores[$anio][$tipo][$p] = $aMano;
                        $origen[$anio][$tipo][$p] = 'manual';
                    }
                }
            }
        }

        // Por día: solo AF data (la hoja no trae días), cada día del rango cargado.
        if ($desde) {
            for ($d = CarbonImmutable::parse($desde); $d->toDateString() <= $hasta; $d = $d->addDay()) {
                $md = $d->format('m-d');
                $valores[$d->year]['dia'][$md] = (object) ($afVal[$d->year]['dia'][$md] ?? []);
                $origen[$d->year]['dia'][$md] = 'af';
            }
        }

        return ['anios' => $anios, 'valores' => (object) $valores, 'origen' => (object) $origen, 'desde' => $desde, 'hasta' => $hasta];
    }

    /**
     * Rango de cada periodo del año (fechas Y-m-d).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function periodos(int $anio, string $tipo): array
    {
        $ene1 = CarbonImmutable::create($anio, 1, 1);
        $dic31 = $ene1->endOfYear()->startOfDay();
        $salida = [];
        if ($tipo === 'mes') {
            for ($m = 1; $m <= 12; $m++) {
                $inicio = $ene1->month($m);
                $salida[$m] = [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()];
            }

            return $salida;
        }
        $desfase = $ene1->dayOfWeekIso - 1;
        for ($k = 1; ; $k++) {
            $inicio = $k === 1 ? $ene1 : $ene1->addDays(7 * ($k - 1) - $desfase);
            if ($inicio > $dic31) {
                break;
            }
            $fin = $ene1->addDays(7 * $k - $desfase - 1);
            $salida[$k] = [$inicio->toDateString(), min($fin, $dic31)->toDateString()];
        }

        return $salida;
    }

    /** Semana de la hoja: 1 = del 1 de enero al primer domingo. */
    public static function semana(CarbonImmutable $dia): int
    {
        $desfase = $dia->startOfYear()->dayOfWeekIso - 1; // lunes = 0

        return intdiv($dia->dayOfYear - 1 + $desfase, 7) + 1;
    }

    /** @param list<int>|null $acc @param list<int> $suma */
    private static function sumar(?array &$acc, array $suma): void
    {
        $acc ??= [0, 0, 0];
        foreach ($suma as $i => $v) {
            $acc[$i] += $v;
        }
    }
}
