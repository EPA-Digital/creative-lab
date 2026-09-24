<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
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
    <GuestLayout>
        <Head title="Verificación en dos pasos" />

        <p class="mb-4 text-sm text-gray-600">
            Ingresá el código de tu app de autenticación, o un código de recuperación si perdiste acceso a ella.
        </p>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="codigo" value="Código" />

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
                    Verificar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
