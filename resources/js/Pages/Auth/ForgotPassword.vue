<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// Ruta sin uso real hoy -- el link que apunta acá está oculto
// (canResetPassword=false, ver AuthenticatedSessionController::create())
// porque sin SMTP real el correo nunca llega (ver docs/pendientes-datos.md).
// La recuperación real es UsuariosController::resetearPassword() (un
// admin genera el link a mano). Se rediseña igual, por consistencia, por
// si algún día se reactiva con un proveedor de correo real.
defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Olvidé mi contraseña" />

        <h1 class="auth-title">Olvidé mi contraseña</h1>

        <p class="auth-status">
            Escribe tu correo, te haremos llegar un link para cambiar tu contraseña.
        </p>

        <p v-if="status" class="auth-status">{{ status }}</p>

        <form class="auth-form" @submit.prevent="submit">
            <div class="auth-field">
                <label for="email" class="auth-label">Correo</label>
                <input
                    id="email"
                    type="email"
                    class="auth-input"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />
                <p v-if="form.errors.email" class="auth-input-error">{{ form.errors.email }}</p>
            </div>

            <button type="submit" class="auth-btn-secondary" :disabled="form.processing">
                Mandar link
            </button>
        </form>
    </AuthCardLayout>
</template>
