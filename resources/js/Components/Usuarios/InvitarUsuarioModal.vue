<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import LogoCuenta from '@/Components/Cuenta/LogoCuenta.vue';

// "Invitar usuario" (diseño 2026-11-05): gente fuera de @epa.digital, solo
// lectura. No se manda correo (MAIL_MAILER=log): al crear la invitación se
// muestra el link para copiarlo y mandarlo a mano. Junior/senior dejan la
// invitación pendiente de aprobación (ver UsuariosController::store()).
const props = defineProps({
    show: { type: Boolean, default: false },
    cuentas: { type: Array, required: true },
    paises: { type: Array, required: true },
    paisesOtorgables: { type: Array, required: true },
});
const emit = defineEmits(['close', 'invitado']);

const DOMINIO_EPA = '@epa.digital';
const form = reactive({ name: '', email: '', metodo: 'password', motivo: '', cuentaIds: [], paisIds: [] });
const errores = ref({});
const enviando = ref(false);
const resultado = ref(null); // { link, pendiente }
const copiado = ref(false);
const campoNombre = ref(null);

const esCorreoEpa = computed(() => form.email.trim().toLowerCase().endsWith(DOMINIO_EPA));

watch(() => props.show, async (abierto) => {
    if (!abierto) return;
    // Por default, la cuenta en la que está quien invita.
    const actual = props.cuentas.find((c) => c.slug === usePage().props.cuentaActual?.slug);
    Object.assign(form, { name: '', email: '', metodo: 'password', motivo: '', cuentaIds: actual ? [actual.id] : [], paisIds: [] });
    errores.value = {};
    resultado.value = null;
    copiado.value = false;
    document.addEventListener('keydown', alTeclear);
    await nextTick();
    campoNombre.value?.focus();
});

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

async function enviar() {
    errores.value = {};
    if (!form.name.trim()) errores.value.name = 'Escribe el nombre.';
    if (!form.email.trim()) errores.value.email = 'Escribe el correo.';
    if (form.metodo === 'password' && !form.motivo.trim()) errores.value.motivo = 'Con contraseña, escribe por qué no entra con Google (queda registrado).';
    if (Object.keys(errores.value).length) return;

    enviando.value = true;
    try {
        const { data } = await axios.post('/usuarios', {
            name: form.name.trim(),
            email: form.email.trim(),
            metodo: form.metodo,
            motivo: form.metodo === 'password' ? form.motivo.trim() : null,
            cuenta_ids: form.cuentaIds,
            pais_ids: form.paisIds,
        });
        resultado.value = { link: data.linkInvitacion, pendiente: data.pendiente };
        emit('invitado');
    } catch (e) {
        const errs = e.response?.data?.errors;
        errores.value = errs
            ? Object.fromEntries(Object.entries(errs).map(([k, v]) => [k.split('.')[0], v[0]]))
            : { general: e.response?.data?.message || 'No se pudo crear la invitación.' };
    } finally {
        enviando.value = false;
    }
}

async function copiar() {
    try {
        await navigator.clipboard.writeText(resultado.value.link);
        copiado.value = true;
    } catch { /* sin portapapeles: se copia a mano del campo */ }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="us-overlay" @mousedown.self="cerrar">
            <form class="us-modal" role="dialog" aria-modal="true" aria-labelledby="inv-titulo" @submit.prevent="enviar">
                <header class="us-modal-head">
                    <div>
                        <h2 id="inv-titulo">{{ resultado ? 'Invitación creada' : 'Invitar usuario' }}</h2>
                        <p>{{ resultado ? 'Mándale este link por Slack, WhatsApp o correo. Vence en 72 horas.' : 'Para gente fuera de @epa.digital. Tendrá acceso de solo lectura.' }}</p>
                    </div>
                    <button type="button" class="us-cerrar" aria-label="Cerrar" @click="cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </header>

                <template v-if="resultado">
                    <div class="us-campo">
                        <span class="us-label">Link de invitación</span>
                        <div style="display: flex; gap: 8px">
                            <input class="us-input" style="flex: 1; font-family: 'JetBrains Mono', monospace; font-size: 12.5px" :value="resultado.link" readonly @focus="$event.target.select()">
                            <button type="button" class="us-btn-sec" @click="copiar">{{ copiado ? 'Copiado' : 'Copiar' }}</button>
                        </div>
                    </div>
                    <p v-if="resultado.pendiente" class="us-panel" style="margin: 0; font-size: 13px">
                        Queda pendiente de aprobación: no podrá entrar hasta que un gerente, director o superadmin confirme sus cuentas y países.
                    </p>
                    <footer class="us-modal-pie">
                        <span class="us-espacio" />
                        <button type="button" class="us-btn" @click="cerrar">Listo</button>
                    </footer>
                </template>

                <template v-else>
                    <div class="us-grid2">
                        <label class="us-campo">
                            <span class="us-label">Nombre</span>
                            <input ref="campoNombre" v-model="form.name" class="us-input" :class="{ err: errores.name }" type="text" maxlength="255" placeholder="Nombre completo" autocomplete="off">
                            <span v-if="errores.name" class="us-error">{{ errores.name }}</span>
                        </label>
                        <label class="us-campo">
                            <span class="us-label">Correo</span>
                            <input v-model="form.email" class="us-input" :class="{ err: errores.email }" type="email" maxlength="255" placeholder="correo@cliente.com" autocomplete="off">
                            <span v-if="errores.email" class="us-error">{{ errores.email }}</span>
                        </label>
                    </div>
                    <p v-if="esCorreoEpa" class="us-hint" style="margin: -6px 0 0">
                        Las cuentas @epa.digital no se invitan: entran con Google directo. Después se les asignan cuentas y países desde Editar.
                    </p>

                    <div class="us-campo">
                        <span id="inv-metodo" class="us-label">Cómo va a entrar</span>
                        <div class="us-opciones" role="radiogroup" aria-labelledby="inv-metodo">
                            <button type="button" role="radio" class="us-opcion" :class="{ activa: form.metodo === 'password' }" :aria-checked="form.metodo === 'password'" @click="form.metodo = 'password'">
                                <strong>Contraseña + 2FA</strong>
                                <span>Con Google Authenticator</span>
                            </button>
                            <button type="button" role="radio" class="us-opcion" :class="{ activa: form.metodo === 'google' }" :aria-checked="form.metodo === 'google'" @click="form.metodo = 'google'">
                                <strong>Cuenta de Google</strong>
                                <span>Su propio correo de Google</span>
                            </button>
                        </div>
                        <span v-if="errores.metodo" class="us-error">{{ errores.metodo }}</span>
                    </div>

                    <div class="us-campo">
                        <span class="us-label">Cuentas que puede ver</span>
                        <div class="us-chips">
                            <button v-for="c in cuentas" :key="c.id" type="button" class="us-chip con-logo" :class="{ activo: form.cuentaIds.includes(c.id) }" :aria-pressed="form.cuentaIds.includes(c.id)" @click="alternar(form.cuentaIds, c.id)">
                                <LogoCuenta :cuenta="c" :tamano="24" :radio="6" />{{ c.nombre }}
                            </button>
                        </div>
                        <span v-if="errores.cuenta_ids" class="us-error">{{ errores.cuenta_ids }}</span>
                    </div>

                    <div class="us-campo">
                        <span class="us-label">Países que puede ver</span>
                        <div class="us-chips">
                            <button
                                v-for="p in paises"
                                :key="p.id"
                                type="button"
                                class="us-chip"
                                :class="{ activo: form.paisIds.includes(p.id) }"
                                :disabled="!paisesOtorgables.includes(p.id)"
                                :title="paisesOtorgables.includes(p.id) ? '' : 'No puedes dar acceso a un país que tú no tienes'"
                                :aria-pressed="form.paisIds.includes(p.id)"
                                @click="alternar(form.paisIds, p.id)"
                            >{{ p.nombre }}</button>
                        </div>
                        <span v-if="errores.pais_ids" class="us-error">{{ errores.pais_ids }}</span>
                    </div>

                    <label v-if="form.metodo === 'password'" class="us-campo">
                        <span class="us-label">Motivo</span>
                        <input v-model="form.motivo" class="us-input" :class="{ err: errores.motivo }" type="text" maxlength="500" placeholder="Ej. cliente sin dominio epa.digital">
                        <span v-if="errores.motivo" class="us-error">{{ errores.motivo }}</span>
                        <span v-else class="us-hint">Por qué entra con contraseña en vez de Google. Queda registrado.</span>
                    </label>

                    <p v-if="errores.general" class="us-error">{{ errores.general }}</p>

                    <footer class="us-modal-pie">
                        <span class="us-espacio" />
                        <button type="button" class="us-btn-sec" @click="cerrar">Cancelar</button>
                        <button type="submit" class="us-btn" :disabled="enviando || esCorreoEpa">{{ enviando ? 'Creando…' : 'Enviar invitación' }}</button>
                    </footer>
                </template>
            </form>
        </div>
    </Teleport>
</template>
