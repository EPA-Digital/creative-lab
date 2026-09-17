<script setup>
import { computed } from 'vue';
import { cardDesdeCreativo, statsParaCard, nombrePrincipalYTecnico, FUNNEL_COLOR_VAR, FUNNEL_LABELS } from '@/motor';

// Vista "Tabla" de "Todos los creativos" (2026-08-27, ver plan del
// rediseño) -- mismo dataset y mismo orden que CreativeCarousel
// (`creativos` ya viene ordenado por AnalisisCreativo.vue), solo cambia
// cómo se pinta: fila en vez de card, mismas 3 stats de statsParaCard.
const props = defineProps({
    creativos: { type: Array, required: true },
    estrellaOverride: { type: Function, default: null },
});
const emit = defineEmits(['abrir']);

const filas = computed(() => props.creativos.map((creativo) => {
    const card = cardDesdeCreativo(creativo);
    const stats = statsParaCard(card);
    const estrella = (props.estrellaOverride ? props.estrellaOverride(creativo) : null) || stats[0] || { label: '—', value: '—' };
    const secundarias = stats.filter((s) => s.label.toUpperCase() !== estrella.label.toUpperCase()).slice(0, 2);
    return {
        creativo,
        card,
        nombre: nombrePrincipalYTecnico(card.nombreAmigable, card.arte || card.adNameShort || card.adId),
        stageColor: FUNNEL_COLOR_VAR[card.etapaFunnel] || 'var(--violet)',
        estrella,
        secundarias,
    };
}));
</script>

<template>
    <div v-if="filas.length" class="creative-table-wrap">
        <table class="creative-table">
            <thead>
                <tr>
                    <th>Creativo</th>
                    <th>Funnel</th>
                    <th class="num">{{ filas[0]?.estrella.label }}</th>
                    <th v-for="s in filas[0]?.secundarias" :key="s.label" class="num">{{ s.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="fila in filas"
                    :key="fila.creativo.id"
                    tabindex="0"
                    role="button"
                    @click="emit('abrir', fila.creativo)"
                    @keydown.enter.space.prevent="emit('abrir', fila.creativo)"
                >
                    <td>
                        <span class="creative-table-titulo">{{ fila.nombre.principal }}</span>
                        <span v-if="fila.nombre.tecnico" class="creative-table-tecnico mono">{{ fila.nombre.tecnico }}</span>
                    </td>
                    <td>
                        <span v-if="fila.card.etapaFunnel" class="resumen-card-funnel" :style="{ '--stage-color': fila.stageColor }">
                            {{ FUNNEL_LABELS[fila.card.etapaFunnel] || fila.card.etapaFunnel }}
                        </span>
                    </td>
                    <td class="num mono creative-table-estrella">{{ fila.estrella.value }}</td>
                    <td v-for="s in fila.secundarias" :key="s.label" class="num mono">{{ s.value }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.creative-table-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: auto;
}
.creative-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.creative-table th {
    text-align: left;
    padding: 10px 16px;
    font-size: 0.66rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.creative-table th.num,
.creative-table td.num {
    text-align: right;
}
.creative-table td {
    padding: 11px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.creative-table tbody tr {
    cursor: pointer;
}
.creative-table tbody tr:hover {
    background: var(--surface-2);
}
.creative-table tbody tr:last-child td {
    border-bottom: none;
}
.creative-table-titulo {
    display: block;
    font-weight: 700;
}
.creative-table-tecnico {
    display: block;
    font-size: 0.68rem;
    color: var(--text-faint);
    word-break: break-all;
    margin-top: 1px;
}
.creative-table-estrella {
    font-weight: 700;
    font-size: 0.95rem;
}
</style>
