<script setup>
// Tipos de gráfica para "Todos los creativos" (2026-08-27, ver plan del
// rediseño) -- Cards (ya existía, CreativeCarousel) / Tabla / Barras
// horizontales. Se deja fuera "Columnas agrupadas" y "Tendencia" acá a
// propósito -- Inteligencia ya cubre comparación entre creativos y
// tendencia mensual, duplicarlo en esta lista no aporta.
defineProps({
    modelValue: { type: String, required: true }, // 'cards' | 'tabla' | 'barras'
});
defineEmits(['update:modelValue']);

const VISTAS = [
    {
        key: 'cards',
        label: 'Cards',
        icono: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    },
    {
        key: 'tabla',
        label: 'Tabla',
        icono: '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 10h18M9 4v16"/>',
    },
    {
        key: 'barras',
        label: 'Barras',
        icono: '<path d="M4 6h10M4 12h14M4 18h7"/>',
    },
];
</script>

<template>
    <div class="vista-switch">
        <button
            v-for="v in VISTAS"
            :key="v.key"
            type="button"
            class="vista-switch-btn"
            :class="{ activo: v.key === modelValue }"
            @click="$emit('update:modelValue', v.key)"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="v.icono" />
            {{ v.label }}
        </button>
    </div>
</template>

<style scoped>
.vista-switch {
    display: flex;
    gap: 3px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 3px;
}
.vista-switch-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 7px;
    font-size: 0.76rem;
    font-weight: 600;
    color: var(--text-faint);
    background: transparent;
    border: none;
}
.vista-switch-btn svg {
    width: 14px;
    height: 14px;
}
.vista-switch-btn.activo {
    background: var(--violet);
    color: #fff;
}
</style>
