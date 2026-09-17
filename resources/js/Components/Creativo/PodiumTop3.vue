<script setup>
import CreativeCard from '@/Components/Creativo/CreativeCard.vue';

// Puerto literal de renderPodio (creativos.html:287-296) -- SÍ existe un
// podio en el sistema real, confirmado leyendo el archivo (contradice una
// instrucción anterior que decía lo contrario). No es un mini-bloque propio:
// envuelve la MISMA CreativeCard completa (imagen, badges, stats) dentro de
// .podio-col, y le agrega debajo un .podio-base con el número de puesto.
// Orden DOM 2-1-3 para el escalonado visual (.podio-puesto-1 se eleva con
// translateY en CSS), tolera menos de 3 elegibles.
const props = defineProps({
    top3: { type: Array, required: true }, // [1º, 2º, 3º] ya ordenados
    estrellaOverride: { type: Function, default: null }, // (creativo) => {label, value} | null
    mes: { type: String, default: null }, // 'YYYY-MM' -- para el rango de actividad de la card (ver rangoActividadEnMes)
});

const emit = defineEmits(['abrir']);

const ORDEN_DOM = [2, 1, 3];
</script>

<template>
    <template v-for="puesto in ORDEN_DOM" :key="puesto">
        <div v-if="top3[puesto - 1]" class="podio-col" :class="`podio-puesto-${puesto}`">
            <CreativeCard
                :creativo="top3[puesto - 1]"
                :rank="puesto"
                :estrella-override="estrellaOverride ? estrellaOverride(top3[puesto - 1]) : null"
                :mes="mes"
                @abrir="emit('abrir', $event)"
            />
            <div class="podio-base"><span>{{ puesto }}</span></div>
        </div>
    </template>
</template>
