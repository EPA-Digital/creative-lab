<script setup>
import { urlPais } from '@/rutas';
import { fechaCorta, hoyCdmx } from '@/fechas';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import TipoCuentaToggle from '@/Components/Creativo/TipoCuentaToggle.vue';
import ConciliacionImportacion from '@/Components/ConciliacionImportacion.vue';
import DatosMensuales from '@/Components/DatosMensuales.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import InfoTip from '@/Components/InfoTip.vue';
import ProgresoImportacion from '@/Components/ProgresoImportacion.vue';
import { confirmar, mostrarAlerta } from '@/avisos';
import { etiquetaMes, seguirTareaMedios } from '@/seguimientoMedios';
import { formatMoneyExacto, formatNumeroExacto, etiquetaFunnel } from '@/motor';

// Puerto del import-panel de meta.html/tiktok.html ("Cargar datos") -- el
// hueco real de la migración: antes no había dónde teclear ncTotalReal/
// ordersTotalReal ni ver el rango de fechas del CSV, todo dependía de
// correr `importar:csv` de memoria por consola. Este panel llama a los
// mismos endpoints que usa ImportadorDatos (la clase que también usa el
// comando de consola) -- nunca reimplementa el parseo/cruce/agrupación acá.
const props = defineProps({
    pais: { type: String, required: true },
    // YYYY-MM del mes más viejo importable; null = sin límite (superadmin).
    // Solo UX -- el backend rechaza igual (ImportarDatosController::
    // rechazarMesHistorico).
    mesMinimoImportable: { type: String, default: null },
    // Eliminar la data de un mes -- solo superadmin (Gate
    // 'eliminar-mes-importado'); solo UX, el backend lo exige igual.
    puedeEliminarMes: { type: Boolean, default: false },
});

// Los totales reales son conteos -- un decimal casi siempre es un separador
// de miles mal escrito ("1.039" en vez de 1039), que reparte casi nada y
// deja NC en 0 (caso real Ecuador sept 2026). El backend también exige
// enteros; esto solo avisa antes de mandar.
function avisoTotales(...valores) {
    const decimal = valores.find((v) => v !== '' && v !== null && !Number.isInteger(Number(v)));
    return decimal === undefined
        ? ''
        : `"${decimal}" no es un número entero. Escribe el total sin separador de miles (ej. 1039).`;
}
const apiTotalesInvalidos = computed(() =>
    !!avisoTotales(apiNcTotalRealMeta.value, apiOrdersTotalRealMeta.value, apiNcTotalRealTiktok.value, apiOrdersTotalRealTiktok.value),
);
const csvTotalesInvalidos = computed(() =>
    !!avisoTotales(ncTotalRealMeta.value, ordersTotalRealMeta.value, ncTotalRealTiktok.value, ordersTotalRealTiktok.value),
);

function esMesBloqueado(mes) {
    return !!(props.mesMinimoImportable && mes && mes < props.mesMinimoImportable);
}
const fechaMinimaImportable = computed(() => (props.mesMinimoImportable ? `${props.mesMinimoImportable}-01` : null));
const avisoMesBloqueado = computed(() =>
    props.mesMinimoImportable
        ? `Solo superadmin puede importar meses anteriores a ${formatMesLargo(props.mesMinimoImportable)}.`
        : '',
);
function formatMesLargo(mes) {
    const [anio, m] = mes.split('-').map(Number);
    return new Date(anio, m - 1, 1).toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
}

// Tab "api" es la vista por defecto (2026-08-12): reemplaza la subida de CSV
// como forma principal de cargar datos -- trae AppsFlyer en vivo vía
// ImportadorDatos::importarDesdeApi(), mismo pipeline que
// `importar:appsflyer-api` por consola. El tab "csv" es el flujo viejo,
// intacto, que queda como fallback manual (API caída, corrección puntual).
const tab = ref('api');

// "Datos por mes" (2026-10-05) -- "Actualizar mes" lleva al tab "Por API"
// con ese mes ya elegido (reimportar reemplaza la data del mes); la tabla
// se recarga sola al terminar cualquier importación.
const datosMensuales = ref(null);
function actualizarMes(mes) {
    tab.value = 'api';
    apiMes.value = mes;
    apiResumen.value = null;
    apiImportError.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

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
    // "Hoy" en CDMX, no en el horario de la computadora.
    const [anio, mes] = hoyCdmx().split('-').map(Number);
    const anterior = new Date(anio, mes - 2, 1);
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
const apiMesBloqueado = computed(() => esMesBloqueado(apiMes.value));
function mesActualIso() {
    return hoyCdmx().slice(0, 7);
}

// Antes de importar (2026-11-11): resumen de qué se va a cargar y qué se
// reemplaza. La última carga que cubre un día gana; sin venta real, cada día
// conserva la que ya tenía.
function confirmarImportacion({ desde: d, hasta: h, origen, totales, notas = [] }) {
    const dia = (f) => fechaCorta(`${f}T12:00:00Z`);
    const rango = d === h ? dia(d) : `${dia(d)} al ${dia(h)}`;
    const conReal = [];
    for (const [nombre, nc, orders] of totales) {
        if (nc !== '' || orders !== '') {
            conReal.push(`${nombre}: ${nc === '' ? '—' : formatNumeroExacto(Number(nc))} NC · ${orders === '' ? '—' : formatNumeroExacto(Number(orders))} órdenes.`);
        }
    }
    const detalle = [
        'Si ya hay datos de estos días, se reemplazan con esta carga.',
        conReal.length
            ? 'La venta real se reparte entre los anuncios y días de este rango:'
            : 'Sin venta real: cada día conserva la venta real que ya tenía.',
        ...conReal,
        ...notas,
    ];
    return confirmar({
        tipo: 'pregunta',
        titulo: `¿Importar ${rango}?`,
        texto: `${origen} · ${etiquetaMes(d.slice(0, 7))}`,
        detalle,
        confirmar: 'Sí, importar',
    });
}

async function importarApi() {
    if (!apiMes.value || apiMesBloqueado.value || apiTotalesInvalidos.value || apiImportando.value) return;
    const ok = await confirmarImportacion({
        desde: apiDesde.value,
        hasta: apiHasta.value,
        origen: 'Por API (Meta, TikTok y AppsFlyer)',
        notas: apiEsMesActual.value ? ['Mes en curso: los últimos días pueden estar subcontados.'] : [],
        totales: [
            ['Meta', apiNcTotalRealMeta.value, apiOrdersTotalRealMeta.value],
            ['TikTok', apiNcTotalRealTiktok.value, apiOrdersTotalRealTiktok.value],
        ],
    });
    if (!ok) return;
    apiImportando.value = true;
    apiImportError.value = '';
    apiResumen.value = null;

    try {
        // Corre en background (ProcesarImportacionApi, 2026-10-06): el POST
        // solo encola y devuelve el id; el avance se sigue con polling.
        const { data } = await axios.post(`${urlPais(props.pais)}/importar/api`, {
            desde: apiDesde.value,
            hasta: apiHasta.value,
            nc_total_real_meta: apiNcTotalRealMeta.value === '' ? null : apiNcTotalRealMeta.value,
            orders_total_real_meta: apiOrdersTotalRealMeta.value === '' ? null : apiOrdersTotalRealMeta.value,
            nc_total_real_tiktok: apiNcTotalRealTiktok.value === '' ? null : apiNcTotalRealTiktok.value,
            orders_total_real_tiktok: apiOrdersTotalRealTiktok.value === '' ? null : apiOrdersTotalRealTiktok.value,
        });
        seguirImportacion(data.importacionId, {
            alTerminar: (resultado) => {
                apiResumen.value = resultado;
                apiImportando.value = false;
            },
            alFallar: (mensaje) => {
                apiImportError.value = mensaje;
                apiImportando.value = false;
            },
        }, apiMes.value);
    } catch (err) {
        apiImportError.value = err.response?.data?.error || err.response?.data?.message || 'No se pudo importar.';
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
// true desde que el POST inicial confirma que el Job quedó despachado hasta
// que el polling ve estado!=='procesando' -- separado de `importando` (que
// cubre TODO el ciclo, incluido el POST inicial) solo para distinguir el
// texto que ve el usuario ("Importando…" vs "Procesando en background…").
const procesando = ref(false);
const importacionId = ref(null);
let pollHandle = null;
const importError = ref('');
const resumen = ref(null);

const TAMANIO_MAXIMO_CSV = 30 * 1024 * 1024;

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

    // Mismo límite que el servidor (30 MB, ver docker/php.ini) -- avisar
    // antes de subir, en vez de esperar la subida completa para fallar.
    if (file.size > TAMANIO_MAXIMO_CSV) {
        previewError.value = `El archivo pesa ${(file.size / 1024 / 1024).toFixed(1)} MB y el máximo es 30 MB. Exporta el CSV por un rango más corto o filtrado por país.`;
        return;
    }
    cargandoPreview.value = true;

    try {
        const formData = new FormData();
        formData.append('archivo', file);
        const { data } = await axios.post(`${urlPais(props.pais)}/importar/previsualizar`, formData);
        preview.value = data;
        token.value = data.token;
        desde.value = data.rangoMin || '';
        hasta.value = data.rangoMax || '';
    } catch (err) {
        previewError.value = err.response?.status === 413
            ? 'El archivo pesa más de 30 MB. Exporta el CSV por un rango más corto o filtrado por país.'
            : err.response?.data?.message || 'No se pudo leer el CSV.';
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
    if (esMesBloqueado(desde.value.slice(0, 7))) {
        return avisoMesBloqueado.value;
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

// El import corre en un Job en background (ver ProcesarImportacionCsv) --
// un CSV real (~250 creativos) tarda más que el timeout de Cloud Run. El
// POST solo despacha y devuelve un id al instante; acá se hace polling de
// estadoImportacion() hasta que termina, mismo shape final que antes
// (resumen.value) para no tocar el resto de la plantilla.
async function importar() {
    if (rangoError.value || csvTotalesInvalidos.value || !token.value || importando.value) return;
    const ok = await confirmarImportacion({
        desde: desde.value,
        hasta: hasta.value,
        origen: `CSV · ${nombreArchivo.value}`,
        notas: esRangoParcialPreview.value ? ['Rango parcial: NC, órdenes, CAC, CPO y CPI quedan vacíos.'] : [],
        totales: [
            ['Meta', ncTotalRealMeta.value, ordersTotalRealMeta.value],
            ['TikTok', ncTotalRealTiktok.value, ordersTotalRealTiktok.value],
        ],
    });
    if (!ok) return;
    importando.value = true;
    importError.value = '';
    resumen.value = null;

    try {
        const { data } = await axios.post(`${urlPais(props.pais)}/importar`, {
            token: token.value,
            nombre_archivo: nombreArchivo.value,
            desde: desde.value,
            hasta: hasta.value,
            nc_total_real_meta: ncTotalRealMeta.value === '' ? null : ncTotalRealMeta.value,
            orders_total_real_meta: ordersTotalRealMeta.value === '' ? null : ordersTotalRealMeta.value,
            nc_total_real_tiktok: ncTotalRealTiktok.value === '' ? null : ncTotalRealTiktok.value,
            orders_total_real_tiktok: ordersTotalRealTiktok.value === '' ? null : ordersTotalRealTiktok.value,
        });
        procesando.value = true;
        token.value = null; // el archivo temporal ya se borró en el server
        if (archivoInput.value) archivoInput.value.value = '';
        seguirImportacion(data.importacionId, {
            alTerminar: (resultado) => {
                resumen.value = resultado;
                importando.value = false;
                procesando.value = false;
            },
            alFallar: (mensaje) => {
                importError.value = mensaje;
                importando.value = false;
                procesando.value = false;
            },
        }, desde.value.slice(0, 7));
    } catch (err) {
        importError.value = err.response?.data?.error || err.response?.data?.message || 'No se pudo importar.';
        importando.value = false;
    }
}

// --- Progreso de importación (2026-10-06) -------------------------------
// Compartido por "Por API" y "Subir CSV": los dos corren en un Job y se
// siguen con polling de estadoImportacion(), que devuelve el avance real
// (progreso/etapa) mientras procesa. ProgresoImportacion lo muestra.
const progreso = ref({ activo: false, visible: false, porcentaje: 0, etapa: null, inicio: 0, terminado: false });

function detenerPollEstadoImportacion() {
    if (pollHandle) {
        clearInterval(pollHandle);
        pollHandle = null;
    }
}

function cerrarProgreso() {
    progreso.value = { ...progreso.value, activo: false, visible: false };
}

// Aviso al terminar (2026-10-07, estilo SweetAlert): los datos ya están;
// imágenes y videos siguen guardándose en background -- se sigue esa tarea
// y llega otro aviso cuando estén listos (ver seguimientoMedios.js).
function avisarImportacionCompleta(data, mes) {
    const etiqueta = etiquetaMes(mes);
    if (data.tareaMediosId) {
        seguirTareaMedios({ pais: props.pais, id: data.tareaMediosId });
    }
    mostrarAlerta({
        tipo: 'exito',
        titulo: 'Importación completa',
        texto: data.tareaMediosId
            ? `Los datos de ${etiqueta} ya están cargados y correctos. Las imágenes y videos pueden tardar unos minutos en aparecer; te avisamos cuando estén listos.`
            : `Los datos de ${etiqueta} ya están cargados y correctos.`,
    });
}

function seguirImportacion(id, { alTerminar, alFallar }, mesDeImportacion) {
    detenerPollEstadoImportacion();
    importacionId.value = id;
    progreso.value = { activo: true, visible: true, porcentaje: 0, etapa: null, inicio: Date.now(), terminado: false };

    const fallar = (mensaje) => {
        detenerPollEstadoImportacion();
        cerrarProgreso();
        alFallar(mensaje);
    };

    pollHandle = setInterval(async () => {
        try {
            const { data } = await axios.get(`${urlPais(props.pais)}/importar/estado/${id}`);
            if (data.estado === 'procesando') {
                progreso.value.porcentaje = data.progreso ?? 0;
                progreso.value.etapa = data.etapa ?? null;
                return;
            }

            detenerPollEstadoImportacion();
            progreso.value.porcentaje = 100;
            progreso.value.terminado = true;
            alTerminar(data);
            datosMensuales.value?.recargar();
            setTimeout(() => {
                cerrarProgreso();
                avisarImportacionCompleta(data, mesDeImportacion);
            }, 1200);
        } catch (err) {
            // estadoImportacion() responde 422 cuando la importación falló --
            // ese mensaje es el que importa mostrar, no uno genérico.
            fallar(err.response?.data?.estado === 'error'
                ? err.response.data.error || 'No se pudo importar.'
                : 'No se pudo consultar el estado de la importación.');
        }
    }, 2000);
}

onUnmounted(() => detenerPollEstadoImportacion());

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

// Filtros (2026-11-11), los mismos que "Datos por mes". Se aplican en el
// servidor para que el total de abajo también los respete.
const resumenAgrupar = ref('arte');
const resumenPlataforma = ref('TODAS');
const resumenFunnel = ref('TODOS');
const resumenBuscar = ref('');
const RESUMEN_AGRUPAR_OPCIONES = [
    { key: 'arte', label: 'Por arte (como el sheet)' },
    { key: 'anuncio', label: 'Por anuncio (Ad ID)' },
];
const RESUMEN_PLATAFORMA_OPCIONES = [
    { key: 'TODAS', label: 'Todas' },
    { key: 'meta', label: 'Meta' },
    { key: 'tiktok', label: 'TikTok' },
];
const resumenFunnelOpciones = computed(() => [
    { key: 'TODOS', label: 'Todas' },
    ...['AWA', 'CONS', 'CNV', 'LOY'].map((f) => ({ key: f, label: etiquetaFunnel(f) || f })),
    { key: 'SIN', label: 'Sin clasificar' },
]);
const PLATAFORMA_CORTA = { meta: 'Meta', tiktok: 'TikTok', ambas: 'Meta + TikTok' };

// Columnas y orden por clic, como en "Datos por mes".
const resumenColumnas = computed(() => [
    { campo: 'fecha', label: 'Fecha' },
    { campo: 'nombreComun', label: 'Arte' },
    ...(resumenAgrupar.value === 'anuncio' ? [{ campo: 'adId', label: 'Ad ID' }] : []),
    { campo: 'plataforma', label: 'Plataforma' },
    { campo: 'funnel', label: 'Etapa' },
    ...(resumenAgrupar.value === 'arte' ? [{ campo: 'anuncios', label: 'Anuncios', num: true }] : []),
    { campo: 'cost', label: 'Cost', num: true },
    { campo: 'impressions', label: 'Impressions', num: true },
    { campo: 'clicks', label: 'Clicks', num: true },
    { campo: 'installs', label: 'Installs', num: true },
    { campo: 'cpi', label: 'CPI', num: true },
    { campo: 'nc', label: 'NC', num: true },
    { campo: 'cac', label: 'CAC', num: true },
    { campo: 'orders', label: 'Órdenes', num: true },
    { campo: 'cpo', label: 'CPO', num: true },
]);
const resumenOrden = ref({ campo: 'fecha', dir: 'desc' });
function ordenarResumen(col) {
    resumenOrden.value = resumenOrden.value.campo === col.campo
        ? { campo: col.campo, dir: resumenOrden.value.dir === 'asc' ? 'desc' : 'asc' }
        : { campo: col.campo, dir: col.num ? 'desc' : 'asc' };
}
const resumenFilasOrdenadas = computed(() => {
    const { campo, dir } = resumenOrden.value;
    const signo = dir === 'asc' ? 1 : -1;
    return [...resumenFilas.value].sort((a, b) => {
        const va = a[campo];
        const vb = b[campo];
        // null/vacío siempre al final, sin importar la dirección.
        if (va === null || va === undefined || va === '') return 1;
        if (vb === null || vb === undefined || vb === '') return -1;
        return (typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb))) * signo;
    });
});

async function cargarResumen(params = {}) {
    resumenCargando.value = true;
    resumenError.value = '';
    try {
        const { data } = await axios.get(`${urlPais(props.pais)}/importar/resumen`, {
            params: {
                desde: resumenDesde.value || undefined,
                hasta: resumenHasta.value || undefined,
                ...params,
                agrupar: resumenAgrupar.value,
                plataforma: resumenPlataforma.value,
                funnel: resumenFunnel.value,
                buscar: resumenBuscar.value.trim() || undefined,
            },
        });
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

function recargarResumenFiltrado() {
    cargarResumen();
}
let resumenBuscarTimer = null;
watch(resumenBuscar, () => {
    clearTimeout(resumenBuscarTimer);
    resumenBuscarTimer = setTimeout(recargarResumenFiltrado, 300);
});
watch([resumenAgrupar, resumenPlataforma, resumenFunnel], recargarResumenFiltrado);

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
                    <h2 class="titulo-con-info">
                        Cargar datos
                        <InfoTip v-if="tab === 'api'" etiqueta="Sobre la importación por API">
                            Trae AppsFlyer en vivo (sin subir nada) + costo/impresiones/clicks de Meta/TikTok para el mes
                            elegido. Mismo pipeline que importar:appsflyer-api por consola.
                        </InfoTip>
                        <InfoTip v-else etiqueta="Sobre la importación por CSV">
                            Sube el CSV único de AppsFlyer (Meta + TikTok juntos: se separan solos por el bloque FB-/TKT- del
                            nombre). Cost/Impressions/Clicks se traen solos desde la API de Meta/TikTok Ads para el rango elegido.
                        </InfoTip>
                    </h2>

                    <div class="tab-row">
                        <button type="button" class="tab-btn" :class="{ active: tab === 'api' }" @click="tab = 'api'">
                            Por API
                        </button>
                        <button type="button" class="tab-btn" :class="{ active: tab === 'csv' }" @click="tab = 'csv'">
                            Subir CSV
                        </button>
                    </div>

                    <template v-if="tab === 'api'">

                        <div class="date-range-row">
                            <div class="date-field">
                                <label for="apiMesInput" class="label-con-info">
                                    Mes
                                    <InfoTip etiqueta="Qué rango se importa">
                                        Se importa el mes completo ({{ apiDesde }} a {{ apiHasta }}). Para corregir un mes a mano o
                                        un rango parcial, usa la pestaña "Subir CSV".
                                    </InfoTip>
                                </label>
                                <input id="apiMesInput" v-model="apiMes" type="month" :min="mesMinimoImportable" />
                            </div>
                            <span v-if="apiMesBloqueado" class="date-range-error">{{ avisoMesBloqueado }}</span>
                            <span v-if="apiEsMesActual" class="date-range-nota">
                                Mes en curso: los últimos días pueden estar subcontados -- AppsFlyer todavía está
                                recibiendo conversiones de esos días.
                            </span>
                        </div>

                        <div class="import-grid">
                            <div class="import-col">
                                <label for="apiNcTotalRealMetaInput" class="label-con-info">
                                    Venta real — Meta
                                    <InfoTip etiqueta="Sobre la venta real">NC y Orders REALES del negocio para este mes (no los de AppsFlyer): se reparten entre los ads de Meta según su participación en AppsFlyer. CAC/CPO salen de acá, nunca del crudo de AppsFlyer.</InfoTip>
                                </label>
                                <input id="apiNcTotalRealMetaInput" v-model="apiNcTotalRealMeta" type="number" placeholder="NC total real (Meta)" min="0" step="1" />
                                <input id="apiOrdersTotalRealMetaInput" v-model="apiOrdersTotalRealMeta" type="number" placeholder="Orders total real (Meta)" min="0" step="1" style="margin-top: 8px" />
                                <span class="date-range-error">{{ avisoTotales(apiNcTotalRealMeta, apiOrdersTotalRealMeta) }}</span>
                            </div>
                            <div class="import-col">
                                <label for="apiNcTotalRealTiktokInput" class="label-con-info">
                                    Venta real — TikTok
                                    <InfoTip etiqueta="Sobre la venta real">Mismo criterio, para los ads de TikTok: Meta y TikTok son cuentas distintas, cada una reparte SU PROPIO total, nunca uno compartido entre las dos.</InfoTip>
                                </label>
                                <input id="apiNcTotalRealTiktokInput" v-model="apiNcTotalRealTiktok" type="number" placeholder="NC total real (TikTok)" min="0" step="1" />
                                <input id="apiOrdersTotalRealTiktokInput" v-model="apiOrdersTotalRealTiktok" type="number" placeholder="Orders total real (TikTok)" min="0" step="1" style="margin-top: 8px" />
                                <span class="date-range-error">{{ avisoTotales(apiNcTotalRealTiktok, apiOrdersTotalRealTiktok) }}</span>
                            </div>
                        </div>

                        <div class="import-actions">
                            <button type="button" class="btn-primary" :disabled="!apiMes || apiMesBloqueado || apiTotalesInvalidos || apiImportando" @click="importarApi">
                                {{ apiImportando ? 'Importando…' : 'Importar' }}
                            </button>
                            <span class="import-status">
                                <template v-if="apiImportError">{{ apiImportError }}</template>
                                <template v-else-if="progreso.activo && !progreso.visible">
                                    Importando en segundo plano ({{ progreso.porcentaje }}%{{ progreso.etapa ? ` · ${progreso.etapa}` : '' }}).
                                    <button type="button" class="link-progreso" @click="progreso.visible = true">Ver progreso</button>
                                </template>
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
                        <div v-if="apiResumen?.conciliacion" class="conciliacion-wrap">
                            <h2>Double check de la importación</h2>
                            <ConciliacionImportacion :conciliacion="apiResumen.conciliacion" />
                        </div>
                    </template>

                    <template v-else>

                    <div class="date-range-row">
                        <div class="date-field">
                            <label for="desdeInput" class="label-con-info">
                                Desde
                                <InfoTip etiqueta="Sobre el rango de fechas">
                                    Se autocompleta con el rango de fechas del CSV. Solo filtra instalaciones/nuevos
                                    clientes/órdenes/venta real (AppsFlyer); costo, CTR y CPM de la API reflejan todo el CSV
                                    subido, sin fecha propia.
                                </InfoTip>
                            </label>
                            <input id="desdeInput" v-model="desde" type="date" :min="fechaMinimaImportable" :disabled="!preview" />
                        </div>
                        <div class="date-field">
                            <label for="hastaInput">Hasta</label>
                            <input id="hastaInput" v-model="hasta" type="date" :min="fechaMinimaImportable" :disabled="!preview" />
                        </div>
                        <span class="date-range-error">{{ rangoError }}</span>
                        <span class="date-range-nota">
                            {{ esRangoParcialPreview ? 'Rango parcial: NC/CAC/Orders/CPO/CPI quedarán null -- no se pueden prorratear a un sub-rango.' : '' }}
                        </span>
                    </div>

                    <!-- DTC/BRD es propio de TaDa (ver opcionesTipoCuenta). -->
                    <div v-if="usePage().props.cuentaActual?.slug === 'tada'" class="tipo-cuenta-row">
                        <span class="tipo-cuenta-label label-con-info">
                            Tipo de cuenta
                            <InfoTip etiqueta="Sobre el tipo de cuenta">
                                {{ pais === 'ecuador' ? 'DTC (paid propio de TaDa) y BRD (marcas de BI) son negocios distintos: nunca se suman juntos.' : 'Paid propio de TaDa.' }}
                            </InfoTip>
                        </span>
                        <TipoCuentaToggle :pais="pais" :counts="preview?.tipoCuentaCounts || {}" readonly />
                    </div>

                    <div class="import-grid">
                        <div class="import-col">
                            <label for="csvInput" class="label-con-info">
                                CSV de AppsFlyer
                                <InfoTip etiqueta="Columnas que necesita el CSV">
                                    Columnas: Campaign · Campaign ID · Ad · Ad ID · Install Day · Installs (sum) · Activity - Event
                                    Counter - First Order (sum) · Activity - Event Counter - Order Submitted (sum).
                                </InfoTip>
                            </label>
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
                            <span v-if="preview?.plataformasSinCuenta?.length" class="cols-hint aviso-sin-cuenta">
                                Este país no tiene cuenta publicitaria activa de
                                {{ preview.plataformasSinCuenta.map((p) => (p === 'meta' ? 'Meta' : 'TikTok')).join(' ni de ') }}:
                                esos anuncios no se van a importar. Un superadmin las agrega en Ajustes → Cuentas publicitarias.
                            </span>
                        </div>
                        <div class="import-col">
                            <label for="ncTotalRealMetaInput" class="label-con-info">
                                Venta real — Meta
                                <InfoTip etiqueta="Sobre la venta real">NC y Orders REALES del negocio para este rango (no los de AppsFlyer): se reparten entre los ads de Meta según su participación en AppsFlyer. CAC/CPO salen de acá, nunca del crudo de AppsFlyer.</InfoTip>
                            </label>
                            <input id="ncTotalRealMetaInput" v-model="ncTotalRealMeta" type="number" placeholder="NC total real (Meta)" min="0" step="1" />
                            <input id="ordersTotalRealMetaInput" v-model="ordersTotalRealMeta" type="number" placeholder="Orders total real (Meta)" min="0" step="1" style="margin-top: 8px" />
                            <span class="date-range-error">{{ avisoTotales(ncTotalRealMeta, ordersTotalRealMeta) }}</span>
                        </div>
                        <div class="import-col">
                            <label for="ncTotalRealTiktokInput" class="label-con-info">
                                Venta real — TikTok
                                <InfoTip etiqueta="Sobre la venta real">Mismo criterio, para los ads de TikTok: Meta y TikTok son cuentas distintas, cada una reparte SU PROPIO total, nunca uno compartido entre las dos.</InfoTip>
                            </label>
                            <input id="ncTotalRealTiktokInput" v-model="ncTotalRealTiktok" type="number" placeholder="NC total real (TikTok)" min="0" step="1" />
                            <input id="ordersTotalRealTiktokInput" v-model="ordersTotalRealTiktok" type="number" placeholder="Orders total real (TikTok)" min="0" step="1" style="margin-top: 8px" />
                            <span class="date-range-error">{{ avisoTotales(ncTotalRealTiktok, ordersTotalRealTiktok) }}</span>
                        </div>
                    </div>

                    <div class="import-actions">
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="!token || !!rangoError || csvTotalesInvalidos || importando"
                            @click="importar"
                        >
                            {{ procesando ? 'Procesando…' : importando ? 'Importando…' : 'Limpiar y cargar' }}
                        </button>
                        <span class="import-status">
                            <template v-if="importError">{{ importError }}</template>
                            <template v-else-if="progreso.activo && !progreso.visible">
                                Importando en segundo plano ({{ progreso.porcentaje }}%{{ progreso.etapa ? ` · ${progreso.etapa}` : '' }}).
                                <button type="button" class="link-progreso" @click="progreso.visible = true">Ver progreso</button>
                            </template>
                            <template v-else-if="procesando">Procesando en background -- esto puede tardar unos minutos…</template>
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
                    <div v-if="resumen?.conciliacion" class="conciliacion-wrap">
                        <h2>Double check de la importación</h2>
                        <ConciliacionImportacion :conciliacion="resumen.conciliacion" />
                    </div>
                    </template>
                </section>

                <section class="import-panel">
                    <h2 class="titulo-con-info">
                        Datos por mes
                        <InfoTip etiqueta="Sobre Datos por mes">
                            Lo que está guardado de cada mes importado, con las mismas columnas que el sheet de QA. Se puede
                            filtrar, ordenar (clic en la columna) y descargar en CSV para abrir en Excel.
                        </InfoTip>
                    </h2>
                    <DatosMensuales ref="datosMensuales" :pais="pais" :puede-eliminar="puedeEliminarMes" @actualizar="actualizarMes" />
                </section>

                <section class="resumen-comparacion">
                    <div class="resumen-comparacion-header">
                        <h2 class="titulo-con-info">
                            Resumen por arte
                            <InfoTip etiqueta="Sobre el Resumen por arte">
                                Lo mismo que "Datos por mes", pero por día: una fila por arte, plataforma y etapa (o por
                                anuncio), con las columnas del sheet de QA para comparar lado a lado y cazar diferencias.
                            </InfoTip>
                        </h2>
                        <span v-if="resumenDesde && resumenHasta" class="resumen-comparacion-rango mono">
                            {{ resumenDesde }} → {{ resumenHasta }}
                        </span>
                    </div>

                    <div v-if="resumenRangoMax" class="resumen-filtros resumen-comparacion-filtros">
                        <FiltroDropdown v-model="resumenAgrupar" label="Ver" :options="RESUMEN_AGRUPAR_OPCIONES" />
                        <FiltroDropdown v-model="resumenPlataforma" label="Plataforma" :options="RESUMEN_PLATAFORMA_OPCIONES" />
                        <FiltroDropdown v-model="resumenFunnel" label="Etapa" :options="resumenFunnelOpciones" />
                        <div class="resumen-buscador">
                            <label for="resumenBuscador" class="filtro-label">Buscar</label>
                            <input id="resumenBuscador" v-model="resumenBuscar" type="search" placeholder="Nombre, Ad ID, campaña…" />
                        </div>
                    </div>

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
                    <p v-else-if="!resumenFilas.length" class="cols-hint">Sin datos para este rango de fechas con estos filtros.</p>
                    <div v-else class="resumen-tabla-wrap" :class="{ cargando: resumenCargando }">
                        <table class="resumen-tabla">
                            <thead>
                                <tr>
                                    <th
                                        v-for="col in resumenColumnas"
                                        :key="col.campo"
                                        :class="{ num: col.num, activa: resumenOrden.campo === col.campo }"
                                        :aria-sort="resumenOrden.campo === col.campo ? (resumenOrden.dir === 'asc' ? 'ascending' : 'descending') : 'none'"
                                    >
                                        <button type="button" @click="ordenarResumen(col)">
                                            {{ col.label }}
                                            <span class="resumen-flecha">{{ resumenOrden.campo === col.campo ? (resumenOrden.dir === 'asc' ? '▲' : '▼') : '' }}</span>
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="fila in resumenFilasOrdenadas" :key="fila.fecha + '|' + (fila.adId || fila.nombreComun || '') + '|' + fila.plataforma + '|' + fila.funnel">
                                    <td class="mono">{{ fila.fecha }}</td>
                                    <td
                                        class="resumen-tabla-arte"
                                        :class="{ 'resumen-tabla-sin-clasificar': !fila.nombreComun }"
                                        :title="[fila.nombreComun, fila.campania].filter(Boolean).join('\n')"
                                    >
                                        {{ fila.nombreComun || '(sin clasificar)' }}
                                    </td>
                                    <td v-if="resumenAgrupar === 'anuncio'" class="mono">{{ fila.adId }}</td>
                                    <td>{{ PLATAFORMA_CORTA[fila.plataforma] || fila.plataforma }}</td>
                                    <td :class="{ 'resumen-tabla-sin-clasificar': !fila.funnel }">
                                        {{ fila.funnel ? (etiquetaFunnel(fila.funnel) || fila.funnel) : '(sin clasificar)' }}
                                    </td>
                                    <td v-if="resumenAgrupar === 'arte'" class="num mono">{{ fila.anuncios }}</td>
                                    <td class="num mono">{{ formatMoneyExacto(fila.cost) }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(fila.impressions) }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(fila.clicks) }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(fila.installs) }}</td>
                                    <td class="num mono">{{ fila.cpi !== null ? formatMoneyExacto(fila.cpi) : '—' }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(fila.nc) }}</td>
                                    <td class="num mono">{{ fila.cac !== null ? formatMoneyExacto(fila.cac) : '—' }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(fila.orders) }}</td>
                                    <td class="num mono">{{ fila.cpo !== null ? formatMoneyExacto(fila.cpo) : '—' }}</td>
                                </tr>
                            </tbody>
                            <tfoot v-if="resumenTotales">
                                <tr>
                                    <td :colspan="resumenColumnas.length - 9">Total ({{ resumenDesde }} → {{ resumenHasta }})</td>
                                    <td class="num mono">{{ formatMoneyExacto(resumenTotales.cost) }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(resumenTotales.impressions) }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(resumenTotales.clicks) }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(resumenTotales.installs) }}</td>
                                    <td class="num mono">{{ resumenTotales.cpi !== null ? formatMoneyExacto(resumenTotales.cpi) : '—' }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(resumenTotales.nc) }}</td>
                                    <td class="num mono">{{ resumenTotales.cac !== null ? formatMoneyExacto(resumenTotales.cac) : '—' }}</td>
                                    <td class="num mono">{{ formatNumeroExacto(resumenTotales.orders) }}</td>
                                    <td class="num mono">{{ resumenTotales.cpo !== null ? formatMoneyExacto(resumenTotales.cpo) : '—' }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <ProgresoImportacion
            v-if="progreso.activo && progreso.visible"
            :porcentaje="progreso.porcentaje"
            :etapa="progreso.etapa"
            :inicio="progreso.inicio"
            :terminado="progreso.terminado"
            @ocultar="progreso.visible = false"
        />
    </DashboardLayout>
</template>

<style scoped>
.titulo-con-info,
.label-con-info {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.resumen-tabla-arte {
    max-width: 380px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.resumen-comparacion-filtros {
    margin: 16px 0 4px;
}
.resumen-buscador {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1 1 220px;
}
.resumen-buscador .filtro-label {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.resumen-buscador input {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.85rem;
}
.resumen-buscador input:focus {
    outline: none;
    border-color: var(--amber);
}
.link-progreso {
    background: none;
    border: none;
    padding: 0;
    color: var(--accent-text);
    font: inherit;
    text-decoration: underline;
    cursor: pointer;
}
.aviso-sin-cuenta {
    color: var(--coral) !important;
    margin-top: 8px;
}
.conciliacion-wrap {
    margin-top: 28px;
    padding-top: 22px;
    border-top: 1px solid var(--border);
}
.conciliacion-wrap > h2 {
    margin-bottom: 16px;
}
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
    color: var(--accent-text);
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
    color: var(--accent-text);
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
    overflow: auto;
    max-height: 70vh;
    margin-top: 16px;
    border: 1px solid var(--border);
    border-radius: 12px;
    transition: opacity 0.15s ease;
}
.resumen-tabla-wrap.cargando {
    opacity: 0.5;
}
.resumen-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.resumen-tabla th,
.resumen-tabla td {
    padding: 9px 12px;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.resumen-tabla .num {
    text-align: right;
}
.resumen-tabla thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface-2);
    padding: 0;
}
.resumen-tabla thead th button {
    width: 100%;
    background: none;
    border: none;
    padding: 9px 12px;
    text-align: inherit;
    font-size: 0.68rem;
    font-weight: 400;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    cursor: pointer;
}
.resumen-tabla thead th button:hover,
.resumen-tabla thead th.activa button {
    color: var(--accent-text);
}
.resumen-tabla thead th button:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: -2px;
}
.resumen-flecha {
    font-size: 0.6rem;
}
.resumen-tabla tbody tr:hover td {
    background: color-mix(in srgb, var(--surface-2) 60%, transparent);
}
.resumen-tabla tfoot td {
    position: sticky;
    bottom: 0;
    background: var(--surface-2);
    font-weight: 700;
    border-bottom: none;
}
.resumen-tabla-sin-clasificar {
    color: var(--coral);
}
</style>
