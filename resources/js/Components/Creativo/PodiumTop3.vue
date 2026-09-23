<script setup>
import { computed } from 'vue';
import CreativeCard from '@/Components/Creativo/CreativeCard.vue';

// Puerto literal de renderPodio (creativos.html:287-296) -- SÍ existe un
// podio en el sistema real, confirmado leyendo el archivo (contradice una
// instrucción anterior que decía lo contrario). No es un mini-bloque propio:
// envuelve la MISMA CreativeCard completa (imagen, badges, stats) dentro de
// .podio-col, y le agrega debajo un .podio-base con el número de puesto.
// Orden DOM 2-1-3 para el escalonado visual (.podio-puesto-1 se eleva con
// translateY en CSS), tolera menos de 3 elegibles.
//
// N variable (2026-09-22, selector Top 3/Top 5) -- con exactamente 3
// elementos se conserva el escalonado 2-1-3 tal cual. Con otra cantidad
// (hoy, 5) se muestran en orden secuencial 1..N -- el escalonado "ganador
// al centro" no tiene un equivalente obvio con más de 3, así que no se
// fuerza. El contenedor `.podio` ya soporta overflow-x, así que 5 columnas
// no rompen el layout aunque no entren todas en una pantalla angosta.
const props = defineProps({
    top3: { type: Array, required: true }, // ya ordenados, 1º primero
    estrellaOverride: { type: Function, default: null }, // (creativo) => {label, value} | null
    mes: { type: String, default: null }, // 'YYYY-MM' -- para el rango de actividad de la card (ver rangoActividadEnMes)
});

const emit = defineEmits(['abrir']);

const ordenDom = computed(() => {
    const n = props.top3.length;
    if (n === 3) return [2, 1, 3];
    return Array.from({ length: n }, (_, i) => i + 1);
});
</script>

<template>
    <template v-for="puesto in ordenDom" :key="puesto">
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
