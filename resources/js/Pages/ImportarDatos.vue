<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import TipoCuentaToggle from '@/Components/Creativo/TipoCuentaToggle.vue';
import { formatMoneyExacto, formatNumeroExacto, FUNNEL_LABELS } from '@/motor';

// Puerto del import-panel de meta.html/tiktok.html ("Cargar datos") -- el
// hueco real de la migración: antes no había dónde teclear ncTotalReal/
// ordersTotalReal ni ver el rango de fechas del CSV, todo dependía de
// correr `importar:csv` de memoria por consola. Este panel llama a los
// mismos endpoints que usa ImportadorDatos (la clase que también usa el
// comando de consola) -- nunca reimplementa el parseo/cruce/agrupación acá.
const props = defineProps({
    pais: { type: String, required: true },
});

// Tab "api" es la vista por defecto (2026-08-12): reemplaza la subida de CSV
// como forma principal de cargar datos -- trae AppsFlyer en vivo vía
// ImportadorDatos::importarDesdeApi(), mismo pipeline que
// `importar:appsflyer-api` por consola. El tab "csv" es el flujo viejo,
// intacto, que queda como fallback manual (API caída, corrección puntual).
const tab = ref('api');

// --- Tab API ---------------------------------------------------------
const apiMes = ref(mesAnteriorPorDefecto());
const apiNcTotalRealMeta = ref('');
const apiOrdersTotalRealMeta = ref('');
const apiNcTotalRealTiktok = ref('');
const apiOrdersTotalRealTiktok = ref('');
const apiImportando = ref(false);
const apiImportError = ref('');
const apiResumen = ref(null);

function mesAnteriorPorDefecto() {
    const hoy = new Date();
    const anterior = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
    return `${anterior.getFullYear()}-${String(anterior.getMonth() + 1).padStart(2, '0')}`;
}

// desde/hasta se derivan del mes elegido -- un solo <input type="month">
// hace estructuralmente imposible elegir un rango que cruce de mes (el
// backend exige "mismo mes", ver ImportadorDatos::validarRango).
const apiDesde = computed(() => (apiMes.value ? `${apiMes.value}-01` : ''));
const apiHasta = computed(() => {
    if (!apiMes.value) return '';
    const [anio, mes] = apiMes.value.split('-').map(Number);
    const ultimoDia = new Date(anio, mes, 0).getDate();
    return `${apiMes.value}-${String(ultimoDia).padStart(2, '0')}`;
});

const apiEsMesActual = computed(() => apiMes.value === mesActualIso());
function mesActualIso() {
    const hoy = new Date();
    return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`;
}

async function importarApi() {
    if (!apiMes.value || apiImportando.value) return;
    apiImportando.value = true;
    apiImportError.value = '';
    apiResumen.value = null;

    try {
        const { data } = await axios.post(`/pais/${props.pais}/importar/api`, {
            desde: apiDesde.value,
            hasta: apiHasta.value,
            nc_total_real_meta: apiNcTotalRealMeta.value === '' ? null : apiNcTotalRealMeta.value,
            orders_total_real_meta: apiOrdersTotalRealMeta.value === '' ? null : apiOrdersTotalRealMeta.value,
            nc_total_real_tiktok: apiNcTotalRealTiktok.value === '' ? null : apiNcTotalRealTiktok.value,
            orders_total_real_tiktok: apiOrdersTotalRealTiktok.value === '' ? null : apiOrdersTotalRealTiktok.value,
        });
        apiResumen.value = data;
    } catch (err) {
        apiImportError.value = err.response?.data?.error || err.response?.data?.message || 'No se pudo importar.';
    } finally {
        apiImportando.value = false;
    }
}

// --- Tab CSV (flujo existente, sin cambios de lógica) -----------------
const archivoInput = ref(null);
const nombreArchivo = ref('');
const token = ref(null);
const cargandoPreview = ref(false);
const previewError = ref('');
const preview = ref(null);

const desde = ref('');
const hasta = ref('');
// Meta y TikTok son cuentas/negocios distintos -- cada uno reparte SU
// PROPIO total tecleado entre sus propios ads, nunca un total compartido.
const ncTotalRealMeta = ref('');
const ordersTotalRealMeta = ref('');
const ncTotalRealTiktok = ref('');
const ordersTotalRealTiktok = ref('');

const importando = ref(false);
const importError = ref('');
const resumen = ref(null);

async function onArchivoSeleccionado(e) {
    const file = e.target.files[0];
    if (!file) return;

    nombreArchivo.value = file.name;
    token.value = null;
    preview.value = null;
    previewError.value = '';
    resumen.value = null;
    importError.value = '';
    desde.value = '';
    hasta.value = '';
    cargandoPreview.value = true;

    try {
        const formData = new FormData();
        formData.append('archivo', file);
        const { data } = await axios.post(`/pais/${props.pais}/importar/previsualizar`, formData);
        preview.value = data;
        token.value = data.token;
        desde.value = data.rangoMin || '';
        hasta.value = data.rangoMax || '';
    } catch (err) {
        previewError.value = err.response?.data?.message || 'No se pudo leer el CSV.';
    } finally {
        cargandoPreview.value = false;
    }
}

// "Desde no puede ser después de Hasta." -- mismo texto y misma condición
// que calcularCardsFinal (motor.js), no se recalcula nada si esto dispara.
const rangoError = computed(() => {
    if (desde.value && hasta.value && desde.value > hasta.value) {
        return 'Desde no puede ser después de Hasta.';
    }
    return '';
});

// esRangoParcial en vivo, mismo cálculo que ImportadorDatos::importar --
// solo para avisar ANTES de confirmar, el server la recalcula igual al
// importar (nunca confía en un valor mandado por el cliente).
const esRangoParcialPreview = computed(() => {
    if (!preview.value?.rangoMin || !preview.value?.rangoMax) return false;
    return (
        (desde.value || preview.value.rangoMin) !== preview.value.rangoMin ||
        (hasta.value || preview.value.rangoMax) !== preview.value.rangoMax
    );
});

async function importar() {
    if (rangoError.value || !token.value || importando.value) return;
    importando.value = true;
    importError.value = '';
    resumen.value = null;

    try {
        const { data } = await axios.post(`/pais/${props.pais}/importar`, {
            token: token.value,
            nombre_archivo: nombreArchivo.value,
            desde: desde.value,
            hasta: hasta.value,
            nc_total_real_meta: ncTotalRealMeta.value === '' ? null : ncTotalRealMeta.value,
            orders_total_real_meta: ordersTotalRealMeta.value === '' ? null : ordersTotalRealMeta.value,
            nc_total_real_tiktok: ncTotalRealTiktok.value === '' ? null : ncTotalRealTiktok.value,
            orders_total_real_tiktok: ordersTotalRealTiktok.value === '' ? null : ordersTotalRealTiktok.value,
        });
        resumen.value = data;
        token.value = null; // el archivo temporal ya se borró en el server
        if (archivoInput.value) archivoInput.value.value = '';
    } catch (err) {
        importError.value = err.response?.data?.error || err.response?.data?.message || 'No se pudo importar.';
    } finally {
        importando.value = false;
    }
}

// --- Resumen por arte (2026-08-28, pedido explícito) ------------------
// Tabla al fondo del panel, una fila por ARTE + FECHA (pedido posterior:
// "agregar una columna de fecha para que se puedan ver por día") -- mismas
// columnas que las pestañas Overview/Meta Ads/TikTok Ads del Google Sheet
// de QA que ya usan, para comparar lado a lado y cazar diferencias. Grano
// DIARIO (resultados_diarios) -- solo existe para países con pipeline
// diario (hoy Panamá); rangoMin/rangoMax null = país sin datos diarios
// todavía, se avisa en vez de mostrar una tabla vacía sin explicación.
const resumenDesde = ref('');
const resumenHasta = ref('');
const resumenRangoMin = ref(null);
const resumenRangoMax = ref(null);
const resumenFilas = ref([]);
const resumenTotales = ref(null);
const resumenCargando = ref(false);
const resumenError = ref('');
// Atajo activo -- solo visual (resalta el botón elegido); "personalizado"
// se activa solo o al tocar los inputs de fecha a mano.
const resumenAtajo = ref('7');

async function cargarResumen(params = {}) {
    resumenCargando.value = true;
    resumenError.value = '';
    try {
        const { data } = await axios.get(`/pais/${props.pais}/importar/resumen`, { params });
        resumenDesde.value = data.desde || '';
        resumenHasta.value = data.hasta || '';
        resumenRangoMin.value = data.rangoMin || null;
        resumenRangoMax.value = data.rangoMax || null;
        resumenFilas.value = data.filas || [];
        resumenTotales.value = data.totales || null;
    } catch {
        resumenError.value = 'No se pudo cargar el resumen.';
    } finally {
        resumenCargando.value = false;
    }
}

// Atajos -- "hasta" siempre el último día CON datos reales (rangoMax), no
// la fecha de hoy del navegador -- si el import diario de hoy todavía no
// corrió, "hoy" mostraría una tabla vacía sin explicación.
function aplicarAtajo(dias) {
    resumenAtajo.value = String(dias);
    if (!resumenRangoMax.value) return;
    const hasta = resumenRangoMax.value;
    const [a, m, d] = hasta.split('-').map(Number);
    const fechaHasta = new Date(Date.UTC(a, m - 1, d));
    const fechaDesde = new Date(fechaHasta);
    fechaDesde.setUTCDate(fechaDesde.getUTCDate() - (dias - 1));
    const desde = `${fechaDesde.getUTCFullYear()}-${String(fechaDesde.getUTCMonth() + 1).padStart(2, '0')}-${String(fechaDesde.getUTCDate()).padStart(2, '0')}`;
    cargarResumen({ desde, hasta });
}
function aplicarPersonalizado() {
    resumenAtajo.value = 'personalizado';
    if (resumenDesde.value && resumenHasta.value) {
        cargarResumen({ desde: resumenDesde.value, hasta: resumenHasta.value });
    }
}

onMounted(() => cargarResumen());
</script>

<template>
    <Head :title="`Cargar datos — ${pais}`" />

    <DashboardLayout :pais="pais">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Cargar datos</h1>
                    </div>
                </header>

                <section class="import-panel">
                    <h2>Cargar datos</h2>

                    <div class="tab-row">
                        <button type="button" class="tab-btn" :class="{ active: tab === 'api' }" @click="tab = 'api'">
                            Por API
                        </button>
                        <button type="button" class="tab-btn" :class="{ active: tab === 'csv' }" @click="tab = 'csv'">
                            Subir CSV
                        </button>
                    </div>

                    <template v-if="tab === 'api'">
                        <p class="hint">
                            Trae AppsFlyer en vivo (sin subir nada) + costo/impresiones/clicks de Meta/TikTok para el
                            mes elegido. Mismo pipeline que <code>importar:appsflyer-api</code> por consola.
                        </p>

                        <div class="date-range-row">
                            <div class="date-field">
                                <label for="apiMesInput">Mes</label>
                                <input id="apiMesInput" v-model="apiMes" type="month" />
                            </div>
                            <span class="date-hint">
                                Se importa el mes completo ({{ apiDesde }} a {{ apiHasta }}). Para corregir un mes a
                                mano o un rango parcial, usá la pestaña "Subir CSV".
                            </span>
                            <span v-if="apiEsMesActual" class="date-range-nota">
                                Mes en curso: los últimos días pueden estar subcontados -- AppsFlyer todavía está
                                recibiendo conversiones de esos días.
                            </span>
                        </div>

                        <div class="import-grid">
                            <div class="import-col">
                                <label for="apiNcTotalRealMetaInput">Venta real — Meta</label>
                                <span class="cols-hint">
                                    NC y Orders REALES del negocio para este mes (no los de AppsFlyer) -- se reparten
                                    entre los ads de Meta según su participación en AppsFlyer. CAC/CPO salen de acá,
                                    nunca del crudo de AppsFlyer.
                                </span>
                                <input id="apiNcTotalRealMetaInput" v-model="apiNcTotalRealMeta" type="number" placeholder="NC total real (Meta)" min="0" />
                                <input id="apiOrdersTotalRealMetaInput" v-model="apiOrdersTotalRealMeta" type="number" placeholder="Orders total real (Meta)" min="0" style="margin-top: 8px" />
                            </div>
                            <div class="import-col">
                                <label for="apiNcTotalRealTiktokInput">Venta real — TikTok</label>
                                <span class="cols-hint">
                                    Mismo criterio, para los ads de TikTok -- Meta y TikTok son cuentas distintas,
                                    cada una reparte SU PROPIO total, nunca uno compartido entre las dos.
                                </span>
                                <input id="apiNcTotalRealTiktokInput" v-model="apiNcTotalRealTiktok" type="number" placeholder="NC total real (TikTok)" min="0" />
                                <input id="apiOrdersTotalRealTiktokInput" v-model="apiOrdersTotalRealTiktok" type="number" placeholder="Orders total real (TikTok)" min="0" style="margin-top: 8px" />
                            </div>
                        </div>

                        <div class="import-actions">
                            <button type="button" class="btn-primary" :disabled="!apiMes || apiImportando" @click="importarApi">
                                {{ apiImportando ? 'Importando…' : 'Importar' }}
                            </button>
                            <span class="import-status">
                                <template v-if="apiImportError">{{ apiImportError }}</template>
                                <template v-else-if="!apiResumen">Listo para importar.</template>
                                <template v-else>Importación completa.</template>
                            </span>
                        </div>

                        <div v-if="apiResumen" class="summary-pills">
                            <span class="pill"><span class="dot" style="background: var(--mint)" /> {{ apiResumen.creativosTocados }} creativo(s)</span>
                            <span class="pill"><span class="dot" style="background: var(--mint)" /> {{ apiResumen.resultadosTocados }} resultado(s)</span>
                            <span class="pill"><span class="dot" style="background: var(--amber)" /> {{ apiResumen.tieneMetaTrue }} con match de costo (tiene_meta)</span>
                            <span v-if="apiResumen.esRangoParcial" class="pill"><span class="dot" style="background: var(--coral)" /> Rango parcial</span>
                            <span v-if="apiResumen.problemas > 0" class="pill"><span class="dot" style="background: var(--coral)" /> {{ apiResumen.problemas }} problema(s)</span>
                            <span v-if="apiResumen.excluidos > 0" class="pill"><span class="dot" style="background: var(--text-faint)" /> {{ apiResumen.excluidos }} excluido(s)</span>
                            <span v-if="apiResumen.sinActividadDescartados > 0" class="pill"><span class="dot" style="background: var(--text-faint)" /> {{ apiResumen.sinActividadDescartados }} sin actividad (descartado)</span>
                        </div>
                    </template>

                    <template v-else>
                    <p class="hint">
                        Sube el CSV único de AppsFlyer (Meta + TikTok juntos -- se separan solos por el bloque
                        FB-/TKT- del nombre). Cost/Impressions/Clicks se traen solos desde la API de Meta/TikTok Ads
                        para el rango elegido.
                    </p>

                    <div class="date-range-row">
                        <div class="date-field">
                            <label for="desdeInput">Desde</label>
                            <input id="desdeInput" v-model="desde" type="date" :disabled="!preview" />
                        </div>
                        <div class="date-field">
                            <label for="hastaInput">Hasta</label>
                            <input id="hastaInput" v-model="hasta" type="date" :disabled="!preview" />
                        </div>
                        <span class="date-hint">
                            Se autocompleta con el rango de fechas del CSV. Solo filtra instalaciones/nuevos
                            clientes/órdenes/venta real (AppsFlyer) — costo, CTR y CPM de la API reflejan todo el CSV
                            subido, sin fecha propia.
                        </span>
                        <span class="date-range-error">{{ rangoError }}</span>
                        <span class="date-range-nota">
                            {{ esRangoParcialPreview ? 'Rango parcial: NC/CAC/Orders/CPO/CPI quedarán null -- no se pueden prorratear a un sub-rango.' : '' }}
                        </span>
                    </div>

                    <div class="tipo-cuenta-row">
                        <span class="tipo-cuenta-label">Tipo de cuenta</span>
                        <TipoCuentaToggle :counts="preview?.tipoCuentaCounts || {}" readonly />
                        <span class="date-hint">DTC (paid propio de TaDa) y BRD (integración de marca) son negocios distintos — nunca se suman juntos.</span>
                    </div>

                    <div class="import-grid">
                        <div class="import-col">
                            <label for="csvInput">CSV de AppsFlyer</label>
                            <span class="cols-hint">
                                Campaign · Campaign ID · Ad · Ad ID · Install Day · Installs (sum) · Activity - Event
                                Counter - First Order (sum) · Activity - Event Counter - Order Submitted (sum).
                            </span>
                            <label for="csvInput" class="file-select-btn">
                                {{ nombreArchivo ? 'Seleccionar otro archivo' : 'Seleccionar archivo' }}
                            </label>
                            <input
                                id="csvInput"
                                ref="archivoInput"
                                type="file"
                                accept=".csv"
                                class="file-select-input"
                                @change="onArchivoSeleccionado"
                            />
                            <span v-if="nombreArchivo" class="cols-hint archivo-cargado">📄 {{ nombreArchivo }}</span>
                            <span v-if="cargandoPreview" class="cols-hint">Leyendo CSV…</span>
                            <span v-else-if="previewError" class="cols-hint" style="color: var(--coral)">{{ previewError }}</span>
                            <span v-else-if="preview" class="cols-hint">
                                {{ preview.totalAdIds }} Ad ID únicos · {{ preview.meta }} Meta, {{ preview.tiktok }} TikTok, {{ preview.excluidos }} excluido(s)
                                <template v-if="preview.problemas > 0">· {{ preview.problemas }} problema(s)</template>
                            </span>
                            <span v-else class="cols-hint">Sin archivos seleccionados.</span>
                        </div>
                        <div class="import-col">
                            <label for="ncTotalRealMetaInput">Venta real — Meta</label>
                            <span class="cols-hint">
                                NC y Orders REALES del negocio para este rango (no los de AppsFlyer) -- se reparten
                                entre los ads de Meta según su participación en AppsFlyer. CAC/CPO salen de acá,
                                nunca del crudo de AppsFlyer.
                            </span>
                            <input id="ncTotalRealMetaInput" v-model="ncTotalRealMeta" type="number" placeholder="NC total real (Meta)" min="0" />
                            <input id="ordersTotalRealMetaInput" v-model="ordersTotalRealMeta" type="number" placeholder="Orders total real (Meta)" min="0" style="margin-top: 8px" />
                        </div>
                        <div class="import-col">
                            <label for="ncTotalRealTiktokInput">Venta real — TikTok</label>
                            <span class="cols-hint">
                                Mismo criterio, para los ads de TikTok -- Meta y TikTok son cuentas distintas, cada
                                una reparte SU PROPIO total, nunca uno compartido entre las dos.
                            </span>
                            <input id="ncTotalRealTiktokInput" v-model="ncTotalRealTiktok" type="number" placeholder="NC total real (TikTok)" min="0" />
                            <input id="ordersTotalRealTiktokInput" v-model="ordersTotalRealTiktok" type="number" placeholder="Orders total real (TikTok)" min="0" style="margin-top: 8px" />
                        </div>
                    </div>

                    <div class="import-actions">
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="!token || !!rangoError || importando"
                            @click="importar"
                        >
                            {{ importando ? 'Importando…' : 'Limpiar y cargar' }}
                        </button>
                        <span class="import-status">
                            <template v-if="importError">{{ importError }}</template>
                            <template v-else-if="!preview">Aún no se ha cargado nada.</template>
                            <template v-else-if="!resumen">Listo para importar.</template>
                            <template v-else>Importación completa.</template>
                        </span>
                    </div>

                    <div v-if="resumen" class="summary-pills">
                        <span class="pill"><span class="dot" style="background: var(--mint)" /> {{ resumen.creativosTocados }} creativo(s)</span>
                        <span class="pill"><span class="dot" style="background: var(--mint)" /> {{ resumen.resultadosTocados }} resultado(s)</span>
                        <span class="pill"><span class="dot" style="background: var(--amber)" /> {{ resumen.tieneMetaTrue }} con match de costo (tiene_meta)</span>
                        <span v-if="resumen.esRangoParcial" class="pill"><span class="dot" style="background: var(--coral)" /> Rango parcial</span>
                        <span v-if="resumen.problemas > 0" class="pill"><span class="dot" style="background: var(--coral)" /> {{ resumen.problemas }} problema(s)</span>
                        <span v-if="resumen.excluidos > 0" class="pill"><span class="dot" style="background: var(--text-faint)" /> {{ resumen.excluidos }} excluido(s)</span>
                        <span v-if="resumen.sinActividadDescartados > 0" class="pill"><span class="dot" style="background: var(--text-faint)" /> {{ resumen.sinActividadDescartados }} sin actividad (descartado)</span>
                    </div>
                    </template>
                </section>

                <section class="resumen-comparacion">
                    <div class="resumen-comparacion-header">
                        <h2>Resumen por arte</h2>
                        <span v-if="resumenDesde && resumenHasta" class="resumen-comparacion-rango mono">
                            {{ resumenDesde }} → {{ resumenHasta }}
                        </span>
                    </div>
                    <p class="hint">
                        Mismas columnas que las pestañas Overview/Meta Ads/TikTok Ads de tu Google Sheet de QA, una
                        fila por arte por día -- para comparar lado a lado y cazar diferencias.
                    </p>

                    <div v-if="resumenRangoMax" class="resumen-comparacion-controles">
                        <div class="resumen-comparacion-atajos">
                            <button type="button" :class="{ activo: resumenAtajo === '1' }" @click="aplicarAtajo(1)">1 día</button>
                            <button type="button" :class="{ activo: resumenAtajo === '7' }" @click="aplicarAtajo(7)">7 días</button>
                            <button type="button" :class="{ activo: resumenAtajo === '14' }" @click="aplicarAtajo(14)">14 días</button>
                            <button type="button" :class="{ activo: resumenAtajo === '30' }" @click="aplicarAtajo(30)">30 días</button>
                            <button type="button" :class="{ activo: resumenAtajo === 'personalizado' }" @click="resumenAtajo = 'personalizado'">
                                Personalizado
                            </button>
                        </div>
                        <div v-if="resumenAtajo === 'personalizado'" class="resumen-comparacion-personalizado">
                            <input
                                v-model="resumenDesde"
                                type="date"
                                :min="resumenRangoMin"
                                :max="resumenRangoMax"
                                @change="aplicarPersonalizado"
                            />
                            <span>→</span>
                            <input
                                v-model="resumenHasta"
                                type="date"
                                :min="resumenRangoMin"
                                :max="resumenRangoMax"
                                @change="aplicarPersonalizado"
                            />
                        </div>
                    </div>

                    <p v-if="resumenCargando" class="cols-hint">Cargando…</p>
                    <p v-else-if="resumenError" class="cols-hint" style="color: var(--coral)">{{ resumenError }}</p>
                    <p v-else-if="!resumenRangoMax" class="cols-hint">
                        Este país todavía no tiene datos diarios cargados (resultados_diarios) -- disponible por ahora
                        solo para los países con pipeline diario.
                    </p>
                    <p v-else-if="!resumenFilas.length" class="cols-hint">Sin datos para este rango de fechas.</p>
                    <div v-else class="resumen-tabla-wrap">
                        <table class="resumen-tabla">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Arte</th>
                                    <th>Etapa</th>
                                    <th>Cost</th>
                                    <th>Impressions</th>
                                    <th>Clicks</th>
                                    <th>Installs</th>
                                    <th>CPI</th>
                                    <th>NC</th>
                                    <th>CAC</th>
                                    <th>Orders</th>
                                    <th>CPO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="fila in resumenFilas" :key="fila.fecha + '|' + (fila.nombreComun || '(sin clasificar)')">
                                    <td class="mono">{{ fila.fecha }}</td>
                                    <td :class="{ 'resumen-tabla-sin-clasificar': !fila.nombreComun }">
                                        {{ fila.nombreComun || '(sin clasificar)' }}
                                    </td>
                                    <td :class="{ 'resumen-tabla-sin-clasificar': !fila.funnel }">
                                        {{ fila.funnel ? (FUNNEL_LABELS[fila.funnel] || fila.funnel) : '(sin clasificar)' }}
                                    </td>
                                    <td class="mono">{{ formatMoneyExacto(fila.cost) }}</td>
                                    <td class="mono">{{ formatNumeroExacto(fila.impressions) }}</td>
                                    <td class="mono">{{ formatNumeroExacto(fila.clicks) }}</td>
                                    <td class="mono">{{ formatNumeroExacto(fila.installs) }}</td>
                                    <td class="mono">{{ fila.cpi !== null ? formatMoneyExacto(fila.cpi) : '—' }}</td>
                                    <td class="mono">{{ formatNumeroExacto(fila.nc) }}</td>
                                    <td class="mono">{{ fila.cac !== null ? formatMoneyExacto(fila.cac) : '—' }}</td>
                                    <td class="mono">{{ formatNumeroExacto(fila.orders) }}</td>
                                    <td class="mono">{{ fila.cpo !== null ? formatMoneyExacto(fila.cpo) : '—' }}</td>
                                </tr>
                            </tbody>
                            <tfoot v-if="resumenTotales">
                                <tr>
                                    <td colspan="3">Total ({{ resumenDesde }} → {{ resumenHasta }})</td>
                                    <td class="mono">{{ formatMoneyExacto(resumenTotales.cost) }}</td>
                                    <td class="mono">{{ formatNumeroExacto(resumenTotales.impressions) }}</td>
                                    <td class="mono">{{ formatNumeroExacto(resumenTotales.clicks) }}</td>
                                    <td class="mono">{{ formatNumeroExacto(resumenTotales.installs) }}</td>
                                    <td class="mono">{{ resumenTotales.cpi !== null ? formatMoneyExacto(resumenTotales.cpi) : '—' }}</td>
                                    <td class="mono">{{ formatNumeroExacto(resumenTotales.nc) }}</td>
                                    <td class="mono">{{ resumenTotales.cac !== null ? formatMoneyExacto(resumenTotales.cac) : '—' }}</td>
                                    <td class="mono">{{ formatNumeroExacto(resumenTotales.orders) }}</td>
                                    <td class="mono">{{ resumenTotales.cpo !== null ? formatMoneyExacto(resumenTotales.cpo) : '—' }}</td>
                                </tr>
                            </tfoot>
                        </table>
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
    max-width: 1600px;
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
.tab-row {
    display: flex;
    gap: 8px;
    margin: 16px 0 20px;
    border-bottom: 1px solid var(--border, rgba(255, 255, 255, 0.1));
}
.tab-btn {
    background: transparent;
    border: none;
    color: var(--text-faint);
    font-size: 13px;
    font-weight: 600;
    padding: 8px 4px 10px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}
.tab-btn.active {
    color: var(--text);
    border-bottom-color: var(--amber);
}
.title {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 700;
    font-size: 28px;
    margin: 0;
}

/* ---------- resumen por arte (2026-08-28) ---------- */
.resumen-comparacion {
    margin: 24px 0;
    padding: 24px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
}
.resumen-comparacion-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.resumen-comparacion-header h2 {
    font-size: 1.05rem;
    margin: 0;
}
.resumen-comparacion-rango {
    color: var(--text-faint);
    font-size: 0.8rem;
}
.resumen-comparacion > p.hint {
    margin: 4px 0 18px;
    color: var(--text-muted);
    font-size: 0.85rem;
}
.resumen-comparacion-controles {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 18px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
}
.resumen-comparacion-atajos {
    display: flex;
    gap: 6px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 4px;
}
.resumen-comparacion-atajos button {
    background: transparent;
    border: none;
    border-radius: 8px;
    color: var(--text-faint);
    padding: 7px 14px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
}
.resumen-comparacion-atajos button.activo {
    background: var(--surface-2);
    color: var(--amber);
}
.resumen-comparacion-personalizado {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-faint);
}
.resumen-comparacion-personalizado input[type='date'] {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 10px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.78rem;
}
.resumen-tabla-wrap {
    overflow-x: auto;
}
.resumen-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.resumen-tabla th,
.resumen-tabla td {
    padding: 11px 16px;
    text-align: right;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.resumen-tabla th:nth-child(-n + 2),
.resumen-tabla td:nth-child(-n + 2) {
    text-align: left;
}
.resumen-tabla th {
    color: var(--text-faint);
    font-weight: 600;
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.resumen-tabla tfoot td {
    font-weight: 700;
    border-bottom: none;
    border-top: 1px solid var(--border);
}
.resumen-tabla-sin-clasificar {
    color: var(--coral);
}
</style>
