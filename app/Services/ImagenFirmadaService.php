<?php

namespace App\Services;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * auth-prompt.md Fase 6 -- las imágenes de creativos se sirven con URLs
 * firmadas de vida corta, generadas SOLO después de que EnsureAccesoPais
 * ya validó el acceso al país (ver AnalisisCreativoController::cardAJson()
 * -- único punto por donde pasa cada `imagen_url` antes de salir a
 * Inertia). El bucket sigue siendo público a nivel IAM hoy (ver
 * docs/pendientes-datos.md) -- esto deja el código listo para cuando deje
 * de serlo, no depende de eso para funcionar.
 *
 * `imagen_url` sigue guardando la URL pública completa (no se migran las
 * filas ya importadas) -- acá se le extrae la ruta del objeto para firmar,
 * en vez de cambiar qué se persiste.
 */
class ImagenFirmadaService
{
    private const MINUTOS_VIGENCIA = 15;

    private ?Bucket $bucket = null;

    public function firmar(?string $imagenUrl): ?string
    {
        if ($imagenUrl === null || $imagenUrl === '') {
            return null;
        }

        $rutaObjeto = $this->rutaObjetoDesde($imagenUrl);

        // No es una URL de nuestro bucket (ej. el cacheo falló y quedó la
        // URL remota del CDN de Meta/TikTok) -- nada que firmar, se
        // devuelve tal cual.
        if ($rutaObjeto === null) {
            return $imagenUrl;
        }

        try {
            return $this->bucket()->object($rutaObjeto)->signedUrl(now()->addMinutes(self::MINUTOS_VIGENCIA));
        } catch (Throwable $e) {
            Log::warning("No se pudo firmar la URL de imagen ({$rutaObjeto}): {$e->getMessage()}");

            return null;
        }
    }

    private function bucket(): Bucket
    {
        if ($this->bucket === null) {
            $client = new StorageClient(array_filter(['projectId' => config('filesystems.disks.gcs.project_id')]));
            $this->bucket = $client->bucket((string) config('filesystems.disks.gcs.bucket'));
        }

        return $this->bucket;
    }

    private function rutaObjetoDesde(string $imagenUrl): ?string
    {
        $prefijo = 'https://storage.googleapis.com/'.config('filesystems.disks.gcs.bucket').'/';

        return str_starts_with($imagenUrl, $prefijo) ? substr($imagenUrl, strlen($prefijo)) : null;
    }
}
