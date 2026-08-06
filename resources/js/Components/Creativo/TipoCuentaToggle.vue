<script setup>
import { TIPO_CUENTA_OPCIONES } from '@/motor';

// Puerto literal de TIPO_CUENTA_OPCIONES + renderTipoCuentaToggle
// (motor.js) -- DTC/BRD es una dimensión ORTOGONAL al funnel, nunca se
// suman en el mismo cálculo. Usado por ImportarDatos.vue como preview de
// solo lectura (readonly) antes de confirmar la importación -- ver
// AnalisisCreativo.vue para el filtro interactivo, que usa un <select> en
// vez de este toggle de botones.
defineProps({
    counts: { type: Object, required: true }, // { DTC, BRD, SIN_CLASIFICAR }
    modelValue: { type: String, default: null },
    readonly: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="tipo-cuenta-toggle">
        <button
            v-for="o in TIPO_CUENTA_OPCIONES"
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
