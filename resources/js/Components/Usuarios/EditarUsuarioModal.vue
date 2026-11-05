<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import AvatarUsuario from '@/Components/Perfil/AvatarUsuario.vue';
import LogoCuenta from '@/Components/Cuenta/LogoCuenta.vue';

// "Editar usuario" (diseño 2026-11-05). Cada control usa el endpoint que ya
// existía y respeta sus permisos (la guardia real vive en el servidor, aquí
// solo se deshabilita lo que daría 403):
// - Rol, cuentas y países: PATCH /usuarios/{id} -- jerarquía (Gate
//   'gestionar-usuarios'); director/superadmin solo los otorga superadmin.
// - Estado activo/suspendido: desactivar/reactivar -- director/superadmin.
// - Invitación pendiente: Aprobar -- gerente/director/superadmin.
// - 2FA y contraseña: restablecer / link nuevo / pasar de Google a
//   contraseña -- gerente/director/superadmin. De contraseña a Google no
//   hay camino todavía. Un @epa.digital entra siempre con Google.
// - Eliminar: solo superadmin, con confirmación (no se puede deshacer).
const props = defineProps({
    show: { type: Boolean, default: false },
    usuario: { type: Object, default: null },
    cuentas: { type: Array, required: true },
    paises: { type: Array, required: true },
    paisesOtorgables: { type: Array, required: true },
    roles: { type: Array, required: true },
});
const emit = defineEmits(['close', 'guardado', 'link']);

const ROL_LABEL = { cliente: 'Solo lectura', junior: 'Junior', senior: 'Senior', gerente: 'Gerente', director: 'Director', superadmin: 'Superadmin' };
const ORDEN_ROLES = ['cliente', 'junior', 'senior', 'gerente', 'director', 'superadmin'];
const ROLES_ALTOS = ['superadmin', 'director'];

const miRol = computed(() => usePage().props.auth?.user?.rol);
const miId = computed(() => usePage().props.auth?.user?.id);
const esSuperadmin = computed(() => miRol.value === 'superadmin');
const puedeDesactivar = computed(() => ['superadmin', 'director'].includes(miRol.value));
const puedeAprobar = computed(() => ['superadmin', 'gerente', 'director'].includes(miRol.value));

const u = computed(() => props.usuario);
const rolesOrdenados = computed(() => ORDEN_ROLES.filter((r) => props.roles.includes(r)));
const misCuentaIds = computed(() => props.cuentas.map((c) => c.id));
// Cuentas que tiene y quien edita no ve: se conservan al guardar
// (UsuariosController::sincronizarCuentas), solo se muestran.
const cuentasFuera = computed(() => (u.value?.cuentas ?? []).filter((c) => !misCuentaIds.value.includes(c.id)));

const form = reactive({ rol: 'cliente', activo: true, metodo: 'password', cuentaIds: [], paisIds: [] });
const guardando = ref(false);
const errores = ref({});
const confirmando = ref(null); // 'eliminar' | 'totp' | 'password'
const aviso = ref('');

watch(() => [props.show, props.usuario], ([abierto]) => {
    if (!abierto || !u.value) return;
    const pendiente = u.value.pendiente;
    Object.assign(form, {
        rol: u.value.rol,
        activo: u.value.activo,
        metodo: u.value.metodo_auth,
        cuentaIds: pendiente
            ? (u.value.cuenta_ids_propuestos ?? []).filter((id) => misCuentaIds.value.includes(id))
            : u.value.cuentas.map((c) => c.id).filter((id) => misCuentaIds.value.includes(id)),
        paisIds: pendiente ? [...(u.value.pais_ids_propuestos ?? [])] : u.value.paises.map((p) => p.id),
    });
    errores.value = {};
    confirmando.value = null;
    aviso.value = '';
    document.addEventListener('keydown', alTeclear);
}, { immediate: true });

function cerrar() {
    document.removeEventListener('keydown', alTeclear);
    emit('close');
}
function alTeclear(e) {
    if (e.key === 'Escape') cerrar();
}
onBeforeUnmount(() => document.removeEventListener('keydown', alTeclear));

const alternar = (lista, id) => {
    const i = lista.indexOf(id);
    i > -1 ? lista.splice(i, 1) : lista.push(id);
};
const errorDe = (e, porDefecto) => e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat()[0] || porDefecto;

// Opciones de "cómo entra" según el método actual y los permisos.
const passwordDeshabilitado = computed(() => u.value?.es_dominio_epa || (u.value?.metodo_auth === 'google' && !puedeAprobar.value));
const passwordTitulo = computed(() => (u.value?.es_dominio_epa ? 'Una cuenta @epa.digital entra siempre con Google' : (passwordDeshabilitado.value ? 'Solo gerente, director o superadmin pueden cambiarlo' : '')));
const googleDeshabilitado = computed(() => u.value?.metodo_auth === 'password');

async function guardar() {
    if (!u.value?.editable) return cerrar();
    guardando.value = true;
    errores.value = {};
    let link = null;
    try {
        const ids = { pais_ids: form.paisIds.filter((id) => props.paisesOtorgables.includes(id)), cuenta_ids: form.cuentaIds };
        if (u.value.pendiente) {
            if (puedeAprobar.value) await axios.post(`/usuarios/${u.value.id}/aprobar`, ids);
        } else {
            await axios.patch(`/usuarios/${u.value.id}`, { rol: form.rol, ...ids });
            if (puedeDesactivar.value && form.activo !== u.value.activo) {
                form.activo ? await axios.post(`/usuarios/${u.value.id}/reactivar`) : await axios.delete(`/usuarios/${u.value.id}`);
            }
            if (form.metodo === 'password' && u.value.metodo_auth === 'google') {
                link = (await axios.post(`/usuarios/${u.value.id}/activar-password`)).data.linkInvitacion;
            }
        }
        emit('guardado', { link, motivoLink: 'contraseña' });
        cerrar();
    } catch (e) {
        errores.value = { general: errorDe(e, 'No se pudieron guardar los cambios.') };
    } finally {
        guardando.value = false;
    }
}

async function restablecerTotp() {
    try {
        await axios.post(`/usuarios/${u.value.id}/resetear-totp`);
        confirmando.value = null;
        aviso.value = 'Listo: en su próximo inicio de sesión escaneará un código nuevo.';
        emit('guardado', { link: null, cerrar: false });
    } catch (e) {
        errores.value = { general: errorDe(e, 'No se pudo restablecer el 2FA.') };
    }
}

async function nuevaContrasena() {
    try {
        const { data } = await axios.post(`/usuarios/${u.value.id}/resetear-password`);
        emit('guardado', { link: data.linkInvitacion, motivoLink: 'contraseña nueva' });
        cerrar();
    } catch (e) {
        errores.value = { general: errorDe(e, 'No se pudo generar la contraseña nueva.') };
    }
}

async function eliminar() {
    try {
        await axios.delete(`/usuarios/${u.value.id}/eliminar`);
        emit('guardado', { link: null });
        cerrar();
    } catch (e) {
        errores.value = { general: errorDe(e, 'No se pudo eliminar.') };
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show && u" class="us-overlay" @mousedown.self="cerrar">
            <form class="us-modal" role="dialog" aria-modal="true" aria-labelledby="ed-titulo" @submit.prevent="guardar">
                <header class="us-modal-head">
                    <AvatarUsuario :usuario="u" :tamano="56" />
                    <div style="min-width: 0">
                        <h2 id="ed-titulo">Editar a {{ u.name }}</h2>
                        <p class="mono" style="font-family: 'JetBrains Mono', monospace">{{ u.email }}</p>
                    </div>
                    <button type="button" class="us-cerrar" aria-label="Cerrar" @click="cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </header>

                <p v-if="!u.editable" class="us-panel" style="margin: 0; font-size: 13px">
                    {{ u.id === miId
                        ? 'Este eres tú. Tu nombre, foto y puesto se editan desde el menú de abajo del nav; tu rol y accesos los cambia alguien de un nivel más alto.'
                        : 'No puedes editar a alguien de tu mismo nivel o más alto.' }}
                </p>

                <!-- Rol a lo ancho: son 6 opciones. -->
                <div class="us-campo">
                        <span id="ed-rol" class="us-label">Rol</span>
                        <div class="us-seg" role="radiogroup" aria-labelledby="ed-rol">
                            <button
                                v-for="r in rolesOrdenados"
                                :key="r"
                                type="button"
                                role="radio"
                                :class="{ activo: form.rol === r }"
                                :aria-checked="form.rol === r"
                                :disabled="!u.editable || u.pendiente || (ROLES_ALTOS.includes(r) && !esSuperadmin)"
                                :title="ROLES_ALTOS.includes(r) && !esSuperadmin ? 'Solo un superadmin otorga este rol' : ''"
                                @click="form.rol = r"
                            >{{ ROL_LABEL[r] || r }}</button>
                        </div>
                </div>
                <div class="us-campo" style="max-width: 320px">
                        <span id="ed-estado" class="us-label">Estado</span>
                        <div v-if="u.pendiente" class="us-seg"><button type="button" class="activo" disabled style="opacity: 1; background: color-mix(in srgb, var(--yellow) 30%, transparent); color: var(--yellow)">Pendiente de aprobación</button></div>
                        <div v-else class="us-seg" role="radiogroup" aria-labelledby="ed-estado">
                            <button type="button" role="radio" :class="{ activo: form.activo }" :aria-checked="form.activo" :disabled="!u.editable || !puedeDesactivar" @click="form.activo = true">Activo</button>
                            <button type="button" role="radio" :class="{ activo: !form.activo }" :aria-checked="!form.activo" :disabled="!u.editable || !puedeDesactivar" :title="puedeDesactivar ? '' : 'Solo director o superadmin'" @click="form.activo = false">Suspendido</button>
                        </div>
                        <span v-if="!form.activo && u.activo" class="us-hint">Al guardar se cierran sus sesiones. No se borra nada de lo que hizo.</span>
                </div>

                <div class="us-campo">
                    <span id="ed-metodo" class="us-label">Cómo entra</span>
                    <div class="us-opciones" role="radiogroup" aria-labelledby="ed-metodo">
                        <button type="button" role="radio" class="us-opcion" :class="{ activa: form.metodo === 'password' }" :aria-checked="form.metodo === 'password'" :disabled="!u.editable || passwordDeshabilitado" :title="passwordTitulo" @click="form.metodo = 'password'">
                            <strong>Contraseña + 2FA</strong>
                            <span>Con Google Authenticator</span>
                        </button>
                        <button type="button" role="radio" class="us-opcion" :class="{ activa: form.metodo === 'google' }" :aria-checked="form.metodo === 'google'" :disabled="!u.editable || googleDeshabilitado" :title="googleDeshabilitado ? 'De contraseña a Google todavía no se puede cambiar desde aquí' : ''" @click="form.metodo = 'google'">
                            <strong>Cuenta de Google</strong>
                            <span>Su propio correo de Google</span>
                        </button>
                    </div>
                    <span v-if="form.metodo === 'password' && u.metodo_auth === 'google'" class="us-hint">Al guardar se genera un link para que ponga su contraseña y configure Google Authenticator. Deja de poder entrar con Google.</span>

                    <div v-if="u.metodo_auth === 'password'" class="us-panel">
                        <span class="us-panel-titulo">
                            <span class="us-punto" :style="{ background: u.tiene_2fa ? 'var(--mint)' : 'var(--yellow)' }" />
                            {{ u.tiene_2fa ? 'Google Authenticator configurado' : 'Google Authenticator sin configurar' }}
                        </span>
                        <span class="us-hint">{{ u.tiene_2fa ? 'Si lo restableces, escaneará un código nuevo al entrar.' : 'Lo configurará la primera vez que entre con su contraseña.' }}</span>
                        <div v-if="confirmando === 'totp'" class="us-confirmar">
                            ¿Restablecer su 2FA?
                            <button type="button" class="us-btn-sec us-btn-sm" @click="restablecerTotp">Sí, restablecer</button>
                            <button type="button" class="us-btn-sec us-btn-sm" @click="confirmando = null">No</button>
                        </div>
                        <div v-else-if="confirmando === 'password'" class="us-confirmar">
                            ¿Generar contraseña nueva? Se cierran sus sesiones hasta que use el link.
                            <button type="button" class="us-btn-sec us-btn-sm" @click="nuevaContrasena">Sí, generar link</button>
                            <button type="button" class="us-btn-sec us-btn-sm" @click="confirmando = null">No</button>
                        </div>
                        <div v-else class="us-panel-acciones">
                            <button type="button" class="us-btn-sec us-btn-sm" :disabled="!u.editable || !puedeAprobar || !u.tiene_2fa" :title="puedeAprobar ? '' : 'Solo gerente, director o superadmin'" @click="confirmando = 'totp'">Restablecer 2FA</button>
                            <button type="button" class="us-btn-sec us-btn-sm" :disabled="!u.editable || !puedeAprobar" :title="puedeAprobar ? '' : 'Solo gerente, director o superadmin'" @click="confirmando = 'password'">Nueva contraseña</button>
                        </div>
                        <span v-if="aviso" class="us-hint" style="color: var(--mint)">{{ aviso }}</span>
                    </div>
                </div>

                <div class="us-campo">
                    <span class="us-label">{{ u.pendiente ? 'Cuentas propuestas' : 'Cuentas que puede ver' }}</span>
                    <div class="us-chips">
                        <button v-for="c in cuentas" :key="c.id" type="button" class="us-chip con-logo" :class="{ activo: form.cuentaIds.includes(c.id) }" :aria-pressed="form.cuentaIds.includes(c.id)" :disabled="!u.editable" @click="alternar(form.cuentaIds, c.id)">
                            <LogoCuenta :cuenta="c" :tamano="24" :radio="6" />{{ c.nombre }}
                        </button>
                        <span v-for="c in cuentasFuera" :key="`f${c.id}`" class="us-chip con-logo fijo" title="Tiene esta cuenta; tú no puedes cambiarla">
                            <LogoCuenta :cuenta="c" :tamano="24" :radio="6" />{{ c.nombre }}
                        </span>
                    </div>
                </div>

                <div class="us-campo">
                    <span class="us-label">{{ u.pendiente ? 'Países propuestos' : 'Países que puede ver' }}</span>
                    <div class="us-chips">
                        <button
                            v-for="p in paises"
                            :key="p.id"
                            type="button"
                            class="us-chip"
                            :class="{ activo: form.paisIds.includes(p.id) }"
                            :aria-pressed="form.paisIds.includes(p.id)"
                            :disabled="!u.editable || !paisesOtorgables.includes(p.id)"
                            :title="paisesOtorgables.includes(p.id) ? '' : 'No puedes dar acceso a un país que tú no tienes'"
                            @click="alternar(form.paisIds, p.id)"
                        >{{ p.nombre }}</button>
                    </div>
                </div>

                <p v-if="errores.general" class="us-error">{{ errores.general }}</p>

                <footer class="us-modal-pie">
                    <template v-if="esSuperadmin && u.editable">
                        <span v-if="confirmando === 'eliminar'" class="us-confirmar">
                            No se puede deshacer.
                            <button type="button" class="us-btn-peligro" @click="eliminar">Eliminar definitivamente</button>
                            <button type="button" class="us-btn-sec us-btn-sm" @click="confirmando = null">No</button>
                        </span>
                        <button v-else type="button" class="us-btn-peligro" @click="confirmando = 'eliminar'">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 13a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1l1-13M9 7V4h6v3" /></svg>
                            Eliminar usuario
                        </button>
                    </template>
                    <span class="us-espacio" />
                    <button type="button" class="us-btn-sec" @click="cerrar">Cancelar</button>
                    <button v-if="u.editable && (!u.pendiente || puedeAprobar)" type="submit" class="us-btn" :disabled="guardando">
                        {{ guardando ? 'Guardando…' : (u.pendiente ? 'Aprobar' : 'Guardar cambios') }}
                    </button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
