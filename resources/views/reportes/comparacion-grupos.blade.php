{{-- Comparación de grupos en PDF (2026-11-17) -- ver ExportadorPdfGrupos. Mismos
     estilos y ficha que el reporte de creativos (reportes.creativos).
     dompdf: CSS 2.1 + tablas (sin flex/grid). --}}
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
    h2 { font-size: 12px; margin: 16px 0 6px; }
    .grupo-titulo { font-size: 13px; margin: 0 0 4px; padding-left: 8px; border-left: 4px solid #6b6f80; }
    .cmp { width: 100%; border-collapse: collapse; margin-bottom: 10px; page-break-inside: avoid; }
    .cmp th { font-size: 7.5px; text-align: left; padding: 5px 6px; border-bottom: 1px solid #e2e2ea; vertical-align: bottom; }
    .cmp th .s { display: block; font-weight: normal; color: #6b6f80; }
    .cmp td { padding: 5px 6px; border-bottom: 1px solid #eeeef3; vertical-align: top; }
    .cmp .v { font-size: 10px; font-weight: bold; }
    .cmp .gana { color: #16a34a; }
    .cmp .m { display: block; color: #6b6f80; font-size: 7px; }
    .gana-txt { color: #6b6f80; margin: 0 0 4px; }
    .salto { page-break-before: always; }
</style>
</head>
<body>
@php
    $colores = ["#2a78d6", "#eb6834", "#1baf7a", "#eda100"];
    $mesTexto = fn (string $m) => \Carbon\Carbon::createFromFormat('Y-m', $m)->locale('es')->translatedFormat('F Y');
    $nombreGrupo = fn (array $g) => '"'.$g['q'].'" · '.$mesTexto($g['mes']);
    $orden = ['AWA', 'CON', 'CONS', 'CNV', 'LOY', 'SIN'];
    $presentes = collect($grupos)->flatMap(fn ($g) => array_column($g['etapas'], 'funnel'))->unique();
    $etapas = array_values(array_filter($orden, fn ($e) => $presentes->contains($e)));
    $funnels = $funnels + ['CON' => 'Consideration', 'SIN' => 'Sin etapa'];
@endphp
    <h1>{{ $titulo }}</h1>
    <p class="sub">{{ $subtitulo }}</p>

    <table class="resumen">
        <tr>
            @foreach ($grupos as $gi => $g)
                <td style="border-top: 3px solid {{ $colores[$gi] }}">
                    <span class="k">{{ $nombreGrupo($g) }}</span>
                    <span class="v">{{ $dinero($g['totales']['cost']) }}</span>
                    <span class="k">{{ $g['creativos'] }} creativo(s) · {{ $numero($g['totales']['orders']) }} órdenes · {{ $numero($g['totales']['nc']) }} NC</span>
                </td>
            @endforeach
        </tr>
    </table>

    @foreach ($etapas as $etapa)
        @php
            $porGrupo = array_map(fn ($g) => collect($g['etapas'])->firstWhere('funnel', $etapa), $grupos);
            $campos = array_column(collect($porGrupo)->filter()->first()['kpis'], 'campo');
        @endphp
        <h2>{{ $funnels[$etapa] ?? $etapa }}</h2>
        <table class="cmp">
            <tr>
                <th>KPI</th>
                @foreach ($grupos as $gi => $g)
                    <th style="border-top: 3px solid {{ $colores[$gi] }}">{{ $nombreGrupo($g) }}
                        <span class="s">{{ $porGrupo[$gi] ? $porGrupo[$gi]['creativos'].' creativo(s) · '.$dinero($porGrupo[$gi]['totales']['cost']) : 'sin creativos' }}</span>
                    </th>
                @endforeach
            </tr>
            @foreach ($campos as $ci => $campo)
                @php
                    $celdas = array_map(fn ($e) => $e ? collect($e['kpis'])->firstWhere('campo', $campo) : null, $porGrupo);
                    $valores = array_values(array_filter(array_map(fn ($c) => $c['valor'] ?? null, $celdas), fn ($v) => $v !== null));
                    $mejor = count($valores) > 1 ? ($campo === 'ctr' ? max($valores) : min($valores)) : null;
                @endphp
                <tr>
                    <td><strong>{{ $etiquetasKpi[$campo] }}</strong></td>
                    @foreach ($celdas as $c)
                        <td>
                            @if ($c && $c['valor'] !== null)
                                <span class="v {{ $mejor !== null && $c['valor'] == $mejor ? 'gana' : '' }}">{{ $kpi($campo, $c['valor']) }}{{ $mejor !== null && $c['valor'] == $mejor ? ' (gana)' : '' }}</span>
                                <span class="m">media del mes {{ $kpi($campo, $c['benchmark']) }}
                                    @if ($c['estado'])· <span class="estado estado-{{ $c['estado'] }}">{{ $estadoTexto[$c['estado']] }}@if ($c['estado'] !== 'media') ({{ $c['pct'] }}%)@endif</span>@endif
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endforeach
    <p class="gana-txt">KPIs sobre totales del grupo (costo total entre resultados totales). La media del mes es todo lo de esa etapa en ese mes, con los mismos filtros. "En la media" es ±10%.</p>

    @foreach ($grupos as $gi => $g)
        <div class="salto"></div>
        <p class="grupo-titulo" style="border-left-color: {{ $colores[$gi] }}">{{ $nombreGrupo($g) }}</p>
        <p class="sub">{{ $g['creativos'] }} creativo(s), cada uno contra los demás de su mes (misma plataforma, tipo de cuenta y etapa).</p>
        @forelse ($g['filas'] as $i => $f)
            @include('reportes._ficha-creativo', ['f' => $f, 'miniatura' => $miniaturas[$gi][$i] ?? null, 'benchmark' => $g['benchmark']])
        @empty
            <p class="sub">Ningún creativo coincide con esta búsqueda en este mes.</p>
        @endforelse
    @endforeach
</body>
</html>
