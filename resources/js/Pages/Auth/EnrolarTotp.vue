<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
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
    <GuestLayout>
        <Head title="Configurar verificación en dos pasos" />

        <p class="mb-4 text-sm text-gray-600">
            Escaneá este código con Google Authenticator, Authy o cualquier app TOTP, y confirmá el primer código para activar tu
            cuenta. Es obligatorio -- sin esto no vas a poder entrar.
        </p>

        <div class="mb-4 flex justify-center" v-html="qr" />

        <p class="mb-4 break-all text-center text-xs text-gray-500">
            ¿No podés escanear? Ingresá este código a mano: <strong>{{ secreto }}</strong>
        </p>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="codigo" value="Código de 6 dígitos" />

                <TextInput
                    id="codigo"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="mt-1 block w-full"
                    v-model="form.codigo"
                    required
                    autofocus
                />

                <InputError class="mt-2" :message="form.errors.codigo" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Confirmar y activar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
