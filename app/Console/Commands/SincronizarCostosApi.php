<?php

namespace App\Console\Commands;

use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Services\Divisas\Divisas;
use App\Services\Fuentes\SincronizadorCostosApi;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use App\Support\CuentaActual;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Costos desde la API (2026-11-26, pedido explícito): trae el gasto,
 * impresiones y clicks de Meta y TikTok por anuncio y día. Corre cada hora
 * (Cloud Scheduler en producción) con los últimos días de
 * config/costos_api.php; --desde/--hasta para cargar un periodo a mano.
 * Ver SincronizadorCostosApi.
 */
class SincronizarCostosApi extends Command
{
    protected $signature = 'costos:sincronizar-api
        {pais? : Slug de config/paises.php -- si se omite, todos los países con cuentas de Meta o TikTok}
        {--cuenta= : Slug de la cuenta -- si se omite, todas las activas}
        {--desde= : YYYY-MM-DD (default: hoy menos costos_api.dias)}
        {--hasta= : YYYY-MM-DD (default: hoy)}';

    protected $description = 'Trae de la API de Meta y TikTok el gasto por anuncio y día';

    public function handle(Divisas $divisas): int
    {
        // UTC: en cualquier país de la cuenta ya es "hoy" o antes.
        $hasta = $this->option('hasta') ?: CarbonImmutable::now('UTC')->toDateString();
        $desde = $this->option('desde') ?: CarbonImmutable::parse($hasta)->subDays((int) config('costos_api.dias', 3))->toDateString();
        $sincronizador = new SincronizadorCostosApi(
            SincronizarOptimizaciones::cliente(fn () => MetaApiClient::fromConfig()),
            SincronizarOptimizaciones::cliente(fn () => TiktokApiClient::fromConfig()),
            $divisas,
        );
        $cuentas = $this->option('cuenta') ? Cuenta::where('slug', $this->option('cuenta'))->get() : Cuenta::where('activa', true)->get();
        $huboError = false;

        foreach ($cuentas as $cuenta) {
            CuentaActual::usar($cuenta, function () use ($cuenta, $sincronizador, $desde, $hasta, &$huboError) {
                $paises = $this->paises();
                if ($paises === []) {
                    return;
                }
                $inicio = now();
                $detalle = [];
                $errores = 0;
                foreach ($paises as $slug => $paisId) {
                    $r = $sincronizador->sincronizar($paisId, $desde, $hasta);
                    foreach ($r['cuentas'] as $c) {
                        $this->info("{$cuenta->nombre} · {$c['cuenta']}: {$c['anuncios']} anuncios, \${$c['costo']} USD ({$desde} a {$hasta}).");
                    }
                    foreach ($r['errores'] as $e) {
                        $this->error("  {$e}");
                    }
                    $errores += count($r['errores']);
                    $detalle[$slug] = $r;
                }
                $huboError = $huboError || $errores > 0;
                $total = array_sum(array_map(fn ($r) => count($r['cuentas']), $detalle));
                DB::table('sincronizaciones_costos')->insert([
                    'cuenta_id' => $cuenta->id, 'iniciado_en' => $inicio, 'terminado_en' => now(),
                    'estado' => $errores === 0 ? 'ok' : ($total > 0 ? 'parcial' : 'error'),
                    'desde' => $desde, 'hasta' => $hasta, 'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                ]);
            });
        }

        return $huboError ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, int> slug → pais_id, solo países con cuentas activas de Meta o TikTok en la cuenta actual. */
    private function paises(): array
    {
        $ids = CuentaPublicitaria::where('cuentas_publicitarias.activa', true)
            ->whereIn('cuentas_publicitarias.plataforma', ['meta', 'tiktok'])->join('paises', 'paises.id', '=', 'cuentas_publicitarias.pais_id')
            ->pluck('paises.id', 'paises.codigo')->all();
        $paises = [];
        foreach (config('paises') as $slug => $cfg) {
            if (isset($ids[$cfg['codigo']]) && (! $this->argument('pais') || $this->argument('pais') === $slug)) {
                $paises[$slug] = (int) $ids[$cfg['codigo']];
            }
        }

        return $paises;
    }
}
