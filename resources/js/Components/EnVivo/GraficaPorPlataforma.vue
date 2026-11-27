<script setup>
// Gasto por hora (o por día) apilado por plataforma (En vivo, 2026-11-27):
// a qué hora se gasta y dónde; un hueco es una plataforma que dejó de gastar.
import { computed, ref } from 'vue';
import { COLOR_PLATAFORMA, NOMBRE_PLATAFORMA, ORDEN_PLATAFORMAS, barra, escala, etiquetaPunto, useAncho, usd, usdCorto } from './escala';

const props = defineProps({
    puntos: { type: Array, required: true },
    series: { type: Object, required: true }, // plataforma → [[costo, impr, clicks], …]
    hasta: { type: Number, default: null }, // último punto con datos (hoy)
});

const caja = ref(null);
const ancho = useAncho(caja);
const ALTO = 230;
const M = { l: 52, r: 12, t: 14, b: 28 };
const GAP = 2;

const plataformas = computed(() => ORDEN_PLATAFORMAS.filter((p) => props.series[p]));
const n = computed(() => props.puntos.length);
const totales = computed(() => props.puntos.map((_, i) => plataformas.value.reduce((s, p) => s + (props.series[p][i]?.[0] ?? 0), 0)));
const esc = computed(() => escala(Math.max(0, ...totales.value)));
const banda = computed(() => (ancho.value - M.l - M.r) / Math.max(1, n.value));
const anchoBarra = computed(() => Math.max(2, banda.value - Math.max(2, banda.value * 0.25)));
const x = (i) => M.l + i * banda.value + (banda.value - anchoBarra.value) / 2;
const alto = (v) => (v / esc.value.max) * (ALTO - M.t - M.b);
const cadaCuanto = computed(() => Math.max(1, Math.ceil(n.value / Math.max(2, Math.floor((ancho.value - M.l - M.r) / 56)))));

// Segmentos apilados de abajo hacia arriba con 2px de separación; solo el de arriba lleva esquinas redondas.
const barras = computed(() =>
    props.puntos.map((_, i) => {
        const segs = [];
        let base = ALTO - M.b;
        const visibles = plataformas.value.filter((p) => (props.series[p][i]?.[0] ?? 0) > 0);
        visibles.forEach((p, k) => {
            const h = alto(props.series[p][i][0]);
            const hAj = Math.max(1, h - (k > 0 ? GAP : 0));
            const y = base - hAj - (k > 0 ? GAP : 0);
            segs.push({ p, d: k === visibles.length - 1 ? barra(x(i), y, anchoBarra.value, hAj) : `M${x(i)},${y}h${anchoBarra.value}v${hAj}h${-anchoBarra.value}Z` });
            base = y;
        });
        return segs;
    }),
);

const hover = ref(null);
function mover(e) {
    const r = e.currentTarget.getBoundingClientRect();
    const px = ((e.clientX - r.left) / r.width) * ancho.value;
    hover.value = Math.min(n.value - 1, Math.max(0, Math.floor((px - M.l) / banda.value)));
}
const tip = computed(() => {
    const i = hover.value;
    if (i == null) return null;
    const cx = x(i) + anchoBarra.value / 2;
    return {
        i,
        filas: plataformas.value.map((p) => ({ p, v: props.series[p][i]?.[0] ?? 0 })),
        total: totales.value[i],
        estilo: cx > ancho.value * 0.6 ? { right: `${ancho.value - cx + 14}px` } : { left: `${cx + 14}px` },
    };
});
</script>

<template>
    <div ref="caja" class="grafica">
        <div class="leyenda mono">
            <span v-for="p in plataformas" :key="p"><i class="sw" :style="{ background: COLOR_PLATAFORMA[p] }" />{{ NOMBRE_PLATAFORMA[p] }}</span>
        </div>
        <svg :width="ancho" :height="ALTO" role="img" aria-label="Gasto por plataforma" @mousemove="mover" @mouseleave="hover = null">
            <g class="eje">
                <template v-for="m in esc.marcas" :key="m">
                    <line :x1="M.l" :x2="ancho - M.r" :y1="ALTO - M.b - alto(m)" :y2="ALTO - M.b - alto(m)" class="grid" />
                    <text :x="M.l - 8" :y="ALTO - M.b - alto(m) + 4" text-anchor="end">{{ usdCorto(m) }}</text>
                </template>
                <template v-for="(p, i) in puntos" :key="p">
                    <text v-if="i % cadaCuanto === 0" :x="x(i) + anchoBarra / 2" :y="ALTO - 8" text-anchor="middle">{{ etiquetaPunto(p) }}</text>
                </template>
            </g>
            <rect v-if="tip" :x="M.l + tip.i * banda" :y="M.t" :width="banda" :height="ALTO - M.t - M.b" class="col-hover" />
            <g v-for="(segs, i) in barras" :key="i" :class="{ tenue: hasta != null && i > hasta }">
                <path v-for="s in segs" :key="s.p" :d="s.d" :fill="COLOR_PLATAFORMA[s.p]" />
            </g>
            <rect :x="M.l" :y="M.t" :width="Math.max(0, ancho - M.l - M.r)" :height="ALTO - M.t - M.b" fill="transparent" />
        </svg>
        <div v-if="tip" class="tooltip" :style="{ ...tip.estilo, top: '34px' }">
            <strong class="mono">{{ etiquetaPunto(puntos[tip.i]) }}</strong>
            <div v-for="f in tip.filas" :key="f.p" class="tip-fila">
                <i class="sw" :style="{ background: COLOR_PLATAFORMA[f.p] }" />{{ NOMBRE_PLATAFORMA[f.p] }}<span class="mono">{{ usd(f.v) }}</span>
            </div>
            <div class="tip-dif tip-fila">Total<span class="mono">{{ usd(tip.total) }}</span></div>
        </div>
    </div>
</template>

<style scoped src="./grafica.css"></style>
<style scoped>
.col-hover {
    fill: var(--surface-2);
}
.tenue {
    opacity: 0.35;
}
</style>
