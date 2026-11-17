<script setup>
import { ref, computed, nextTick } from 'vue';

// Celda editable del Daily (2026-11-18, pedido explícito: "no me gusta como
// se ve" la tabla llena de cajas): se ve como texto; al pasar el mouse sale
// un lápiz y al tocarla se vuelve campo. Enter o salir guarda, Esc cancela.
// Lo que ya se capturó a mano lleva un lápiz morado chico (el "morado" de la
// hoja), así se distingue de lo calculado.
const props = defineProps({
    valor: { type: [String, Number], default: null },
    tipo: { type: String, default: 'texto' }, // texto | numero | select
    opciones: { type: Array, default: () => [] }, // select: [{ key, label }]
    formato: { type: Function, default: null },
    vacio: { type: String, default: '—' },
    etiqueta: { type: String, default: 'Editar' },
    alinear: { type: String, default: 'derecha' }, // derecha | izquierda
    claseValor: { type: String, default: '' },
});
const emit = defineEmits(['guardar']);

const editando = ref(false);
const borrador = ref('');
const campo = ref(null);
const tieneValor = computed(() => props.valor !== null && props.valor !== undefined && props.valor !== '');
const mostrado = computed(() => {
    if (!tieneValor.value) return props.vacio;
    if (props.tipo === 'select') return props.opciones.find((o) => o.key === props.valor)?.label ?? props.valor;
    return props.formato ? props.formato(props.valor) : props.valor;
});

async function abrir() {
    borrador.value = tieneValor.value ? String(props.valor) : '';
    editando.value = true;
    await nextTick();
    campo.value?.focus();
    if (props.tipo !== 'select') campo.value?.select();
}
function guardar() {
    if (!editando.value) return;
    editando.value = false;
    if (borrador.value !== (tieneValor.value ? String(props.valor) : '')) emit('guardar', borrador.value);
}
function cancelar() {
    editando.value = false;
}
</script>

<template>
    <span class="celda" :class="[`al-${alinear}`]">
        <select v-if="editando && tipo === 'select'" ref="campo" v-model="borrador" class="campo" @change="guardar" @blur="guardar" @keydown.esc.prevent="cancelar">
            <option v-for="o in opciones" :key="o.key" :value="o.key">{{ o.label }}</option>
        </select>
        <input
            v-else-if="editando"
            ref="campo"
            v-model="borrador"
            class="campo"
            :inputmode="tipo === 'numero' ? 'decimal' : 'text'"
            type="text"
            @keydown.enter.prevent="guardar"
            @keydown.esc.prevent="cancelar"
            @blur="guardar"
        />
        <button v-else type="button" class="ver" :class="{ manual: tieneValor }" :title="etiqueta" :aria-label="etiqueta" @click="abrir">
            <span class="texto" :class="[claseValor, { vacio: !tieneValor }]">{{ mostrado }}</span>
            <svg class="lapiz" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
            </svg>
        </button>
    </span>
</template>

<style scoped>
.celda {
    display: inline-flex;
    white-space: nowrap;
}
.al-derecha {
    justify-content: flex-end;
}
.ver {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-width: 22px;
    min-height: 20px;
    background: none;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 2px 5px;
    margin: -3px -5px;
    color: inherit;
    font: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.al-derecha .ver {
    flex-direction: row-reverse;
}
.texto {
    white-space: nowrap;
}
.texto.vacio {
    color: var(--text-faint);
}
.lapiz {
    flex: none;
    color: var(--serie-6);
    opacity: 0;
    transition: opacity 0.15s ease;
}
/* Capturado a mano: lápiz morado tenue siempre visible. */
.ver.manual .lapiz {
    opacity: 0.55;
}
.ver:hover,
.ver:focus-visible {
    border-color: color-mix(in srgb, var(--serie-6) 45%, transparent);
    background: color-mix(in srgb, var(--serie-6) 10%, transparent);
    outline: none;
}
.ver:hover .lapiz,
.ver:focus-visible .lapiz {
    opacity: 1;
}
.campo {
    width: 100%;
    min-width: 70px;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--serie-6);
    border-radius: 6px;
    padding: 3px 6px;
    font: inherit;
    text-align: inherit;
    outline: none;
}
.al-derecha .campo {
    text-align: right;
}
</style>
