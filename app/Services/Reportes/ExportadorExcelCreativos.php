<?php

namespace App\Services\Reportes;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reporte de creativos en Excel (ver ReporteCreativosService): una fila por
 * arte/anuncio con su miniatura incrustada, métricas con formato y fila de
 * totales.
 */
class ExportadorExcelCreativos
{
    private const FILA_ENCABEZADO = 4;

    private const ALTO_FILA_PT = 115;

    private const FUNNELS = ['AWA' => 'Awareness', 'CONS' => 'Consideration', 'CNV' => 'Conversion', 'LOY' => 'Loyalty'];

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  array<int, ?string>  $miniaturas
     */
    public function generar(array $filas, array $miniaturas, string $titulo, string $subtitulo, string $rutaDestino): void
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Creativos');

        // [encabezado, ancho, clave o closure, formato numérico]
        $columnas = [
            ['Imagen', 24, null, null],
            ['Nombre común', 34, fn ($f) => $f['nombreAmigable'] ? "{$f['nombreAmigable']}\n{$f['nombre']}" : $f['nombre'], null],
            ['Plataforma', 11, fn ($f) => $f['plataforma'] === 'meta' ? 'Meta' : 'TikTok', null],
            ['Etapa', 14, fn ($f) => self::FUNNELS[$f['funnel']] ?? 'Sin clasificar', null],
            ['Tipo de cuenta', 12, 'tipoCuenta', null],
            ['Formato', 11, fn ($f) => $f['formato'] ? ucfirst(mb_strtolower($f['formato'])) : '', null],
            ['Cuenta', 20, 'cuenta', null],
            ['Campaña', 40, fn ($f) => implode("\n", $f['campanias']), null],
            ['Copy', 50, fn ($f) => trim(($f['copyTitulo'] ? "{$f['copyTitulo']}\n" : '').($f['copyTexto'] ?? '')), null],
            ['Anuncios', 10, 'anuncios', '#,##0'],
            ['Cost', 13, 'cost', '"$"#,##0.00'],
            ['Impressions', 14, 'impressions', '#,##0'],
            ['Clicks', 11, 'clicks', '#,##0'],
            ['CTR', 9, fn ($f) => $f['ctr'] === null ? null : $f['ctr'] / 100, '0.00%'],
            ['CPM', 11, 'cpm', '"$"#,##0.00'],
            ['Installs', 11, 'installs', '#,##0'],
            ['CPI', 10, 'cpi', '"$"#,##0.00'],
            ['NC', 9, 'nc', '#,##0'],
            ['CAC', 10, 'cac', '"$"#,##0.00'],
            ['Órdenes', 10, 'orders', '#,##0'],
            ['CPO', 10, 'cpo', '"$"#,##0.00'],
            ['Ad IDs', 24, fn ($f) => implode("\n", $f['adIds']), null],
        ];
        $ultimaCol = Coordinate::stringFromColumnIndex(count($columnas));

        $hoja->setCellValue('A1', $titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $hoja->setCellValue('A2', $subtitulo);
        $hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('6B6F80');

        $fila = self::FILA_ENCABEZADO;
        foreach ($columnas as $c => [$encabezado, $ancho]) {
            $col = Coordinate::stringFromColumnIndex($c + 1);
            $hoja->setCellValue("{$col}{$fila}", $encabezado);
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
        $hoja->getStyle("A{$fila}:{$ultimaCol}{$fila}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '232532']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $hoja->freezePane('C'.($fila + 1));

        foreach ($filas as $i => $f) {
            $fila++;
            foreach ($columnas as $c => [, , $valor, $formato]) {
                if ($valor === null) {
                    continue;
                }
                $col = Coordinate::stringFromColumnIndex($c + 1);
                $hoja->setCellValue("{$col}{$fila}", is_callable($valor) ? $valor($f) : ($f[$valor] ?? null));
                if ($formato) {
                    $hoja->getStyle("{$col}{$fila}")->getNumberFormat()->setFormatCode($formato);
                }
            }
            $hoja->getRowDimension($fila)->setRowHeight(self::ALTO_FILA_PT);

            if ($miniaturas[$i] ?? null) {
                $dibujo = MemoryDrawing::fromString($miniaturas[$i]);
                $dibujo->setCoordinates("A{$fila}");
                $dibujo->setOffsetX(6);
                $dibujo->setOffsetY(4);
                $dibujo->setHeight(min(imagesy($dibujo->getImageResource()), (int) (self::ALTO_FILA_PT / 0.75) - 8));
                $dibujo->setWorksheet($hoja);
            } else {
                $hoja->setCellValue("A{$fila}", 'Sin imagen');
                $hoja->getStyle("A{$fila}")->getFont()->getColor()->setRGB('9397AB');
            }
        }

        $primeraDatos = self::FILA_ENCABEZADO + 1;
        $hoja->getStyle("A{$primeraDatos}:{$ultimaCol}{$fila}")->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDE3']]],
        ]);

        // Totales (ratios desde las sumas).
        $t = ReporteCreativosService::totales($filas);
        $fila++;
        $hoja->setCellValue("B{$fila}", 'Total');
        $totales = ['anuncios' => 'J', 'cost' => 'K', 'impressions' => 'L', 'clicks' => 'M', 'ctr' => 'N', 'cpm' => 'O', 'installs' => 'P', 'cpi' => 'Q', 'nc' => 'R', 'cac' => 'S', 'orders' => 'T', 'cpo' => 'U'];
        foreach ($totales as $clave => $col) {
            $valor = $clave === 'ctr' && $t['ctr'] !== null ? $t['ctr'] / 100 : $t[$clave];
            $hoja->setCellValue("{$col}{$fila}", $valor);
            $hoja->getStyle("{$col}{$fila}")->getNumberFormat()->setFormatCode($hoja->getStyle("{$col}{$primeraDatos}")->getNumberFormat()->getFormatCode());
        }
        $hoja->getStyle("A{$fila}:{$ultimaCol}{$fila}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF0F5']],
        ]);

        $hoja->setAutoFilter('A'.self::FILA_ENCABEZADO.":{$ultimaCol}".($fila - 1));

        (new Xlsx($libro))->save($rutaDestino);
        $libro->disconnectWorksheets();
    }
}
