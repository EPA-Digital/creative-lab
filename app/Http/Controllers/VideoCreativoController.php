<?php

namespace App\Http\Controllers;

use App\Jobs\GuardarVideoCreativo;
use App\Models\Creativo;
use App\Models\Pais;
use App\Services\VideoCreativoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Video de un creativo para el modal (pedido explícito 2026-10-06) -- ver
 * VideoCreativoService. Se pide al abrir el modal, no al cargar la página:
 * resolverlo implica llamadas a TikTok/Meta.
 */
class VideoCreativoController extends Controller
{
    public function show(string $pais, Creativo $creativo, VideoCreativoService $videos): JsonResponse
    {
        $config = config("paises.{$pais}");
        abort_unless($config && $creativo->pais_id === Pais::where('codigo', $config['codigo'])->value('id'), 404);

        $video = $videos->paraReproducir($creativo);
        if (! $video) {
            return response()->json(['message' => 'Este video ya no está disponible en la plataforma.'], 404);
        }

        // Se está reproduciendo directo de TikTok/Meta (enlace que expira):
        // guardar la versión ligera para la próxima vez y para cuando TikTok
        // borre el anuncio. El candado evita encolarlo de nuevo en cada
        // apertura mientras el primero sigue en la cola.
        if ($video['tipo'] === 'directo' && Cache::add("video-guardando-{$creativo->id}", true, now()->addHour())) {
            GuardarVideoCreativo::dispatch($creativo->id);
        }

        return response()->json($video);
    }
}
