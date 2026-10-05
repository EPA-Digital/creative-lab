<?php

namespace App\Console\Commands;

use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Services\Ingesta\EnriquecedorCostosMeta;
use App\Services\Ingesta\EnriquecedorCostosTiktok;
use App\Services\Ingesta\ImagenCacheService;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Console\Command;

/**
 * Repara imagen_url/copy/nombre_campania faltante en creativos YA importados
 * -- por Ad ID directo (2026-08-12, pedido explícito del usuario: "busquemos
 * con el id"), sin volver a correr el pipeline completo de costo/AppsFlyer/
 * venta real (que además pisaría venta_real innecesariamente). Complementa
 * -- no reemplaza -- los fixes de resiliencia en EnriquecedorCostosMeta/
 * Tiktok: este comando repara lo que YA se perdió en corridas anteriores a
 * esos fixes; los fixes evitan que se vuelva a perder en corridas futuras.
 *
 * imagen_url es propiedad del AD (no cambia mes a mes), así que no hace
 * falta repetir esto por mes -- reparar el creativo una vez alcanza para
 * todos los meses que ya tiene cargados.
 */
class RepararCreativosIncompletos extends Command
{
    protected $signature = 'reparar:creativos-incompletos {pais}';

    protected $description = 'Re-consulta por Ad ID la imagen/copy/campaña de creativos que quedaron sin imagen_url';

    public function handle(): int
    {
        // Ver nota en ImportarAppsFlyerApi::handle() -- mismo pipeline de
        // caché de imágenes.
        ini_set('memory_limit', '512M');

        $paisSlug = $this->argument('pais');
        $config = config("paises.{$paisSlug}");
        if (! $config) {
            $this->error("País \"{$paisSlug}\" no existe en config/paises.php.");

            return self::FAILURE;
        }

        $pais = Pais::where('codigo', $config['codigo'])->first();
        if (! $pais) {
            $this->error("País \"{$config['codigo']}\" no existe en la tabla paises.");

            return self::FAILURE;
        }

        $incompletos = Creativo::where('pais_id', $pais->id)->whereNull('imagen_url')->get();
        if ($incompletos->isEmpty()) {
            $this->info('Ningún creativo con imagen faltante -- nada que reparar.');

            return self::SUCCESS;
        }

        $this->info("{$incompletos->count()} creativo(s) sin imagen para \"{$paisSlug}\".");

        $imagenesRecuperadas = 0;

        // Todas las cuentas activas del país (ver CuentaPublicitaria) -- un
        // ad solo existe en una, en las demás simplemente no aparece.
        $cuentasMeta = CuentaPublicitaria::activasPara($pais->id, 'meta')->pluck('cuenta_id');
        $meta = $incompletos->where('plataforma', 'meta');
        if ($meta->isNotEmpty() && $cuentasMeta->isNotEmpty()) {
            $this->line("Meta: reintentando {$meta->count()} ad(s)...");
            $enriquecedor = new EnriquecedorCostosMeta(MetaApiClient::fromConfig(), new ImagenCacheService);
            $imagenPorAdId = [];
            $copyPorAdId = [];
            foreach ($cuentasMeta as $cuentaId) {
                [, $imagenes, $copys] = $enriquecedor->reintentarImagenYCopy($cuentaId, $meta->pluck('ad_id')->all());
                $imagenPorAdId += $imagenes;
                $copyPorAdId += $copys;
            }

            foreach ($meta as $creativo) {
                $imagen = $imagenPorAdId[$creativo->ad_id] ?? null;
                $copy = $copyPorAdId[$creativo->ad_id] ?? null;

                $cambios = [];
                if ($imagen) {
                    $cambios['imagen_url'] = $imagen;
                    $imagenesRecuperadas++;
                }
                if ($copy) {
                    $cambios['copy'] = $copy;
                }
                if ($cambios !== []) {
                    $creativo->update($cambios);
                }
            }
        }

        $cuentasTiktok = CuentaPublicitaria::activasPara($pais->id, 'tiktok')->pluck('cuenta_id');
        $tiktok = $incompletos->where('plataforma', 'tiktok');
        if ($tiktok->isNotEmpty() && $cuentasTiktok->isNotEmpty()) {
            $this->line("TikTok: reintentando {$tiktok->count()} ad(s)...");
            $enriquecedor = new EnriquecedorCostosTiktok(TiktokApiClient::fromConfig(), new ImagenCacheService);
            $resultado = [];
            foreach ($cuentasTiktok as $cuentaId) {
                // reintentarNombreImagenYCopy devuelve TODOS los ad_id pedidos
                // (con nulls si no son de esta cuenta) -- solo se conserva lo
                // que trajo algo real.
                foreach ($enriquecedor->reintentarNombreImagenYCopy($cuentaId, $tiktok->pluck('ad_id')->all()) as $adId => $datos) {
                    if (! isset($resultado[$adId]) && ($datos['imagenUrl'] || $datos['copy'] || $datos['campaignName'])) {
                        $resultado[$adId] = $datos;
                    }
                }
            }

            foreach ($tiktok as $creativo) {
                $datos = $resultado[$creativo->ad_id] ?? null;
                if (! $datos) {
                    continue;
                }

                $cambios = [];
                if ($datos['imagenUrl']) {
                    $cambios['imagen_url'] = $datos['imagenUrl'];
                    $imagenesRecuperadas++;
                }
                if ($datos['copy']) {
                    $cambios['copy'] = $datos['copy'];
                }
                if ($datos['campaignName']) {
                    $cambios['nombre_campania'] = $datos['campaignName'];
                }
                if ($cambios !== []) {
                    $creativo->update($cambios);
                }
            }
        }

        $this->info("Listo: {$imagenesRecuperadas} imagen(es) recuperada(s) de {$incompletos->count()} creativo(s) que la tenían faltante.");

        return self::SUCCESS;
    }
}
