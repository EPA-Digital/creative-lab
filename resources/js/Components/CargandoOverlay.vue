<script setup>
import { computed, ref, watch } from 'vue';
import { cargando, rutaDestino } from '@/loadingState';
import EpaLoader from '@/Components/EpaLoader.vue';

// Overlay global de navegación (2026-09-24, pedido explícito) -- en
// TODAS las pantallas, pero solo si de verdad hay que esperar: el
// delay antes de mostrar (ver loadingState.js, ~250ms) ya hace que una
// navegación rápida nunca lo llegue a pintar. Arrancó acotado a
// Análisis Creativo (2026-09-24, pedido explícito anterior); ahora es
// global, con fases genéricas -- se mantienen las de creativos
// (más específicas, "Consolidando por arte y etapa...") solo cuando el
// destino es de verdad Análisis Creativo, no tendría sentido en
// Usuarios/Ajustes/etc.
const esAnalisis = computed(() => rutaDestino.value.includes('/analisis'));

const FASES_GENERICAS = ['Cargando…', 'Preparando la información…', 'Casi listo…'];
const FASES_ANALISIS = [
    'Cargando creativos…',
    'Consolidando por arte y etapa…',
    'Generando el análisis…',
    'Casi listo…',
];
const fases = computed(() => (esAnalisis.value ? FASES_ANALISIS : FASES_GENERICAS));

const faseIndex = ref(0);
let temporizador = null;

watch(cargando, (activo) => {
    if (activo) {
        faseIndex.value = 0;
        temporizador = setInterval(() => {
            faseIndex.value = (faseIndex.value + 1) % fases.value.length;
        }, 1100);
    } else if (temporizador) {
        clearInterval(temporizador);
        temporizador = null;
    }
});
</script>

<template>
    <Transition name="carga-fade">
        <div v-if="cargando" class="carga-overlay" role="status" aria-live="polite">
            <EpaLoader :tamano="130" />
            <p class="carga-texto mono">{{ fases[faseIndex] }}</p>
        </div>
    </Transition>
</template>

<style scoped>
.carga-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
    background: color-mix(in srgb, var(--bg, #161826) 94%, transparent);
    backdrop-filter: blur(4px);
}
.carga-texto {
    color: var(--text-muted, #9397ab);
    font-size: 0.85rem;
    letter-spacing: 0.02em;
}
.carga-fade-enter-active,
.carga-fade-leave-active {
    transition: opacity 150ms ease;
}
.carga-fade-enter-from,
.carga-fade-leave-to {
    opacity: 0;
}
</style>
