<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

// Única vez que estos códigos existen en texto plano (auth-prompt.md Fase
// 3) -- el backend solo guarda sus hashes (ver TotpService). Si el usuario
// pierde esta pantalla, no hay forma de volver a verlos -- solo
// resetearTotp() y volver a enrolar.
defineProps({
    codigos: { type: Array, required: true },
    continuarA: { type: String, required: true },
    mensajePendiente: { type: String, default: null },
});
</script>

<template>
    <AuthCardLayout>
        <Head title="Códigos de recuperación" />

        <h1 class="auth-title">Códigos de recuperación</h1>

        <p class="auth-status">
            Guardá estos códigos en un lugar seguro. Cada uno sirve una sola vez, para entrar si perdés acceso a tu app de
            autenticación. No se van a volver a mostrar.
        </p>

        <ul class="auth-recovery-grid">
            <li v-for="codigo in codigos" :key="codigo">{{ codigo }}</li>
        </ul>

        <p v-if="mensajePendiente" class="auth-status">{{ mensajePendiente }}</p>

        <Link :href="continuarA" class="auth-btn-secondary auth-btn-link">
            Ya los guardé, continuar
        </Link>
    </AuthCardLayout>
</template>
