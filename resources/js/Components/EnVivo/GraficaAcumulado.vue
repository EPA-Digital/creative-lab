<script setup>
// Gasto acumulado (En vivo, 2026-11-27): hoy contra el día anterior a la
// misma hora y, si el Daily tiene plan diario, el ritmo que llevaría
// gastándolo parejo. Por día (7 días / Mes) solo el acumulado del periodo.
import { computed, ref } from 'vue';
import { escala, etiquetaPunto, useAncho, usd, usdCorto } from './escala';

const props = defineProps({
    puntos: { type: Array, required: true },
    actual: { type: Array, required: true }, // costo por punto (null = todavía no llega)
    previo: { type: Array, default: null },
    plan: { type: Number, default: null }, // plan del día completo
    etiquetaActual: { type: String, default: 'Hoy' },
    etiquetaPrevio: { type: String, default: 'Ayer' },
});

const caja = ref(null);
const ancho = useAncho(caja);
const ALTO = 230;
const M = { l: 52, r: 64, t: 14, b: 28 };

const acumular = (lista) => {
    let s = 0;
    return lista.map((v) => (v == null ? null : (s += v)));
};
const actualAc = computed(() => acumular(props.actual));
const previoAc = computed(() => (props.previo ? acumular(props.previo) : null));
const ritmo = computed(() => (props.plan ? props.puntos.map((_, i) => (props.plan * (i + 1)) / props.puntos.length) : null));

const n = computed(() => props.puntos.length);
const esc = computed(() => escala(Math.max(0, ...[actualAc.value, previoAc.value, ritmo.value].flat().filter((v) => v != null))));
const x = (i) => M.l + (n.value > 1 ? (i * (ancho.value - M.l - M.r)) / (n.value - 1) : 0);
const y = (v) => M.t + (1 - v / esc.value.max) * (ALTO - M.t - M.b);
const linea = (vals) => {
    const pts = (vals ?? []).map((v, i) => (v == null ? null : `${x(i).toFixed(1)},${y(v).toFixed(1)}`)).filter(Boolean);
    return pts.length ? 'M' + pts.join('L') : '';
};
const ultimo = computed(() => {
    const i = actualAc.value.findLastIndex((v) => v != null);
    return i >= 0 ? { i, v: actualAc.value[i] } : null;
});
const cadaCuanto = computed(() => Math.max(1, Math.ceil(n.value / Math.max(2, Math.floor((ancho.value - M.l - M.r) / 64)))));

// Hover: cruz + tooltip.
const hover = ref(null);
function mover(e) {
    const r = e.currentTarget.getBoundingClientRect();
    const px = ((e.clientX - r.left) / r.width) * ancho.value;
    hover.value = Math.min(n.value - 1, Math.max(0, Math.round(((px - M.l) / (ancho.value - M.l - M.r)) * (n.value - 1))));
}
const tip = computed(() => {
    const i = hover.value;
    if (i == null) return null;
    const filas = [];
    if (actualAc.value[i] != null) filas.push({ k: 'a', label: props.etiquetaActual, v: actualAc.value[i] });
    if (previoAc.value?.[i] != null) filas.push({ k: 'p', label: props.etiquetaPrevio, v: previoAc.value[i] });
    if (ritmo.value) filas.push({ k: 'r', label: 'Ritmo del plan', v: ritmo.value[i] });
    const izq = x(i) > ancho.value * 0.6;
    return { i, filas, estilo: izq ? { right: `${ancho.value - x(i) + 12}px` } : { left: `${x(i) + 12}px` } };
});
</script>

<template>
    <div ref="caja" class="grafica">
        <div class="leyenda mono">
            <span><i class="sw actual" />{{ etiquetaActual }}</span>
            <span v-if="previo"><i class="sw previo" />{{ etiquetaPrevio }}</span>
            <span v-if="plan"><i class="sw ritmo" />Ritmo del plan diario ({{ usd(plan) }})</span>
        </div>
        <svg :width="ancho" :height="ALTO" role="img" :aria-label="`Gasto acumulado ${etiquetaActual}`" @mousemove="mover" @mouseleave="hover = null">
            <g class="eje">
                <template v-for="m in esc.marcas" :key="m">
                    <line :x1="M.l" :x2="ancho - M.r" :y1="y(m)" :y2="y(m)" class="grid" />
                    <text :x="M.l - 8" :y="y(m) + 4" text-anchor="end">{{ usdCorto(m) }}</text>
                </template>
                <template v-for="(p, i) in puntos" :key="p">
                    <text v-if="i % cadaCuanto === 0" :x="x(i)" :y="ALTO - 8" text-anchor="middle">{{ etiquetaPunto(p) }}</text>
                </template>
            </g>
            <path v-if="ritmo" :d="linea(ritmo)" class="l-ritmo" />
            <path v-if="previoAc" :d="linea(previoAc)" class="l-previo" />
            <path :d="linea(actualAc)" class="l-actual" />
            <template v-if="ultimo">
                <circle :cx="x(ultimo.i)" :cy="y(ultimo.v)" r="4" class="punto" />
                <text :x="x(ultimo.i) + 8" :y="y(ultimo.v) + 4" class="etq-final">{{ usdCorto(ultimo.v) }}</text>
            </template>
            <template v-if="tip">
                <line :x1="x(tip.i)" :x2="x(tip.i)" :y1="M.t" :y2="ALTO - M.b" class="cruz" />
                <circle v-if="actualAc[tip.i] != null" :cx="x(tip.i)" :cy="y(actualAc[tip.i])" r="4" class="punto" />
                <circle v-if="previoAc?.[tip.i] != null" :cx="x(tip.i)" :cy="y(previoAc[tip.i])" r="4" class="punto previo" />
            </template>
            <rect :x="M.l" :y="M.t" :width="Math.max(0, ancho - M.l - M.r)" :height="ALTO - M.t - M.b" fill="transparent" />
        </svg>
        <div v-if="tip" class="tooltip" :style="{ ...tip.estilo, top: '34px' }">
            <strong class="mono">{{ etiquetaPunto(puntos[tip.i]) }}</strong>
            <div v-for="f in tip.filas" :key="f.k" class="tip-fila">
                <i class="sw" :class="{ a: 'actual', p: 'previo', r: 'ritmo' }[f.k]" />{{ f.label }}<span class="mono">{{ usd(f.v) }}</span>
            </div>
            <div v-if="tip.filas.length > 1 && tip.filas[0].k === 'a'" class="tip-dif mono">
                vs {{ tip.filas[1].label.toLowerCase() }}:
                {{ tip.filas[0].v >= tip.filas[1].v ? '+' : '−' }}{{ usd(Math.abs(tip.filas[0].v - tip.filas[1].v)) }}
            </div>
        </div>
    </div>
</template>

<style scoped src="./grafica.css"></style>
<style scoped>
.l-actual {
    fill: none;
    stroke: var(--amber);
    stroke-width: 2;
    stroke-linejoin: round;
}
.l-previo {
    fill: none;
    stroke: var(--text-faint);
    stroke-width: 2;
    stroke-dasharray: 5 4;
}
.l-ritmo {
    fill: none;
    stroke: var(--yellow);
    stroke-width: 1.5;
    stroke-dasharray: 2 4;
}
.punto {
    fill: var(--amber);
    stroke: var(--surface);
    stroke-width: 2;
}
.punto.previo {
    fill: var(--text-faint);
}
.etq-final {
    font-size: 11px;
    font-weight: 600;
    fill: var(--text);
}
.sw.actual {
    background: var(--amber);
}
.sw.previo {
    background: var(--text-faint);
}
.sw.ritmo {
    background: var(--yellow);
}
</style>
