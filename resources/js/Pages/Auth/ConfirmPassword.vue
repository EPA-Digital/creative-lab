<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <AuthCardLayout>
        <Head title="Confirmar contraseña" />

        <h1 class="auth-title">Confirmá tu contraseña</h1>

        <p class="auth-status">Esta es un área protegida -- confirmá tu contraseña antes de seguir.</p>

        <form class="auth-form" @submit.prevent="submit">
            <div class="auth-field">
                <label for="password" class="auth-label">Contraseña</label>
                <input
                    id="password"
                    type="password"
                    class="auth-input"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <p v-if="form.errors.password" class="auth-input-error">{{ form.errors.password }}</p>
            </div>

            <button type="submit" class="auth-btn-secondary" :disabled="form.processing">
                Confirmar
            </button>
        </form>
    </AuthCardLayout>
</template>
