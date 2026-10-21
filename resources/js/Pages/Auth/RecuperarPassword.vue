<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// "¿Olvidaste tu contraseña?" sin correo (ver RecuperarPasswordController)
// -- la identidad se prueba con el código de la app de autenticación o un
// código de recuperación, no con un link por email.
const form = useForm({
    email: '',
    codigo: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.recuperar'), {
        onFinish: () => form.reset('codigo', 'password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Recuperar contraseña" />

        <h1 class="auth-title">Recuperar contraseña</h1>

        <p class="auth-status">
            Ingresá tu correo, el código de tu app de autenticación (o un código de recuperación) y tu nueva contraseña.
            Si nunca configuraste la verificación en dos pasos, pedile a alguien de EPA que resetee tu contraseña.
        </p>

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
                <label for="codigo" class="auth-label">Código de verificación</label>
                <input
                    id="codigo"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="auth-input"
                    v-model="form.codigo"
                    required
                />
                <p v-if="form.errors.codigo" class="auth-input-error">{{ form.errors.codigo }}</p>
            </div>

            <div class="auth-field">
                <label for="password" class="auth-label">Nueva contraseña</label>
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
                <label for="password_confirmation" class="auth-label">Confirmar nueva contraseña</label>
                <input
                    id="password_confirmation"
                    type="password"
                    class="auth-input"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />
            </div>

            <button type="submit" class="auth-btn-secondary" :disabled="form.processing">
                Cambiar contraseña
            </button>

            <Link :href="route('login')" class="auth-link">Volver a iniciar sesión</Link>
        </form>
    </AuthCardLayout>
</template>
