<?php

namespace App\Console\Commands;

use App\Models\Creativo;
use App\Models\Cuenta;
use App\Models\CuentaPublicitaria;
use App\Models\Pais;
use App\Services\Ingesta\EnriquecedorCostosMeta;
use App\Services\Ingesta\ImagenCacheService;
use App\Services\Ingesta\MetaApiClient;
use App\Support\CuentaActual;
use Illuminate\Console\Command;

/**
 * Vuelve a elegir y guardar la imagen de los creativos de Meta de un país
 * (2026-11-13). Una vez que la imagen está en el bucket, reimportar ya no la
 * reemplaza; esto la reemplaza después de un cambio en cómo se elige (ej.
 * elegir por ubicación en EnriquecedorCostosMeta::elegirHashAssetFeedSpec).
 * Nunca toca las imágenes curadas a mano (Drive).
 *
 *   php artisan reparar:imagenes-meta ecuador --mes=2026-10 --mes=2026-11
 *   php artisan reparar:imagenes-meta ecuador --mes=2026-10 --aplicar
 */
class RepararImagenesMeta extends Command
{
    protected $signature = 'reparar:imagenes-meta {pais} {--mes=* : YYYY-MM; sin --mes, todos los meses} {--cuenta= : slug de la cuenta (default tada)} {--aplicar : sin esto solo dice cuántos creativos tocaría}';

    protected $description = 'Vuelve a traer de Meta la imagen de los creativos de un país y la reemplaza (no toca las curadas a mano)';

    public function handle(ImagenCacheService $imagenes): int
    {
        ini_set('memory_limit', '512M');

        $config = config("paises.{$this->argument('pais')}");
        $pais = $config ? Pais::where('codigo', $config['codigo'])->first() : null;
        if (! $pais) {
            $this->error("País \"{$this->argument('pais')}\" no existe.");

            return self::FAILURE;
        }
        $cuenta = Cuenta::where('slug', $this->option('cuenta') ?: Cuenta::SLUG_TADA)->first();
        if (! $cuenta) {
            $this->error("No existe la cuenta \"{$this->option('cuenta')}\".");

            return self::FAILURE;
        }

        return CuentaActual::usar($cuenta, function () use ($pais, $imagenes) {
            $meses = $this->option('mes');
            $creativos = Creativo::where('pais_id', $pais->id)
                ->where('plataforma', 'meta')
                ->when($meses !== [], fn ($q) => $q->whereHas('resultados', fn ($r) => $r->whereIn('mes', $meses)))
                ->get(['id', 'ad_id', 'imagen_url'])
                ->reject(fn (Creativo $c) => ImagenCacheService::esImagenCurada($c->imagen_url))
                ->keyBy('ad_id');

            $this->info("{$pais->nombre}: {$creativos->count()} creativo(s) de Meta".($meses ? ' en '.implode(', ', $meses) : '').' (sin contar los curados a mano).');
            if (! $this->option('aplicar')) {
                $this->line('Corre de nuevo con --aplicar para reemplazar sus imágenes.');

                return self::SUCCESS;
            }

            $enriquecedor = new EnriquecedorCostosMeta(MetaApiClient::fromConfig(), $imagenes);
            $faltan = $creativos->keys()->map(fn ($id) => (string) $id)->all();
            $actualizados = 0;
            foreach (CuentaPublicitaria::activasPara($pais->id, 'meta')->pluck('id_externo') as $cuentaId) {
                if ($faltan === []) {
                    break;
                }
                // Nombre de archivo nuevo: la URL cambia y nadie ve la imagen vieja en caché.
                $imagenPorAdId = $enriquecedor->reintentarImagenYCopy($cuentaId, $faltan, 'meta-img')[1];
                foreach ($imagenPorAdId as $adId => $url) {
                    if (ImagenCacheService::esImagenEnBucket($url) && $creativos->has($adId)) {
                        $creativos[$adId]->update(['imagen_url' => $url]);
                        $actualizados++;
                    }
                }
                $faltan = array_values(array_diff($faltan, array_map('strval', array_keys($imagenPorAdId))));
            }

            $this->info("Listo: {$actualizados} imagen(es) reemplazada(s); ".count($faltan).' sin respuesta de Meta (se quedan como estaban).');

            return self::SUCCESS;
        });
    }
}
