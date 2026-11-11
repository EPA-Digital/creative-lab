<script setup>
import { ref, watch } from 'vue';
import { iniciales } from '@/cuentas';

// Avatar de una persona del equipo: su foto si la subió, si no sus
// iniciales sobre el color que eligió (Editar perfil).
const props = defineProps({
    usuario: { type: Object, default: null }, // { name, avatar_color, foto }
    tamano: { type: Number, default: 36 },
});

const fallo = ref(false);
watch(() => props.usuario?.foto, () => { fallo.value = false; });
</script>

<template>
    <span
        class="avatar-usuario"
        :style="{ width: `${tamano}px`, height: `${tamano}px`, fontSize: `${Math.round(tamano * 0.36)}px`, background: usuario?.avatar_color || 'var(--avatar-default)' }"
        role="img"
        :aria-label="usuario?.name ? `Foto de ${usuario.name}` : 'Foto de perfil'"
    >
        <img v-if="usuario?.foto && !fallo" :src="usuario.foto" alt="" @error="fallo = true">
        <template v-else>{{ iniciales(usuario?.name) }}</template>
    </span>
</template>

<style scoped>
.avatar-usuario {
    display: inline-grid;
    place-items: center;
    flex: none;
    overflow: hidden;
    border-radius: 999px;
    color: var(--avatar-ink);
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    line-height: 1;
}
.avatar-usuario img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
