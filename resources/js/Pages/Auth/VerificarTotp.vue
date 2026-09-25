<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// Segundo paso del login para metodo_auth=password (auth-prompt.md Fase 3)
// -- la contraseña ya se validó (ver AuthenticatedSessionController), acá
// solo falta el código TOTP (o un código de recuperación de un solo uso)
// para que Auth::login() pase de verdad.
const form = useForm({ codigo: '' });

const submit = () => {
    form.post(route('2fa.verificar'), {
        onFinish: () => form.reset('codigo'),
    });
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Verificación en dos pasos" />

        <h1 class="auth-title">Verificación en dos pasos</h1>

        <p class="auth-status">Ingresá el código de tu app de autenticación, o un código de recuperación si perdiste acceso a ella.</p>

        <form class="auth-form" @submit.prevent="submit">
            <div class="auth-field">
                <label for="codigo" class="auth-label">Código</label>
                <input
                    id="codigo"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="auth-input"
                    v-model="form.codigo"
                    required
                    autofocus
                />
                <p v-if="form.errors.codigo" class="auth-input-error">{{ form.errors.codigo }}</p>
            </div>

            <button type="submit" class="auth-btn-secondary" :disabled="form.processing">
                Verificar
            </button>
        </form>
    </AuthCardLayout>
</template>
