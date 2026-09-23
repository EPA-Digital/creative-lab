<?php

namespace App\Jobs;

use App\Models\Importacion;
use App\Services\Ingesta\ImportadorDatos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Corre ImportadorDatos::importar() fuera del ciclo request/response --
 * el panel "Cargar datos" (Subir CSV) lo despacha en vez de llamarlo
 * directo (ver ImportarDatosController::importar()) porque un CSV real
 * (~250 creativos) tarda más que el timeout de Cloud Run. El pipeline en
 * sí no cambia en nada -- sigue corriendo como una sola unidad atómica
 * (necesario para que el reparto de NC/Orders en
 * VentaRealYAgrupacion::calcularVentaReal sea correcto), solo que en
 * background.
 *
 * El CSV llega como contenido (csv_contenido en la fila `importaciones`),
 * no como ruta de archivo -- el worker corre como una ejecución de Cloud
 * Run Job separada del contenedor que recibió el upload, sin acceso a su
 * disco local.
 */
class ProcesarImportacionCsv implements ShouldQueue
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
        public readonly string $nombreArchivo,
    ) {}

    public function handle(): void
    {
        $importacion = Importacion::findOrFail($this->importacionId);

        $rutaTmp = sys_get_temp_dir().'/importacion-'.Str::uuid().'.csv';
        file_put_contents($rutaTmp, (string) $importacion->csv_contenido);

        try {
            ImportadorDatos::importar(
                $rutaTmp,
                $this->paisSlug,
                $this->desde,
                $this->hasta,
                $this->ncTotalRealMeta,
                $this->ordersTotalRealMeta,
                $this->ncTotalRealTiktok,
                $this->ordersTotalRealTiktok,
                $this->nombreArchivo,
                $importacion,
            );
        } catch (Throwable $e) {
            $importacion->update([
                'estado' => 'error',
                'error_mensaje' => $e->getMessage(),
            ]);
        } finally {
            @unlink($rutaTmp);
            $importacion->update(['csv_contenido' => null]);
        }
    }
}
