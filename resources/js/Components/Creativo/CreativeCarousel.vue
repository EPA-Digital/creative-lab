<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import CreativeCard from '@/Components/Creativo/CreativeCard.vue';

// Puerto literal de renderCarruselShell + wireCarrusel (motor.js:1264-1311).
// El paso de scroll se mide del ANCHO REAL de la primera .resumen-card
// renderizada + el gap real de .carrusel-track (getComputedStyle), no una
// constante hardcodeada -- así el carrusel no se desincroniza si el CSS de
// la card cambia.
const props = defineProps({
    creativos: { type: Array, required: true }, // ya ordenados
    estrellaOverride: { type: Function, default: null }, // (creativo) => {label, value} | null
});

const emit = defineEmits(['abrir']);

const track = ref(null);
const dots = ref(null);
const activo = ref(0);
let rafPendiente = false;

function anchoPaso() {
    if (!track.value) return 236;
    const primera = track.value.querySelector('.resumen-card');
    if (!primera) return 236;
    const estilo = getComputedStyle(track.value);
    return primera.getBoundingClientRect().width + parseFloat(estilo.gap || '16');
}

function anterior() {
    if (track.value) track.value.scrollBy({ left: -anchoPaso(), behavior: 'smooth' });
}
function siguiente() {
    if (track.value) track.value.scrollBy({ left: anchoPaso(), behavior: 'smooth' });
}
function irADot(i) {
    if (track.value) track.value.scrollTo({ left: i * anchoPaso(), behavior: 'smooth' });
}

function onScroll() {
    if (rafPendiente) return;
    rafPendiente = true;
    requestAnimationFrame(() => {
        rafPendiente = false;
        const paso = anchoPaso();
        activo.value = paso ? Math.round(track.value.scrollLeft / paso) : 0;
    });
}

onMounted(() => {
    track.value?.addEventListener('scroll', onScroll);
});
onUnmounted(() => {
    track.value?.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <div v-if="creativos.length" class="carrusel">
        <button type="button" class="carrusel-flecha carrusel-prev" aria-label="Anterior" @click="anterior">‹</button>
        <div ref="track" class="carrusel-track">
            <CreativeCard
                v-for="(c, i) in creativos"
                :key="c.id"
                :creativo="c"
                :rank="i + 1"
                :estrella-override="estrellaOverride ? estrellaOverride(c) : null"
                @abrir="emit('abrir', $event)"
            />
        </div>
        <button type="button" class="carrusel-flecha carrusel-next" aria-label="Siguiente" @click="siguiente">›</button>
    </div>
    <div v-if="creativos.length" ref="dots" class="carrusel-dots">
        <button
            v-for="(c, i) in creativos"
            :key="c.id"
            type="button"
            class="carrusel-dot"
            :class="{ activo: i === activo }"
            :aria-label="`Ir al anuncio ${i + 1}`"
            @click="irADot(i)"
        />
    </div>
</template>
