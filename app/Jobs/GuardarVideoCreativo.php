<?php

namespace App\Jobs;

use App\Models\Creativo;
use App\Services\VideoCreativoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Guarda en el bucket la versión ligera del video de un creativo de TikTok
 * (ver VideoCreativoService::guardar). Se encola al abrir un video que
 * todavía no está guardado y, después de cada importación, para los de
 * mayor gasto del mes (config('videos.top_por_mes')).
 */
class GuardarVideoCreativo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $creativoId) {}

    public function handle(VideoCreativoService $videos): void
    {
        $creativo = Creativo::find($this->creativoId);
        if ($creativo) {
            $videos->guardar($creativo);
        }
    }
}
