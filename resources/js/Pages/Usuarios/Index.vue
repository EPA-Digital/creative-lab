<script setup>
import { ref, computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';

// Admin-only (->middleware('can:epa'), ver routes/web.php) -- único lugar
// donde se invita a un 'cliente' (acceso de solo lectura, sin cuenta
// @epa.digital). Sin envío de correo automático: store() devuelve el link
// de invitación, acá se muestra para copiar y mandar a mano (Slack/
// WhatsApp/correo). Mismo patrón axios+JSON que Ajustes/Index.vue.
//
// Cambiar rol/países de OTRO usuario es más estricto -- superadmin/
// director (Gate 'gestionar-usuarios', ver
// UsuariosController::actualizarRolYPaises y User::puedeGestionarUsuarios()).
// Otorgar 'director'/'superadmin' en sí es solo superadmin. Acá se refleja
// ocultando/deshabilitando esos controles -- la guardia real es el backend.
const props = defineProps({
    pais: { type: String, required: true },
    usuarios: { type: Array, required: true },
    paises: { type: Array, required: true },
    roles: { type: Array, required: true },
});

const usuarios = ref(props.usuarios.map((u) => ({ ...u, paisIdsEditando: u.paises.map((p) => p.id) })));
// superadmin/director -- ver User::puedeGestionarUsuarios() (mismo check
// del lado backend, esto es solo para mostrar/ocultar los controles).
const puedeGestionar = computed(() => ['superadmin', 'director'].includes(usePage().props.auth?.user?.rol));
const esSuperadmin = computed(() => usePage().props.auth?.user?.rol === 'superadmin');
const miId = computed(() => usePage().props.auth?.user?.id);
const ROLES_ALTOS = ['superadmin', 'director'];

const nombre = ref('');
const email = ref('');
const paisIdsInvitar = ref([]);
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
            pais_ids: paisIdsInvitar.value,
        });
        usuarios.value.unshift({ ...data.usuario, invitacion_token: 'pendiente', paisIdsEditando: data.usuario.paises.map((p) => p.id) });
        ultimoLink.value = data.linkInvitacion;
        nombre.value = '';
        email.value = '';
        paisIdsInvitar.value = [];
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

async function guardarRolYPaises(usuario) {
    usuario.guardando = true;
    usuario.errorFila = '';
    try {
        const { data } = await axios.patch(`/usuarios/${usuario.id}`, {
            rol: usuario.rol,
            pais_ids: usuario.paisIdsEditando,
        });
        usuario.paises = data.usuario.paises;
        usuario.rol = data.usuario.rol;
    } catch (e) {
        usuario.errorFila = e.response?.data?.message || 'No se pudo guardar.';
    } finally {
        usuario.guardando = false;
    }
}

const ROL_LABEL = {
    superadmin: 'Superadmin',
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
                ese dominio -- entran con correo/contraseña, solo lectura, nunca pueden modificar nada. Cada
                usuario solo ve los países que tenga asignados abajo -- incluido un director.
            </p>

            <form class="invitar-form" @submit.prevent="invitar">
                <input v-model="nombre" type="text" placeholder="Nombre" required />
                <input v-model="email" type="email" placeholder="correo@cliente.com" required />
                <span class="paises-label">Países que puede ver:</span>
                <div class="paises-chips">
                    <label v-for="p in paises" :key="p.id" class="chip" :class="{ activo: paisIdsInvitar.includes(p.id) }">
                        <input type="checkbox" :value="p.id" v-model="paisIdsInvitar" />
                        {{ p.nombre }}
                    </label>
                </div>
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
                        <th>Países</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in usuarios" :key="u.id">
                        <td>{{ u.name }}</td>
                        <td>{{ u.email }}</td>
                        <td>
                            <select
                                v-if="puedeGestionar"
                                v-model="u.rol"
                                :disabled="u.id === miId && ROLES_ALTOS.includes(u.rol)"
                            >
                                <option
                                    v-for="r in roles"
                                    :key="r"
                                    :value="r"
                                    :disabled="ROLES_ALTOS.includes(r) && !esSuperadmin"
                                >
                                    {{ ROL_LABEL[r] || r }}
                                </option>
                            </select>
                            <span v-else>{{ ROL_LABEL[u.rol] || u.rol }}</span>
                        </td>
                        <td>
                            <div v-if="puedeGestionar" class="paises-chips">
                                <label
                                    v-for="p in paises"
                                    :key="p.id"
                                    class="chip"
                                    :class="{ activo: u.paisIdsEditando.includes(p.id) }"
                                >
                                    <input type="checkbox" :value="p.id" v-model="u.paisIdsEditando" />
                                    {{ p.nombre }}
                                </label>
                            </div>
                            <span v-else>{{ u.paises.map((p) => p.nombre).join(', ') || '—' }}</span>
                        </td>
                        <td>
                            <span :class="['estado', u.activo ? 'activo' : 'inactivo']">
                                {{ u.activo ? 'Activo' : 'Desactivado' }}
                            </span>
                        </td>
                        <td class="acciones">
                            <button
                                v-if="puedeGestionar"
                                type="button"
                                class="btn-guardar"
                                :disabled="u.guardando"
                                @click="guardarRolYPaises(u)"
                            >
                                {{ u.guardando ? 'Guardando…' : 'Guardar' }}
                            </button>
                            <button v-if="u.activo && u.id !== miId" type="button" class="btn-desactivar" @click="desactivar(u)">
                                Desactivar
                            </button>
                            <p v-if="u.errorFila" class="error fila">{{ u.errorFila }}</p>
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
    max-width: 1100px;
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
    max-width: 680px;
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
.paises-label {
    display: flex;
    align-items: center;
    color: var(--text-muted);
    font-size: 0.8rem;
    white-space: nowrap;
}
.paises-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-muted);
    font-size: 0.75rem;
    cursor: pointer;
    user-select: none;
    transition: all 0.15s ease;
}
.chip input {
    /* El checkbox real sigue ahí (accesibilidad, teclado) pero invisible --
       el estado se ve en el estilo del chip completo (.activo), no en un
       checkbox nativo chiquito difícil de ver dentro de una tabla. */
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
}
.chip.activo {
    background: var(--amber);
    border-color: var(--amber);
    color: #1a1a1a;
    font-weight: 600;
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
.error.fila {
    margin: 4px 0 0;
    font-size: 0.7rem;
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
    vertical-align: top;
}
.tabla-usuarios select {
    padding: 4px 6px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    color: var(--text);
    font-size: 0.8rem;
}
.acciones {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-start;
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
.btn-desactivar,
.btn-guardar {
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: none;
    color: var(--text-muted);
    font-size: 0.75rem;
    cursor: pointer;
}
.btn-guardar {
    color: var(--text);
    border-color: var(--amber);
}
.btn-guardar:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>
