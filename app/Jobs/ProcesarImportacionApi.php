<?php

namespace App\Jobs;

use App\Models\Importacion;
use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Por API" en background (2026-10-06) -- mismo criterio que
 * ProcesarImportacionCsv: antes corría dentro del request, y un país grande
 * (México: AppsFlyer + costos + ~1,000 creativos) puede pasar el timeout de
 * 300s de Cloud Run. Ahora el panel despacha este Job y hace polling de
 * estadoImportacion(), con el avance que va guardando el pipeline.
 */
class ProcesarImportacionApi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $importacionId,
        public readonly string $paisSlug,
        public readonly string $desde,
        public readonly string $hasta,
        public readonly mixed $ncTotalRealMeta,
        public readonly mixed $ordersTotalRealMeta,
        public readonly mixed $ncTotalRealTiktok,
        public readonly mixed $ordersTotalRealTiktok,
    ) {}

    public function handle(): void
    {
        // Ver nota en ImportarAppsFlyerApi::handle() -- caché de imágenes.
        ini_set('memory_limit', '512M');

        $importacion = Importacion::findOrFail($this->importacionId);

        try {
            ImportadorDatos::importarDesdeApi(
                $this->paisSlug,
                $this->desde,
                $this->hasta,
                $this->ncTotalRealMeta,
                $this->ordersTotalRealMeta,
                $this->ncTotalRealTiktok,
                $this->ordersTotalRealTiktok,
                $importacion,
                Importacion::reporteroDeProgreso($this->importacionId),
            );
        } catch (Throwable $e) {
            // Query directa por id -- ver el mismo bloque en ProcesarImportacionCsv.
            Importacion::whereKey($this->importacionId)->update([
                'estado' => 'error',
                'error_mensaje' => $e->getMessage(),
            ]);
        }
    }
}
