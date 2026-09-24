<script setup>
import { computed, ref, watch } from 'vue';
import { cargando, rutaDestino } from '@/loadingState';

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
            <div class="carga-cervezas" aria-hidden="true">
                <span class="carga-cerveza carga-cerveza-izq">🍺</span>
                <span class="carga-cerveza carga-cerveza-der">🍺</span>
            </div>
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
    background: color-mix(in srgb, var(--bg, #161826) 85%, transparent);
    backdrop-filter: blur(2px);
}
.carga-cervezas {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    font-size: 3rem;
    line-height: 1;
    filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.35));
}
.carga-cerveza {
    display: inline-block;
}
.carga-cerveza-izq {
    animation: carga-clink-izq 1.1s ease-in-out infinite;
}
.carga-cerveza-der {
    display: inline-block;
    transform: scaleX(-1);
    animation: carga-clink-der 1.1s ease-in-out infinite;
}
@keyframes carga-clink-izq {
    0%, 20%, 100% { transform: translateX(0) rotate(0deg); }
    45%, 55% { transform: translateX(9px) rotate(-14deg); }
}
@keyframes carga-clink-der {
    0%, 20%, 100% { transform: scaleX(-1) translateX(0) rotate(0deg); }
    45%, 55% { transform: scaleX(-1) translateX(9px) rotate(-14deg); }
}
.carga-texto {
    color: var(--text, #e9e9ed);
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
