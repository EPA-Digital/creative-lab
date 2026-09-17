<?php

namespace App\Console\Commands;

use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\MetaEstadoAdsSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Trae el historial real de encendido/apagado/etc. de los ads desde la
 * Activity Log de Meta (2026-08-28, ver MetaEstadoAdsSyncService para el
 * criterio completo). Meta solo expone ~1 semana hacia atrás -- por eso
 * este comando está pensado para correr seguido (ver Schedule en
 * routes/console.php), nunca como un pull de una sola vez.
 */
class SincronizarEstadoAds extends Command
{
    protected $signature = 'meta:sincronizar-estado-ads {pais? : Slug de config/paises.php -- si se omite, corre para todos los países habilitados con meta_ad_account_id}';

    protected $description = 'Sincroniza el historial de estado (activo/pausado/etc.) de los ads desde la Activity Log de Meta';

    public function handle(): int
    {
        $paisArg = $this->argument('pais');
        $paises = $paisArg ? [$paisArg] : $this->paisesConMeta();

        if (! $paises) {
            $this->warn('Ningún país habilitado tiene meta_ad_account_id configurado -- nada que sincronizar.');

            return self::SUCCESS;
        }

        $servicio = new MetaEstadoAdsSyncService(MetaApiClient::fromConfig());
        $huboError = false;

        foreach ($paises as $pais) {
            try {
                $r = $servicio->sincronizar($pais);
                $this->info("{$pais}: {$r['eventosNuevos']} evento(s) nuevo(s) ({$r['resueltosACreativo']} con creativo resuelto, {$r['sinCreativo']} sin creativo todavía).");
            } catch (InvalidArgumentException $e) {
                $this->error("{$pais}: {$e->getMessage()}");
                $huboError = true;
            } catch (Throwable $e) {
                // Un país que falla (rate limit, token vencido, etc.) no
                // debe frenar a los demás -- mismo criterio best-effort que
                // el resto del pipeline de Ingesta.
                $this->error("{$pais}: error al sincronizar -- {$e->getMessage()}");
                $huboError = true;
            }
        }

        return $huboError ? self::FAILURE : self::SUCCESS;
    }

    /** @return string[] */
    private function paisesConMeta(): array
    {
        return collect(config('paises'))
            ->filter(fn (array $cfg) => ($cfg['habilitado'] ?? false) && ($cfg['meta_ad_account_id'] ?? null))
            ->keys()
            ->all();
    }
}
