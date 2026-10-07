<?php

namespace App\Jobs;

use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Models\TareaMedios;
use App\Services\Ingesta\EnriquecedorCostosMeta;
use App\Services\Ingesta\EnriquecedorCostosTiktok;
use App\Services\Ingesta\ImagenCacheService;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Recuperar imágenes" de un país y mes (pedido explícito 2026-10-07,
 * septiembre quedó sin imágenes): vuelve a pedirle a Meta/TikTok la imagen
 * de cada creativo del mes que no la tiene en el bucket, la guarda y
 * reporta el avance en la tarea (el panel avisa al terminar o muestra el
 * error). Mismo mecanismo que `reparar:creativos-incompletos`, acotado al mes.
 */
class RecuperarImagenesMes implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3500;

    public function __construct(public readonly int $tareaId) {}

    /**
     * Creativos del mes cuya imagen no está en el bucket.
     */
    public static function creativosSinImagen(int $paisId, string $mes)
    {
        return Creativo::where('pais_id', $paisId)
            ->whereIn('plataforma', ['meta', 'tiktok'])
            ->whereHas('resultados', fn ($q) => $q->where('mes', $mes))
            ->get(['id', 'ad_id', 'plataforma', 'imagen_url'])
            ->reject(fn (Creativo $c) => ImagenCacheService::esImagenEnBucket($c->imagen_url))
            ->values();
    }

    public function handle(ImagenCacheService $imagenes): void
    {
        ini_set('memory_limit', '512M');
        $tarea = TareaMedios::findOrFail($this->tareaId);
        $tarea->update(['estado' => 'procesando']);

        $pendientes = self::creativosSinImagen($tarea->pais_id, $tarea->mes);

        foreach (['meta', 'tiktok'] as $plataforma) {
            $creativos = $pendientes->where('plataforma', $plataforma)->keyBy('ad_id');
            if ($creativos->isEmpty()) {
                continue;
            }

            $faltan = $creativos->keys()->all();
            foreach (CuentaPublicitaria::activasPara($tarea->pais_id, $plataforma)->pluck('cuenta_id') as $cuentaId) {
                if ($faltan === []) {
                    break;
                }
                $imagenPorAdId = $plataforma === 'meta'
                    ? (new EnriquecedorCostosMeta(MetaApiClient::fromConfig(), $imagenes))->reintentarImagenYCopy($cuentaId, $faltan)[1]
                    : array_map(fn (array $d) => $d['imagenUrl'], (new EnriquecedorCostosTiktok(TiktokApiClient::fromConfig(), $imagenes))->reintentarNombreImagenYCopy($cuentaId, $faltan));

                $listas = 0;
                foreach ($imagenPorAdId as $adId => $url) {
                    if (ImagenCacheService::esImagenEnBucket($url) && $creativos->has($adId)) {
                        $creativos[$adId]->update(['imagen_url' => $url]);
                        $listas++;
                    }
                }
                $faltan = array_values(array_diff($faltan, array_keys(array_filter($imagenPorAdId, fn ($url) => ImagenCacheService::esImagenEnBucket($url)))));
                TareaMedios::avanzar($this->tareaId, $listas);
            }

            // Lo que ninguna cuenta devolvió (ad borrado, sin imagen en la
            // plataforma, cuenta no cargada) cuenta como fallido.
            TareaMedios::avanzar($this->tareaId, 0, count($faltan));
        }

        // Por si no había nada que hacer en ninguna plataforma.
        TareaMedios::avanzar($this->tareaId);
    }

    public function failed(Throwable $e): void
    {
        TareaMedios::fallar($this->tareaId, "No se pudieron recuperar las imágenes: {$e->getMessage()}");
    }
}
