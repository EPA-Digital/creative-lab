<?php

namespace App\Jobs;

use App\Models\Creativo;
use App\Models\TareaMedios;
use App\Services\VideoCreativoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Guarda en el bucket la versión ligera del video de un creativo de TikTok
 * o Meta (ver VideoCreativoService::guardar). Se encola al abrir un video que
 * todavía no está guardado y, después de cada importación, para los de
 * mayor gasto del mes (config('videos.top_por_mes')).
 */
class GuardarVideoCreativo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $creativoId,
        // Avance para el aviso del panel (ver TareaMedios) -- opcional.
        public readonly ?int $tareaId = null,
    ) {}

    public function handle(VideoCreativoService $videos): void
    {
        $creativo = Creativo::find($this->creativoId);
        $guardado = $creativo && $videos->guardar($creativo);
        TareaMedios::avanzar($this->tareaId, $guardado ? 1 : 0, $guardado ? 0 : 1);
    }

    /**
     * Un video que falla no tumba la tarea completa (las imágenes y los
     * demás videos siguen) -- solo cuenta como fallido.
     */
    public function failed(Throwable $e): void
    {
        TareaMedios::avanzar($this->tareaId, 0, 1);
    }
}
