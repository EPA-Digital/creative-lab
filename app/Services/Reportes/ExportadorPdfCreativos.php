<?php

namespace App\Services\Reportes;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Reporte de creativos en PDF (ver ReporteCreativosService): resumen de
 * totales arriba y una ficha por creativo (imagen + datos + métricas),
 * con número de página.
 */
class ExportadorPdfCreativos
{
    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  array<int, ?string>  $miniaturas
     */
    public function generar(array $filas, array $miniaturas, string $titulo, string $subtitulo, string $rutaDestino): void
    {
        $opciones = new Options;
        // Las imágenes van incrustadas (data URI) -- nunca se piden URLs
        // remotas desde el PDF.
        $opciones->setIsRemoteEnabled(false);
        $opciones->setDefaultFont('DejaVu Sans');
        $opciones->setTempDir(sys_get_temp_dir());

        $pdf = new Dompdf($opciones);
        $pdf->loadHtml(view('reportes.creativos', [
            'filas' => $filas,
            'miniaturas' => $miniaturas,
            'titulo' => $titulo,
            'subtitulo' => $subtitulo,
            'totales' => ReporteCreativosService::totales($filas),
        ])->render());
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $pdf->getCanvas()->page_text(520, 815, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.45, 0.45, 0.5]);

        file_put_contents($rutaDestino, $pdf->output());
    }
}
