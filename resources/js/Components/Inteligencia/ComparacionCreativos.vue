<script setup>
import { computed } from 'vue';
import {
    cardDesdeCreativo, placeholderPorTipo, promediosDeGrupo, calcularScoreDesglose, serieMensual, serieDiaria,
    DIRECCION_METRICA_MAYOR_ES_MEJOR, FUNNEL_LABELS, nombrePrincipalYTecnico,
    formatMoneyExacto, formatPercent, formatNumeroExacto,
    formatearRangoFecha, diasEnRango, evaluarComparabilidad,
} from '@/motor';
import TendenciaChart from '@/Components/Inteligencia/TendenciaChart.vue';

// ComparacionCreativos -- 2026-08-27, Inteligencia (ver plan). Head-to-head
// de 2-4 creativos YA elegidos (mismo funnel, garantizado por
// Inteligencia.vue). Dos hallazgos reales respetados acá (nunca inventar
// datos, ver plan):
// 1) Las imágenes son las REALES del creativo (cardDesdeCreativo/
//    placeholderPorTipo) -- el mockup usaba íconos SVG ilustrados porque el
//    canvas de diseño no tenía acceso a imágenes reales.
// 2) La tendencia es MENSUAL (con datos reales de `resultados`), no
//    "diaria" como en el mockup -- la tabla resultados solo tiene una fila
//    por creativo por mes, no hay granularidad diaria en el pipeline.
const props = defineProps({
    creativos: { type: Array, required: true }, // 2-4 creativos elegidos (objetos crudos de Eloquent)
    todos: { type: Array, required: true }, // todos los creativos del país+mes, para promedios de grupo
    mes: { type: String, required: true },
});
const emit = defineEmits(['volver', 'abrir']);

// "Ver detalle" desde Inteligencia (2026-08-27, ver plan del rediseño) --
// abre el MISMO CreativeModal.vue real de Creativos, no uno nuevo. Antes
// las cards de comparación eran solo informativas (.comparacion-card tenía
// cursor:default); Inteligencia.vue monta el modal cuando llega este evento
// y le pasa promediosDeGrupo(creativo, todos, mes) -- mismo cálculo que ya
// usa AnalisisCreativo.vue para su propio modal, ninguna lógica nueva.
function abrirDetalle(it) {
    emit('abrir', it.creativo);
}

const IDENTIDAD = ['var(--amber)', 'var(--sky)', 'var(--mint)', 'var(--yellow)'];
const METRICA_LABEL = {
    cpm: 'CPM', cpi: 'CPI', cpo: 'CPO', cac: 'CAC', ctr: 'CTR', cost: 'Costo',
    impressions: 'Impresiones', installs: 'Instalaciones', orders: 'Órdenes', newCustomers: 'Nuevos clientes',
};
const CAMPOS_CONTEO = new Set(['impressions', 'installs', 'orders', 'newCustomers']);

// METRICAS_COMPARACION_POR_FUNNEL -- 2026-08-27, pedido explícito: "muestra
// tooodas [las métricas relevantes], pero para calificar enfocate en sus
// principales KPIs". Deliberadamente MÁS generosa que
// motor.js:METRICAS_RELEVANTES_POR_FUNNEL (que sigue siendo la fuente de
// verdad para Lectura y las categorías del Score, sin tocar acá) -- suma
// el KPI de VOLUMEN propio de cada etapa (ej. Impresiones en Awareness)
// para que la comparación cuente la historia completa, aunque el Score
// (calcularScoreDesglose) se quede enfocado solo en las métricas de
// eficiencia core.
//
// 2026-08-28, dos pedidos explícitos:
// 1) `cost` en TODAS las etapas -- hasta ahora el costo total no aparecía
//    en ningún lado de esta comparación, solo las eficiencias derivadas
//    (CPM/CPI/CPO/CAC).
// 2) installs/newCustomers/orders también en AWA -- Meta (y desde agosto
//    TikTok) SÍ atribuyen instalaciones/NC/órdenes en Awareness, ya no es
//    "no aplica" (ver el fallback de conteo crudo en motor.js:etapasFunnel,
//    mismo criterio). filasParaMetrica ya descarta solo la fila si hay
//    menos de 2 creativos con valor real para ese campo, así que un grupo
//    AWA sin ninguna atribución real simplemente no muestra esas filas --
//    nunca fuerza un dato que no existe.
const METRICAS_COMPARACION_POR_FUNNEL = {
    AWA: ['impressions', 'cost', 'cpm', 'ctr', 'installs', 'newCustomers', 'orders'],
    CON: ['installs', 'cost', 'cpi', 'ctr'],
    CONS: ['installs', 'cost', 'cpi', 'ctr'],
    CNV: ['newCustomers', 'cost', 'cac', 'cpo', 'ctr'],
    LOY: ['orders', 'cost', 'cpo', 'ctr'],
};
const items = computed(() => props.creativos.map((creativo) => {
    const card = cardDesdeCreativo(creativo, props.mes);
    const promedios = promediosDeGrupo(creativo, props.todos, props.mes);
    return { creativo, card, score: calcularScoreDesglose(card, promedios) };
}));

// Ordenados por score (mejor primero) -- define posición, color de
// identidad y quién lleva "Líder". Score null (sin datos suficientes) va
// al final -- nunca se inventa un orden con datos que no existen.
const ordenados = computed(() => [...items.value]
    .sort((a, b) => (b.score?.score ?? -1) - (a.score?.score ?? -1))
    .map((it, i) => ({ ...it, color: IDENTIDAD[i] ?? 'var(--text-faint)', posicion: i + 1, ph: placeholderPorTipo(it.card.tipoCreativo) })));

const esVs = computed(() => ordenados.value.length === 2);
const funnelComun = computed(() => ordenados.value[0]?.card.etapaFunnel ?? null);
const camposMetrica = computed(() => METRICAS_COMPARACION_POR_FUNNEL[funnelComun.value] || []);

// Con nombreAmigable asignado, es el que se usa en los usos compactos de un
// solo string (barras, leyenda de tendencia, la frase "X gana en N de M
// métricas") -- taxonomía legible aplica en Inteligencia igual que en
// Creativos (2026-08-27, ver plan). nombrePrincipalYTecnico (con
// subtexto técnico aparte) se usa en el título de la card, más abajo.
function nombreCorto(it) {
    return it.card.nombreAmigable || it.card.arte || it.card.adNameShort || it.card.adId;
}
function nombreCard(it) {
    return nombrePrincipalYTecnico(it.card.nombreAmigable, it.card.arte || it.card.adNameShort || it.card.adId);
}
function formatearValor(campo, valor) {
    if (campo === 'ctr') return formatPercent(valor);
    if (CAMPOS_CONTEO.has(campo)) return formatNumeroExacto(valor);
    return formatMoneyExacto(valor);
}

// filasParaMetrica -- para una métrica, ordena los creativos de mejor a
// peor y calcula el largo de barra (20%-100%, nunca 0%, mismo lenguaje
// visual del mockup pero con datos reales). Nunca compara con menos de 2
// creativos con valor real para ese campo.
function filasParaMetrica(campo) {
    const filas = ordenados.value
        .map((it) => ({ it, valor: it.card[campo] }))
        .filter((f) => f.valor !== null && f.valor !== undefined);
    if (filas.length < 2) return null;
    const mayorEsMejor = DIRECCION_METRICA_MAYOR_ES_MEJOR[campo] ?? true;
    filas.sort((a, b) => (mayorEsMejor ? b.valor - a.valor : a.valor - b.valor));
    const mejorValor = filas[0].valor;
    const peorValor = filas[filas.length - 1].valor;
    const span = Math.abs(mejorValor - peorValor);
    return filas.map((f) => ({
        ...f,
        pct: span === 0 ? 100 : 20 + 80 * (mayorEsMejor ? (f.valor - peorValor) / span : (peorValor - f.valor) / span),
    }));
}
const TITULO_METRICA = {
    cpm: 'Eficiencia en CPM', cpi: 'Eficiencia en CPI', cpo: 'Eficiencia en CPO', cac: 'Eficiencia en CAC',
    ctr: 'Enganche (CTR)', impressions: 'Volumen (Impresiones)', installs: 'Volumen (Instalaciones)',
    orders: 'Volumen (Órdenes)', newCustomers: 'Volumen (Nuevos clientes)', cost: 'Costo total',
};
const metricasConDatos = computed(() => camposMetrica.value
    .map((campo) => ({
        campo,
        label: METRICA_LABEL[campo] || campo.toUpperCase(),
        titulo: TITULO_METRICA[campo] || METRICA_LABEL[campo] || campo.toUpperCase(),
        filas: filasParaMetrica(campo),
    }))
    .filter((m) => m.filas !== null));

// resumenGanador -- "X gana en N de M métricas", mismo criterio que el
// mockup pero contado sobre datos reales (cuántas filas encabeza cada
// creativo).
const resumenGanador = computed(() => {
    if (!metricasConDatos.value.length) return null;
    const conteos = new Map();
    metricasConDatos.value.forEach((m) => {
        const id = m.filas[0].it.creativo.id;
        conteos.set(id, (conteos.get(id) || 0) + 1);
    });
    const [idGanador, votos] = [...conteos.entries()].sort((a, b) => b[1] - a[1])[0];
    const it = ordenados.value.find((x) => x.creativo.id === idGanador);
    return it ? { it, votos, total: metricasConDatos.value.length } : null;
});

// opcionesTendencia -- 2026-08-28, pedido explícito: antes se probaba UNA
// lista fija de candidatas y se mostraba la primera con datos (sin poder
// elegir); ahora se ofrecen TODAS las métricas de METRICAS_COMPARACION_POR_FUNNEL
// que sí tengan 2+ puntos reales, y el usuario elige cuál ver dentro de
// TendenciaChart.vue (selector de métrica + tipo de gráfica ahí). Los
// puntos se normalizan a {clave, valor} -- `serieFn` decide si `clave` es
// mes o fecha, TendenciaChart no necesita saberlo.
function construirOpcionesTendencia(serieFn) {
    return METRICAS_COMPARACION_POR_FUNNEL[funnelComun.value]
        ?.map((campo) => {
            const series = ordenados.value.map((it) => ({
                it,
                puntos: serieFn(it.creativo, campo).map((p) => ({ clave: p.mes ?? p.fecha, valor: p.valor })),
            }));
            const eje = [...new Set(series.flatMap((s) => s.puntos.map((p) => p.clave)))].sort();
            return { campo, label: METRICA_LABEL[campo] || campo.toUpperCase(), series, eje };
        })
        .filter((o) => o.eje.length >= 2 && o.series.some((s) => s.puntos.length >= 2)) ?? [];
}
// Tendencia mensual real (motor.js:serieMensual) -- nunca inventa un punto
// para un mes sin resultado.
const opcionesTendenciaMensual = computed(() => construirOpcionesTendencia(serieMensual));
// Tendencia DIARIA (2026-08-28, pedido explícito) -- mismo criterio, pero
// dentro del MES YA elegido (motor.js:serieDiaria) en vez de a través de
// varios meses. Solo existe para países con `resultados_diarios` (hoy
// Panamá) -- un país sin esa relación cargada da series vacías para todos
// los creativos y la lista de opciones queda vacía, el bloque entero se
// oculta (TendenciaChart con opciones=[] no se monta).
const opcionesTendenciaDiaria = computed(() => construirOpcionesTendencia(serieDiaria));

// diasActivos -- 2026-08-28, pedido explícito: "días activos" como dato
// visible por creativo en Inteligencia. Vacío (null) para países/creativos
// sin resultados_diarios -- la card simplemente no muestra la línea, nunca
// fuerza un dato que no existe.
function diasActivos(it) {
    const rango = it.card.rangosActividad?.[0] ?? null;
    return rango ? { rango, dias: diasEnRango(rango) } : null;
}

// comparabilidad -- 2026-08-28, pedido explícito: al comparar 2+
// creativos, marcar claramente cuáles SÍ y cuáles NO son comparables
// según sus días activos REALES (no la taxonomía), con el motivo. Ver
// motor.js:evaluarComparabilidad -- basado en rangosActividad
// (aproximado desde resultados_diarios).
const comparabilidad = computed(() => evaluarComparabilidad(ordenados.value.map((it) => it.card)));
</script>

<template>
    <div class="comparacion">
        <div class="comparacion-header">
            <button type="button" class="comparacion-volver" @click="$emit('volver')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
                Elegir otros creativos
            </button>
            <div class="comparacion-titulo">
                <span class="comparacion-eyebrow">Inteligencia · Competencia{{ !esVs ? ` de ${ordenados.length}` : '' }}</span>
                <span class="comparacion-titulo-texto">
                    {{ esVs ? `${FUNNEL_LABELS[funnelComun] || funnelComun} vs. ${FUNNEL_LABELS[funnelComun] || funnelComun}` : `Todos ${FUNNEL_LABELS[funnelComun] || funnelComun} — comparados a la vez` }}
                </span>
            </div>
            <span class="comparacion-header-spacer"></span>
        </div>

        <!-- Comparabilidad real (2026-08-28, pedido explícito) -- días
             activos REALES (no taxonomía) definen si esta comparación tiene
             sentido. Solo aparece si hay al menos un par con datos reales. -->
        <div v-if="comparabilidad.pares.length || comparabilidad.avisos.length" class="comparacion-comparabilidad" :class="{ 'comparacion-comparabilidad--ok': comparabilidad.todosComparables }">
            <p class="comparacion-comparabilidad-titulo">
                <template v-if="comparabilidad.todosComparables">✓ Corrieron en simultáneo -- comparación válida</template>
                <template v-else>⚠ Comparación con reservas -- no todos corrieron en simultáneo</template>
            </p>
            <ul class="comparacion-comparabilidad-lista">
                <li v-for="(p, i) in comparabilidad.pares.filter((p) => p.comparable !== true)" :key="'par-' + i">{{ p.motivo }}</li>
                <li v-for="(a, i) in comparabilidad.avisos" :key="'aviso-' + i">{{ a.mensaje }}</li>
            </ul>
        </div>

        <!-- Layout VS (2 creativos) -->
        <div v-if="esVs" class="comparacion-vs">
            <span class="comparacion-vs-divisor"></span>
            <span class="comparacion-vs-badge">VS</span>
            <div v-for="it in ordenados" :key="it.creativo.id" class="comparacion-vs-lado">
                <article
                    class="resumen-card comparacion-card"
                    :style="{ '--id-color': it.color, borderColor: it.color }"
                    tabindex="0"
                    role="button"
                    @click="abrirDetalle(it)"
                    @keydown.enter.space.prevent="abrirDetalle(it)"
                >
                    <div class="resumen-card-imagen">
                        <div class="no-image" :style="it.card.imageUrl ? { display: 'none' } : {}">
                            <span class="glyph">{{ it.ph.glyph }}</span>{{ it.ph.texto }}
                        </div>
                        <img v-if="it.card.imageUrl" :src="it.card.imageUrl" alt="" loading="lazy" />
                        <span v-if="it.card.tipoCreativo === 'VIDEO'" class="badge badge-tipo">▶ Video</span>
                    </div>
                    <div class="resumen-card-body">
                        <p class="resumen-card-titulo">{{ nombreCard(it).principal }}</p>
                        <p v-if="nombreCard(it).tecnico" class="resumen-card-tecnico mono">{{ nombreCard(it).tecnico }}</p>
                        <p class="resumen-card-meta">{{ it.card.plataforma === 'tiktok' ? 'TikTok Ads' : 'Meta Ads' }}</p>
                        <p v-if="diasActivos(it)" class="resumen-card-vigencia" title="Aproximado: primer día con impresiones o costo real hasta el último -- no es el registro exacto de Meta">
                            {{ diasActivos(it).dias }} días activos ({{ formatearRangoFecha(diasActivos(it).rango) }})
                        </p>
                    </div>
                </article>
                <div v-if="it.score" class="comparacion-score">
                    <span class="comparacion-score-label">Score</span>
                    <span class="mono comparacion-score-valor" :style="{ color: it.color }">{{ it.score.nivel }}/5</span>
                    <span class="comparacion-score-etiqueta">{{ it.score.etiqueta }}</span>
                </div>
            </div>
        </div>

        <!-- Layout grilla (3-4 creativos) -->
        <div v-else class="comparacion-grilla" :style="{ '--n-cols': ordenados.length }">
            <div v-for="it in ordenados" :key="it.creativo.id" class="comparacion-grilla-col">
                <article
                    class="resumen-card comparacion-card"
                    :style="{ borderColor: it.color }"
                    tabindex="0"
                    role="button"
                    @click="abrirDetalle(it)"
                    @keydown.enter.space.prevent="abrirDetalle(it)"
                >
                    <span v-if="it.posicion === 1" class="comparacion-lider" :style="{ color: it.color, background: `color-mix(in srgb, ${it.color} 20%, var(--bg))` }">Líder</span>
                    <div class="resumen-card-imagen">
                        <div class="no-image" :style="it.card.imageUrl ? { display: 'none' } : {}">
                            <span class="glyph">{{ it.ph.glyph }}</span>{{ it.ph.texto }}
                        </div>
                        <img v-if="it.card.imageUrl" :src="it.card.imageUrl" alt="" loading="lazy" />
                        <span v-if="it.card.tipoCreativo === 'VIDEO'" class="badge badge-tipo">▶ Video</span>
                    </div>
                    <div class="resumen-card-body">
                        <p class="resumen-card-titulo">{{ nombreCard(it).principal }}</p>
                        <p v-if="nombreCard(it).tecnico" class="resumen-card-tecnico mono">{{ nombreCard(it).tecnico }}</p>
                        <p class="resumen-card-meta">{{ it.card.plataforma === 'tiktok' ? 'TikTok Ads' : 'Meta Ads' }}</p>
                        <p v-if="diasActivos(it)" class="resumen-card-vigencia" title="Aproximado: primer día con impresiones o costo real hasta el último -- no es el registro exacto de Meta">
                            {{ diasActivos(it).dias }} días activos ({{ formatearRangoFecha(diasActivos(it).rango) }})
                        </p>
                    </div>
                </article>
                <div v-if="it.score" class="comparacion-score comparacion-score--centrado">
                    <span class="comparacion-score-label">Score</span>
                    <span class="mono comparacion-score-valor" :style="{ color: it.color }">{{ it.score.nivel }}/5</span>
                    <span class="comparacion-score-etiqueta">{{ it.score.etiqueta }}</span>
                </div>
            </div>
        </div>

        <!-- Barras "quién va ganando" por métrica -->
        <div v-if="metricasConDatos.length" class="comparacion-bloque">
            <p v-if="resumenGanador" class="comparacion-resumen">
                {{ nombreCorto(resumenGanador.it) }} gana en {{ resumenGanador.votos }} de {{ resumenGanador.total }} métricas
            </p>
            <div v-for="m in metricasConDatos" :key="m.campo" class="comparacion-metrica">
                <span class="comparacion-metrica-label">{{ m.titulo }}</span>
                <div v-for="f in m.filas" :key="f.it.creativo.id" class="comparacion-barra-fila">
                    <span class="comparacion-barra-nombre">{{ nombreCorto(f.it) }}</span>
                    <div class="comparacion-barra-track">
                        <span class="comparacion-barra-fill" :style="{ width: f.pct + '%', background: f.it.color }"></span>
                    </div>
                    <span class="mono comparacion-barra-valor">{{ formatearValor(m.campo, f.valor) }}</span>
                </div>
            </div>
        </div>

        <!-- Tendencia mensual real -->
        <div v-if="opcionesTendenciaMensual.length" class="comparacion-bloque">
            <TendenciaChart titulo="Tendencia mensual" :opciones="opcionesTendenciaMensual" eje-tipo="mes" />
        </div>

        <!-- Tendencia diaria real (2026-08-28, solo países con resultados_diarios --
             hoy Panamá) -- mismo componente, día a día dentro del mes elegido en
             vez de mes a mes. -->
        <div v-if="opcionesTendenciaDiaria.length" class="comparacion-bloque">
            <TendenciaChart titulo="Tendencia diaria" :opciones="opcionesTendenciaDiaria" eje-tipo="dia" />
        </div>

        <!-- Selector de modo -->
        <div class="comparacion-modo">
            <div class="comparacion-modo-toggle">
                <button type="button" class="activo">Por métricas</button>
                <button type="button" disabled>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                    Por arte
                </button>
            </div>
            <span class="date-hint">"Por arte" requiere conectar tu API key de Anthropic — próximamente.</span>
        </div>
    </div>
</template>
