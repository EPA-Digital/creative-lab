<?php

namespace App\Jobs;

use App\Models\Creativo;
use App\Services\Ingesta\ImagenCacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Descarga y guarda en el bucket las imágenes de los creativos recién
 * importados (2026-10-06, pedido explícito: "hacer las cosas de forma
 * asíncrona"). Antes esto pasaba DENTRO de la importación -- cientos de
 * descargas + subidas a GCS bloqueaban el guardado de los datos (Perú:
 * 20+ minutos atorada en "trayendo costos e imágenes"). Ahora la
 * importación guarda los datos y encola este Job; las imágenes aparecen en
 * el dashboard a medida que quedan listas.
 *
 * Las URLs remotas de Meta/TikTok vienen firmadas y expiran en horas -- el
 * Job corre en minutos, mucho antes. Si una descarga falla, el creativo
 * queda sin imagen ("sin thumbnail") y `reparar:creativos-incompletos` la
 * reintenta.
 */
class CachearImagenesCreativos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3500;

    /**
     * @param  array<int, array{url: string, nombre: string}>  $pendientes  creativo_id => URL remota + nombre base del archivo
     */
    public function __construct(public readonly array $pendientes) {}

    public function handle(ImagenCacheService $imagenes): void
    {
        ini_set('memory_limit', '512M');

        foreach (array_chunk($this->pendientes, 100, preserve_keys: true) as $tanda) {
            $guardadas = $imagenes->cachearVarias(
                array_map(fn (array $p) => $p['url'], $tanda),
                fn (string $creativoId) => $tanda[(int) $creativoId]['nombre'],
            );

            foreach ($guardadas as $creativoId => $url) {
                // cachearVarias devuelve la URL remota si la descarga
                // falló -- esa no se guarda (expira, y la CSP no la deja
                // mostrar); el creativo sigue "sin thumbnail".
                if (ImagenCacheService::esImagenEnBucket($url)) {
                    Creativo::whereKey($creativoId)->update(['imagen_url' => $url]);
                }
            }
        }
    }
}
