<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

// Único camino de acceso para un usuario 'cliente' -- un EPA lo invitó
// desde /usuarios (ver UsuariosController), este link (con el token) se le
// mandó a mano (Slack/WhatsApp/correo, sin envío automático todavía). Acá
// solo pone su contraseña -- nombre/correo ya vienen fijos, no se editan.
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
    <GuestLayout>
        <Head title="Aceptar invitación" />

        <p class="mb-4 text-sm text-gray-600">
            Hola {{ nombre }} -- estás por activar el acceso de <strong>{{ email }}</strong>. Elegí una contraseña para entrar.
        </p>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="password" value="Contraseña" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autofocus
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Confirmar contraseña" />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Activar cuenta
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
