{{-- Reporte de creativos en PDF (2026-10-07) -- ver ExportadorPdfCreativos.
     dompdf: CSS 2.1 + tablas (sin flex/grid), por eso el layout va con tablas. --}}
@php
    $dinero = fn ($v) => $v === null ? '—' : '$'.number_format($v, 2);
    $numero = fn ($v) => $v === null ? '—' : number_format($v);
    $porcentaje = fn ($v) => $v === null ? '—' : number_format($v, 2).'%';
    $funnels = ['AWA' => 'Awareness', 'CONS' => 'Consideration', 'CNV' => 'Conversion', 'LOY' => 'Loyalty'];
    // La fuente del PDF (DejaVu) no trae emojis -- salían como cuadritos.
    // Benchmark (2026-11-13): CTR en %, el resto en dinero.
    $kpi = fn (string $campo, $v) => $campo === 'ctr' ? $porcentaje($v) : $dinero($v);
    $etiquetasKpi = \App\Services\Reportes\BenchmarkMes::ETIQUETAS;
    $estadoTexto = ['mejor' => 'Mejor que la media', 'peor' => 'Peor que la media', 'media' => 'En la media'];
    $sinEmojis = fn (?string $t) => trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{E000}-\x{F8FF}]/u', '', (string) $t));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $titulo }}</title>
<style>
    @page { margin: 26px 28px 34px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #1b1424; }
    h1 { font-size: 17px; margin: 0 0 2px; }
    .sub { color: #6b6f80; margin: 0 0 12px; }
    .resumen { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .resumen td { border: 1px solid #e2e2ea; padding: 6px 8px; text-align: center; }
    .resumen .k { display: block; font-size: 7px; text-transform: uppercase; color: #6b6f80; letter-spacing: .5px; }
    .resumen .v { font-size: 11px; font-weight: bold; }
    .creativo { width: 100%; border-collapse: collapse; border: 1px solid #e2e2ea; margin-bottom: 10px; page-break-inside: avoid; }
    .creativo td { vertical-align: top; }
    .img { width: 130px; padding: 8px; background: #f6f6f9; text-align: center; }
    .img img { max-width: 120px; max-height: 160px; }
    .sin-img { color: #9397ab; padding-top: 60px; }
    .info { padding: 8px 10px; }
    .nombre { font-size: 10.5px; font-weight: bold; margin: 0; }
    .tecnico { color: #9397ab; font-size: 7.5px; margin: 1px 0 0; }
    .chips { margin: 5px 0; }
    .chip { display: inline-block; padding: 1px 6px; margin: 0 3px 3px 0; border-radius: 8px; background: #eef3ff; color: #003ad6; font-size: 7.5px; }
    .etiqueta { color: #6b6f80; font-size: 7px; text-transform: uppercase; letter-spacing: .4px; margin: 6px 0 1px; }
    .texto { margin: 0; line-height: 1.35; }
    .metricas { width: 100%; border-collapse: collapse; margin-top: 7px; }
    .metricas td { border-top: 1px solid #eeeef3; padding: 3px 4px; width: 16.6%; }
    .metricas .k { display: block; font-size: 6.5px; text-transform: uppercase; color: #9397ab; }
    .metricas .v { font-size: 9px; font-weight: bold; }
    /* Benchmark del mes */
    .vs { width: 100%; border-collapse: collapse; margin-top: 7px; }
    .vs th { font-size: 6.5px; text-transform: uppercase; color: #6b6f80; text-align: left; padding: 3px 4px; border-top: 1px solid #eeeef3; }
    .vs td { padding: 3px 4px; font-size: 8px; border-top: 1px solid #eeeef3; }
    .vs .b { font-weight: bold; }
    .estado { font-weight: bold; }
    .estado-mejor { color: #16a34a; }
    .estado-peor { color: #dc2626; }
    .estado-media { color: #6b6f80; }
    .veredicto { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .veredicto td { padding: 6px 8px; font-size: 8px; line-height: 1.4; vertical-align: middle; }
    .veredicto .sello { width: 62px; text-align: center; font-weight: bold; font-size: 9px; border-radius: 6px; color: #ffffff; }
    .sello-bueno { background: #16a34a; }
    .sello-promedio { background: #6b6f80; }
    .sello-malo { background: #dc2626; }
    .sello-sin { background: #a3a3a3; }
    .veredicto .razon { background: #f6f6f9; }
</style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <p class="sub">{{ $subtitulo }}</p>

    <table class="resumen">
        <tr>
            <td><span class="k">Creativos</span><span class="v">{{ $numero(count($filas)) }}</span></td>
            <td><span class="k">Cost</span><span class="v">{{ $dinero($totales['cost']) }}</span></td>
            <td><span class="k">Impressions</span><span class="v">{{ $numero($totales['impressions']) }}</span></td>
            <td><span class="k">CTR</span><span class="v">{{ $porcentaje($totales['ctr']) }}</span></td>
            <td><span class="k">Installs</span><span class="v">{{ $numero($totales['installs']) }}</span></td>
            <td><span class="k">NC</span><span class="v">{{ $numero($totales['nc']) }}</span></td>
            <td><span class="k">CAC</span><span class="v">{{ $dinero($totales['cac']) }}</span></td>
            <td><span class="k">Órdenes</span><span class="v">{{ $numero($totales['orders']) }}</span></td>
            <td><span class="k">CPO</span><span class="v">{{ $dinero($totales['cpo']) }}</span></td>
        </tr>
    </table>


    @foreach ($filas as $i => $f)
        @include('reportes._ficha-creativo', ['f' => $f, 'miniatura' => $miniaturas[$i] ?? null, 'benchmark' => $benchmark])
    @endforeach
</body>
</html>
