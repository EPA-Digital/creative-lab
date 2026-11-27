<script setup>
// Una métrica por hora o día (CPC, CTR o CPM) en su propia gráfica, con la
// misma escala de tiempo que sus vecinas: se ven juntas sin mezclar escalas.
// El hover se comparte entre las tres (v-model:hover).
import { computed, ref } from 'vue';
import { escala, etiquetaPunto, useAncho } from './escala';

const props = defineProps({
    titulo: { type: String, required: true },
    puntos: { type: Array, required: true },
    valores: { type: Array, required: true }, // null = sin datos en ese punto
    formato: { type: Function, required: true },
    hover: { type: Number, default: null },
});
const emit = defineEmits(['update:hover']);

const caja = ref(null);
const ancho = useAncho(caja, 300);
const ALTO = 130;
const M = { l: 46, r: 10, t: 10, b: 24 };

const n = computed(() => props.puntos.length);
const esc = computed(() => escala(Math.max(0, ...props.valores.filter((v) => v != null))));
const x = (i) => M.l + (n.value > 1 ? (i * (ancho.value - M.l - M.r)) / (n.value - 1) : 0);
const y = (v) => M.t + (1 - v / esc.value.max) * (ALTO - M.t - M.b);
// Tramos continuos: un punto sin datos corta la línea en vez de inventarlo.
const tramos = computed(() => {
    const t = [];
    let actual = [];
    props.valores.forEach((v, i) => {
        if (v == null) {
            if (actual.length) t.push(actual);
            actual = [];
        } else actual.push(`${x(i).toFixed(1)},${y(v).toFixed(1)}`);
    });
    if (actual.length) t.push(actual);
    return t.map((pts) => (pts.length === 1 ? null : 'M' + pts.join('L'))).filter(Boolean);
});
const sueltos = computed(() => props.valores.map((v, i) => ({ v, i })).filter(({ v, i }) => v != null && props.valores[i - 1] == null && props.valores[i + 1] == null));
const cadaCuanto = computed(() => Math.max(1, Math.ceil(n.value / Math.max(2, Math.floor((ancho.value - M.l - M.r) / 52)))));
const ultimo = computed(() => {
    const i = props.valores.findLastIndex((v) => v != null);
    return i >= 0 ? props.valores[i] : null;
});

function mover(e) {
    const r = e.currentTarget.getBoundingClientRect();
    const px = ((e.clientX - r.left) / r.width) * ancho.value;
    emit('update:hover', Math.min(n.value - 1, Math.max(0, Math.round(((px - M.l) / (ancho.value - M.l - M.r)) * (n.value - 1)))));
}
const etiquetaEje = (v) => props.formato(v, true);
</script>

<template>
    <div ref="caja" class="grafica metrica">
        <div class="cabeza">
            <span class="titulo">{{ titulo }}</span>
            <span class="valor mono">
                <template v-if="hover != null">{{ etiquetaPunto(puntos[hover]) }} · {{ valores[hover] == null ? '—' : formato(valores[hover]) }}</template>
                <template v-else>{{ ultimo == null ? '—' : formato(ultimo) }}</template>
            </span>
        </div>
        <svg :width="ancho" :height="ALTO" role="img" :aria-label="titulo" @mousemove="mover" @mouseleave="emit('update:hover', null)">
            <g class="eje">
                <template v-for="m in esc.marcas.filter((_, k) => k % 2 === 0)" :key="m">
                    <line :x1="M.l" :x2="ancho - M.r" :y1="y(m)" :y2="y(m)" class="grid" />
                    <text :x="M.l - 6" :y="y(m) + 4" text-anchor="end">{{ etiquetaEje(m) }}</text>
                </template>
                <template v-for="(p, i) in puntos" :key="p">
                    <text v-if="i % cadaCuanto === 0" :x="x(i)" :y="ALTO - 6" text-anchor="middle">{{ etiquetaPunto(p) }}</text>
                </template>
            </g>
            <path v-for="(d, k) in tramos" :key="k" :d="d" class="linea" />
            <circle v-for="s in sueltos" :key="s.i" :cx="x(s.i)" :cy="y(s.v)" r="3" class="punto" />
            <template v-if="hover != null">
                <line :x1="x(hover)" :x2="x(hover)" :y1="M.t" :y2="ALTO - M.b" class="cruz" />
                <circle v-if="valores[hover] != null" :cx="x(hover)" :cy="y(valores[hover])" r="4" class="punto" />
            </template>
            <rect :x="M.l" :y="M.t" :width="Math.max(0, ancho - M.l - M.r)" :height="ALTO - M.t - M.b" fill="transparent" />
        </svg>
    </div>
</template>

<style scoped src="./grafica.css"></style>
<style scoped>
.cabeza {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 6px;
}
.titulo {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
}
.valor {
    font-size: 0.78rem;
    color: var(--text);
}
.linea {
    fill: none;
    stroke: var(--amber);
    stroke-width: 2;
    stroke-linejoin: round;
}
.punto {
    fill: var(--amber);
    stroke: var(--surface);
    stroke-width: 2;
}
</style>
