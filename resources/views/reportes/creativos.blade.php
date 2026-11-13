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
    .bench-titulo { font-size: 11px; font-weight: bold; margin: 4px 0 2px; }
    .bench-nota { color: #6b6f80; font-size: 7px; margin: 0 0 6px; }
    .bench { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .bench th { font-size: 6.5px; text-transform: uppercase; color: #6b6f80; text-align: left; padding: 3px 5px; border-bottom: 1px solid #e2e2ea; }
    .bench td { padding: 3px 5px; border-bottom: 1px solid #eeeef3; font-size: 7.5px; vertical-align: top; }
    .bench .grupo { font-weight: bold; }
    .bench .mejor-nombre { color: #6b6f80; font-size: 6.5px; }
    .vs { width: 100%; border-collapse: collapse; margin-top: 7px; }
    .vs th { font-size: 6.5px; text-transform: uppercase; color: #6b6f80; text-align: left; padding: 3px 4px; border-top: 1px solid #eeeef3; }
    .vs td { padding: 3px 4px; font-size: 8px; border-top: 1px solid #eeeef3; }
    .vs .b { font-weight: bold; }
    .estado { font-weight: bold; }
    .estado-mejor { color: #16a34a; }
    .estado-peor { color: #dc2626; }
    .estado-media { color: #6b6f80; }
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

    @if ($benchmark)
        <p class="bench-titulo">Benchmark del mes</p>
        <p class="bench-nota">Todos los creativos del mes en el país, por plataforma y etapa (sin los filtros del reporte). Media = promedio simple; mejor = el más barato (o el CTR más alto). Solo cuentan los creativos con volumen suficiente (gasto mayor a $0 y al menos 1,000 impresiones para CPM/CTR, 30 instalaciones para CPI, 10 nuevos clientes para CAC y 10 órdenes para CPO), los mismos pisos que los rankings del dashboard. En cada ficha, el creativo se compara contra los demás de su grupo; ±10% de la media cuenta como "en la media".</p>
        <table class="bench">
            <tr><th>Plataforma · etapa</th><th>Creativos</th><th>KPI</th><th>Media</th><th>Mejor</th></tr>
            @foreach ($benchmark->resumen() as $g)
                @foreach ($g['kpis'] as $k => $b)
                    <tr>
                        @if ($k === 0)
                            <td class="grupo" rowspan="{{ count($g['kpis']) }}">{{ $g['plataforma'] === 'meta' ? 'Meta' : 'TikTok' }} · {{ $funnels[$g['funnel']] ?? 'Sin clasificar' }}</td>
                            <td rowspan="{{ count($g['kpis']) }}">{{ $numero($g['creativos']) }}</td>
                        @endif
                        <td>{{ $etiquetasKpi[$b['campo']] }}</td>
                        <td>{{ $kpi($b['campo'], $b['media']) }}</td>
                        <td>{{ $kpi($b['campo'], $b['mejor']) }} @if ($b['mejorNombre'])<span class="mejor-nombre">· {{ \Illuminate\Support\Str::limit($b['mejorNombre'], 48) }}</span>@endif</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    @endif

    @foreach ($filas as $i => $f)
        <table class="creativo">
            <tr>
                <td class="img">
                    @if ($miniaturas[$i] ?? null)
                        <img src="data:image/jpeg;base64,{{ base64_encode($miniaturas[$i]) }}" alt="">
                    @else
                        <div class="sin-img">Sin imagen</div>
                    @endif
                </td>
                <td class="info">
                    <p class="nombre">{{ $f['nombreAmigable'] ?: $f['nombre'] }}</p>
                    @if ($f['nombreAmigable'])
                        <p class="tecnico">{{ $f['nombre'] }}</p>
                    @endif
                    <div class="chips">
                        <span class="chip">{{ $f['plataforma'] === 'meta' ? 'Meta' : 'TikTok' }}</span>
                        <span class="chip">{{ $funnels[$f['funnel']] ?? 'Sin clasificar' }}</span>
                        @if ($f['tipoCuenta'])<span class="chip">{{ $f['tipoCuenta'] }}</span>@endif
                        @if ($f['formato'])<span class="chip">{{ ucfirst(mb_strtolower($f['formato'])) }}</span>@endif
                        @if ($f['cuenta'])<span class="chip">{{ $f['cuenta'] }}</span>@endif
                        <span class="chip">{{ $f['anuncios'] }} {{ $f['anuncios'] === 1 ? 'anuncio' : 'anuncios' }}</span>
                    </div>

                    @if ($f['campanias'])
                        <p class="etiqueta">Campaña</p>
                        <p class="texto">{{ implode(' · ', $f['campanias']) }}</p>
                    @endif
                    @if ($f['copyTitulo'] || $f['copyTexto'])
                        <p class="etiqueta">Copy</p>
                        <p class="texto">@if ($f['copyTitulo'])<strong>{{ $sinEmojis($f['copyTitulo']) }}</strong> — @endif{{ \Illuminate\Support\Str::limit($sinEmojis($f['copyTexto']), 420) }}</p>
                    @endif

                    <table class="metricas">
                        <tr>
                            <td><span class="k">Cost</span><span class="v">{{ $dinero($f['cost']) }}</span></td>
                            <td><span class="k">Impressions</span><span class="v">{{ $numero($f['impressions']) }}</span></td>
                            <td><span class="k">Clicks</span><span class="v">{{ $numero($f['clicks']) }}</span></td>
                            <td><span class="k">CTR</span><span class="v">{{ $porcentaje($f['ctr']) }}</span></td>
                            <td><span class="k">CPM</span><span class="v">{{ $dinero($f['cpm']) }}</span></td>
                            <td><span class="k">Installs</span><span class="v">{{ $numero($f['installs']) }}</span></td>
                        </tr>
                        <tr>
                            <td><span class="k">CPI</span><span class="v">{{ $dinero($f['cpi']) }}</span></td>
                            <td><span class="k">NC</span><span class="v">{{ $numero($f['nc']) }}</span></td>
                            <td><span class="k">CAC</span><span class="v">{{ $dinero($f['cac']) }}</span></td>
                            <td><span class="k">Órdenes</span><span class="v">{{ $numero($f['orders']) }}</span></td>
                            <td><span class="k">CPO</span><span class="v">{{ $dinero($f['cpo']) }}</span></td>
                            <td><span class="k">Ad ID</span><span class="v" style="font-size:7px">{{ implode(', ', array_slice($f['adIds'], 0, 3)) }}{{ count($f['adIds']) > 3 ? '…' : '' }}</span></td>
                        </tr>
                    </table>

                    @if ($benchmark)
                        @php $cmp = $benchmark->comparar($f); @endphp
                        <table class="vs">
                            <tr>
                                <th>vs. {{ $cmp['comparables'] }} {{ $cmp['comparables'] === 1 ? 'creativo' : 'creativos' }} del mes ({{ $f['plataforma'] === 'meta' ? 'Meta' : 'TikTok' }} · {{ $funnels[$f['funnel']] ?? 'Sin clasificar' }})</th>
                                <th>Este</th><th>Media</th><th>Mejor</th><th></th>
                            </tr>
                            @foreach ($cmp['kpis'] as $c)
                                <tr>
                                    <td class="b">{{ $etiquetasKpi[$c['campo']] }}</td>
                                    <td class="b">{{ $kpi($c['campo'], $c['valor']) }}</td>
                                    <td>{{ $kpi($c['campo'], $c['media']) }}</td>
                                    <td>{{ $kpi($c['campo'], $c['mejor']) }}</td>
                                    <td>
                                        @if ($c['estado'])
                                            <span class="estado estado-{{ $c['estado'] }}">{{ $estadoTexto[$c['estado']] }}@if ($c['estado'] !== 'media') ({{ $c['pct'] }}%)@endif</span>
                                            @if ($c['esElMejor'])<span class="estado estado-mejor"> · el mejor del mes</span>@endif
                                            @if ($c['pocoVolumen'])<span class="estado-media"> · poco volumen</span>@endif
                                        @else
                                            <span class="estado estado-media">Sin con qué comparar</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </td>
            </tr>
        </table>
    @endforeach
</body>
</html>
