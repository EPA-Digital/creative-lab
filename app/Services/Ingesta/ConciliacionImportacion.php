<?php

namespace App\Services\Ingesta;

use App\Models\CuentaPublicitaria;
use Illuminate\Support\Collection;

/**
 * "Double check" de una importación, por plataforma (pedido explícito
 * 2026-10-05) -- se arma en ImportadorDatos::procesarPlataforma() mientras
 * se persiste cada creativo y queda guardado en importaciones.conciliacion.
 * Responde tres preguntas:
 *   - ¿Cuánto trajo cada cuenta? (anuncios, costo, NC/órdenes de AppsFlyer
 *     y lo que le tocó del total real). Una cuenta activa con 0 anuncios
 *     suele ser un ID mal cargado o sin acceso.
 *   - ¿Qué NC de AppsFlyer cayó en anuncios SIN costo en ninguna cuenta?
 *     Agrupado por campaña -- delata una cuenta que falta agregar.
 *   - ¿Cuánto del reparto se fue a DTC no paid (MLM/NON)? Informativo:
 *     entra al reparto como todos los canales.
 * Y si lo repartido cuadra con el total tecleado.
 */
class ConciliacionImportacion
{
    private const MAX_CAMPANIAS = 15;

    /** @var array<int, array<string, mixed>> */
    private array $cuentas = [];

    /** @var array{ads: int, af_nc: float, af_orders: float, campanias: array<string, array{campania: string, ads: int, af_nc: float, af_orders: float}>} */
    private array $sinCuenta;

    /** @var array{ads: int, af_nc: float, af_orders: float, campanias: array<string, array{campania: string, ads: int, af_nc: float, af_orders: float}>} */
    private array $dtcNoPaid;

    private int $ncRepartido = 0;

    private int $ordersRepartido = 0;

    /**
     * @param  Collection<int, CuentaPublicitaria>  $cuentas
     */
    public function __construct(Collection $cuentas, private readonly mixed $ncTecleado, private readonly mixed $ordersTecleado)
    {
        foreach ($cuentas as $cuenta) {
            $this->cuentas[$cuenta->id] = [
                'id' => $cuenta->id,
                'nombre' => $cuenta->nombre,
                'cuenta_id' => $cuenta->cuenta_id,
                'tipo' => $cuenta->tipo,
                'cuenta_en_venta_real' => $cuenta->cuenta_en_venta_real,
                'ads' => 0,
                'costo' => 0.0,
                'af_nc' => 0.0,
                'af_orders' => 0.0,
                'nc' => 0,
                'orders' => 0,
            ];
        }
        $this->sinCuenta = self::grupoVacio();
        $this->dtcNoPaid = self::grupoVacio();
    }

    public function registrar(array $card, bool $participa, ?int $nc, ?int $orders): void
    {
        $afNc = (float) ($card['newCustomers'] ?? 0);
        $afOrders = (float) ($card['orders'] ?? 0);
        $cuentaId = $card['cuentaPublicitariaId'] ?? null;

        if ($cuentaId !== null && isset($this->cuentas[$cuentaId])) {
            $c = &$this->cuentas[$cuentaId];
            $c['ads']++;
            $c['costo'] += (float) ($card['cost'] ?? 0);
            $c['af_nc'] += $afNc;
            $c['af_orders'] += $afOrders;
            $c['nc'] += $nc ?? 0;
            $c['orders'] += $orders ?? 0;
            unset($c);
        } else {
            self::acumular($this->sinCuenta, $card, $afNc, $afOrders);
        }

        if (ClasificadorNombres::esDtcNoPaid($card['campaignName'] ?? null)) {
            self::acumular($this->dtcNoPaid, $card, $afNc, $afOrders);
        }

        if ($participa) {
            $this->ncRepartido += $nc ?? 0;
            $this->ordersRepartido += $orders ?? 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $sinActividadDescartados): array
    {
        return [
            'totales' => [
                'nc_tecleado' => self::numeroONull($this->ncTecleado),
                'orders_tecleado' => self::numeroONull($this->ordersTecleado),
                'nc_repartido' => $this->ncRepartido,
                'orders_repartido' => $this->ordersRepartido,
            ],
            'cuentas' => array_values(array_map(fn (array $c) => [...$c, 'costo' => round($c['costo'], 2)], $this->cuentas)),
            'sin_cuenta' => self::cerrarGrupo($this->sinCuenta),
            'dtc_no_paid' => self::cerrarGrupo($this->dtcNoPaid),
            'sin_actividad_descartados' => $sinActividadDescartados,
        ];
    }

    private static function grupoVacio(): array
    {
        return ['ads' => 0, 'af_nc' => 0.0, 'af_orders' => 0.0, 'campanias' => []];
    }

    private static function acumular(array &$grupo, array $card, float $afNc, float $afOrders): void
    {
        $campania = ($card['campaignName'] ?? '') !== '' ? $card['campaignName'] : '(sin campaña)';
        $grupo['ads']++;
        $grupo['af_nc'] += $afNc;
        $grupo['af_orders'] += $afOrders;
        $grupo['campanias'][$campania] ??= ['campania' => $campania, 'ads' => 0, 'af_nc' => 0.0, 'af_orders' => 0.0];
        $grupo['campanias'][$campania]['ads']++;
        $grupo['campanias'][$campania]['af_nc'] += $afNc;
        $grupo['campanias'][$campania]['af_orders'] += $afOrders;
    }

    private static function cerrarGrupo(array $grupo): array
    {
        $campanias = array_values($grupo['campanias']);
        usort($campanias, fn (array $a, array $b) => [$b['af_nc'], $b['af_orders']] <=> [$a['af_nc'], $a['af_orders']]);

        return [...$grupo, 'campanias' => array_slice($campanias, 0, self::MAX_CAMPANIAS)];
    }

    private static function numeroONull(mixed $valor): ?float
    {
        return ($valor === null || $valor === '' || ! is_numeric($valor)) ? null : (float) $valor;
    }
}
