<?php

namespace App\Console\Commands;

use App\Models\AppsflyerApp;
use App\Models\Cuenta;
use App\Models\Pais;
use App\Services\Ingesta\ImportadorDatos;
use App\Support\CuentaActual;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reemplaza el paso manual de "descargar el CSV de AppsFlyer y subirlo" por
 * un pull en vivo al Master API (EnriquecedorAppsFlyerApi) -- mismo pipeline
 * de ahí en adelante que `importar:csv` (ImportadorDatos::ejecutarPipeline).
 * `importar:csv` queda intacto en paralelo como fallback manual (API caída,
 * corrección puntual con un CSV curado a mano).
 *
 * Los totales manuales por plataforma SOLO tienen sentido con un {pais}
 * explícito -- en modo multi-país (pensado para un refresh recurrente, sin
 * saber los totales de cierre de mes de cada cuenta) se rechazan: así el
 * modo recurrente nunca pasa totales por diseño, no por descuido, y
 * ImportadorDatos::resolverNcOrdersFinal siempre tiene una razón clara para
 * preservar en vez de recalcular.
 *
 * Multi-cuenta: con {pais} explícito corre en --cuenta (TaDa si se omite,
 * como siempre). Sin {pais} recorre las cuentas activas con AppsFlyer y
 * token propio -- una cuenta sin token se salta con aviso, no es un error.
 */
class ImportarAppsFlyerApi extends Command
{
    protected $signature = 'importar:appsflyer-api
        {pais? : Slug de config/paises.php; si se omite, corre para todos los países habilitados con apps de AppsFlyer configuradas}
        {--desde= : YYYY-MM-DD; si se omite, primer día del mes de --hasta}
        {--hasta= : YYYY-MM-DD; si se omite, hoy menos el buffer de atribución configurado}
        {--nc-total-real-meta= : NC total real de Meta para el rango (solo válido con {pais} explícito)}
        {--orders-total-real-meta= : Orders total real de Meta para el rango (solo válido con {pais} explícito)}
        {--nc-total-real-tiktok= : NC total real de TikTok para el rango (solo válido con {pais} explícito)}
        {--orders-total-real-tiktok= : Orders total real de TikTok para el rango (solo válido con {pais} explícito)}
        {--buffer-dias= : Override puntual del buffer de atribución de AppsFlyer configurado}
        {--cuenta= : Slug de la cuenta -- con país, TaDa si se omite; sin país, todas las cuentas activas con token}';

    protected $description = 'Trae AppsFlyer vía API (en vez de CSV manual) + costos de Meta/TikTok, calcula venta real y persiste en creativos/resultados';

    public function handle(): int
    {
        // ImagenCacheService::cachearVarias ya acota a 25 descargas
        // concurrentes, pero el 128M default de PHP-CLI sigue quedando
        // corto para cuentas con muchas imágenes distintas en simultáneo
        // (confirmado 2026-08-12: memory_limit exhausted real corriendo
        // México). Mismo criterio que set_time_limit(0) en
        // ImportarDatosController -- el default de PHP no está pensado
        // para este volumen real.
        ini_set('memory_limit', '512M');

        $paisSlug = $this->argument('pais');
        $totalesOptions = ['nc-total-real-meta', 'orders-total-real-meta', 'nc-total-real-tiktok', 'orders-total-real-tiktok'];

        if (! $paisSlug) {
            foreach ($totalesOptions as $opt) {
                if ($this->option($opt) !== null) {
                    $this->error("--{$opt} no es válido sin especificar un país -- los totales reales son por país+plataforma, no tiene sentido en modo multi-país.");

                    return self::FAILURE;
                }
            }

            return $this->correrTodasLasCuentas();
        }

        $cuenta = Cuenta::where('slug', $this->option('cuenta') ?: Cuenta::SLUG_TADA)->first();
        if (! $cuenta) {
            $this->error("No existe la cuenta \"{$this->option('cuenta')}\".");

            return self::FAILURE;
        }

        return CuentaActual::usar($cuenta, fn () => $this->correrUnPais($paisSlug, [
            'nc-total-real-meta' => $this->option('nc-total-real-meta'),
            'orders-total-real-meta' => $this->option('orders-total-real-meta'),
            'nc-total-real-tiktok' => $this->option('nc-total-real-tiktok'),
            'orders-total-real-tiktok' => $this->option('orders-total-real-tiktok'),
        ]));
    }

    private function correrTodasLasCuentas(): int
    {
        $cuentas = Cuenta::where('activa', true)->where('fuente_mmp', true)
            ->when($this->option('cuenta'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        if ($cuentas->isEmpty()) {
            $this->warn('Ninguna cuenta activa usa AppsFlyer -- nada para correr.');

            return self::SUCCESS;
        }

        $resultado = self::SUCCESS;
        foreach ($cuentas as $cuenta) {
            $this->line("=== {$cuenta->nombre} ===");
            if (! $cuenta->credencial('appsflyer')?->listaParaImportar()) {
                $this->warn("{$cuenta->nombre} no tiene token de AppsFlyer ni BigQuery configurado -- se salta (agrégalo en Cuentas → editar).");

                continue;
            }
            if (CuentaActual::usar($cuenta, fn () => $this->correrTodosLosPaises()) !== self::SUCCESS) {
                $resultado = self::FAILURE;
            }
        }

        return $resultado;
    }

    /**
     * @param  array{nc-total-real-meta: ?string, orders-total-real-meta: ?string, nc-total-real-tiktok: ?string, orders-total-real-tiktok: ?string}  $totales
     */
    private function correrUnPais(string $paisSlug, array $totales): int
    {
        [$desde, $hasta, $bufferDias] = $this->resolverRango();

        if ($this->option('hasta') && $this->option('hasta') > now()->subDays($bufferDias)->toDateString()) {
            $this->warn("Estás importando datos hasta {$hasta} -- los últimos {$bufferDias} día(s) pueden estar subcontados por lag de atribución de AppsFlyer (las conversiones siguen llegando después del install).");
        }

        $this->info("Trayendo AppsFlyer vía API + costos de Meta/TikTok para \"{$paisSlug}\" ({$desde}..{$hasta})...");

        try {
            // Sin token/eventos en la cuenta, ImportadorDatos lanza
            // RuntimeException con el mensaje para el usuario.
            $resumen = ImportadorDatos::importarDesdeApi(
                $paisSlug, $desde, $hasta,
                $totales['nc-total-real-meta'], $totales['orders-total-real-meta'],
                $totales['nc-total-real-tiktok'], $totales['orders-total-real-tiktok'],
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($resumen['esRangoParcial']) {
            $this->warn('Rango parcial -- ncReal/ordersReal/cac/cpo quedan null para creativos nuevos (no se pueden prorratear a un sub-rango); los que ya tenían venta_real de una corrida anterior la preservan.');
        }
        if ($resumen['problemas'] > 0) {
            $this->warn("{$resumen['problemas']} fila(s) con problemas en la respuesta de AppsFlyer -- revisar a mano.");
        }
        if ($resumen['excluidos'] > 0) {
            $this->line("{$resumen['excluidos']} ad(s) excluido(s) (sin bloque FB-/TKT- reconocible).");
        }
        if ($resumen['sinActividadDescartados'] > 0) {
            $this->line("{$resumen['sinActividadDescartados']} ad(s) descartado(s) por no tener actividad real (cost/impressions/clicks/installs = 0).");
        }

        $this->info("Listo: {$resumen['creativosTocados']} creativo(s) creados/actualizados, {$resumen['resultadosTocados']} resultado(s) del mes guardados, {$resumen['tieneMetaTrue']} con match real de costo (tiene_meta=true).");

        return self::SUCCESS;
    }

    private function correrTodosLosPaises(): int
    {
        [$desde, $hasta] = $this->resolverRango();
        $sinTotales = ['nc-total-real-meta' => null, 'orders-total-real-meta' => null, 'nc-total-real-tiktok' => null, 'orders-total-real-tiktok' => null];

        // Solo los países de la cuenta actual (cuenta_pais).
        $codigosCuenta = Cuenta::findOrFail(CuentaActual::id())->paises()->pluck('codigo')->all();

        $paisesElegibles = collect(config('paises'))
            ->filter(function (array $c) use ($codigosCuenta) {
                if (! ($c['habilitado'] ?? false) || ! in_array($c['codigo'], $codigosCuenta, true)) {
                    return false;
                }
                $pais = Pais::where('codigo', $c['codigo'])->first();

                return $pais && AppsflyerApp::where('pais_id', $pais->id)->exists();
            })
            ->keys();

        if ($paisesElegibles->isEmpty()) {
            $this->warn('Ningún país habilitado tiene apps de AppsFlyer configuradas (tabla appsflyer_apps) -- nada para correr.');

            return self::SUCCESS;
        }

        $this->info("Modo multi-país ({$desde}..{$hasta}): ".$paisesElegibles->implode(', '));

        $fallidos = [];
        foreach ($paisesElegibles as $paisSlug) {
            $this->line("--- {$paisSlug} ---");
            if ($this->correrUnPais($paisSlug, $sinTotales) !== self::SUCCESS) {
                $fallidos[] = $paisSlug;
            }
        }

        if ($fallidos !== []) {
            $this->error('País(es) con error: '.implode(', ', $fallidos));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function resolverRango(): array
    {
        $bufferDias = (int) ($this->option('buffer-dias') ?? config('services.appsflyer.buffer_atribucion_dias', 5));
        $hasta = $this->option('hasta') ?: now()->subDays($bufferDias)->toDateString();
        // desde se deriva del MES de hasta (ya ajustado por el buffer), no
        // del mes de hoy -- así, si el buffer empuja hasta al mes anterior
        // (primeros días de un mes nuevo), desde cae solo en ese mismo mes
        // anterior, sin lógica especial para detectar el cruce de mes.
        $desde = $this->option('desde') ?: substr($hasta, 0, 7).'-01';

        return [$desde, $hasta, $bufferDias];
    }
}
