<script setup>
import { computed } from 'vue';
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Sin uso real hoy -- User no implementa MustVerifyEmail (comentado a
// propósito, ver app/Models/User.php), así que nada redirige acá. Se
// rediseña igual por consistencia con el resto de la familia de auth.
const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <AuthCardLayout>
        <Head title="Verificar correo" />

        <h1 class="auth-title">Verificá tu correo</h1>

        <p class="auth-status">
            Antes de arrancar, confirmá tu correo haciendo clic en el link que te mandamos. Si no te llegó, te mandamos otro.
        </p>

        <p v-if="verificationLinkSent" class="auth-status">
            Te mandamos un link de verificación nuevo al correo que registraste.
        </p>

        <form class="auth-form" @submit.prevent="submit">
            <button type="submit" class="auth-btn-secondary" :disabled="form.processing">
                Reenviar correo de verificación
            </button>

            <Link :href="route('logout')" method="post" as="button" class="auth-link auth-btn-link">
                Cerrar sesión
            </Link>
        </form>
    </AuthCardLayout>
</template>
