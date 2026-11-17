{{-- Ficha de un creativo en los PDFs (creativos y comparación de grupos).
     Recibe $f (fila de ReporteCreativosService), $miniatura (bytes o null),
     $benchmark (BenchmarkMes o null) y los helpers de formato de la vista
     que la incluye. --}}
    <table class="creativo">
        <tr>
            <td class="img">
                @if ($miniatura)
                    <img src="data:image/jpeg;base64,{{ base64_encode($miniatura) }}" alt="">
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
                    @php
                        $cmp = $benchmark->comparar($f);
                        $ver = \App\Services\Reportes\BenchmarkMes::veredicto($cmp);
                        $sello = ['bueno' => 'Bueno', 'promedio' => 'Promedio', 'malo' => 'Malo'][$ver['calificacion']] ?? 'Sin dato';
                    @endphp
                    <table class="veredicto">
                        <tr>
                            <td class="sello sello-{{ $ver['calificacion'] ?? 'sin' }}">{{ $sello }}</td>
                            <td class="razon">{{ $ver['texto'] }}</td>
                        </tr>
                    </table>
                    <table class="vs">
                        <tr>
                            <th>vs. {{ $cmp['comparables'] }} {{ $cmp['comparables'] === 1 ? 'creativo' : 'creativos' }} del mes ({{ $f['plataforma'] === 'meta' ? 'Meta' : 'TikTok' }} · {{ $f['tipoCuenta'] ?? 'Sin tipo' }} · {{ $funnels[$f['funnel']] ?? 'Sin clasificar' }})</th>
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
