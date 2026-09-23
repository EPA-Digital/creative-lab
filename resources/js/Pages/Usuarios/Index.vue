<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';

// Admin-only (->middleware('can:epa'), ver routes/web.php) -- único lugar
// donde se invita a un 'cliente' (acceso de solo lectura, sin cuenta
// @epa.digital). Sin envío de correo automático: store() devuelve el link
// de invitación, acá se muestra para copiar y mandar a mano (Slack/
// WhatsApp/correo). Mismo patrón axios+JSON que Ajustes/Index.vue.
const props = defineProps({
    pais: { type: String, required: true },
    usuarios: { type: Array, required: true },
});

const usuarios = ref(props.usuarios);

const nombre = ref('');
const email = ref('');
const invitando = ref(false);
const error = ref('');
const ultimoLink = ref('');

async function invitar() {
    if (!nombre.value.trim() || !email.value.trim() || invitando.value) return;
    invitando.value = true;
    error.value = '';
    ultimoLink.value = '';

    try {
        const { data } = await axios.post('/usuarios', {
            name: nombre.value.trim(),
            email: email.value.trim(),
        });
        usuarios.value.unshift({ ...data.usuario, invitacion_token: 'pendiente' });
        ultimoLink.value = data.linkInvitacion;
        nombre.value = '';
        email.value = '';
    } catch (e) {
        error.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(' ') || 'No se pudo invitar.';
    } finally {
        invitando.value = false;
    }
}

async function copiarLink() {
    try {
        await navigator.clipboard.writeText(ultimoLink.value);
    } catch { /* clipboard no disponible, el usuario copia a mano del input */ }
}

async function desactivar(usuario) {
    if (!confirm(`¿Desactivar a ${usuario.name}? Deja de poder entrar, pero no se borra nada de lo que hizo.`)) return;
    try {
        await axios.delete(`/usuarios/${usuario.id}`);
        usuario.activo = false;
    } catch (e) {
        alert(e.response?.data?.message || 'No se pudo desactivar.');
    }
}

const ROL_LABEL = {
    director: 'Director',
    gerente: 'Gerente',
    senior: 'Senior',
    junior: 'Junior',
    cliente: 'Cliente (solo lectura)',
};
</script>

<template>
    <Head title="Usuarios" />

    <DashboardLayout :pais="pais">
        <div class="usuarios-page">
            <h1>Usuarios</h1>
            <p class="hint">
                Cuentas @epa.digital entran solo con Google (acceso completo, sin invitación). Acá invitás gente sin
                ese dominio -- entran con correo/contraseña, solo lectura, nunca pueden modificar nada.
            </p>

            <form class="invitar-form" @submit.prevent="invitar">
                <input v-model="nombre" type="text" placeholder="Nombre" required />
                <input v-model="email" type="email" placeholder="correo@cliente.com" required />
                <button type="submit" :disabled="invitando">{{ invitando ? 'Invitando…' : 'Invitar' }}</button>
            </form>
            <p v-if="error" class="error">{{ error }}</p>
            <div v-if="ultimoLink" class="link-invitacion">
                <span>Link de invitación (copiá y mandalo vos):</span>
                <input :value="ultimoLink" readonly @focus="$event.target.select()" />
                <button type="button" @click="copiarLink">Copiar</button>
            </div>

            <table class="tabla-usuarios">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in usuarios" :key="u.id">
                        <td>{{ u.name }}</td>
                        <td>{{ u.email }}</td>
                        <td>{{ ROL_LABEL[u.rol] || u.rol }}</td>
                        <td>
                            <span :class="['estado', u.activo ? 'activo' : 'inactivo']">
                                {{ u.activo ? 'Activo' : 'Desactivado' }}
                            </span>
                        </td>
                        <td>
                            <button v-if="u.activo" type="button" class="btn-desactivar" @click="desactivar(u)">
                                Desactivar
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </DashboardLayout>
</template>

<style scoped>
.usuarios-page {
    padding: 32px 40px;
    max-width: 900px;
}
h1 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.5rem;
    color: var(--text);
    margin: 0 0 8px;
}
.hint {
    color: var(--text-muted);
    font-size: 0.85rem;
    margin: 0 0 24px;
    max-width: 640px;
}
.invitar-form {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}
.invitar-form input {
    flex: 1;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    color: var(--text);
}
.invitar-form button {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    background: var(--amber);
    color: #1a1a1a;
    font-weight: 600;
    cursor: pointer;
}
.invitar-form button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.error {
    color: var(--coral);
    font-size: 0.85rem;
    margin: 0 0 16px;
}
.link-invitacion {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 24px;
    font-size: 0.85rem;
    color: var(--text-muted);
}
.link-invitacion input {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    color: var(--text);
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
}
.link-invitacion button {
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: none;
    color: var(--text);
    cursor: pointer;
}
.tabla-usuarios {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.tabla-usuarios th {
    text-align: left;
    color: var(--text-muted);
    font-weight: 600;
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
}
.tabla-usuarios td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
}
.estado {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
}
.estado.activo {
    background: rgba(52, 199, 89, 0.15);
    color: var(--mint, #34c759);
}
.estado.inactivo {
    background: rgba(255, 69, 58, 0.15);
    color: var(--coral, #ff453a);
}
.btn-desactivar {
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: none;
    color: var(--text-muted);
    font-size: 0.75rem;
    cursor: pointer;
}
</style>
