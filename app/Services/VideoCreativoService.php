<?php

namespace App\Services;

use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Videos de los creativos en el modal (pedido explícito 2026-10-06).
 *
 * - TikTok: la API da el MP4 original (~30 MB, enlace que expira en
 *   horas). Se guarda en el bucket una versión ligera (MP4, lado corto
 *   480 px, con audio) -- queda aunque TikTok borre el anuncio. Mientras no
 *   esté guardado, se reproduce directo del enlace de TikTok.
 * - Meta: con el token actual la API NO da el archivo (falta permiso de
 *   las páginas; `source` viene vacío) -- solo el enlace permanente al video
 *   en Facebook.
 *
 * MP4 y no GIF: un GIF pesa 5-10x más con peor calidad y sin audio.
 */
class VideoCreativoService
{
    public function __construct(private readonly ImagenFirmadaService $firmas) {}

    /**
     * @return array{tipo: 'archivo'|'directo'|'enlace', url: string}|null
     */
    public function paraReproducir(Creativo $creativo): ?array
    {
        if ($creativo->video_url && ($firmada = $this->firmas->firmar($creativo->video_url))) {
            return ['tipo' => 'archivo', 'url' => $firmada];
        }

        if ($creativo->plataforma === 'tiktok' && ($preview = $this->previewTiktok($creativo))) {
            return ['tipo' => 'directo', 'url' => $preview];
        }

        if ($creativo->plataforma === 'meta' && ($enlace = $this->permalinkMeta($creativo))) {
            return ['tipo' => 'enlace', 'url' => $enlace];
        }

        return null;
    }

    /**
     * Enlace fresco al MP4 original en TikTok (expira en horas). Prueba la
     * cuenta del creativo primero y después las demás cuentas activas de
     * TikTok del país (creativos importados antes de las cuentas
     * publicitarias no tienen cuenta asignada).
     */
    public function previewTiktok(Creativo $creativo): ?string
    {
        foreach ($this->cuentasTiktok($creativo) as $advertiserId) {
            try {
                $videoId = $creativo->video_id ?: $this->videoIdTiktok($advertiserId, $creativo->ad_id);
                if (! $videoId) {
                    continue;
                }
                $info = TiktokApiClient::fromConfig()->get('/file/video/ad/info/', [
                    'advertiser_id' => $advertiserId,
                    'video_ids' => [$videoId],
                ]);
                $url = $info['list'][0]['preview_url'] ?? null;
                if ($url) {
                    if (! $creativo->video_id) {
                        $creativo->update(['video_id' => $videoId]);
                    }

                    return $url;
                }
            } catch (Throwable $e) {
                Log::warning("No se pudo traer el video de TikTok del creativo {$creativo->id} (cuenta {$advertiserId}): {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Enlace permanente al video en Facebook -- se guarda en el creativo la
     * primera vez para no volver a consultar la API.
     */
    public function permalinkMeta(Creativo $creativo): ?string
    {
        if ($creativo->video_permalink) {
            return $creativo->video_permalink;
        }

        try {
            $meta = MetaApiClient::fromConfig();
            $ad = $meta->get($creativo->ad_id, ['fields' => 'creative{video_id,object_story_spec{video_data{video_id}},asset_feed_spec{videos{video_id}}}']);
            $c = $ad['creative'] ?? [];
            $videoId = $c['video_id'] ?? $c['object_story_spec']['video_data']['video_id'] ?? $c['asset_feed_spec']['videos'][0]['video_id'] ?? null;
            if (! $videoId) {
                return null;
            }

            $permalink = $meta->get($videoId, ['fields' => 'permalink_url'])['permalink_url'] ?? null;
            if (! $permalink) {
                return null;
            }
            $enlace = str_starts_with($permalink, 'http') ? $permalink : 'https://www.facebook.com'.$permalink;
            $creativo->update(['video_permalink' => $enlace]);

            return $enlace;
        } catch (Throwable $e) {
            Log::warning("No se pudo traer el enlace del video de Meta del creativo {$creativo->id}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Descarga el original de TikTok, lo convierte a un MP4 ligero con
     * ffmpeg y lo guarda en el bucket. Devuelve true si quedó guardado.
     */
    public function guardar(Creativo $creativo): bool
    {
        if ($creativo->video_url || $creativo->plataforma !== 'tiktok') {
            return false;
        }
        if (! self::hayFfmpeg()) {
            Log::warning("ffmpeg no está instalado -- no se puede guardar el video del creativo {$creativo->id}.");

            return false;
        }

        $preview = $this->previewTiktok($creativo);
        if (! $preview) {
            return false;
        }

        $original = tempnam(sys_get_temp_dir(), 'video-orig-');
        $ligero = tempnam(sys_get_temp_dir(), 'video-ligero-').'.mp4';
        try {
            $descarga = Http::timeout(180)->sink($original)->get($preview);
            if (! $descarga->successful()) {
                Log::warning("No se pudo descargar el video de TikTok del creativo {$creativo->id}: HTTP {$descarga->status()}");

                return false;
            }

            $conversion = Process::timeout(600)->run(self::comandoConversion($original, $ligero));
            if (! $conversion->successful() || ! filesize($ligero)) {
                Log::warning("ffmpeg no pudo convertir el video del creativo {$creativo->id}: {$conversion->errorOutput()}");

                return false;
            }

            $ruta = "creative-videos/tiktok-{$creativo->ad_id}.mp4";
            Storage::disk('gcs')->put($ruta, fopen($ligero, 'r'));
            $bucket = (string) config('filesystems.disks.gcs.bucket');
            $creativo->update(['video_url' => "https://storage.googleapis.com/{$bucket}/{$ruta}"]);

            return true;
        } finally {
            @unlink($original);
            @unlink($ligero);
        }
    }

    /**
     * Lado corto a `videos.alto` px (vertical 1080x1920 -> 480x854), H.264
     * + AAC, `faststart` para que empiece a reproducir antes de bajar todo.
     *
     * @return list<string>
     */
    public static function comandoConversion(string $entrada, string $salida): array
    {
        $lado = (int) config('videos.alto');

        return [
            'ffmpeg', '-y', '-loglevel', 'error',
            '-i', $entrada,
            '-t', (string) config('videos.duracion_maxima'),
            '-vf', "scale='if(gt(iw,ih),-2,{$lado})':'if(gt(iw,ih),{$lado},-2)'",
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', (string) config('videos.crf'),
            '-c:a', 'aac', '-b:a', config('videos.audio_kbps').'k',
            '-movflags', '+faststart',
            $salida,
        ];
    }

    public static function hayFfmpeg(): bool
    {
        try {
            return Process::run(['ffmpeg', '-version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function cuentasTiktok(Creativo $creativo): array
    {
        $propia = $creativo->cuentaPublicitaria?->plataforma === 'tiktok' ? [$creativo->cuentaPublicitaria->cuenta_id] : [];

        return array_values(array_unique([
            ...$propia,
            ...CuentaPublicitaria::activasPara($creativo->pais_id, 'tiktok')->pluck('cuenta_id')->all(),
        ]));
    }

    private function videoIdTiktok(string $advertiserId, string $adId): ?string
    {
        $data = TiktokApiClient::fromConfig()->get('/ad/get/', [
            'advertiser_id' => $advertiserId,
            'filtering' => ['ad_ids' => [$adId]],
            'page_size' => 1,
        ]);

        return $data['list'][0]['video_id'] ?? null;
    }
}
