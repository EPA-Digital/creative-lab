<?php

namespace App\Services\Fuentes;

use App\Support\CuentaActual;
use Illuminate\Support\Facades\DB;

/**
 * Funnel de conversión (2026-11-17, pedido explícito; réplica de las hojas
 * funnel.2026 y funnel.medio.2026 de "TaDa ECU | Daily Cuentas"): los
 * eventos de la app por día, sacados de AF data (fuentes_appsflyer, la
 * misma tabla que la hoja llama af.data.2026).
 *
 * La hoja hace SUMIFS sobre af.data.2026 por rango de fechas y:
 * - Total negocio: todas las filas.
 * - Paid: partner (= fuente final) "TADA PERFORMANCE".
 * - Orgánico: partner "Organic".
 * - Por medio: Paid + media source.
 * Total Order = Order Submitted + First Order. Las tasas se calculan en la
 * página (resources/js/Pages/Funnel.vue) sobre los totales del periodo.
 *
 * Se manda por día y la página agrupa por mes / semana / día, así cambiar
 * de granularidad o de segmento no vuelve al servidor.
 */
class FunnelFuentes
{
    /**
     * Eventos del funnel en orden de etapa. Los que viven en `otros` llevan
     * los encabezados posibles del pivot (cada país nombra distinto las
     * sesiones).
     */
    public const EVENTOS = [
        'installs' => ['columna' => 'installs'],
        'app_opened' => ['otros' => ['App Opened']],
        'sessions' => ['otros' => ['Activity Sessions', 'Sessions']],
        'product_viewed' => ['otros' => ['Product Details Viewed']],
        'product_added' => ['otros' => ['Product Added']],
        'payment' => ['otros' => ['Payment Method Selected']],
        'order_submitted' => ['columna' => 'recompras'],
        'first_order' => ['columna' => 'nc'],
        'total_order' => ['columna' => 'ordenes'],
    ];

    /**
     * @param  array<string, string>  $canalesPaid  media source → nombre del canal (config fuentes.{pais}.canales_paid)
     * @return array{fuentes: list<string>, medios: list<array{id: string, label: string}>, dias: list<list<int|string>>, fuentePaid: string}
     */
    public function armar(int $paisId, string $fuentePaid, array $canalesPaid): array
    {
        $sumas = [];
        foreach (self::EVENTOS as $clave => $evento) {
            $sumas[] = 'SUM('.(isset($evento['columna']) ? $evento['columna'] : self::desdeOtros($evento['otros'])).') AS '.$clave;
        }

        // Dentro de Paid se separa por media source (funnel por medio); fuera
        // de Paid basta la fuente final.
        $filas = DB::table('fuentes_appsflyer')
            ->where('cuenta_id', CuentaActual::id())->where('pais_id', $paisId)
            ->selectRaw('fecha, fuente_final, CASE WHEN fuente_final = ? THEN media_source ELSE \'\' END AS medio, '.implode(', ', $sumas), [$fuentePaid])
            ->groupBy('fecha', 'fuente_final', 'medio')
            ->orderBy('fecha')
            ->get();

        $fuentes = [];
        $medios = [];
        $dias = [];
        foreach ($filas as $f) {
            $fuente = array_search($f->fuente_final, $fuentes, true);
            if ($fuente === false) {
                $fuentes[] = $f->fuente_final;
                $fuente = count($fuentes) - 1;
            }
            $medio = -1;
            if ($f->medio !== '') {
                $medio = array_search($f->medio, $medios, true);
                if ($medio === false) {
                    $medios[] = $f->medio;
                    $medio = count($medios) - 1;
                }
            }
            $fecha = substr((string) $f->fecha, 0, 10);
            $dias[] = [$fecha, $fuente, $medio, ...array_map(fn ($k) => (int) round((float) $f->{$k}), array_keys(self::EVENTOS))];
        }

        // Medios en el orden de la config (Facebook, Google, ...); los que no
        // están en ella, al final.
        $orden = array_flip(array_keys($canalesPaid));
        $listaMedios = array_map(fn ($m, $i) => ['i' => $i, 'id' => $m, 'label' => $canalesPaid[$m] ?? $m], $medios, array_keys($medios));
        usort($listaMedios, fn ($a, $b) => [$orden[$a['id']] ?? PHP_INT_MAX, $a['label']] <=> [$orden[$b['id']] ?? PHP_INT_MAX, $b['label']]);

        return [
            'fuentePaid' => $fuentePaid,
            'fuentes' => $fuentes,
            'medios' => $listaMedios,
            'dias' => $dias,
        ];
    }

    /** COALESCE de los encabezados posibles dentro del JSON `otros`. @param list<string> $claves */
    private static function desdeOtros(array $claves): string
    {
        $partes = array_map(fn ($c) => "JSON_EXTRACT(otros, '$.\"".str_replace(['"', "'"], '', $c)."\"')", $claves);

        return 'COALESCE('.implode(', ', $partes).', 0)';
    }
}
