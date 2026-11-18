<?php

namespace App\Console\Commands;

use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Services\Fuentes\SincronizadorOptimizaciones;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Illuminate\Console\Command;
use Throwable;

/**
 * Optimizaciones automáticas (2026-11-18, pedido explícito): trae los
 * cambios de Meta (Activity Log) y TikTok (contra la foto) a
 * Optimizaciones, como hacía el trigger diario de Optis_Optimizaciones.gs.
 * Ver SincronizadorOptimizaciones.
 */
class SincronizarOptimizaciones extends Command
{
    protected $signature = 'optimizaciones:sincronizar
        {pais? : Slug de config/paises.php -- si se omite, todos los países con cuentas publicitarias activas}
        {--cuenta= : Slug de la cuenta -- si se omite, todas las cuentas activas}';

    protected $description = 'Trae a Optimizaciones los cambios de Meta y TikTok';

    public function handle(): int
    {
        $sincronizador = new SincronizadorOptimizaciones(self::cliente(fn () => MetaApiClient::fromConfig()), self::cliente(fn () => TiktokApiClient::fromConfig()));
        $cuentas = $this->option('cuenta') ? Cuenta::where('slug', $this->option('cuenta'))->get() : Cuenta::where('activa', true)->get();
        $huboError = false;

        foreach ($cuentas as $cuenta) {
            CuentaActual::usar($cuenta, function () use ($cuenta, $sincronizador, &$huboError) {
                $paises = $this->argument('pais') ? [$this->argument('pais')] : self::paisesConCuentas();
                foreach ($paises as $pais) {
                    $r = $sincronizador->sincronizar($pais);
                    $this->info("{$cuenta->nombre} · {$pais}: {$r['meta']} de Meta, {$r['tiktok']} de TikTok.");
                    foreach ($r['errores'] as $e) {
                        $this->error("  {$e}");
                        $huboError = true;
                    }
                }
            });
        }

        return $huboError ? self::FAILURE : self::SUCCESS;
    }

    /** Sin token configurado el cliente queda en null y esa plataforma se salta. */
    public static function cliente(callable $crear): mixed
    {
        try {
            return $crear();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function paisesConCuentas(): array
    {
        $codigos = CuentaPublicitaria::where('activa', true)
            ->join('paises', 'paises.id', '=', 'cuentas_publicitarias.pais_id')
            ->distinct()->pluck('paises.codigo')->all();

        return collect(config('paises'))
            ->filter(fn ($cfg, $slug) => in_array($cfg['codigo'], $codigos, true) && config("canales.{$slug}"))
            ->keys()->values()->all();
    }
}
