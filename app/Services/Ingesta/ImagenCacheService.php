<?php

namespace App\Services\Ingesta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puerto de cachearImagenLocal + extensionDesdeContentType (pipeline.js,
 * proyecto Node, referencia). Descarga una imagen remota (CDN de Meta/
 * TikTok, con firma que expira en horas/días) y la cachea en disco público
 * -- si un thumbnail se rompe días después de resuelto, la firma expiró;
 * cachear localmente es justamente para no depender de esa URL viva.
 *
 * A diferencia de Node (./output/creative-images/, servido por el server a
 * medida de scripts/serve-dashboard.js), acá se escribe directo a
 * public/creative-images/ -- ya público sin symlink de storage:link, mismo
 * criterio de simplicidad que el server de Node.
 */
class ImagenCacheService
{
    private const EXTENSIONES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Tamaño de tanda para las descargas en paralelo -- Node dispara TODAS
     * a la vez con Promise.all porque V8 no tiene un memory_limit fijo por
     * proceso; PHP-CLI sí (128M por default). Confirmado con datos reales
     * (2026-08-01): descargar de una sola pasada las ~500+ imágenes de una
     * cuenta de TikTok agota el memory_limit default (Guzzle bufferea cada
     * respuesta completa en memoria mientras el pool está en vuelo). 25 a
     * la vez mantiene la mayor parte del beneficio de paralelismo sin
     * acumular cientos de imágenes en memoria ni abrir cientos de
     * conexiones simultáneas al CDN.
     */
    private const TANDA_DESCARGAS = 25;

    /**
     * Prefijo de archivo que marca una imagen curada a mano (matcheada
     * manualmente contra el Drive real de creativos, ver
     * scratchpad/panama-images -- 2026-08-27) -- nunca debe pisarse con el
     * thumbnail borroso que trae una corrida de rutina del importador. Sin
     * esto, reimportar el mismo mes revierte silenciosamente la imagen
     * buena a la de Meta/AppsFlyer otra vez.
     */
    private const PREFIJO_IMAGEN_CURADA = '/creative-images/drive-';

    public static function esImagenCurada(?string $imagenUrl): bool
    {
        return $imagenUrl !== null && str_starts_with($imagenUrl, self::PREFIJO_IMAGEN_CURADA);
    }

    /**
     * Descarga y cachea EN PARALELO por tandas (Http::pool, equivalente a
     * Promise.all de Node pero acotado -- ver TANDA_DESCARGAS) -- no es la
     * API de Graph/TikTok, es el CDN de la imagen, no cuenta contra el rate
     * limit de la cuenta. Si una descarga puntual falla, esa clave se queda
     * con la URL remota (puede expirar) en vez de nada -- nunca bloquea a
     * las demás.
     *
     * @param  array<string, string>  $urlPorClave
     * @param  callable(string): string  $nombreArchivoBase  arma el nombre de archivo a partir de la clave (ej. fn ($adId) => "meta-costo-{$adId}")
     * @return array<string, string>  clave => URL pública local (o remota si falló la descarga)
     */
    public function cachearVarias(array $urlPorClave, callable $nombreArchivoBase): array
    {
        if ($urlPorClave === []) {
            return [];
        }

        $resultado = [];
        foreach (array_chunk($urlPorClave, self::TANDA_DESCARGAS, preserve_keys: true) as $tanda) {
            // timeout+retry agregados 2026-08-12 -- confirmado en logs
            // reales 44 timeouts (`cURL error 28`, 10-30s) descargando
            // thumbnails de video de TikTok, sin ningún retry hasta ahora.
            // Mismo criterio que MetaApiClient/TiktokApiClient/
            // AppsFlyerApiClient (timeout más largo que el default + 2
            // reintentos cortos para lo transitorio).
            $respuestas = Http::pool(function ($pool) use ($tanda) {
                foreach ($tanda as $clave => $url) {
                    $pool->as($clave)->timeout(45)->retry(2, 1000)->get($url);
                }
            });

            foreach ($tanda as $clave => $url) {
                $guardada = $this->guardarSiOk($respuestas[$clave] ?? null, $nombreArchivoBase((string) $clave));
                $resultado[$clave] = $guardada ?? $url;
            }
        }

        return $resultado;
    }

    private function guardarSiOk(mixed $respuesta, string $nombreBase): ?string
    {
        if (! $respuesta instanceof Response || ! $respuesta->successful()) {
            $motivo = $respuesta instanceof Throwable ? $respuesta->getMessage() : 'sin respuesta';
            Log::warning("No se pudo cachear localmente la imagen ({$nombreBase}): {$motivo}");

            return null;
        }

        try {
            $ext = self::extensionDesdeContentType($respuesta->header('Content-Type'));
            $dir = public_path('creative-images');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $nombreArchivo = "{$nombreBase}.{$ext}";
            file_put_contents("{$dir}/{$nombreArchivo}", $respuesta->body());

            return "/creative-images/{$nombreArchivo}";
        } catch (Throwable $e) {
            Log::warning("No se pudo cachear localmente la imagen ({$nombreBase}): {$e->getMessage()}");

            return null;
        }
    }

    private static function extensionDesdeContentType(?string $contentType): string
    {
        $tipo = trim(explode(';', $contentType ?? '')[0]);

        return self::EXTENSIONES[$tipo] ?? 'jpg';
    }
}
