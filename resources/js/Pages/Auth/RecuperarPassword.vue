<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

// "¿Olvidaste tu contraseña?" sin correo (ver RecuperarPasswordController)
// -- la identidad se prueba con el código de la app de autenticación o un
// código de recuperación, no con un link por email.
// "¿Ya tienes tu 2FA?" (pedido explícito 2026-10-21). Si responde que no,
// NO se le da un QR acá: sin sesión ni código no hay forma de saber que es
// el dueño del correo, y quien lo tenga podría tomar la cuenta. Se le
// explica el camino seguro (login normal -> QR, o link de EPA).
// Si vuelve con errores de validación, ya había elegido "sí".
const tiene2fa = ref(Object.keys(usePage().props.errors ?? {}).length > 0 ? true : null);

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

        <template v-if="tiene2fa === null">
            <p class="auth-status">¿Ya tienes activada la verificación en dos pasos (Google Authenticator)?</p>

            <button type="button" class="auth-btn-secondary" @click="tiene2fa = true">Sí, ya la tengo</button>
            <button type="button" class="auth-btn-ghost" @click="tiene2fa = false">No, aún no</button>
            <Link :href="route('login')" class="auth-link">Volver a iniciar sesión</Link>
        </template>

        <template v-else-if="tiene2fa === false">
            <p class="auth-status">
                Sin la verificación en dos pasos no podemos confirmar desde aquí que la cuenta es tuya, así que el código QR se
                activa por otro camino:
            </p>
            <p class="auth-status">
                <strong>Si recuerdas tu contraseña:</strong> inicia sesión normalmente y te mostramos el QR para escanear y tus
                códigos de recuperación.
            </p>
            <p class="auth-status">
                <strong>Si no la recuerdas:</strong> pide a alguien de EPA un link nuevo. Con ese link pones tu contraseña,
                escaneas el QR y recibes tus códigos.
            </p>

            <Link :href="route('login')" class="auth-btn-secondary auth-btn-link">Ir a iniciar sesión</Link>
            <button type="button" class="auth-link auth-link-btn" @click="tiene2fa = null">Volver</button>
        </template>

        <form v-else class="auth-form" @submit.prevent="submit">
            <p class="auth-status">
                Ingresá tu correo, el código de tu app de autenticación (o un código de recuperación) y tu nueva contraseña.
            </p>

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

<style scoped>
.auth-link-btn {
    padding: 0;
    border: 0;
    background: none;
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    align-self: center;
}
</style>
