<script setup>
import { ref, watch } from 'vue';
import { iniciales } from '@/cuentas';

// Logo de una cuenta: su imagen si la subió (LogoCuentaController) o las
// iniciales sobre su color. Si la imagen falla al cargar, vuelve a las
// iniciales en vez de mostrar un ícono roto.
const props = defineProps({
    cuenta: { type: Object, default: null }, // { nombre, color, logo }
    tamano: { type: Number, default: 40 },
    radio: { type: Number, default: 10 },
});

const fallo = ref(false);
watch(() => props.cuenta?.logo, () => { fallo.value = false; });
</script>

<template>
    <span
        class="logo-cuenta"
        :class="{ 'con-imagen': cuenta?.logo && !fallo }"
        :style="{ width: `${tamano}px`, height: `${tamano}px`, borderRadius: `${radio}px`, fontSize: `${Math.round(tamano * 0.35)}px`, background: cuenta?.logo && !fallo ? null : cuenta?.color }"
        role="img"
        :aria-label="cuenta?.nombre ? `Logo de ${cuenta.nombre}` : 'Logo de la cuenta'"
    >
        <img v-if="cuenta?.logo && !fallo" :src="cuenta.logo" alt="" @error="fallo = true">
        <template v-else>{{ iniciales(cuenta?.nombre) }}</template>
    </span>
</template>

<style scoped>
.logo-cuenta {
    display: inline-grid;
    place-items: center;
    flex: none;
    overflow: hidden;
    color: #fff;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    line-height: 1;
}
/* Fondo blanco detrás de la imagen: la mayoría de los logos son para fondo claro. */
.logo-cuenta.con-imagen {
    background: #fff;
}
.logo-cuenta img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 12%;
}
</style>
