<script setup>
// Mini gráfica de la tabla de En vivo (2026-11-27): costo de la campaña por
// hora (o día). Sin ejes: solo la forma — pausada, arrancó tarde, se aceleró.
import { computed } from 'vue';
import { etiquetaPunto, usd } from './escala';

const props = defineProps({
    valores: { type: Array, default: null },
    puntos: { type: Array, default: () => [] },
    hasta: { type: Number, default: null },
    color: { type: String, default: 'var(--amber)' },
});
const W = 96;
const H = 22;
const vals = computed(() => (props.valores ?? []).slice(0, props.hasta == null ? undefined : props.hasta + 1));
const max = computed(() => Math.max(0, ...vals.value));
const d = computed(() => {
    if (!vals.value.length || !(max.value > 0)) return '';
    const n = (props.valores ?? []).length;
    return 'M' + vals.value.map((v, i) => `${((i / Math.max(1, n - 1)) * (W - 4) + 2).toFixed(1)},${(H - 2 - (v / max.value) * (H - 4)).toFixed(1)}`).join('L');
});
const titulo = computed(() => {
    if (!max.value) return 'Sin gasto en el periodo';
    const i = vals.value.indexOf(max.value);
    return `Mayor gasto: ${usd(max.value)} (${etiquetaPunto(props.puntos[i] ?? '')})`;
});
</script>

<template>
    <svg class="mini" :width="W" :height="H" :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="titulo">
        <title>{{ titulo }}</title>
        <line x1="2" :x2="W - 2" :y1="H - 2" :y2="H - 2" class="base" />
        <path v-if="d" :d="d" :stroke="color" class="linea" />
    </svg>
</template>

<style scoped>
.mini {
    display: block;
}
.base {
    stroke: var(--border);
    stroke-width: 1;
}
.linea {
    fill: none;
    stroke-width: 1.5;
    stroke-linejoin: round;
}
</style>
