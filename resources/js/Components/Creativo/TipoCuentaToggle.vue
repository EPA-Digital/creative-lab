<script setup>
import { computed } from 'vue';
import { opcionesTipoCuenta } from '@/motor';

// Puerto literal de TIPO_CUENTA_OPCIONES + renderTipoCuentaToggle
// (motor.js) -- DTC/BRD es una dimensión ORTOGONAL al funnel, nunca se
// suman en el mismo cálculo. Usado por ImportarDatos.vue como preview de
// solo lectura (readonly) antes de confirmar la importación -- ver
// AnalisisCreativo.vue para el filtro interactivo, que usa un <select> en
// vez de este toggle de botones.
const props = defineProps({
    pais: { type: String, default: null },
    counts: { type: Object, required: true }, // { DTC, BRD, SIN_CLASIFICAR }
    modelValue: { type: String, default: null },
    readonly: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
// BRD solo en TaDa Ecuador; otras cuentas: Clasificados / Sin clasificar.
const opciones = computed(() => opcionesTipoCuenta(props.pais));
</script>

<template>
    <div class="tipo-cuenta-toggle">
        <button
            v-for="o in opciones"
            :key="o.key"
            type="button"
            class="toggle-btn"
            :class="{ active: modelValue === o.key }"
            :disabled="readonly"
            @click="!readonly && $emit('update:modelValue', o.key)"
        >
            {{ o.label }} <span class="count">{{ counts[o.key] ?? 0 }}</span>
        </button>
    </div>
</template>
