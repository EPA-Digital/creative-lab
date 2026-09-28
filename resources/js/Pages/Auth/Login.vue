<script setup>
import { ref } from 'vue';
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// Si el form.errors ya tiene algo (validación fallida en /login, ver
// LoginRequest) el formulario de correo/contraseña arranca abierto --
// nunca esconder un error detrás del botón "Usar correo y contraseña".
const mostrarFormulario = ref(Object.keys(usePage().props.errors ?? {}).length > 0);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Iniciar sesión" />

        <h1 class="auth-title">Bienvenido de vuelta</h1>

        <p v-if="status" class="auth-status">{{ status }}</p>

        <a :href="route('google.redirect')" class="auth-btn-google">
            <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.47a5.53 5.53 0 0 1-2.4 3.63v3h3.89c2.28-2.1 3.56-5.2 3.56-8.82Z"/>
                <path fill="#34A853" d="M12 24c3.24 0 5.95-1.07 7.93-2.91l-3.89-3a7.4 7.4 0 0 1-11-3.9H1.02v3.1A12 12 0 0 0 12 24Z"/>
                <path fill="#FBBC05" d="M5.04 14.19a7.2 7.2 0 0 1 0-4.38v-3.1H1.02a12 12 0 0 0 0 10.58l4.02-3.1Z"/>
                <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.44-3.44C17.94 1.19 15.23 0 12 0A12 12 0 0 0 1.02 6.71l4.02 3.1A7.15 7.15 0 0 1 12 4.75Z"/>
            </svg>
            Continuar con Google <span class="auth-btn-google-domain">(@epa.digital)</span>
        </a>

        <button v-if="!mostrarFormulario" type="button" class="auth-btn-ghost" @click="mostrarFormulario = true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="14" rx="2" />
                <path d="m4 7 8 6 8-6" />
            </svg>
            Usar correo y contraseña
        </button>

        <form v-else class="auth-form" @submit.prevent="submit">
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
                    autocomplete="current-password"
                />
                <p v-if="form.errors.password" class="auth-input-error">{{ form.errors.password }}</p>
            </div>

            <div class="auth-row-between">
                <label class="auth-checkbox-label">
                    <input type="checkbox" v-model="form.remember" />
                    Recordarme
                </label>

                <Link v-if="canResetPassword" :href="route('password.request')" class="auth-link">
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>

            <button type="submit" class="auth-btn-secondary" :disabled="form.processing">
                Entrar
            </button>
        </form>
    </AuthCardLayout>
</template>
