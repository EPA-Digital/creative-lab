<?php

namespace App\Services\Divisas;

use App\Models\TipoCambio;
use RuntimeException;

/**
 * Todo el dashboard reporta en USD (2026-11-11). El gasto de una cuenta en
 * otra moneda (TikTok México: MXN) se convierte al importar, día por día,
 * con el tipo de cambio vigente ese día (tabla tipos_cambio).
 */
class Divisas
{
    public const MONEDA_REPORTE = 'USD';

    /** @var array<string, list<array{desde: string, tasa: float}>> */
    private array $tasas = [];

    /** Unidades de $moneda por 1 USD vigentes en $fecha (YYYY-MM-DD). */
    public function tasa(string $moneda, string $fecha): float
    {
        $moneda = strtoupper($moneda);
        if ($moneda === self::MONEDA_REPORTE) {
            return 1.0;
        }

        $this->tasas[$moneda] ??= TipoCambio::where('moneda', $moneda)->orderBy('vigente_desde')->get()
            ->map(fn (TipoCambio $t) => ['desde' => $t->vigente_desde->toDateString(), 'tasa' => $t->por_usd])
            ->all();
        $tasas = $this->tasas[$moneda];
        if ($tasas === []) {
            throw new RuntimeException("Falta el tipo de cambio de {$moneda} a USD: agrégalo en Ajustes → Tipo de cambio y vuelve a importar.");
        }

        // El vigente: el último que empezó en o antes de $fecha. Antes del
        // primero registrado se usa el primero.
        $vigente = $tasas[0]['tasa'];
        foreach ($tasas as $t) {
            if ($t['desde'] <= $fecha) {
                $vigente = $t['tasa'];
            }
        }

        return $vigente;
    }

    public function aUsd(float $monto, string $moneda, string $fecha): float
    {
        return $monto / $this->tasa($moneda, $fecha);
    }

    /**
     * Pasa a USD las filas de costo de una cuenta (salida de
     * EnriquecedorCostosMeta/Tiktok::enriquecer): cada día con su tipo de
     * cambio y el total como la suma de los días. Sin detalle por día, el
     * total se convierte con el tipo de cambio de $fechaRespaldo.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    public function filasAUsd(array $filas, ?string $moneda, string $fechaRespaldo): array
    {
        if ($moneda === null || strtoupper($moneda) === self::MONEDA_REPORTE) {
            return $filas;
        }

        return array_map(function (array $fila) use ($moneda, $fechaRespaldo) {
            $dias = [];
            foreach ($fila['dias'] ?? [] as $fecha => $dia) {
                $dias[$fecha] = [...$dia, 'cost' => round($this->aUsd($dia['cost'], $moneda, $fecha), 2)];
            }
            $cost = $dias !== []
                ? round(array_sum(array_column($dias, 'cost')), 2)
                : round($this->aUsd((float) ($fila['cost'] ?? 0), $moneda, $fechaRespaldo), 2);

            return [...$fila, 'cost' => $cost, 'dias' => $dias];
        }, $filas);
    }
}
