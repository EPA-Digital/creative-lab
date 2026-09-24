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
// Jerarquía completa (2026-09-23) -- el backend (UsuariosController::
// index()) YA filtró la lista a "lo que puedo administrar + yo mismo", así
// que si una fila aparece acá (y no es la mía) la puedo editar. Lo que
// sigue siendo más estricto y necesita su propio check en el frontend:
// desactivar (solo director/superadmin), aprobar invitación pendiente
// (gerente/director/superadmin) y otorgar director/superadmin (solo
// superadmin) -- la guardia real de los tres vive en el backend, esto es
// solo para no mostrar un botón que va a dar 403.
const props = defineProps({
    pais: { type: String, required: true },
    usuarios: { type: Array, required: true },
    paises: { type: Array, required: true },
    roles: { type: Array, required: true },
});

const usuarios = ref(props.usuarios.map((u) => ({
    ...u,
    paisIdsEditando: u.paises.map((p) => p.id),
    paisIdsAprobando: u.pais_ids_propuestos ?? [],
})));
const miRol = computed(() => usePage().props.auth?.user?.rol);
const puedeDesactivar = computed(() => ['superadmin', 'director'].includes(miRol.value));
const puedeAprobar = computed(() => ['superadmin', 'gerente', 'director'].includes(miRol.value));
const esSuperadmin = computed(() => miRol.value === 'superadmin');
const miId = computed(() => usePage().props.auth?.user?.id);
const ROLES_ALTOS = ['superadmin', 'director'];

function esPendiente(u) {
    return ! u.activo && u.pais_ids_propuestos !== null;
}

const nombre = ref('');
const email = ref('');
const paisIdsInvitar = ref([]);
const invitando = ref(false);
const error = ref('');
const ultimoLink = ref('');
const avisoPendiente = ref(false);

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
        usuarios.value.unshift({
            ...data.usuario,
            invitacion_token: 'pendiente',
            paisIdsEditando: data.usuario.paises.map((p) => p.id),
            paisIdsAprobando: data.usuario.pais_ids_propuestos ?? [],
        });
        ultimoLink.value = data.linkInvitacion;
        avisoPendiente.value = data.pendiente;
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

// "Olvidé mi contraseña" sin SMTP real (pedido explícito 2026-09-24) --
// mismo Gate que aprobar invitaciones (gerente/director/superadmin).
// Genera un link nuevo (mismo mecanismo que invitar) para mandar a mano;
// el TOTP que el usuario ya tenía no se toca.
async function resetearPassword(usuario) {
    if (!confirm(`¿Resetear la contraseña de ${usuario.name}? Va a necesitar el link nuevo para volver a entrar -- se lo mandás vos por otro canal (Slack/WhatsApp).`)) return;
    usuario.guardando = true;
    usuario.errorFila = '';
    try {
        const { data } = await axios.post(`/usuarios/${usuario.id}/resetear-password`);
        ultimoLink.value = data.linkInvitacion;
        avisoPendiente.value = false;
    } catch (e) {
        usuario.errorFila = e.response?.data?.message || 'No se pudo resetear la contraseña.';
    } finally {
        usuario.guardando = false;
    }
}

// "TOTP perdido" -- mismo Gate. El usuario re-enrola en su próximo login
// por contraseña (ver AuthenticatedSessionController::store()).
async function resetearTotp(usuario) {
    if (!confirm(`¿Resetear el TOTP de ${usuario.name}? Va a tener que volver a escanear un QR nuevo en su próximo login.`)) return;
    usuario.guardando = true;
    usuario.errorFila = '';
    try {
        await axios.post(`/usuarios/${usuario.id}/resetear-totp`);
    } catch (e) {
        usuario.errorFila = e.response?.data?.message || 'No se pudo resetear el TOTP.';
    } finally {
        usuario.guardando = false;
    }
}

// Inverso de desactivar() -- mismo permiso (director/superadmin).
async function reactivar(usuario) {
    usuario.guardando = true;
    usuario.errorFila = '';
    try {
        await axios.post(`/usuarios/${usuario.id}/reactivar`);
        usuario.activo = true;
    } catch (e) {
        usuario.errorFila = e.response?.data?.message || 'No se pudo reactivar.';
    } finally {
        usuario.guardando = false;
    }
}

// Solo superadmin (Gate 'eliminar-usuarios') -- borra la fila de verdad,
// a diferencia de desactivar() que es reversible. Doble confirmación a
// propósito, es irreversible.
async function eliminar(usuario) {
    if (!confirm(`¿Eliminar el registro de ${usuario.name} (${usuario.email})? Esto NO es reversible -- se borra la cuenta de verdad, no solo se desactiva.`)) return;
    if (!confirm('Confirmá de nuevo: esta acción no se puede deshacer.')) return;
    try {
        await axios.delete(`/usuarios/${usuario.id}/eliminar`);
        usuarios.value = usuarios.value.filter((u) => u.id !== usuario.id);
    } catch (e) {
        alert(e.response?.data?.message || 'No se pudo eliminar.');
    }
}

async function aprobar(usuario) {
    usuario.guardando = true;
    usuario.errorFila = '';
    try {
        const { data } = await axios.post(`/usuarios/${usuario.id}/aprobar`, {
            pais_ids: usuario.paisIdsAprobando,
        });
        usuario.paises = data.usuario.paises;
        usuario.paisIdsEditando = data.usuario.paises.map((p) => p.id);
        usuario.activo = data.usuario.activo;
        usuario.pais_ids_propuestos = null;
    } catch (e) {
        usuario.errorFila = e.response?.data?.message || 'No se pudo aprobar.';
    } finally {
        usuario.guardando = false;
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
                <span>Link (copiá y mandalo vos -- sirve para invitar o para resetear contraseña):</span>
                <input :value="ultimoLink" readonly @focus="$event.target.select()" />
                <button type="button" @click="copiarLink">Copiar</button>
            </div>
            <p v-if="avisoPendiente" class="aviso-pendiente">
                Queda pendiente de aprobación -- no va a poder entrar hasta que gerente, director o superadmin
                confirme los países.
            </p>

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
                                v-if="u.id !== miId && !esPendiente(u)"
                                v-model="u.rol"
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
                            <!-- Pendiente de aprobación: países PROPUESTOS por quien invitó -- editables
                                 solo para quien puede aprobar (gerente/director/superadmin), de lo
                                 contrario solo lectura (ver User::puedeAprobarInvitaciones()). -->
                            <div v-if="esPendiente(u) && puedeAprobar && u.id !== miId" class="paises-chips">
                                <label
                                    v-for="p in paises"
                                    :key="p.id"
                                    class="chip"
                                    :class="{ activo: u.paisIdsAprobando.includes(p.id) }"
                                >
                                    <input type="checkbox" :value="p.id" v-model="u.paisIdsAprobando" />
                                    {{ p.nombre }}
                                </label>
                            </div>
                            <span v-else-if="esPendiente(u)" class="propuesto">
                                Propuesto: {{ paises.filter((p) => u.pais_ids_propuestos?.includes(p.id)).map((p) => p.nombre).join(', ') || '—' }}
                            </span>
                            <div v-else-if="u.id !== miId" class="paises-chips">
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
                            <span v-if="esPendiente(u)" class="estado pendiente">Pendiente de aprobación</span>
                            <span v-else :class="['estado', u.activo ? 'activo' : 'inactivo']">
                                {{ u.activo ? 'Activo' : 'Desactivado' }}
                            </span>
                        </td>
                        <td class="acciones">
                            <div class="acciones-botones">
                                <button
                                    v-if="esPendiente(u) && puedeAprobar && u.id !== miId"
                                    type="button"
                                    class="btn-guardar"
                                    :disabled="u.guardando"
                                    @click="aprobar(u)"
                                >
                                    {{ u.guardando ? 'Aprobando…' : 'Aprobar' }}
                                </button>
                                <button
                                    v-else-if="!esPendiente(u) && u.id !== miId"
                                    type="button"
                                    class="btn-guardar"
                                    :disabled="u.guardando"
                                    @click="guardarRolYPaises(u)"
                                >
                                    {{ u.guardando ? 'Guardando…' : 'Guardar' }}
                                </button>
                                <button
                                    v-if="u.activo && u.id !== miId && puedeDesactivar"
                                    type="button"
                                    class="btn-desactivar"
                                    @click="desactivar(u)"
                                >
                                    Desactivar
                                </button>
                                <button
                                    v-if="!u.activo && !esPendiente(u) && u.id !== miId && puedeDesactivar"
                                    type="button"
                                    class="btn-guardar"
                                    :disabled="u.guardando"
                                    @click="reactivar(u)"
                                >
                                    {{ u.guardando ? 'Reactivando…' : 'Reactivar' }}
                                </button>
                                <button
                                    v-if="u.metodo_auth === 'password' && u.activo && !esPendiente(u) && u.id !== miId && puedeAprobar"
                                    type="button"
                                    class="btn-guardar"
                                    :disabled="u.guardando"
                                    @click="resetearPassword(u)"
                                >
                                    Resetear contraseña
                                </button>
                                <button
                                    v-if="u.metodo_auth === 'password' && u.activo && !esPendiente(u) && u.id !== miId && puedeAprobar"
                                    type="button"
                                    class="btn-guardar"
                                    :disabled="u.guardando"
                                    @click="resetearTotp(u)"
                                >
                                    Resetear TOTP
                                </button>
                                <button
                                    v-if="u.id !== miId && esSuperadmin"
                                    type="button"
                                    class="btn-eliminar"
                                    @click="eliminar(u)"
                                >
                                    Eliminar
                                </button>
                            </div>
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
    /* Ancha a propósito (pedido explícito 2026-09-24) -- con los 4
       botones de acciones (Guardar/Desactivar-Reactivar/Eliminar) 1100px
       los hacía saltar a una segunda línea, y las filas quedaban de
       distinta altura (se veía mal el separador horizontal entre filas). */
    max-width: 1600px;
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
.acciones-botones {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    gap: 6px;
    align-items: center;
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
.estado.pendiente {
    background: rgba(255, 159, 10, 0.15);
    color: var(--amber);
}
.aviso-pendiente {
    color: var(--amber);
    font-size: 0.8rem;
    margin: 0 0 16px;
    max-width: 640px;
}
.propuesto {
    color: var(--text-muted);
    font-size: 0.75rem;
    font-style: italic;
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
.btn-eliminar {
    padding: 4px 10px;
    border: 1px solid var(--coral, #ff453a);
    border-radius: 6px;
    background: none;
    color: var(--coral, #ff453a);
    font-size: 0.75rem;
    cursor: pointer;
}
</style>
