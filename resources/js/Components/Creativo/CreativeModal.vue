<script setup>
import { computed } from 'vue';
import {
    cardDesdeCreativo, statsParaCard, placeholderPorTipo,
    formatNumeroExacto, formatMoneyExacto, formatPercent, FUNNEL_LABELS,
} from '@/motor';

// Puerto literal de openModal + renderAreaMultimediaModal + adIdParaModal +
// renderCopySection + renderFatigaSection + sparklineSvg (motor.js) --
// fuente de verdad, no un mockup. A diferencia de la card (que compacta a
// K/M), acá todo se muestra exacto (formatNumeroExacto/formatMoneyExacto)
// para poder auditar el dato.
const props = defineProps({
    creativo: { type: Object, required: true },
});

defineEmits(['cerrar']);

const card = computed(() => cardDesdeCreativo(props.creativo));
const stats = computed(() => statsParaCard(card.value));
const esTikTok = computed(() => card.value.plataforma === 'tiktok');
const ph = computed(() => placeholderPorTipo(card.value.tipoCreativo));

// TikTok: un arte agrupado trae hasta 2 ad_id reales (uno por campaña) -- se
// listan los dos, nunca se colapsa a uno solo en silencio. Meta/TikTok sin
// match de API caen a card.adId tal cual.
const adIdMostrado = computed(() => {
    const c = card.value;
    if (Array.isArray(c.adIdsMostrables) && c.adIdsMostrables.length) {
        return c.adIdsMostrables.length > 1
            ? `${c.adIdsMostrables.join(', ')} (${c.adIdsMostrables.length} anuncios)`
            : c.adIdsMostrables[0];
    }
    if (c.adIdMostrable) return c.adIdMostrable;
    return c.adId;
});

// Detalle exacto -- nada de esto se compacta a K/M, el negocio necesita el
// número real para auditar el dato.
const metrics = computed(() => {
    const c = card.value;
    return [
        ['Costo', formatMoneyExacto(c.cost)],
        ['Impresiones', formatNumeroExacto(c.impressions)],
        ['Clicks', formatNumeroExacto(c.clicks)],
        ['CTR', formatPercent(c.ctr)],
        ['CPM', formatMoneyExacto(c.cpm)],
        ['CPI', formatMoneyExacto(c.cpi)],
        ['CPO', formatMoneyExacto(c.cpo)],
        ['Instalaciones', formatNumeroExacto(c.installs)],
        ['Nuevos clientes', formatNumeroExacto(c.newCustomers)],
        ['Órdenes', formatNumeroExacto(c.orders)],
        ['CAC', formatMoneyExacto(c.cac)],
        ['Etapa funnel', FUNNEL_LABELS[c.etapaFunnel] || c.etapaFunnel || '—'],
        ['Tipo creativo', c.tipoCreativo || '—'],
        ['Estado', c.status || '—'],
        ['Ad ID', adIdMostrado.value],
    ];
});

// Copy: creativo.copy es un objeto único {titulo, texto} persistido desde
// la API (Meta: creative.body/title; TikTok: ad_text, vacío para los ads
// Smart+ automatizados -- ver motor.js/cardDesdeCreativo) -- copyDescriptions
// queda siempre [] porque ninguna de las dos plataformas expone una
// descripción separada del body/ad_text vía estas llamadas.
const copyBodies = computed(() => card.value.copyBodies || []);
const copyTitles = computed(() => card.value.copyTitles || []);
const copyDescriptions = computed(() => card.value.copyDescriptions || []);
const hayCopy = computed(() => copyBodies.value.length || copyTitles.value.length || copyDescriptions.value.length);
const multiVariante = computed(() => copyBodies.value.length > 1);
const rotacionLabel = computed(() => (esTikTok.value ? 'TikTok Smart+ las rota' : 'Meta las rota dinámicamente'));

// Fatiga: requiere la serie diaria de fetchInsightsDiarios (Node, live-fetch,
// todavía no portado a Laravel) -- card.fatiga siempre null hoy, así que la
// sección real simplemente no se dibuja (mismo `if (!f) return ''` del
// original), no se inventa un estado "saludable" falso.
const fatiga = computed(() => card.value.fatiga);

// Sparkline: requiere serie diaria de installs, mismo gap que fatiga --
// serie siempre null hoy, sparklineSvg real también retorna '' en ese caso.
const serie = computed(() => card.value.serie);

// tieneMeta/tieneAppsFlyer: el esquema Laravel no distingue todavía de qué
// export vino cada match (siempre null) -- se guarda contra `=== false`
// explícito en vez de `!card.tieneMeta`, para no mostrar "sin contraparte"
// como si lo supiéramos cuando en realidad no tenemos ese dato.
const faltaMeta = computed(() => card.value.tieneMeta === false);
const faltaAppsFlyer = computed(() => card.value.tieneAppsFlyer === false);
</script>

<template>
    <div class="modal-overlay" @click="$event.target === $event.currentTarget && $emit('cerrar')">
        <div class="modal">
            <button class="modal-close" type="button" aria-label="Cerrar" @click="$emit('cerrar')">✕</button>

            <div class="modal-image-wrap">
                <template v-if="card.tipoCreativo === 'VIDEO' && card.videoUrl">
                    <div class="no-image" :style="card.imageUrl ? { display: 'none' } : {}">
                        <span class="glyph">{{ ph.glyph }}</span>{{ ph.texto }}
                    </div>
                    <img v-if="card.imageUrl" :src="card.imageUrl" alt="" />
                    <a class="video-play-overlay" :href="card.videoUrl" target="_blank" rel="noopener noreferrer">
                        <span class="video-play-circle">▶</span>
                        <span class="video-play-label">Ver video en Facebook</span>
                    </a>
                </template>
                <template v-else>
                    <div class="no-image" :style="card.imageUrl ? { display: 'none' } : {}">
                        <span class="glyph">{{ ph.glyph }}</span>{{ ph.texto }}
                    </div>
                    <img v-if="card.imageUrl" :src="card.imageUrl" alt="" />
                </template>
            </div>

            <div class="modal-body">
                <h2>{{ card.arte || card.adNameShort || card.adId }}</h2>
                <p class="modal-meta mono">{{ card.campaignName || 'Sin campaña asociada' }}</p>

                <div class="copy-section">
                    <p v-if="!hayCopy" class="modal-copy-note">Copy no disponible — no viene en los exports.</p>
                    <template v-else>
                        <div v-if="copyBodies.length" class="copy-block">
                            <span class="copy-label">
                                Texto{{ multiVariante ? ` — ${copyBodies.length} variantes (${rotacionLabel})` : '' }}
                            </span>
                            <p v-for="(b, i) in copyBodies" :key="i" class="copy-body">{{ b }}</p>
                        </div>
                        <div v-if="copyTitles.length" class="copy-block">
                            <span class="copy-label">Título{{ copyTitles.length > 1 ? 's' : '' }}</span>
                            <p v-for="(t, i) in copyTitles" :key="i" class="copy-title">{{ t }}</p>
                        </div>
                        <div v-if="copyDescriptions.length" class="copy-block">
                            <span class="copy-label">Descripción{{ copyDescriptions.length > 1 ? 'es' : '' }}</span>
                            <p v-for="(d, i) in copyDescriptions" :key="i" class="copy-desc">{{ d }}</p>
                        </div>
                    </template>
                </div>

                <div class="modal-highlight-stats">
                    <div v-for="s in stats" :key="s.label" class="stat">
                        <div class="stat-label">{{ s.label }}</div>
                        <div class="stat-value mono">{{ s.value }}</div>
                    </div>
                </div>

                <div v-if="fatiga" class="fatiga-section">
                    <!-- Sin datos de fatiga persistidos todavía -- ver nota arriba. -->
                </div>

                <div class="modal-metrics-grid">
                    <div v-for="[k, v] in metrics" :key="k" class="metric-item">
                        <span class="k">{{ k }}</span>
                        <span class="v">{{ v }}</span>
                    </div>
                </div>

                <div v-if="serie && serie.length >= 2" class="sparkline-block">
                    <!-- Sin serie diaria persistida todavía -- ver nota arriba. -->
                </div>

                <p v-if="faltaMeta" class="modal-copy-note">
                    Este anuncio no tiene contraparte en el export de Meta Ads — solo se muestran los datos de AppsFlyer.
                </p>
                <p v-if="faltaAppsFlyer" class="modal-copy-note">
                    Este anuncio no tiene contraparte en el export de AppsFlyer — solo se muestran los datos de Meta Ads.
                </p>
            </div>
        </div>
    </div>
</template>
