<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import { fechaHora } from '@/fechas';
import { Head, router, usePage } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import AvatarUsuario from '@/Components/Perfil/AvatarUsuario.vue';
import LogoCuenta from '@/Components/Cuenta/LogoCuenta.vue';
import InvitarUsuarioModal from '@/Components/Usuarios/InvitarUsuarioModal.vue';
import EditarUsuarioModal from '@/Components/Usuarios/EditarUsuarioModal.vue';
import '../../../css/usuarios.css';

// Usuarios (diseño 2026-11-05). Quién entra al dashboard y qué cuentas y
// países ve. El backend (UsuariosController::index()) ya filtró la lista a
// "lo que puedo administrar + yo mismo" y marca `editable` por fila; los
// permisos más finos (suspender, aprobar, eliminar, otorgar director) los
// aplica EditarUsuarioModal y, de verdad, el servidor. Sin envío de correo:
// los links (invitación, contraseña nueva) se muestran para copiarlos.
const props = defineProps({
    pais: { type: String, required: true },
    usuarios: { type: Array, required: true },
    paises: { type: Array, required: true },
    paisesOtorgables: { type: Array, default: () => [] },
    cuentas: { type: Array, default: () => [] },
    roles: { type: Array, required: true },
});

const ROL_LABEL = { cliente: 'Solo lectura', junior: 'Junior', senior: 'Senior', gerente: 'Gerente', director: 'Director', superadmin: 'Superadmin' };
const miId = computed(() => usePage().props.auth?.user?.id);

// --- Presencia (2026-11-11) -----------------------------------------------------
// Círculo sobre el avatar: verde = usándolo (últimos 5 min), amarillo = sesión
// abierta sin uso reciente, rojo = cerró sesión o se le venció. Se refresca
// cada minuto (ver Presencia.php y presencia.js).
const presencia = ref(Object.fromEntries(props.usuarios.map((u) => [u.id, u.presencia])));
const ahora = ref(Date.now());
let refresco = null;
async function refrescarPresencia() {
    if (document.visibilityState !== 'visible') return;
    try {
        presencia.value = (await axios.get('/usuarios/presencia')).data;
        ahora.value = Date.now();
    } catch {
        // Sin red: se queda con lo último que se supo.
    }
}
onMounted(() => {
    refresco = setInterval(refrescarPresencia, 60 * 1000);
});
onBeforeUnmount(() => clearInterval(refresco));

const PRESENCIA_LABEL = { en_linea: 'En línea', inactivo: 'Inactivo', desconectado: 'Desconectado' };
function hace(iso) {
    if (!iso) return '';
    const min = Math.max(0, Math.round((ahora.value - new Date(iso).getTime()) / 60000));
    if (min < 1) return 'hace un momento';
    if (min < 60) return `hace ${min} min`;
    const horas = Math.round(min / 60);
    if (horas < 24) return `hace ${horas} h`;
    return `el ${fechaHora(iso)}`;
}
function presenciaDe(u) {
    const p = presencia.value[u.id] ?? { estado: 'desconectado', ultima_actividad: null };
    const cuando = p.estado === 'en_linea' ? '' : (p.ultima_actividad ? ` · última vez ${hace(p.ultima_actividad)}` : ' · sin actividad registrada');
    return { estado: p.estado, titulo: `${PRESENCIA_LABEL[p.estado]}${cuando}` };
}

// --- Filtros ------------------------------------------------------------------
const filtro = ref('todos');
const busqueda = ref('');
const conteos = computed(() => ({
    todos: props.usuarios.length,
    epa: props.usuarios.filter((u) => u.es_dominio_epa).length,
    externos: props.usuarios.filter((u) => !u.es_dominio_epa).length,
}));
const FILTROS = [
    { clave: 'todos', label: 'Todos' },
    { clave: 'epa', label: 'Equipo EPA' },
    { clave: 'externos', label: 'Externos' },
];
const visibles = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.usuarios
        .filter((u) => filtro.value === 'todos' || (filtro.value === 'epa') === u.es_dominio_epa)
        .filter((u) => !q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q));
});

function estado(u) {
    if (u.pendiente) return { label: 'Pendiente', clase: 'us-badge-amber', titulo: 'Esperando que un gerente, director o superadmin la apruebe' };
    if (!u.activo) return { label: 'Suspendido', clase: 'us-badge-coral', titulo: 'No puede entrar; no se borró nada' };
    if (u.invitado) return { label: 'Invitado', clase: 'us-badge-sky', titulo: 'Todavía no usa su link (invitación o contraseña nueva)' };
    return { label: 'Activo', clase: 'us-badge-mint', titulo: '' };
}
const codigos = (u) => (u.pendiente ? props.paises.filter((p) => u.pais_ids_propuestos?.includes(p.id)) : u.paises).map((p) => p.codigo).join(' · ');

// --- Modales y links --------------------------------------------------------------
const invitarAbierto = ref(false);
const editando = ref(null);
const infoAbierta = ref(false);
const link = ref(null); // { url, motivo }
const copiado = ref(false);

function recargar() {
    router.reload({ only: ['usuarios'] });
}
function alGuardar({ link: url, motivoLink } = {}) {
    recargar();
    if (url) {
        link.value = { url, motivo: motivoLink };
        copiado.value = false;
    }
}
async function copiarLink() {
    try {
        await navigator.clipboard.writeText(link.value.url);
        copiado.value = true;
    } catch { /* sin portapapeles: se copia a mano del campo */ }
}
</script>

<template>
    <Head title="Usuarios" />

    <DashboardLayout :pais="pais" vista-activa="usuarios">
        <div class="us">
            <div class="us-page">
                <div class="us-eyebrow">Administración</div>
                <header class="us-head">
                    <div>
                        <h1 class="us-title">Usuarios</h1>
                        <p class="us-subtitle">
                            Quién entra al dashboard y qué cuentas y países ve.
                            <button type="button" class="us-info-btn" :aria-expanded="infoAbierta" @click="infoAbierta = !infoAbierta">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>
                                Cómo funciona el acceso
                            </button>
                        </p>
                    </div>
                    <button type="button" class="us-btn" @click="invitarAbierto = true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4" /><path d="M2 21a7 7 0 0 1 14 0M19 8v6M16 11h6" /></svg>
                        Invitar usuario
                    </button>
                </header>

                <div v-if="infoAbierta" class="us-info">
                    <ul>
                        <li><strong>Equipo EPA</strong> (@epa.digital) entra con Google, sin invitación. Un director le asigna cuentas y países.</li>
                        <li><strong>Externos</strong> (clientes) se invitan aquí y son solo lectura: entran con contraseña + Google Authenticator o con su cuenta de Google.</li>
                        <li>Cada quien ve <strong>solo las cuentas y países que tiene asignados</strong>, incluido un director. Nadie puede dar una cuenta o un país que no tiene.</li>
                        <li>Junior y senior pueden invitar, pero la invitación queda <strong>pendiente</strong> hasta que un gerente, director o superadmin la apruebe.</li>
                        <li>Suspender es de director o superadmin. Eliminar y dar el rol de director o superadmin, solo superadmin.</li>
                    </ul>
                </div>

                <div class="us-filtros">
                    <div class="us-pills" role="tablist" aria-label="Filtrar usuarios">
                        <button
                            v-for="f in FILTROS"
                            :key="f.clave"
                            type="button"
                            role="tab"
                            class="us-pill"
                            :class="{ activo: filtro === f.clave }"
                            :aria-selected="filtro === f.clave"
                            @click="filtro = f.clave"
                        >{{ f.label }} · {{ conteos[f.clave] }}</button>
                    </div>
                    <label class="us-buscar">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input v-model="busqueda" type="text" placeholder="Buscar por nombre o correo" aria-label="Buscar por nombre o correo">
                    </label>
                </div>

                <div v-if="link" class="us-link" role="status">
                    <span>Link de {{ link.motivo || 'acceso' }} (mándalo tú por Slack o WhatsApp; vence en 72 h):</span>
                    <input :value="link.url" readonly @focus="$event.target.select()">
                    <button type="button" class="us-btn-sec us-btn-sm" @click="copiarLink">{{ copiado ? 'Copiado' : 'Copiar' }}</button>
                    <button type="button" class="us-cerrar" style="margin-left: 0" aria-label="Ocultar link" @click="link = null">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>

                <div class="us-tabla-box">
                    <table class="us-tabla">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Acceso</th>
                                <th>Rol</th>
                                <th>Cuentas</th>
                                <th>Países</th>
                                <th>Estado</th>
                                <th><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in visibles" :key="u.id" :class="{ inactivo: !u.activo && !u.pendiente }">
                                <td>
                                    <div class="us-persona">
                                        <span class="us-avatar-presencia">
                                            <AvatarUsuario :usuario="u" :tamano="40" />
                                            <span
                                                class="us-presencia"
                                                :class="`us-presencia--${presenciaDe(u).estado}`"
                                                role="img"
                                                :aria-label="presenciaDe(u).titulo"
                                                :title="presenciaDe(u).titulo"
                                            />
                                        </span>
                                        <div style="min-width: 0">
                                            <div class="us-persona-nombre">{{ u.name }}<span v-if="u.id === miId" class="us-tu">Tú</span></div>
                                            <div class="us-persona-correo">{{ u.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span v-if="u.metodo_auth === 'password'" class="us-badge us-badge-sky">Contraseña + 2FA</span>
                                    <span v-else class="us-badge us-badge-violet">Google</span>
                                </td>
                                <td>{{ ROL_LABEL[u.rol] || u.rol }}</td>
                                <td>
                                    <div class="us-logos">
                                        <LogoCuenta v-for="c in u.cuentas" :key="c.id" :cuenta="c" :tamano="28" :radio="7" :title="c.nombre" />
                                        <span v-if="!u.cuentas.length" class="us-codigos">—</span>
                                    </div>
                                </td>
                                <td><span class="us-codigos">{{ codigos(u) || '—' }}</span></td>
                                <td><span class="us-badge" :class="estado(u).clase" :title="estado(u).titulo">{{ estado(u).label }}</span></td>
                                <td style="text-align: right">
                                    <button type="button" class="us-btn-sec us-btn-sm" @click="editando = u">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z" /></svg>
                                        {{ u.editable ? 'Editar' : 'Ver' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="!visibles.length" class="us-vacio">
                        {{ busqueda ? `Nadie coincide con “${busqueda}”.` : 'No hay usuarios en este filtro.' }}
                    </p>
                </div>
            </div>
        </div>

        <InvitarUsuarioModal :show="invitarAbierto" :cuentas="cuentas" :paises="paises" :paises-otorgables="paisesOtorgables" @close="invitarAbierto = false" @invitado="recargar" />
        <EditarUsuarioModal :show="!!editando" :usuario="editando" :cuentas="cuentas" :paises="paises" :paises-otorgables="paisesOtorgables" :roles="roles" @close="editando = null" @guardado="alGuardar" />
    </DashboardLayout>
</template>
