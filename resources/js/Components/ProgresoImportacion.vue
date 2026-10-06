<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

// Progreso de una importación (2026-10-06, pedido explícito) -- mismo
// lenguaje visual que CargandoOverlay (las cervezas), pero con avance REAL:
// `porcentaje`/`etapa` vienen del pipeline (ImportadorDatos reporta, el Job
// guarda en importaciones.progreso/etapa, el panel hace polling).
//
// Entre una actualización real y la siguiente la barra NO se queda quieta:
// avanza sola, cada vez más lento, hasta un techo apenas por encima del
// avance real (nunca se adelanta a la siguiente etapa ni llega a 100 sin
// que el servidor lo confirme). Cuando llega un avance real, la barra lo
// alcanza rápido.
const props = defineProps({
    porcentaje: { type: Number, default: 0 },
    etapa: { type: String, default: null },
    inicio: { type: Number, required: true },
    terminado: { type: Boolean, default: false },
});
const emit = defineEmits(['ocultar']);

const mostrado = ref(0);
const ahora = ref(Date.now());
let timer = null;

function tick() {
    ahora.value = Date.now();
    const real = props.porcentaje || 0;
    if (props.terminado) {
        mostrado.value = Math.min(100, mostrado.value + Math.max(3, (100 - mostrado.value) * 0.3));
        return;
    }
    const techo = Math.min(real + 8, 98);
    if (mostrado.value < real) {
        mostrado.value += Math.max(0.5, (real - mostrado.value) * 0.15);
    } else if (mostrado.value < techo) {
        mostrado.value += (techo - mostrado.value) * 0.012;
    }
}
onMounted(() => {
    timer = setInterval(tick, 200);
});
onUnmounted(() => clearInterval(timer));

const enCola = computed(() => !props.terminado && !props.porcentaje && !props.etapa);
const texto = computed(() => {
    if (props.terminado) return 'Listo';
    if (enCola.value) return 'En cola: arranca en menos de un minuto…';
    return `${props.etapa}…`;
});
const transcurrido = computed(() => {
    const s = Math.max(0, Math.floor((ahora.value - props.inicio) / 1000));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
});
</script>

<template>
    <div class="progreso-overlay" role="status" aria-live="polite">
        <div class="progreso-card">
            <div class="progreso-cervezas" aria-hidden="true">
                <span class="progreso-cerveza progreso-cerveza-izq">🍺</span>
                <span class="progreso-cerveza progreso-cerveza-der">🍺</span>
            </div>

            <h2 class="progreso-titulo">{{ terminado ? 'Importación completa' : 'Importando datos' }}</h2>

            <div
                class="progreso-track"
                role="progressbar"
                aria-label="Avance de la importación"
                :aria-valuenow="Math.floor(mostrado)"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div class="progreso-fill" :style="{ width: `${mostrado}%` }" />
            </div>

            <div class="progreso-meta mono">
                <span>{{ Math.floor(mostrado) }}%</span>
                <span>{{ transcurrido }}</span>
            </div>
            <p class="progreso-etapa">{{ texto }}</p>

            <template v-if="!terminado">
                <p class="progreso-nota">Puedes seguir trabajando: la importación continúa aunque cierres esto.</p>
                <button type="button" class="modal-copy-btn progreso-ocultar" @click="emit('ocultar')">Seguir en segundo plano</button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.progreso-overlay {
    position: fixed;
    inset: 0;
    z-index: 9000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: color-mix(in srgb, var(--bg) 85%, transparent);
    backdrop-filter: blur(2px);
}
.progreso-card {
    width: 100%;
    max-width: 440px;
    padding: 28px 28px 24px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
    text-align: center;
}
.progreso-cervezas {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 2px;
    font-size: 2.4rem;
    line-height: 1;
}
.progreso-cerveza {
    display: inline-block;
}
.progreso-cerveza-izq {
    animation: progreso-clink-izq 1.1s ease-in-out infinite;
}
.progreso-cerveza-der {
    transform: scaleX(-1);
    animation: progreso-clink-der 1.1s ease-in-out infinite;
}
@keyframes progreso-clink-izq {
    0%, 20%, 100% { transform: translateX(0) rotate(0deg); }
    45%, 55% { transform: translateX(9px) rotate(-14deg); }
}
@keyframes progreso-clink-der {
    0%, 20%, 100% { transform: scaleX(-1) translateX(0) rotate(0deg); }
    45%, 55% { transform: scaleX(-1) translateX(9px) rotate(-14deg); }
}
.progreso-titulo {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.1rem;
    font-weight: 600;
    margin: 6px 0 4px;
}
.progreso-track {
    height: 10px;
    border-radius: 999px;
    background: var(--surface-2);
    overflow: hidden;
}
.progreso-fill {
    height: 100%;
    border-radius: 999px;
    background: var(--amber);
    transition: width 0.2s linear;
}
.progreso-meta {
    display: flex;
    justify-content: space-between;
    font-size: 0.78rem;
    color: var(--text-muted);
}
.progreso-etapa {
    margin: 2px 0 0;
    font-size: 0.85rem;
    color: var(--text);
    min-height: 1.3em;
}
.progreso-nota {
    margin: 8px 0 0;
    font-size: 0.75rem;
    color: var(--text-faint);
}
.progreso-ocultar {
    align-self: center;
    margin-top: 4px;
}
@media (prefers-reduced-motion: reduce) {
    .progreso-cerveza-izq,
    .progreso-cerveza-der {
        animation: none;
    }
    .progreso-fill {
        transition: none;
    }
}
</style>
