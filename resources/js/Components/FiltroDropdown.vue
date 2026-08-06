<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

// Dropdown de filtro (Plataforma / Tipo de cuenta / Rankear por en
// AnalisisCreativo.vue) -- estilo pedido por el usuario (caja con label
// arriba + chevron + panel flotante con check en la opción activa), no
// viene del sistema Node real (esa parte de la UI era selects nativos) --
// decisión de diseño explícita del usuario, aplicada acá una sola vez y
// reusada en los 3 selectores para que no queden con looks distintos.
const props = defineProps({
    label: { type: String, default: '' },
    options: { type: Array, required: true }, // [{ key, label }]
    modelValue: { type: [String, Number], default: null },
    counts: { type: Object, default: null }, // opcional: agrega " (n)" a cada opción
});
const emit = defineEmits(['update:modelValue']);

const abierto = ref(false);
const raiz = ref(null);

function textoOpcion(o) {
    return props.counts ? `${o.label} (${props.counts[o.key] ?? 0})` : o.label;
}
const opcionActual = computed(() => props.options.find((o) => o.key === props.modelValue));

function seleccionar(key) {
    emit('update:modelValue', key);
    abierto.value = false;
}
function onClickFuera(e) {
    if (raiz.value && !raiz.value.contains(e.target)) abierto.value = false;
}
function onKeydown(e) {
    if (e.key === 'Escape') abierto.value = false;
}
onMounted(() => {
    document.addEventListener('click', onClickFuera);
    document.addEventListener('keydown', onKeydown);
});
onUnmounted(() => {
    document.removeEventListener('click', onClickFuera);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="raiz" class="filtro-dropdown">
        <span v-if="label" class="filtro-dropdown-label">{{ label }}</span>
        <button type="button" class="filtro-dropdown-btn" :class="{ abierto }" @click="abierto = !abierto">
            <span>{{ opcionActual ? textoOpcion(opcionActual) : '' }}</span>
            <svg width="11" height="7" viewBox="0 0 11 7" fill="none" class="filtro-dropdown-chevron">
                <path d="M1 1l4.5 4.5L10 1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <div v-if="abierto" class="filtro-dropdown-panel">
            <button
                v-for="o in options"
                :key="o.key"
                type="button"
                class="filtro-dropdown-opcion"
                :class="{ seleccionada: o.key === modelValue }"
                @click="seleccionar(o.key)"
            >
                <span class="filtro-dropdown-check">{{ o.key === modelValue ? '✓' : '' }}</span>
                {{ textoOpcion(o) }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.filtro-dropdown {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.filtro-dropdown-label {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.filtro-dropdown-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 190px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    /* Rectangular (8px), igual que .chip/.toggle-btn -- pedido explícito:
       que combine con el resto de los botones seleccionables. */
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    transition: border-color 0.15s ease;
}
.filtro-dropdown-btn:hover,
.filtro-dropdown-btn.abierto {
    border-color: var(--amber);
}
.filtro-dropdown-chevron {
    flex: 0 0 auto;
    color: var(--text-muted);
    transition: transform 0.15s ease;
}
.filtro-dropdown-btn.abierto .filtro-dropdown-chevron {
    transform: rotate(180deg);
}
.filtro-dropdown-panel {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    z-index: 20;
    min-width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 6px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.filtro-dropdown-opcion {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 10px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    text-align: left;
    white-space: nowrap;
    transition: background 0.15s ease, color 0.15s ease;
}
.filtro-dropdown-opcion:hover {
    background: var(--surface-2);
    color: var(--text);
}
.filtro-dropdown-opcion.seleccionada {
    color: var(--amber);
    background: color-mix(in srgb, var(--amber) 12%, transparent);
}
.filtro-dropdown-check {
    flex: 0 0 auto;
    width: 12px;
    color: var(--amber);
    font-size: 12px;
}
</style>
