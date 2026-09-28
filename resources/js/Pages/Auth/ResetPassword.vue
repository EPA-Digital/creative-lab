<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// Ruta sin uso real hoy -- ver ForgotPassword.vue (sin uso, mismo motivo).
const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Resetear contraseña" />

        <h1 class="auth-title">Elige una contraseña nueva</h1>

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

            <div class="auth-field">
                <label for="password" class="auth-label">Contraseña</label>
                <input
                    id="password"
                    type="password"
                    class="auth-input"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />
                <p v-if="form.errors.password" class="auth-input-error">{{ form.errors.password }}</p>
            </div>

            <div class="auth-field">
                <label for="password_confirmation" class="auth-label">Confirmar contraseña</label>
                <input
                    id="password_confirmation"
                    type="password"
                    class="auth-input"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <p v-if="form.errors.password_confirmation" class="auth-input-error">{{ form.errors.password_confirmation }}</p>
            </div>

            <button type="submit" class="auth-btn-secondary" :disabled="form.processing">
                Resetear contraseña
            </button>
        </form>
    </AuthCardLayout>
</template>
