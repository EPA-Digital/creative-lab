<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// Enrolamiento TOTP obligatorio para metodo_auth=password (auth-prompt.md
// Fase 3) -- parte del mismo flujo de aceptar una invitación (ver
// InvitacionController::store()) o de recuperar acceso después de un
// reset de TOTP (ver UsuariosController::resetearTotp()). El QR lo genera
// el backend (TotpService) a partir de un secreto ya guardado -- acá solo
// se muestra y se confirma el primer código.
const props = defineProps({
    qr: { type: String, required: true },
    secreto: { type: String, required: true },
});

const form = useForm({ codigo: '' });

const submit = () => {
    form.post(route('2fa.enrolar'), {
        onFinish: () => form.reset('codigo'),
    });
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Configurar verificación en dos pasos" />

        <h1 class="auth-title">Configurar verificación en dos pasos</h1>

        <p class="auth-status">
            Escaneá este código con Google Authenticator, Authy o cualquier app TOTP, y confirmá el primer código para activar tu
            cuenta. Es obligatorio -- sin esto no vas a poder entrar.
        </p>

        <div class="auth-qr-wrap" v-html="qr" />

        <p class="auth-secret">¿No podés escanear? Ingresá este código a mano: <strong>{{ secreto }}</strong></p>

        <form class="auth-form" @submit.prevent="submit">
            <div class="auth-field">
                <label for="codigo" class="auth-label">Código de 6 dígitos</label>
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
                Confirmar y activar
            </button>
        </form>
    </AuthCardLayout>
</template>
