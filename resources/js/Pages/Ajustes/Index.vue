<script setup>
import { urlPais } from '@/rutas';
import { ref, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import ConciliacionImportacion from '@/Components/ConciliacionImportacion.vue';
import { fechaHora, fechaCorta, hoyCdmx } from '@/fechas';
import CampaniasPais from '@/Components/CampaniasPais.vue';
import InfoTip from '@/Components/InfoTip.vue';
import { confirmar, notificar } from '@/avisos';

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
    campanias: { type: Array, default: () => [] },
    tiposCambio: { type: Array, default: () => [] },
});

// --- Divisas (2026-11-11) ---------------------------------------------------
// Todo el dashboard reporta en USD. La moneda de cada cuenta se detecta sola
// al importar; el tipo de cambio lo captura EPA (hoy, una vez al año).
const MONEDAS = ['USD', 'MXN', 'COP', 'PEN', 'CLP', 'ARS', 'BRL', 'GTQ'];
const tiposCambio = ref([...props.tiposCambio]);
const nuevoTipo = ref({ moneda: 'MXN', vigente_desde: `${hoyCdmx().slice(0, 4)}-01-01`, por_usd: '' });
const tipoGuardando = ref(false);
const tipoError = ref('');
const tipoOk = ref('');

async function guardarTipoCambio() {
    if (tipoGuardando.value || !nuevoTipo.value.por_usd) return;
    const { moneda, vigente_desde: desde, por_usd: tasa } = nuevoTipo.value;
    const existente = tiposCambio.value.find((t) => t.moneda === moneda && t.vigente_desde.slice(0, 10) === desde);
    const fecha = fechaCorta(`${desde}T12:00:00Z`);
    const ok = await confirmar({
        tipo: 'pregunta',
        titulo: existente ? 'Actualizar tipo de cambio' : 'Guardar tipo de cambio',
        texto: existente
            ? `${moneda} desde el ${fecha}: de ${formatTasa(existente.por_usd)} a ${formatTasa(tasa)} ${moneda} por dólar.`
            : `1 USD = ${formatTasa(tasa)} ${moneda} a partir del ${fecha}.`,
        detalle: ['Aplica al gasto que se importe desde ahora.', 'Los meses ya cargados no cambian hasta que los vuelvas a importar.'],
        confirmar: existente ? 'Sí, actualizar' : 'Sí, guardar',
    });
    if (!ok) return;
    tipoGuardando.value = true;
    tipoError.value = '';
    tipoOk.value = '';
    try {
        const { data } = await axios.post(`${urlPais(props.pais)}/ajustes/tipos-cambio`, nuevoTipo.value);
        tiposCambio.value = [data, ...tiposCambio.value.filter((t) => t.id !== data.id)]
            .sort((a, b) => a.moneda.localeCompare(b.moneda) || b.vigente_desde.localeCompare(a.vigente_desde));
        tipoOk.value = `Guardado: 1 USD = ${formatTasa(data.por_usd)} ${data.moneda} desde ${fechaCorta(`${data.vigente_desde.slice(0, 10)}T12:00:00Z`)}.`;
        nuevoTipo.value.por_usd = '';
    } catch (err) {
        tipoError.value = err.response?.data?.message || 'No se pudo guardar el tipo de cambio.';
    } finally {
        tipoGuardando.value = false;
    }
}
async function borrarTipoCambio(tipo) {
    tipoError.value = '';
    tipoOk.value = '';
    await confirmar({
        tipo: 'peligro',
        titulo: '¿Borrar este tipo de cambio?',
        texto: `1 USD = ${formatTasa(tipo.por_usd)} ${tipo.moneda} desde el ${fechaCorta(`${tipo.vigente_desde.slice(0, 10)}T12:00:00Z`)}.`,
        detalle: ['Las siguientes importaciones usarán el tipo de cambio anterior.', 'Lo ya importado no cambia.'],
        confirmar: 'Sí, borrar',
        accion: async () => {
            await axios.delete(`${urlPais(props.pais)}/ajustes/tipos-cambio/${tipo.id}`);
            tiposCambio.value = tiposCambio.value.filter((t) => t.id !== tipo.id);
        },
        exito: 'Tipo de cambio borrado',
    });
}
function formatTasa(v) {
    return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 6 });
}
// El tipo de cambio que se usa HOY para cada moneda (el último que ya empezó).
const vigentes = computed(() => {
    const hoy = hoyCdmx();
    const porMoneda = {};
    for (const t of [...tiposCambio.value].sort((a, b) => a.vigente_desde.localeCompare(b.vigente_desde))) {
        if (t.vigente_desde.slice(0, 10) <= hoy) porMoneda[t.moneda] = t.id;
    }
    return new Set(Object.values(porMoneda));
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
    id_externo: '',
    nombre: '',
    tipo: 'tada',
    cuenta_en_venta_real: true,
});
const cuentaGuardando = ref(false);
const cuentaError = ref('');
const cuentaGuardadaOk = ref('');

async function agregarCuenta() {
    if (!nuevaCuenta.value.id_externo.trim() || cuentaGuardando.value) return;
    cuentaGuardando.value = true;
    cuentaError.value = '';
    cuentaGuardadaOk.value = '';
    try {
        const { data } = await axios.post(`${urlPais(props.pais)}/ajustes/cuentas-publicitarias`, {
            ...nuevaCuenta.value,
            id_externo: nuevaCuenta.value.id_externo.trim(),
            nombre: nuevaCuenta.value.nombre.trim() || null,
        });
        cuentas.value.push({ ...data, guardando: false, error: '' });
        cuentaGuardadaOk.value = `Cuenta verificada y guardada: ${data.nombre}.`;
        nuevaCuenta.value.id_externo = '';
        nuevaCuenta.value.nombre = '';
    } catch (err) {
        cuentaError.value = err.response?.data?.message || 'No se pudo guardar la cuenta.';
    } finally {
        cuentaGuardando.value = false;
    }
}

// Cada cambio en la tabla pregunta antes de guardarse; si se cancela, el
// control vuelve a como estaba.
async function cambiarCuenta(cuenta, campo, valor, control) {
    const n = cuenta.nombre;
    const textos = {
        tipo: {
            titulo: `¿Cambiar ${n} a ${TIPO_LABEL[valor]}?`,
            texto: 'Cambia cómo se agrupan sus anuncios (TaDa / BRD) a partir de la próxima importación.',
        },
        moneda: valor
            ? {
                  titulo: `¿La moneda de ${n} es ${valor}?`,
                  texto: valor === 'USD'
                      ? 'Su gasto se tomará tal cual, sin convertir.'
                      : `Su gasto se convertirá de ${valor} a USD con el tipo de cambio de ${valor}.`,
                  detalle: ['Aplica a lo que se importe desde ahora; para corregir un mes ya cargado, vuelve a importarlo.'],
              }
            : { titulo: `¿Volver a detectar la moneda de ${n}?`, texto: 'Se preguntará a la plataforma en la próxima importación.' },
        activa: valor
            ? { titulo: `¿Activar ${n}?`, texto: 'Su gasto se vuelve a traer en las importaciones.' }
            : { titulo: `¿Desactivar ${n}?`, texto: 'Su gasto deja de traerse en las siguientes importaciones. Lo ya importado se queda.' },
        cuenta_en_venta_real: valor
            ? { titulo: `¿Incluir ${n} en la venta real?`, texto: 'Sus anuncios entran al reparto de nuevos clientes y órdenes reales en las siguientes importaciones.' }
            : { titulo: `¿Sacar ${n} de la venta real?`, texto: 'Sus anuncios dejan de recibir nuevos clientes y órdenes reales en las siguientes importaciones.' },
    }[campo];
    const ok = await confirmar({
        tipo: campo === 'activa' && !valor ? 'peligro' : 'advertencia',
        confirmar: 'Sí, cambiar',
        ...textos,
    });
    if (!ok) {
        if (control.type === 'checkbox') control.checked = cuenta[campo];
        else control.value = cuenta[campo] ?? '';
        return;
    }
    await actualizarCuenta(cuenta, { [campo]: valor });
    if (!cuenta.error) notificar({ tipo: 'exito', titulo: 'Cambio guardado', texto: n, duracion: 4000 });
}

async function actualizarCuenta(cuenta, cambios) {
    const anterior = { ...cuenta };
    Object.assign(cuenta, cambios, { guardando: true, error: '' });
    try {
        const { data } = await axios.patch(`${urlPais(props.pais)}/ajustes/cuentas-publicitarias/${cuenta.id}`, cambios);
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
    const cuando = imp.creado_en ? `${fechaHora(imp.creado_en)} (CDMX)` : '';
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
    const actual = appsflyerApps.value.find((a) => a.pais_id === paisId.value && a.plataforma === plataforma.value);
    if (actual && actual.app_id !== appId.value) {
        const ok = await confirmar({
            tipo: 'advertencia',
            titulo: 'Reemplazar app de AppsFlyer',
            texto: `${actual.pais?.nombre ?? ''} ${plataforma.value}: de ${actual.app_id} a ${appId.value}.`,
            detalle: ['Las siguientes importaciones por API leerán la app nueva.'],
            confirmar: 'Sí, reemplazar',
        });
        if (!ok) return;
    }
    guardando.value = true;
    error.value = '';
    guardadoOk.value = false;

    try {
        const { data } = await axios.post(`${urlPais(props.pais)}/ajustes/appsflyer-apps`, {
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
                    <h2 class="titulo-con-info">
                        Cuentas publicitarias
                        <InfoTip etiqueta="Sobre Cuentas publicitarias">
                            Cuentas de Meta y TikTok de las que se trae el costo al importar. "Venta real" decide si sus anuncios entran al reparto de NC y órdenes reales (todos los canales: BRD, DTC paid y no paid).
                        </InfoTip>
                    </h2>
                    <p v-if="!puedeGestionarCuentas" class="hint">Solo superadmin puede agregarlas o editarlas.</p>

                    <div v-if="cuentas.length === 0" class="cols-hint">Sin cuentas cargadas todavía.</div>
                    <table v-else class="tabla-cuentas">
                        <thead>
                            <tr>
                                <th>País</th>
                                <th>Plataforma</th>
                                <th>Cuenta</th>
                                <th>Tipo</th>
                                <th>Moneda</th>
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
                                    <span class="mono cuenta-id">{{ cuenta.id_externo }}</span>
                                    <span v-if="cuenta.error" class="cuenta-error">{{ cuenta.error }}</span>
                                </td>
                                <td>
                                    <select
                                        v-if="puedeGestionarCuentas"
                                        :value="cuenta.tipo"
                                        :disabled="cuenta.guardando"
                                        :aria-label="`Tipo de ${cuenta.nombre}`"
                                        @change="cambiarCuenta(cuenta, 'tipo', $event.target.value, $event.target)"
                                    >
                                        <option value="tada">TaDa (EPA)</option>
                                        <option value="brd">BRD</option>
                                    </select>
                                    <template v-else>{{ TIPO_LABEL[cuenta.tipo] }}</template>
                                </td>
                                <td>
                                    <select
                                        v-if="puedeGestionarCuentas"
                                        :value="cuenta.moneda || ''"
                                        :disabled="cuenta.guardando"
                                        :aria-label="`Moneda de ${cuenta.nombre}`"
                                        @change="cambiarCuenta(cuenta, 'moneda', $event.target.value || null, $event.target)"
                                    >
                                        <option value="">Se detecta al importar</option>
                                        <option v-for="m in MONEDAS" :key="m" :value="m">{{ m }}</option>
                                    </select>
                                    <span v-else class="mono">{{ cuenta.moneda || '—' }}</span>
                                </td>
                                <td>
                                    <input
                                        type="checkbox"
                                        :checked="cuenta.activa"
                                        :disabled="!puedeGestionarCuentas || cuenta.guardando"
                                        :aria-label="`Activa: ${cuenta.nombre}`"
                                        @change="cambiarCuenta(cuenta, 'activa', $event.target.checked, $event.target)"
                                    />
                                </td>
                                <td>
                                    <input
                                        type="checkbox"
                                        :checked="cuenta.cuenta_en_venta_real"
                                        :disabled="!puedeGestionarCuentas || cuenta.guardando"
                                        :aria-label="`Cuenta para venta real: ${cuenta.nombre}`"
                                        @change="cambiarCuenta(cuenta, 'cuenta_en_venta_real', $event.target.checked, $event.target)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <template v-if="puedeGestionarCuentas">
                        <h2 class="titulo-con-info form-titulo">
                            Agregar cuenta
                            <InfoTip etiqueta="Sobre Agregar cuenta">
                                Se verifica contra la API antes de guardar; si no escribes un nombre, se usa el de la plataforma.
                            </InfoTip>
                        </h2>
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
                                    v-model="nuevaCuenta.id_externo"
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
                            <button type="button" class="btn-primary" :disabled="!nuevaCuenta.id_externo.trim() || cuentaGuardando" @click="agregarCuenta">
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
                    <h2 class="titulo-con-info">
                        Tipo de cambio
                        <InfoTip etiqueta="Sobre el tipo de cambio">
                            Todo el dashboard se ve en dólares (USD). El gasto de las cuentas en otra moneda (hoy, TikTok México
                            en pesos) se convierte al importar, cada día con el tipo de cambio vigente ese día. Si cambias el
                            tipo de cambio, aplica a lo que se importe después: para actualizar un mes ya cargado, vuelve a
                            importarlo.
                        </InfoTip>
                    </h2>

                    <table v-if="tiposCambio.length" class="tabla-cuentas">
                        <thead>
                            <tr>
                                <th>Moneda</th>
                                <th>Desde</th>
                                <th>1 USD =</th>
                                <th></th>
                                <th v-if="puedeGestionarCuentas"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in tiposCambio" :key="t.id">
                                <td class="mono">{{ t.moneda }}</td>
                                <td>{{ fechaCorta(`${t.vigente_desde.slice(0, 10)}T12:00:00Z`) }}</td>
                                <td class="mono">{{ formatTasa(t.por_usd) }} {{ t.moneda }}</td>
                                <td><span v-if="vigentes.has(t.id)" class="badge-vigente mono">Vigente</span></td>
                                <td v-if="puedeGestionarCuentas" class="celda-accion">
                                    <button type="button" class="modal-copy-btn btn-peligro" @click="borrarTipoCambio(t)">Borrar</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="cols-hint">Sin tipos de cambio: las cuentas que no estén en USD no se pueden importar.</p>

                    <template v-if="puedeGestionarCuentas">
                        <h2 class="form-titulo">Agregar / actualizar</h2>
                        <div class="import-grid">
                            <div class="import-col">
                                <label for="tipoMoneda">Moneda</label>
                                <select id="tipoMoneda" v-model="nuevoTipo.moneda">
                                    <option v-for="m in MONEDAS.filter((x) => x !== 'USD')" :key="m" :value="m">{{ m }}</option>
                                </select>
                            </div>
                            <div class="import-col">
                                <label for="tipoDesde">Vigente desde</label>
                                <input id="tipoDesde" v-model="nuevoTipo.vigente_desde" type="date" />
                            </div>
                            <div class="import-col">
                                <label for="tipoTasa">1 USD =</label>
                                <input id="tipoTasa" v-model="nuevoTipo.por_usd" type="number" min="0" step="0.000001" :placeholder="`ej. 18.677168 ${nuevoTipo.moneda}`" />
                            </div>
                        </div>
                        <div class="import-actions">
                            <button type="button" class="btn-primary" :disabled="tipoGuardando || !nuevoTipo.por_usd" @click="guardarTipoCambio">
                                {{ tipoGuardando ? 'Guardando…' : 'Guardar tipo de cambio' }}
                            </button>
                            <span v-if="tipoError" class="import-status" style="color: var(--coral)">{{ tipoError }}</span>
                            <span v-else-if="tipoOk" class="import-status" style="color: var(--mint)">{{ tipoOk }}</span>
                        </div>
                    </template>
                </section>

                <section class="import-panel">
                    <h2 class="titulo-con-info">
                        Campañas
                        <InfoTip etiqueta="Sobre Campañas">
                            Las campañas de este país tal como quedaron en los datos importados: de qué cuenta vienen, etapa, tipo, anuncios, gasto total y en qué meses aparecen.
                        </InfoTip>
                    </h2>
                    <CampaniasPais :campanias="campanias" />
                </section>

                <section class="import-panel">
                    <h2 class="titulo-con-info">
                        Double check de importaciones
                        <InfoTip etiqueta="Sobre Double check de importaciones">
                            Qué trajo cada cuenta, qué conversiones de AppsFlyer quedaron sin costo en ninguna cuenta y si lo repartido cuadra con el total tecleado. Se guarda en cada importación de este país.
                        </InfoTip>
                    </h2>
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
                    <h2 class="titulo-con-info">
                        Taxonomía de creativos
                        <InfoTip etiqueta="Sobre Taxonomía de creativos">
                            Nombres amigables por creativo (ej. "AON-BURGER-SHOW" → "Burger Show"): se aplican en Creativos e Inteligencia, el nombre técnico original nunca se pierde.
                        </InfoTip>
                    </h2>
                    <Link :href="`${urlPais(pais)}/ajustes/nombres`" class="btn-primary" style="text-decoration: none; display: inline-block;">
                        Gestionar nombres
                    </Link>
                    <!-- Cuentas con clasificación propia: Spark Ads y demás sin creativo. -->
                    <Link v-if="usePage().props.cuentaActual?.slug !== 'tada'" :href="`${urlPais(pais)}/ajustes/sin-creativo`" class="btn-primary" style="text-decoration: none; display: inline-block; margin-left: 8px;">
                        Anuncios sin creativo
                    </Link>
                </section>

                <section class="import-panel">
                    <h2 class="titulo-con-info">
                        Apps cargadas
                        <InfoTip etiqueta="Sobre Apps cargadas">
                            Cada país puede tener hasta 2 apps (iOS + Android): se usan para traer AppsFlyer vía API en "Cargar datos" y en el refresh diario agendado.
                        </InfoTip>
                    </h2>

                    <div v-if="appsflyerAppsOrdenadas.length === 0" class="cols-hint">Sin apps cargadas todavía.</div>
                    <div v-for="app in appsflyerAppsOrdenadas" :key="app.id" class="fila-app">
                        <span class="fila-app-pais">{{ app.pais?.nombre ?? '—' }}</span>
                        <span class="fila-app-plataforma pill">{{ app.plataforma }}</span>
                        <span class="fila-app-nombre">{{ app.nombre || '(sin nombre)' }}</span>
                        <span class="fila-app-id mono">{{ app.app_id }}</span>
                    </div>

                    <h2 class="titulo-con-info form-titulo">
                        Agregar / actualizar
                        <InfoTip etiqueta="Sobre Agregar / actualizar">
                            Si el país + plataforma ya existe, esto lo actualiza en vez de duplicarlo.
                        </InfoTip>
                    </h2>

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
.badge-vigente {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    background: color-mix(in srgb, var(--mint) 20%, transparent);
    color: var(--mint);
}
.celda-accion {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-end;
}
.btn-peligro {
    border-color: var(--coral);
    color: var(--coral);
}
.titulo-con-info {
    display: flex;
    align-items: center;
    gap: 6px;
}
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
    color: var(--accent-text);
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
