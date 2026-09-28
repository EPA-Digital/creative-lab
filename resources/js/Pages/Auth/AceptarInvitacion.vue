<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// Único camino de acceso para un usuario 'cliente' -- un EPA lo invitó
// desde /usuarios (ver UsuariosController), este link (con el token) se le
// mandó a mano (Slack/WhatsApp/correo, sin envío automático todavía). Acá
// solo pone su contraseña -- nombre/correo ya vienen fijos, no se editan.
// Mismo link para "olvidé mi contraseña" (ver UsuariosController::
// resetearPassword()) -- no hay forma de distinguir los dos casos desde
// acá (ni hace falta), el copy sirve para ambos.
const props = defineProps({
    token: { type: String, required: true },
    nombre: { type: String, required: true },
    email: { type: String, required: true },
});

const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('invitaciones.store', props.token), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Activar cuenta" />

        <h1 class="auth-title">Hola, {{ nombre }}</h1>

        <p class="auth-status">Estás por activar el acceso de <strong>{{ email }}</strong>. Elige una contraseña para entrar.</p>

        <form class="auth-form" @submit.prevent="submit">
            <div class="auth-field">
                <label for="password" class="auth-label">Contraseña</label>
                <input
                    id="password"
                    type="password"
                    class="auth-input"
                    v-model="form.password"
                    required
                    autofocus
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
                Activar cuenta
            </button>
        </form>
    </AuthCardLayout>
</template>
