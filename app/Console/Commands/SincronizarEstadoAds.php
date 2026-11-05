<?php

namespace App\Console\Commands;

use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\MetaEstadoAdsSyncService;
use App\Support\CuentaActual;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Trae el historial real de encendido/apagado/etc. de los ads desde la
 * Activity Log de Meta (2026-08-28, ver MetaEstadoAdsSyncService para el
 * criterio completo). Meta solo expone ~1 semana hacia atrás -- por eso
 * este comando está pensado para correr seguido (ver Schedule en
 * routes/console.php), nunca como un pull de una sola vez.
 *
 * Multi-cuenta: sin --cuenta recorre todas las cuentas activas, y en cada
 * una los países que tienen cuentas de Meta activas.
 */
class SincronizarEstadoAds extends Command
{
    protected $signature = 'meta:sincronizar-estado-ads
        {pais? : Slug de config/paises.php -- si se omite, todos los países de la cuenta con cuentas de Meta activas}
        {--cuenta= : Slug de la cuenta -- si se omite, todas las cuentas activas}';

    protected $description = 'Sincroniza el historial de estado (activo/pausado/etc.) de los ads desde la Activity Log de Meta';

    public function handle(): int
    {
        $slugCuenta = $this->option('cuenta');
        $cuentas = $slugCuenta
            ? Cuenta::where('slug', $slugCuenta)->get()
            : Cuenta::where('activa', true)->orderBy('id')->get();

        if ($cuentas->isEmpty()) {
            $this->error($slugCuenta ? "No existe la cuenta \"{$slugCuenta}\"." : 'No hay cuentas activas.');

            return self::FAILURE;
        }

        $servicio = new MetaEstadoAdsSyncService(MetaApiClient::fromConfig());
        $huboError = false;

        foreach ($cuentas as $cuenta) {
            CuentaActual::usar($cuenta, function () use ($cuenta, $servicio, &$huboError) {
                $paisArg = $this->argument('pais');
                $paises = $paisArg ? [$paisArg] : $this->paisesConMeta();

                if (! $paises) {
                    $this->line("{$cuenta->nombre}: sin cuentas de Meta activas, nada que sincronizar.");

                    return;
                }

                foreach ($paises as $pais) {
                    try {
                        $r = $servicio->sincronizar($pais);
                        $this->info("{$cuenta->nombre} · {$pais}: {$r['eventosNuevos']} evento(s) nuevo(s) ({$r['resueltosACreativo']} con creativo resuelto, {$r['sinCreativo']} sin creativo todavía).");
                    } catch (InvalidArgumentException $e) {
                        $this->error("{$cuenta->nombre} · {$pais}: {$e->getMessage()}");
                        $huboError = true;
                    } catch (Throwable $e) {
                        // Un país que falla (rate limit, token vencido, etc.) no
                        // debe frenar a los demás -- mismo criterio best-effort que
                        // el resto del pipeline de Ingesta.
                        $this->error("{$cuenta->nombre} · {$pais}: error al sincronizar -- {$e->getMessage()}");
                        $huboError = true;
                    }
                }
            });
        }

        return $huboError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Países habilitados donde la cuenta actual tiene cuentas de Meta activas.
     *
     * @return string[]
     */
    private function paisesConMeta(): array
    {
        $codigos = CuentaPublicitaria::where('plataforma', 'meta')->where('activa', true)
            ->join('paises', 'paises.id', '=', 'cuentas_publicitarias.pais_id')
            ->distinct()
            ->pluck('paises.codigo')
            ->all();

        return collect(config('paises'))
            ->filter(fn (array $cfg) => ($cfg['habilitado'] ?? false) && in_array($cfg['codigo'], $codigos, true))
            ->keys()
            ->all();
    }
}
