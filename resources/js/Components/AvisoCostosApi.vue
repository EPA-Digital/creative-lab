<script setup>
// Costos desde la API (2026-11-26): "Datos de Meta, TikTok y Google (API) actualizados
// al 26 nov 13:09:09 · próxima actualización en 1 h 20 min". El estado viene
// del prop compartido costosApi (SincronizadorCostosApi::estado()).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const estado = computed(() => usePage().props.costosApi);
const ahora = ref(Date.now());
let reloj = null;
onMounted(() => (reloj = setInterval(() => (ahora.value = Date.now()), 15000)));
onBeforeUnmount(() => clearInterval(reloj));

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const dos = (n) => String(n).padStart(2, '0');
function fechaHora(iso) {
    const d = new Date(iso);
    return `${d.getDate()} ${MESES[d.getMonth()]} ${dos(d.getHours())}:${dos(d.getMinutes())}:${dos(d.getSeconds())}`;
}
function falta(iso) {
    const min = Math.max(0, Math.round((new Date(iso).getTime() - ahora.value) / 60000));
    if (min < 1) return 'en un momento';
    const h = Math.floor(min / 60);
    return h ? `${h} h ${min % 60} min` : `${min} min`;
}
// Más de 2 intervalos sin correr: algo pasó con el scheduler.
const atrasado = computed(() => {
    const e = estado.value;
    return !!e?.ultima && ahora.value - new Date(e.ultima).getTime() > e.intervalo * 2 * 60000;
});
</script>

<template>
    <p v-if="estado" class="aviso-api mono" :class="{ atrasado: atrasado || estado.estado === 'error' }">
        <span class="punto" />
        <template v-if="estado.ultima">
            Datos de Meta, TikTok y Google (API) actualizados al <strong>{{ fechaHora(estado.ultima) }}</strong>
            <template v-if="estado.estado === 'parcial'"> (con errores en alguna cuenta)</template>
            <template v-else-if="estado.estado === 'error'"> (falló la última corrida)</template>
        </template>
        <template v-else>Meta, TikTok y Google todavía no se han actualizado por API</template>
        · próxima actualización en <strong>{{ falta(estado.proxima) }}</strong>
    </p>
</template>

<style scoped>
.aviso-api {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin: 8px 0 0;
    font-size: 0.72rem;
    color: var(--text-muted);
}
.aviso-api strong {
    color: var(--text);
    font-weight: 600;
}
.punto {
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: var(--mint);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--mint) 20%, transparent);
}
.atrasado .punto {
    background: var(--coral);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--coral) 20%, transparent);
}
</style>
