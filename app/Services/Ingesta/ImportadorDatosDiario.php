<?php

namespace App\Services\Ingesta;

use App\Models\Creativo;
use App\Models\Pais;
use App\Models\Resultado;
use App\Models\ResultadoDiario;
use InvalidArgumentException;

/**
 * Pipeline de importación DIARIA (2026-08-27, ver plan del selector de
 * fecha) -- alimentado por el export de Snowflake (costo/impresiones/
 * clicks de Meta ya cruzado con AppsFlyer, por día, con NC/Orders reales
 * ya prorrateados por fila). A diferencia de ImportadorDatos::importar()
 * (CSV de AppsFlyer + pull en vivo a la API de costos, UN mes a la vez):
 *
 * - No hace ninguna llamada a la API de Meta/TikTok -- el costo ya viene
 *   en el archivo.
 * - No pide desde/hasta -- la columna Date de cada fila ya lo decide, y un
 *   mismo archivo puede traer varios meses (a diferencia de importar(),
 *   que exige que desde/hasta caigan en el mismo mes).
 * - No reprorratea NC/Orders -- ncReal/ordersReal del parser se persisten
 *   tal cual.
 *
 * Persiste en `resultados_diarios` (fuente fina) Y recalcula el `Resultado`
 * mensual de cada (creativo, mes) tocado sumando sus días -- así
 * Creativos/Inteligencia/motor.js (que solo leen `resultados`) ven un
 * total mensual más preciso sin que nadie los tenga que tocar.
 */
class ImportadorDatosDiario
{
    /**
     * @return array{creativosTocados: int, diasTocados: int, mesesTocados: int, excluidos: int, sinActividadDescartados: int, problemas: int}
     */
    public static function importar(string $archivo, string $paisSlug): array
    {
        $config = config("paises.{$paisSlug}");
        if (! $config) {
            throw new InvalidArgumentException("País \"{$paisSlug}\" no existe en config/paises.php.");
        }
        $pais = Pais::where('codigo', $config['codigo'])->first();
        if (! $pais) {
            throw new InvalidArgumentException("País \"{$config['codigo']}\" no existe en la tabla paises -- corré el PaisSeeder primero.");
        }

        $csv = SnowflakeCsvParser::parse(file_get_contents($archivo));

        $excluidos = 0;
        $sinActividadDescartados = 0;
        $porAdId = [];
        foreach ($csv['filas'] as $fila) {
            $r = ClasificadorNombres::parsearNombre($fila['campaign'], $fila['adRaw']);
            if ($r['plataforma'] === null) {
                $excluidos++;

                continue;
            }
            if (self::esSinActividad($fila)) {
                $sinActividadDescartados++;

                continue;
            }
            $porAdId[$fila['adId']]['clasificacion'] = $r;
            $porAdId[$fila['adId']]['dias'][] = $fila;
        }

        // Thumbnail: una URL por Ad ID (repetida en todas sus filas), se
        // cachea UNA vez por ad, no por fila -- mismo criterio que
        // EnriquecedorCostosMeta::enriquecer.
        $thumbnailPorAdId = [];
        foreach ($porAdId as $adId => $grupo) {
            foreach ($grupo['dias'] as $dia) {
                if ($dia['thumbnailUrl']) {
                    $thumbnailPorAdId[$adId] = $dia['thumbnailUrl'];

                    break;
                }
            }
        }
        $thumbnailLocalPorAdId = (new ImagenCacheService)->cachearVarias(
            $thumbnailPorAdId,
            fn (string $adId) => "snowflake-{$adId}"
        );

        // imagenActualPorAdId: valor YA guardado antes de esta corrida --
        // necesario para no pisar una imagen curada a mano contra el Drive
        // real (ver ImagenCacheService::esImagenCurada) con el thumbnail
        // borroso de esta importación. Una sola query para todos los ad_id
        // tocados, no N+1 dentro del loop de abajo.
        $imagenActualPorAdId = Creativo::where('pais_id', $pais->id)
            ->whereIn('ad_id', array_keys($porAdId))
            ->pluck('imagen_url', 'ad_id');

        $creativosTocados = 0;
        $diasTocados = 0;
        $mesesTocados = [];

        foreach ($porAdId as $adId => $grupo) {
            $r = $grupo['clasificacion'];
            $funnel = in_array($r['funnel'], ['AWA', 'CONS', 'CNV', 'LOY'], true) ? $r['funnel'] : null;
            $primerDia = $grupo['dias'][0];
            $arte = $r['arte'];
            $nombreCompleto = $arte ?: ($primerDia['adRaw'] ?: "Ad {$adId}");

            $imagenActual = $imagenActualPorAdId[$adId] ?? null;
            $imagenUrl = ImagenCacheService::esImagenCurada($imagenActual)
                ? $imagenActual
                : ($thumbnailLocalPorAdId[$adId] ?? null);

            $creativo = Creativo::updateOrCreate(
                ['ad_id' => $adId, 'pais_id' => $pais->id],
                [
                    'nombre_comun' => $arte,
                    'nombre_completo' => $nombreCompleto,
                    'nombre_campania' => $primerDia['campaign'] !== '' ? $primerDia['campaign'] : null,
                    'imagen_url' => $imagenUrl,
                    'plataforma' => $r['plataforma'],
                    'funnel' => $funnel,
                    'tipo_cuenta' => $r['tipoCuenta'] ?? 'DTC',
                    'formato' => $r['formato'],
                ]
            );
            $creativosTocados++;

            foreach ($grupo['dias'] as $dia) {
                ResultadoDiario::updateOrCreate(
                    ['creativo_id' => $creativo->id, 'fecha' => $dia['fecha']],
                    [
                        'cost' => $dia['cost'],
                        'impressions' => $dia['impressions'],
                        'clicks' => $dia['clicks'],
                        'installs' => $dia['installs'],
                        'nc' => $dia['nc'],
                        'repurchases' => $dia['repurchases'],
                        'orders' => $dia['orders'],
                        'nc_real' => $dia['ncReal'],
                        'orders_real' => $dia['ordersReal'],
                    ]
                );
                $diasTocados++;
                $mesesTocados[$creativo->id.'|'.substr($dia['fecha'], 0, 7)] = [$creativo->id, substr($dia['fecha'], 0, 7)];
            }
        }

        foreach ($mesesTocados as [$creativoId, $mes]) {
            self::recalcularResultadoMensual($creativoId, $mes);
        }

        return [
            'creativosTocados' => $creativosTocados,
            'diasTocados' => $diasTocados,
            'mesesTocados' => count($mesesTocados),
            'excluidos' => $excluidos,
            'sinActividadDescartados' => $sinActividadDescartados,
            'problemas' => count($csv['problemas']),
        ];
    }

    /**
     * Sin actividad real ese día -- se omite (nunca se persiste una fila
     * de puro 0), mismo criterio conceptual que
     * ImportadorDatos::esSinActividad pero evaluado por FILA DIARIA en vez
     * de por mes. Acá no hay riesgo de perder venta real al descartar
     * (a diferencia del pipeline mensual, arreglado hoy): ncReal/
     * ordersReal ya vienen resueltos por fila desde el CSV, nunca se
     * reparten desde un total compartido que dependa de qué filas
     * sobreviven.
     */
    private static function esSinActividad(array $fila): bool
    {
        return $fila['cost'] <= 0
            && $fila['impressions'] <= 0
            && $fila['clicks'] <= 0
            && $fila['installs'] <= 0
            && $fila['orders'] <= 0
            && $fila['nc'] <= 0;
    }

    /**
     * Recalcula el Resultado MENSUAL de un creativo sumando sus
     * resultados_diarios de ese mes -- rollup, ver docblock de la clase.
     * nc/orders finales prefieren el real (ya prorrateado) sobre el crudo
     * de AppsFlyer, fila por fila, antes de sumar -- nunca mezcla "unos
     * días con real, otros sin" perdiendo el real disponible.
     */
    private static function recalcularResultadoMensual(int $creativoId, string $mes): void
    {
        // Rango de fechas en vez de una función de fecha específica del
        // motor (strftime en SQLite, DATE_FORMAT en MySQL) -- portable
        // entre el MySQL real y el SQLite de los tests sin ninguna rama.
        $primerDia = "{$mes}-01";
        $ultimoDia = date('Y-m-t', strtotime($primerDia));

        $dias = ResultadoDiario::where('creativo_id', $creativoId)
            ->whereBetween('fecha', [$primerDia, $ultimoDia])
            ->get();

        if ($dias->isEmpty()) {
            return;
        }

        $cost = (float) $dias->sum('cost');
        $impressions = (int) $dias->sum('impressions');
        $clicks = (int) $dias->sum('clicks');
        $installs = (int) $dias->sum('installs');
        $reorders = (int) $dias->sum('repurchases');
        $nc = (int) round($dias->sum(fn ($d) => $d->nc_real ?? $d->nc));
        $orders = (int) round($dias->sum(fn ($d) => $d->orders_real ?? $d->orders));
        $tieneVentaReal = $dias->every(fn ($d) => $d->nc_real !== null && $d->orders_real !== null);

        $fechaInicio = $dias->min('fecha');
        $fechaFin = $dias->max('fecha');

        Resultado::updateOrCreate(
            ['creativo_id' => $creativoId, 'mes' => $mes],
            [
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'cost' => $cost,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'installs' => $installs,
                'cpi' => ($installs > 0 && $cost > 0) ? round($cost / $installs, 2) : null,
                'nc' => $nc,
                'cac' => ($nc > 0 && $cost > 0) ? round($cost / $nc, 2) : null,
                'orders' => $orders,
                'reorders' => $reorders,
                'cpo' => ($orders > 0 && $cost > 0) ? round($cost / $orders, 2) : null,
                'tiene_venta_real' => $tieneVentaReal,
                'tiene_meta' => true,
                'ctr' => ($impressions > 0) ? round(($clicks / $impressions) * 100, 2) : null,
                'cpm' => ($impressions > 0 && $cost > 0) ? round(($cost / $impressions) * 1000, 2) : null,
            ]
        );
    }
}
