<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import ConciliacionImportacion from '@/Components/ConciliacionImportacion.vue';

// Gestión de apps de AppsFlyer por país (tabla appsflyer_apps, 2026-08-12) --
// antes solo se podían cargar por seeder/tinker. Mismo patrón de axios+JSON
// (no Inertia form/redirect) que ya usa ImportarDatos.vue.
const props = defineProps({
    pais: { type: String, required: true },
    paises: { type: Array, required: true },
    appsflyerApps: { type: Array, required: true },
    cuentasPublicitarias: { type: Array, default: () => [] },
    puedeGestionarCuentas: { type: Boolean, default: false },
    conciliaciones: { type: Array, default: () => [] },
});

// --- Cuentas publicitarias (2026-10-05) ---------------------------------
// Ver CuentaPublicitaria: cada país puede tener varias cuentas de Meta y
// TikTok. Solo superadmin agrega/edita (el backend lo exige igual, ver
// Gate 'gestionar-cuentas-publicitarias'); el resto de EPA solo las ve.
const PLATAFORMA_LABEL = { meta: 'Meta', tiktok: 'TikTok' };
const TIPO_LABEL = { tada: 'TaDa (EPA)', brd: 'BRD' };

const cuentas = ref(props.cuentasPublicitarias.map((c) => ({ ...c, guardando: false, error: '' })));
const nuevaCuenta = ref({
    pais_id: props.paises[0]?.id ?? null,
    plataforma: 'meta',
    cuenta_id: '',
    nombre: '',
    tipo: 'tada',
    cuenta_en_venta_real: true,
});
const cuentaGuardando = ref(false);
const cuentaError = ref('');
const cuentaGuardadaOk = ref('');

async function agregarCuenta() {
    if (!nuevaCuenta.value.cuenta_id.trim() || cuentaGuardando.value) return;
    cuentaGuardando.value = true;
    cuentaError.value = '';
    cuentaGuardadaOk.value = '';
    try {
        const { data } = await axios.post(`/pais/${props.pais}/ajustes/cuentas-publicitarias`, {
            ...nuevaCuenta.value,
            cuenta_id: nuevaCuenta.value.cuenta_id.trim(),
            nombre: nuevaCuenta.value.nombre.trim() || null,
        });
        cuentas.value.push({ ...data, guardando: false, error: '' });
        cuentaGuardadaOk.value = `Cuenta verificada y guardada: ${data.nombre}.`;
        nuevaCuenta.value.cuenta_id = '';
        nuevaCuenta.value.nombre = '';
    } catch (err) {
        cuentaError.value = err.response?.data?.message || 'No se pudo guardar la cuenta.';
    } finally {
        cuentaGuardando.value = false;
    }
}

async function actualizarCuenta(cuenta, cambios) {
    const anterior = { ...cuenta };
    Object.assign(cuenta, cambios, { guardando: true, error: '' });
    try {
        const { data } = await axios.patch(`/pais/${props.pais}/ajustes/cuentas-publicitarias/${cuenta.id}`, cambios);
        Object.assign(cuenta, data);
    } catch (err) {
        Object.assign(cuenta, anterior);
        cuenta.error = err.response?.data?.message || 'No se pudo guardar.';
    } finally {
        cuenta.guardando = false;
    }
}

// --- Double check de importaciones -----------------------------------------
const conciliacionId = ref(props.conciliaciones[0]?.id ?? null);
const conciliacionElegida = computed(() => props.conciliaciones.find((c) => c.id === conciliacionId.value) ?? null);
function etiquetaImportacion(imp) {
    const mes = new Date(`${imp.desde.slice(0, 10)}T00:00:00`).toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
    const cuando = imp.creado_en ? new Date(imp.creado_en).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '';
    return `${mes} · ${imp.origen === 'csv' ? 'CSV' : 'API'} · ${cuando}`;
}

const appsflyerApps = ref(props.appsflyerApps);
const appsflyerAppsOrdenadas = computed(() =>
    [...appsflyerApps.value].sort((a, b) => {
        const nombrePais = (a.pais?.nombre || '').localeCompare(b.pais?.nombre || '');
        return nombrePais !== 0 ? nombrePais : a.plataforma.localeCompare(b.plataforma);
    }),
);

const paisId = ref(props.paises[0]?.id ?? null);
const plataforma = ref('ios');
const appId = ref('');
const nombre = ref('');
const guardando = ref(false);
const error = ref('');
const guardadoOk = ref(false);

async function guardar() {
    if (!paisId.value || !plataforma.value || !appId.value || guardando.value) return;
    guardando.value = true;
    error.value = '';
    guardadoOk.value = false;

    try {
        const { data } = await axios.post(`/pais/${props.pais}/ajustes/appsflyer-apps`, {
            pais_id: paisId.value,
            plataforma: plataforma.value,
            app_id: appId.value,
            nombre: nombre.value || null,
        });
        const idx = appsflyerApps.value.findIndex((a) => a.id === data.id);
        if (idx >= 0) appsflyerApps.value[idx] = data;
        else appsflyerApps.value.push(data);
        appId.value = '';
        nombre.value = '';
        guardadoOk.value = true;
    } catch (err) {
        error.value = err.response?.data?.message || 'No se pudo guardar.';
    } finally {
        guardando.value = false;
    }
}
</script>

<template>
    <Head title="Ajustes" />

    <DashboardLayout :pais="pais" vista-activa="ajustes">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">Ajustes</p>
                        <h1 class="title">Apps de AppsFlyer por país</h1>
                    </div>
                </header>

                <section class="import-panel">
                    <h2>Cuentas publicitarias</h2>
                    <p class="hint">
                        Cuentas de Meta y TikTok de las que se trae el costo al importar. "Venta real" decide si sus
                        anuncios entran al reparto de NC y órdenes reales (todos los canales: BRD, DTC paid y no paid).
                        <template v-if="!puedeGestionarCuentas">Solo superadmin puede agregarlas o editarlas.</template>
                    </p>

                    <div v-if="cuentas.length === 0" class="cols-hint">Sin cuentas cargadas todavía.</div>
                    <table v-else class="tabla-cuentas">
                        <thead>
                            <tr>
                                <th>País</th>
                                <th>Plataforma</th>
                                <th>Cuenta</th>
                                <th>Tipo</th>
                                <th>Activa</th>
                                <th>Venta real</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cuenta in cuentas" :key="cuenta.id" :class="{ inactiva: !cuenta.activa }">
                                <td>{{ cuenta.pais?.nombre ?? '—' }}</td>
                                <td><span class="pill mono">{{ PLATAFORMA_LABEL[cuenta.plataforma] }}</span></td>
                                <td>
                                    {{ cuenta.nombre || '(sin nombre)' }}
                                    <span class="mono cuenta-id">{{ cuenta.cuenta_id }}</span>
                                    <span v-if="cuenta.error" class="cuenta-error">{{ cuenta.error }}</span>
                                </td>
                                <td>
                                    <select
                                        v-if="puedeGestionarCuentas"
                                        :value="cuenta.tipo"
                                        :disabled="cuenta.guardando"
                                        :aria-label="`Tipo de ${cuenta.nombre}`"
                                        @change="actualizarCuenta(cuenta, { tipo: $event.target.value })"
                                    >
                                        <option value="tada">TaDa (EPA)</option>
                                        <option value="brd">BRD</option>
                                    </select>
                                    <template v-else>{{ TIPO_LABEL[cuenta.tipo] }}</template>
                                </td>
                                <td>
                                    <input
                                        type="checkbox"
                                        :checked="cuenta.activa"
                                        :disabled="!puedeGestionarCuentas || cuenta.guardando"
                                        :aria-label="`Activa: ${cuenta.nombre}`"
                                        @change="actualizarCuenta(cuenta, { activa: $event.target.checked })"
                                    />
                                </td>
                                <td>
                                    <input
                                        type="checkbox"
                                        :checked="cuenta.cuenta_en_venta_real"
                                        :disabled="!puedeGestionarCuentas || cuenta.guardando"
                                        :aria-label="`Cuenta para venta real: ${cuenta.nombre}`"
                                        @change="actualizarCuenta(cuenta, { cuenta_en_venta_real: $event.target.checked })"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <template v-if="puedeGestionarCuentas">
                        <h2 class="form-titulo">Agregar cuenta</h2>
                        <p class="hint">Se verifica contra la API antes de guardar; si no escribís un nombre, se usa el de la plataforma.</p>
                        <div class="import-grid">
                            <div class="import-col">
                                <label for="cuentaPaisSelect">País</label>
                                <select id="cuentaPaisSelect" v-model.number="nuevaCuenta.pais_id">
                                    <option v-for="p in paises" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                                </select>
                            </div>
                            <div class="import-col">
                                <label for="cuentaPlataformaSelect">Plataforma</label>
                                <select id="cuentaPlataformaSelect" v-model="nuevaCuenta.plataforma">
                                    <option value="meta">Meta</option>
                                    <option value="tiktok">TikTok</option>
                                </select>
                            </div>
                            <div class="import-col">
                                <label for="cuentaIdInput">ID de cuenta</label>
                                <input
                                    id="cuentaIdInput"
                                    v-model="nuevaCuenta.cuenta_id"
                                    type="text"
                                    :placeholder="nuevaCuenta.plataforma === 'meta' ? 'ej. 913224553013929 o act_913…' : 'ej. 7332930135332749314'"
                                />
                            </div>
                            <div class="import-col">
                                <label for="cuentaNombreInput">Nombre (opcional)</label>
                                <input id="cuentaNombreInput" v-model="nuevaCuenta.nombre" type="text" placeholder="ej. Ecuador Meta BRD" />
                            </div>
                            <div class="import-col">
                                <label for="cuentaTipoSelect">Tipo</label>
                                <select id="cuentaTipoSelect" v-model="nuevaCuenta.tipo">
                                    <option value="tada">TaDa (EPA)</option>
                                    <option value="brd">BRD</option>
                                </select>
                            </div>
                            <div class="import-col">
                                <span class="label-check">Venta real</span>
                                <label class="check-inline">
                                    <input v-model="nuevaCuenta.cuenta_en_venta_real" type="checkbox" />
                                    Entra al reparto de NC y órdenes
                                </label>
                            </div>
                        </div>
                        <div class="import-actions">
                            <button type="button" class="btn-primary" :disabled="!nuevaCuenta.cuenta_id.trim() || cuentaGuardando" @click="agregarCuenta">
                                {{ cuentaGuardando ? 'Verificando…' : 'Verificar y guardar' }}
                            </button>
                            <span class="import-status">
                                <span v-if="cuentaError" class="cuenta-error">{{ cuentaError }}</span>
                                <template v-else-if="cuentaGuardadaOk">{{ cuentaGuardadaOk }}</template>
                            </span>
                        </div>
                    </template>
                </section>

                <section class="import-panel">
                    <h2>Double check de importaciones</h2>
                    <p class="hint">
                        Qué trajo cada cuenta, qué conversiones de AppsFlyer quedaron sin costo en ninguna cuenta y si lo
                        repartido cuadra con el total tecleado. Se guarda en cada importación de este país.
                    </p>
                    <p v-if="conciliaciones.length === 0" class="empty-note">
                        Todavía no hay importaciones con double check. Aparece desde la próxima importación.
                    </p>
                    <template v-else>
                        <div class="import-col selector-conciliacion">
                            <label for="conciliacionSelect">Importación</label>
                            <select id="conciliacionSelect" v-model.number="conciliacionId">
                                <option v-for="imp in conciliaciones" :key="imp.id" :value="imp.id">{{ etiquetaImportacion(imp) }}</option>
                            </select>
                        </div>
                        <ConciliacionImportacion v-if="conciliacionElegida" :conciliacion="conciliacionElegida.conciliacion" />
                    </template>
                </section>

                <section class="import-panel">
                    <h2>Taxonomía de creativos</h2>
                    <p class="hint">
                        Nombres amigables por creativo (ej. "AON-BURGER-SHOW" → "Burger Show") -- se aplican en
                        Creativos e Inteligencia, el nombre técnico original nunca se pierde.
                    </p>
                    <Link :href="`/pais/${pais}/ajustes/nombres`" class="btn-primary" style="text-decoration: none; display: inline-block;">
                        Gestionar nombres
                    </Link>
                </section>

                <section class="import-panel">
                    <h2>Apps cargadas</h2>
                    <p class="hint">
                        Cada país puede tener hasta 2 apps (iOS + Android) -- se usan para traer AppsFlyer vía API en
                        "Cargar datos" y en el refresh diario agendado.
                    </p>

                    <div v-if="appsflyerAppsOrdenadas.length === 0" class="cols-hint">Sin apps cargadas todavía.</div>
                    <div v-for="app in appsflyerAppsOrdenadas" :key="app.id" class="fila-app">
                        <span class="fila-app-pais">{{ app.pais?.nombre ?? '—' }}</span>
                        <span class="fila-app-plataforma pill">{{ app.plataforma }}</span>
                        <span class="fila-app-nombre">{{ app.nombre || '(sin nombre)' }}</span>
                        <span class="fila-app-id mono">{{ app.app_id }}</span>
                    </div>

                    <h2 class="form-titulo">Agregar / actualizar</h2>
                    <p class="hint">
                        Si el país + plataforma ya existe, esto lo actualiza en vez de duplicarlo.
                    </p>

                    <div class="import-grid">
                        <div class="import-col">
                            <label for="paisSelect">País</label>
                            <select id="paisSelect" v-model.number="paisId">
                                <option v-for="p in paises" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                            </select>
                        </div>
                        <div class="import-col">
                            <label for="plataformaSelect">Plataforma</label>
                            <select id="plataformaSelect" v-model="plataforma">
                                <option value="ios">iOS</option>
                                <option value="android">Android</option>
                            </select>
                        </div>
                        <div class="import-col">
                            <label for="appIdInput">App ID</label>
                            <input id="appIdInput" v-model="appId" type="text" placeholder="ej. id1596944067 / com.empresa.app" />
                        </div>
                        <div class="import-col">
                            <label for="nombreInput">Nombre (para identificarla)</label>
                            <input id="nombreInput" v-model="nombre" type="text" placeholder="ej. México Android" />
                        </div>
                    </div>

                    <div class="import-actions">
                        <button type="button" class="btn-primary" :disabled="!paisId || !appId || guardando" @click="guardar">
                            {{ guardando ? 'Guardando…' : 'Guardar' }}
                        </button>
                        <span class="import-status">
                            <template v-if="error">{{ error }}</template>
                            <template v-else-if="guardadoOk">Guardado.</template>
                        </span>
                    </div>
                </section>
            </div>
        </div>
    </DashboardLayout>
</template>

<style scoped>
.page {
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
}
.page-inner {
    max-width: 1000px;
    margin: 0 auto;
    padding: 32px 32px 64px;
}
.header {
    margin-bottom: 8px;
}
.eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--amber);
    margin: 0 0 4px;
}
.title {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 700;
    font-size: 28px;
    margin: 0;
}
.form-titulo {
    margin-top: 28px;
}
.fila-app {
    display: grid;
    grid-template-columns: 1fr auto 1.5fr 2fr;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
}
.fila-app-pais {
    font-weight: 600;
}
.fila-app-id {
    color: var(--text-faint);
}
.tabla-cuentas {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.tabla-cuentas th {
    text-align: left;
    padding: 8px 10px;
    font-size: 0.68rem;
    font-weight: 400;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    border-bottom: 1px solid var(--border);
}
.tabla-cuentas td {
    padding: 10px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}
.tabla-cuentas tr.inactiva td {
    opacity: 0.5;
}
.tabla-cuentas select {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 4px 6px;
    font-size: 0.8rem;
}
.tabla-cuentas input[type='checkbox'],
.check-inline input[type='checkbox'] {
    accent-color: var(--amber);
    width: 16px;
    height: 16px;
}
.cuenta-id {
    display: block;
    font-size: 0.72rem;
    color: var(--text-faint);
}
.cuenta-error {
    display: block;
    font-size: 0.75rem;
    color: var(--coral);
}
.label-check {
    display: block;
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 6px;
}
.check-inline {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: var(--text-muted);
    padding: 10px 0;
}
.selector-conciliacion {
    max-width: 420px;
    margin-bottom: 20px;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
    margin: 0;
}
.import-col select,
.import-col input[type='text'] {
    width: 100%;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.85rem;
}
</style>
