<script setup>
import { computed } from 'vue';
import { cardDesdeCreativo, nombrePrincipalYTecnico, FUNNEL_COLOR_VAR } from '@/motor';

// Vista "Barras horizontales" de "Todos los creativos" (2026-08-27, ver
// plan del rediseño) -- ranking en barras, buena para nombres largos. Mismo
// dataset/orden que CreativeCarousel/CreativeTable; `valorNumerico` es el
// valor CRUDO de la métrica activa (AnalisisCreativo.vue: valorCampo) para
// poder calcular el ancho de barra -- estrellaOverride solo trae el string
// ya formateado, no alcanza para proporciones.
const props = defineProps({
    creativos: { type: Array, required: true },
    estrellaOverride: { type: Function, default: null },
    valorNumerico: { type: Function, required: true }, // (creativo) => number|null
});
const emit = defineEmits(['abrir']);

// Piso de 10% -- una barra en 0% se ve como si no tuviera dato; con datos
// reales (nunca null acá, AnalisisCreativo.vue ya filtra antes de pasar la
// lista) el piso solo evita el caso borde de "todos con el mismo valor".
const filas = computed(() => {
    const base = props.creativos.map((creativo) => {
        const card = cardDesdeCreativo(creativo);
        const estrella = (props.estrellaOverride ? props.estrellaOverride(creativo) : null) || { label: '—', value: '—' };
        return {
            creativo,
            card,
            nombre: nombrePrincipalYTecnico(card.nombreAmigable, card.arte || card.adNameShort || card.adId),
            stageColor: FUNNEL_COLOR_VAR[card.etapaFunnel] || 'var(--violet)',
            estrella,
            valor: props.valorNumerico(creativo),
        };
    });
    const valores = base.map((f) => f.valor).filter((v) => v !== null && v !== undefined);
    const max = valores.length ? Math.max(...valores) : 0;
    return base.map((f) => ({
        ...f,
        pct: f.valor && max ? Math.max(10, (f.valor / max) * 100) : 10,
    }));
});
</script>

<template>
    <div v-if="filas.length" class="creative-bars">
        <div
            v-for="fila in filas"
            :key="fila.creativo.id"
            class="creative-bars-fila"
            tabindex="0"
            role="button"
            @click="emit('abrir', fila.creativo)"
            @keydown.enter.space.prevent="emit('abrir', fila.creativo)"
        >
            <div class="creative-bars-nombre">
                <span class="creative-bars-titulo">{{ fila.nombre.principal }}</span>
                <span v-if="fila.nombre.tecnico" class="creative-bars-tecnico mono">{{ fila.nombre.tecnico }}</span>
            </div>
            <div class="creative-bars-track">
                <div class="creative-bars-fill" :style="{ width: fila.pct + '%', background: fila.stageColor }" />
            </div>
            <span class="creative-bars-valor mono">{{ fila.estrella.value }}</span>
        </div>
    </div>
</template>

<style scoped>
.creative-bars {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 8px 20px;
}
.creative-bars-fila {
    display: grid;
    grid-template-columns: 220px 1fr 90px;
    align-items: center;
    gap: 14px;
    padding: 11px 0;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
}
.creative-bars-fila:last-child {
    border-bottom: none;
}
.creative-bars-fila:hover {
    background: var(--surface-2);
}
.creative-bars-nombre {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}
.creative-bars-titulo {
    font-size: 0.84rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.creative-bars-tecnico {
    font-size: 0.64rem;
    color: var(--text-faint);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.creative-bars-track {
    height: 22px;
    border-radius: 6px;
    background: var(--bg);
    overflow: hidden;
}
.creative-bars-fill {
    height: 100%;
    border-radius: 6px;
}
.creative-bars-valor {
    font-size: 0.85rem;
    font-weight: 700;
    text-align: right;
}
</style>
