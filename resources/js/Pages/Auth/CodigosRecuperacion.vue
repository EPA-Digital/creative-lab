<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
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
    <GuestLayout>
        <Head title="Códigos de recuperación" />

        <p class="mb-4 text-sm text-gray-600">
            Guardá estos códigos en un lugar seguro. Cada uno sirve una sola vez, para entrar si perdés acceso a tu app de
            autenticación. No se van a volver a mostrar.
        </p>

        <ul class="mb-6 grid grid-cols-2 gap-2 rounded border border-gray-200 p-4 font-mono text-sm">
            <li v-for="codigo in codigos" :key="codigo">{{ codigo }}</li>
        </ul>

        <p v-if="mensajePendiente" class="mb-4 text-sm text-gray-600">{{ mensajePendiente }}</p>

        <div class="flex items-center justify-end">
            <Link
                :href="continuarA"
                class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900"
            >
                Ya los guardé, continuar
            </Link>
        </div>
    </GuestLayout>
</template>
