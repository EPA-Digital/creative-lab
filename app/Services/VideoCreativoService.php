<?php

namespace App\Services;

use App\Models\Creativo;
use App\Models\CuentaPublicitaria;
use App\Services\Ingesta\MetaApiClient;
use App\Services\Ingesta\TiktokApiClient;
use Illuminate\Support\Facades\Cache;
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
 * - Meta: `source` (el MP4) solo lo entrega la API con el token de la
 *   PÁGINA dueña del video, no con el de usuario (verificado 2026-10-07:
 *   con el de usuario viene vacío, con el de "TaDa Delivery Ecuador" sí).
 *   El token del proyecto tiene pages_show_list/pages_read_engagement, así
 *   que el de la página sale de /me/accounts. Mismo flujo que TikTok; si la
 *   página no está entre las del token, queda el enlace a Facebook.
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

        if ($creativo->plataforma === 'meta') {
            if ($source = $this->sourceMeta($creativo)) {
                return ['tipo' => 'directo', 'url' => $source];
            }
            if ($enlace = $this->permalinkMeta($creativo)) {
                return ['tipo' => 'enlace', 'url' => $enlace];
            }
        }

        return null;
    }

    /**
     * Enlace al MP4 de Meta (expira en horas) usando el token de la página
     * dueña del video. null si el ad no es de video o la página no está
     * entre las del token.
     */
    public function sourceMeta(Creativo $creativo): ?string
    {
        try {
            $videoId = $this->videoIdMeta($creativo);
            if (! $videoId) {
                return null;
            }
            $pagina = MetaApiClient::fromConfig()->get($videoId, ['fields' => 'from'])['from']['id'] ?? null;
            $tokenPagina = $pagina ? ($this->tokensDePaginas()[$pagina] ?? null) : null;
            if (! $tokenPagina) {
                return null;
            }

            return (new MetaApiClient($tokenPagina, config('services.meta.graph_version', 'v21.0')))
                ->get($videoId, ['fields' => 'source'])['source'] ?? null;
        } catch (Throwable $e) {
            Log::warning("No se pudo traer el video de Meta del creativo {$creativo->id}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * id del video de un ad de Meta -- se guarda en el creativo la primera
     * vez (misma columna video_id que TikTok).
     */
    private function videoIdMeta(Creativo $creativo): ?string
    {
        if ($creativo->video_id) {
            return $creativo->video_id;
        }
        $ad = MetaApiClient::fromConfig()->get($creativo->ad_id, ['fields' => 'creative{video_id,object_story_spec{video_data{video_id}},asset_feed_spec{videos{video_id}}}']);
        $c = $ad['creative'] ?? [];
        $videoId = $c['video_id'] ?? $c['object_story_spec']['video_data']['video_id'] ?? $c['asset_feed_spec']['videos'][0]['video_id'] ?? null;
        if ($videoId) {
            $creativo->update(['video_id' => $videoId]);
        }

        return $videoId;
    }

    /**
     * page_id => access_token de las páginas a las que tiene acceso el token
     * del proyecto. Cacheado 1 h: no cambia seguido y evita pedirlo por
     * cada video.
     *
     * @return array<string, string>
     */
    private function tokensDePaginas(): array
    {
        return Cache::remember('meta-tokens-paginas', now()->addHour(), function () {
            $meta = MetaApiClient::fromConfig();
            $tokens = [];
            $after = null;
            do {
                $r = $meta->get('me/accounts', ['fields' => 'id,access_token', 'limit' => 100, ...($after ? ['after' => $after] : [])]);
                foreach ($r['data'] ?? [] as $pagina) {
                    $tokens[$pagina['id']] = $pagina['access_token'];
                }
                $after = ! empty($r['paging']['next']) ? ($r['paging']['cursors']['after'] ?? null) : null;
            } while ($after);

            return $tokens;
        });
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
            $videoId = $this->videoIdMeta($creativo);
            if (! $videoId) {
                return null;
            }

            $permalink = MetaApiClient::fromConfig()->get($videoId, ['fields' => 'permalink_url'])['permalink_url'] ?? null;
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
     * Descarga el original (TikTok o Meta), lo convierte a un MP4 ligero con
     * ffmpeg y lo guarda en el bucket. Devuelve true si quedó guardado.
     */
    public function guardar(Creativo $creativo): bool
    {
        if ($creativo->video_url || ! in_array($creativo->plataforma, ['tiktok', 'meta'], true)) {
            return false;
        }
        if (! self::hayFfmpeg()) {
            Log::warning("ffmpeg no está instalado -- no se puede guardar el video del creativo {$creativo->id}.");

            return false;
        }

        $preview = $creativo->plataforma === 'tiktok' ? $this->previewTiktok($creativo) : $this->sourceMeta($creativo);
        if (! $preview) {
            return false;
        }

        $original = tempnam(sys_get_temp_dir(), 'video-orig-');
        $ligero = tempnam(sys_get_temp_dir(), 'video-ligero-').'.mp4';
        try {
            $descarga = Http::timeout(180)->sink($original)->get($preview);
            if (! $descarga->successful()) {
                Log::warning("No se pudo descargar el video del creativo {$creativo->id}: HTTP {$descarga->status()}");

                return false;
            }

            $conversion = Process::timeout(600)->run(self::comandoConversion($original, $ligero));
            if (! $conversion->successful() || ! filesize($ligero)) {
                Log::warning("ffmpeg no pudo convertir el video del creativo {$creativo->id}: {$conversion->errorOutput()}");

                return false;
            }

            $ruta = "creative-videos/{$creativo->plataforma}-{$creativo->ad_id}.mp4";
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
