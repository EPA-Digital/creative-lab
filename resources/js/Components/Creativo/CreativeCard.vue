<script setup>
import { computed, ref } from 'vue';
import { cardDesdeCreativo, statsParaCard, placeholderPorTipo, FUNNEL_COLOR_VAR } from '@/motor';

// Puerto literal de renderCardPulida + renderAreaImagen + placeholderPorTipo
// + statsParaCard (dashboard/shared/motor.js, líneas 1079-1253 del proyecto
// Node real) -- fuente de verdad, no un mockup. estrellaOverride reproduce
// el mismo mecanismo que estrellaActual() en creativos.html: cuando se pasa,
// reemplaza stats[0] como "la estrella" (usado tanto por el podio como por
// el carrusel general, siempre con la métrica activa en "Rankear por").
const props = defineProps({
    creativo: { type: Object, required: true },
    rank: { type: Number, required: true },
    estrellaOverride: { type: Object, default: null },
});

const emit = defineEmits(['abrir']);

const card = computed(() => cardDesdeCreativo(props.creativo));

const stats = computed(() => statsParaCard(card.value));
const estrella = computed(() => props.estrellaOverride || stats.value[0] || { label: '—', value: '—' });
const secundarias = computed(() =>
    stats.value.filter((s) => s.label.toUpperCase() !== estrella.value.label.toUpperCase()).slice(0, 2),
);
const esTikTok = computed(() => card.value.plataforma === 'tiktok');
const activo = computed(() => card.value.status && /ACTIVE/i.test(card.value.status));
const stageColor = computed(() => FUNNEL_COLOR_VAR[card.value.etapaFunnel] || 'var(--violet)');
const ph = computed(() => placeholderPorTipo(card.value.tipoCreativo));

// onerror real: oculta la <img> y muestra el .no-image que la precede
// (this.previousElementSibling). Acá se resuelve con estado reactivo en vez
// de manipular el DOM a mano, mismo resultado visual.
const imagenRota = ref(false);
const catalogTilesRotas = ref(new Set());
function onImgError() {
    imagenRota.value = true;
}
function onTileError(i) {
    catalogTilesRotas.value = new Set([...catalogTilesRotas.value, i]);
}

// wireCardClicks real: click O keydown Enter/Space abren el modal -- mismo
// criterio de accesibilidad, portado acá como listeners directos en vez de
// delegación de evento sobre un contenedor (equivalente, más idiomático en
// Vue).
function abrir() {
    emit('abrir', props.creativo);
}
function onKeydown(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    abrir();
}
</script>

<template>
    <article
        class="resumen-card"
        tabindex="0"
        role="button"
        :data-ad-id="card.adId"
        @click="abrir"
        @keydown="onKeydown"
    >
        <div class="resumen-card-imagen">
            <!-- renderAreaImagen: rama CATALOGO (mosaico) -->
            <template v-if="card.tipoCreativo === 'CATALOGO'">
                <div class="no-image" :style="card.catalogThumbs.length > 0 ? { display: 'none' } : {}">
                    <span class="glyph">{{ ph.glyph }}</span>{{ ph.texto }}
                </div>
                <div v-if="card.catalogThumbs.length > 0" class="catalog-mosaic">
                    <div v-for="(url, i) in card.catalogThumbs.slice(0, 4)" :key="i" class="tile">
                        <img v-if="!catalogTilesRotas.has(i)" :src="url" alt="" loading="lazy" @error="onTileError(i)" />
                    </div>
                </div>
                <span class="badge badge-tipo">Catálogo dinámico — imagen varía por usuario</span>
            </template>

            <!-- renderAreaImagen: rama imagen real / placeholder -->
            <template v-else>
                <div class="no-image" :style="card.imageUrl && !imagenRota ? { display: 'none' } : {}">
                    <span class="glyph">{{ ph.glyph }}</span>{{ ph.texto }}
                </div>
                <img
                    v-if="card.imageUrl && !imagenRota"
                    :src="card.imageUrl"
                    alt=""
                    loading="lazy"
                    @error="onImgError"
                />
                <span v-if="card.tipoCreativo === 'VIDEO'" class="badge badge-tipo">▶ Video</span>
            </template>

            <span class="badge badge-rank">#{{ rank }}</span>
            <span
                v-if="card.status"
                class="badge badge-estado"
                :style="{ color: activo ? 'var(--mint)' : 'var(--coral)' }"
            >
                {{ card.status }}
            </span>
        </div>
        <div class="resumen-card-body">
            <p class="resumen-card-titulo">{{ card.adNameShort || card.adId }}</p>
            <p class="resumen-card-meta">
                {{ esTikTok ? 'TikTok Ads' : 'Meta Ads' }}
                <span v-if="card.etapaFunnel" class="resumen-card-funnel" :style="{ '--stage-color': stageColor }">
                    {{ card.etapaFunnel }}
                </span>
            </p>
            <div class="resumen-card-estrella">
                <span class="k">{{ estrella.label }}</span>
                <span class="v mono">{{ estrella.value }}</span>
            </div>
            <div class="resumen-card-secundarias">
                <div v-for="s in secundarias" :key="s.label">
                    <span class="k">{{ s.label }}</span>
                    <span class="v mono">{{ s.value }}</span>
                </div>
            </div>
        </div>
    </article>
</template>
