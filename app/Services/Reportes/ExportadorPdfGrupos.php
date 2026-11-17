<?php

namespace App\Services\Reportes;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF de la comparación por grupos de Inteligencia (2026-11-17): los grupos
 * lado a lado por etapa contra la media de su mes y, después, los creativos
 * de cada grupo con la misma ficha que el reporte de creativos.
 */
class ExportadorPdfGrupos
{
    /**
     * @param  list<array<string, mixed>>  $grupos  CompararGrupos::comparar()
     * @param  list<array<int, ?string>>  $miniaturas  por grupo, en el orden de sus filas
     */
    public function generar(array $grupos, array $miniaturas, string $titulo, string $subtitulo, string $rutaDestino): void
    {
        $opciones = new Options;
        $opciones->setIsRemoteEnabled(false);
        $opciones->setDefaultFont('DejaVu Sans');
        $opciones->setTempDir(sys_get_temp_dir());

        $pdf = new Dompdf($opciones);
        $pdf->loadHtml(view('reportes.comparacion-grupos', compact('grupos', 'miniaturas', 'titulo', 'subtitulo'))->render());
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $pdf->getCanvas()->page_text(520, 815, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.45, 0.45, 0.5]);

        file_put_contents($rutaDestino, $pdf->output());
    }
}
