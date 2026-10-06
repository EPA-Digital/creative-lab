<?php

// Videos de los creativos (2026-10-06) -- ver VideoCreativoService.
return [
    // Cuántos videos de TikTok se guardan en segundo plano después de cada
    // importación (los de mayor gasto del mes). Los demás se guardan la
    // primera vez que alguien los abre en el modal. Ajustable sin deploy de
    // código: variable de entorno VIDEOS_TOP_POR_MES.
    'top_por_mes' => (int) env('VIDEOS_TOP_POR_MES', 30),

    // Versión ligera que se guarda en el bucket: lado corto en px (un
    // vertical 1080x1920 queda 480x854), calidad (CRF de x264: más alto =
    // más liviano, ~1-3 MB por video) y audio.
    'alto' => 480,
    'crf' => 30,
    'audio_kbps' => 64,
    // Tope de duración guardada, en segundos.
    'duracion_maxima' => 90,
];
