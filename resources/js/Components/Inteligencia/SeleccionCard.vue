<script setup>
import { computed } from 'vue';
import { cardDesdeCreativo, placeholderPorTipo, nombrePrincipalYTecnico, FUNNEL_COLOR_VAR, FUNNEL_LABELS } from '@/motor';

// SeleccionCard -- 2026-08-27, Inteligencia (ver plan). Versión compacta de
// CreativeCard.vue para el "carrito" de selección: MISMO patrón de imagen
// real + fallback (cardDesdeCreativo/placeholderPorTipo, nunca un ícono
// inventado) y las mismas clases .resumen-card* del resto del dashboard --
// pero sin estrella/secundarias (el usuario fue explícito: acá NO van
// NC/CAC ni ninguna métrica completa, solo identidad del creativo).
const props = defineProps({
    creativo: { type: Object, required: true },
    mes: { type: String, required: true },
    seleccionada: { type: Boolean, default: false },
    deshabilitada: { type: Boolean, default: false },
});

defineEmits(['toggle']);

const card = computed(() => cardDesdeCreativo(props.creativo, props.mes));
const esTikTok = computed(() => card.value.plataforma === 'tiktok');
const ph = computed(() => placeholderPorTipo(card.value.tipoCreativo));
const stageColor = computed(() => FUNNEL_COLOR_VAR[card.value.etapaFunnel] || 'var(--violet)');
const nombre = computed(() => nombrePrincipalYTecnico(card.value.nombreAmigable, card.value.arte || card.value.adNameShort || card.value.adId));
</script>

<template>
    <article
        class="resumen-card seleccion-card"
        :class="{ 'seleccion-card--elegida': seleccionada, 'seleccion-card--deshabilitada': deshabilitada }"
        tabindex="0"
        role="button"
        :aria-pressed="seleccionada"
        :aria-disabled="deshabilitada"
        @click="!deshabilitada && $emit('toggle', creativo)"
        @keydown.enter.space.prevent="!deshabilitada && $emit('toggle', creativo)"
    >
        <div class="resumen-card-imagen">
            <div class="no-image" :style="card.imageUrl ? { display: 'none' } : {}">
                <span class="glyph">{{ ph.glyph }}</span>{{ ph.texto }}
            </div>
            <img v-if="card.imageUrl" :src="card.imageUrl" alt="" loading="lazy" />
            <span v-if="card.tipoCreativo === 'VIDEO'" class="badge badge-tipo">▶ Video</span>
            <span v-if="seleccionada" class="seleccion-card-check" aria-hidden="true">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#161826" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
            </span>
        </div>
        <div class="resumen-card-body">
            <p class="resumen-card-titulo">{{ nombre.principal }}</p>
            <p v-if="nombre.tecnico" class="resumen-card-tecnico mono">{{ nombre.tecnico }}</p>
            <p class="resumen-card-meta">
                {{ esTikTok ? 'TikTok Ads' : 'Meta Ads' }}
                <span v-if="card.etapaFunnel" class="resumen-card-funnel" :style="{ '--stage-color': stageColor }">
                    {{ FUNNEL_LABELS[card.etapaFunnel] || card.etapaFunnel }}
                </span>
            </p>
        </div>
    </article>
</template>
