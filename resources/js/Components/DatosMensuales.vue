<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import { formatMoneyExacto, formatNumeroExacto, FUNNEL_LABELS } from '@/motor';

// "Datos por mes" (2026-10-05, pedido explícito) -- la data importada de un
// mes como si fuera un Excel: mismas columnas que el sheet de QA, por arte
// o por anuncio, con búsqueda, filtros, orden por columna, totales y
// descarga CSV. Ver DatosMensualesController. Eliminar el mes es solo
// superadmin (el backend lo exige igual).
const props = defineProps({
    pais: { type: String, required: true },
    puedeEliminar: { type: Boolean, default: false },
});
const emit = defineEmits(['actualizar']);

const meses = ref([]);
const mes = ref(null);
const agrupar = ref('arte');
const filas = ref([]);
const importaciones = ref([]);
const cargando = ref(false);
const error = ref('');

const busqueda = ref('');
const plataforma = ref('TODAS');
const funnel = ref('TODOS');
const orden = ref({ campo: 'cost', dir: 'desc' });

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`/pais/${props.pais}/importar/mensual`, {
            params: { mes: mes.value, agrupar: agrupar.value },
        });
        meses.value = data.meses;
        mes.value = data.mes;
        filas.value = data.filas;
        importaciones.value = data.importaciones || [];
    } catch (err) {
        error.value = err.response?.data?.message || 'No se pudo cargar la data del mes.';
    } finally {
        cargando.value = false;
    }
}
onMounted(cargar);
defineExpose({ recargar: cargar });

function cambiarMes(nuevo) {
    mes.value = nuevo;
    cargar();
}
function cambiarAgrupar(nuevo) {
    agrupar.value = nuevo;
    cargar();
}

function etiquetaMes(m) {
    const [anio, mm] = m.split('-').map(Number);
    return new Date(anio, mm - 1, 1).toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
}
const mesOpciones = computed(() =>
    meses.value.map((m) => ({ key: m.mes, label: `${etiquetaMes(m.mes)} · ${formatNumeroExacto(m.anuncios)} anuncios` })),
);
const AGRUPAR_OPCIONES = [
    { key: 'arte', label: 'Por arte (como el sheet)' },
    { key: 'anuncio', label: 'Por anuncio (Ad ID)' },
];
const PLATAFORMA_OPCIONES = [
    { key: 'TODAS', label: 'Todas' },
    { key: 'meta', label: 'Meta' },
    { key: 'tiktok', label: 'TikTok' },
];
const funnelOpciones = computed(() => [
    { key: 'TODOS', label: 'Todas' },
    ...[...new Set(filas.value.map((f) => f.funnel || 'SIN'))].map((f) => ({ key: f, label: f === 'SIN' ? 'Sin clasificar' : FUNNEL_LABELS[f] || f })),
]);

// Columnas -- mismas que el sheet de QA; "por anuncio" agrega Ad ID,
// campaña, cuenta y si cruzó con costo de la API.
const COLUMNAS_NUMERICAS = [
    { campo: 'cost', label: 'Cost', tipo: 'money' },
    { campo: 'impressions', label: 'Impressions', tipo: 'num' },
    { campo: 'clicks', label: 'Clicks', tipo: 'num' },
    { campo: 'installs', label: 'Installs', tipo: 'num' },
    { campo: 'cpi', label: 'CPI', tipo: 'money' },
    { campo: 'nc', label: 'NC', tipo: 'num' },
    { campo: 'cac', label: 'CAC', tipo: 'money' },
    { campo: 'orders', label: 'Órdenes', tipo: 'num' },
    { campo: 'cpo', label: 'CPO', tipo: 'money' },
];
const columnasTexto = computed(() =>
    agrupar.value === 'anuncio'
        ? [
              { campo: 'nombreComun', label: 'Nombre común' },
              { campo: 'adId', label: 'Ad ID', mono: true },
              { campo: 'funnel', label: 'Etapa' },
              { campo: 'plataforma', label: 'Plataforma' },
              { campo: 'campania', label: 'Campaña', mono: true },
              { campo: 'cuenta', label: 'Cuenta' },
          ]
        : [
              { campo: 'nombreComun', label: 'Nombre común' },
              { campo: 'funnel', label: 'Etapa' },
              { campo: 'plataforma', label: 'Plataforma' },
              { campo: 'anuncios', label: 'Anuncios', tipo: 'num' },
          ],
);

const filasFiltradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    const resultado = filas.value.filter((f) => {
        if (plataforma.value !== 'TODAS' && f.plataforma !== plataforma.value) return false;
        if (funnel.value !== 'TODOS' && (f.funnel || 'SIN') !== funnel.value) return false;
        if (!q) return true;
        return [f.nombreComun, f.adId, f.campania, f.cuenta].some((v) => (v || '').toLowerCase().includes(q));
    });
    const { campo, dir } = orden.value;
    const signo = dir === 'asc' ? 1 : -1;
    return [...resultado].sort((a, b) => {
        const va = a[campo];
        const vb = b[campo];
        // null/vacío siempre al final, sin importar la dirección.
        if (va === null || va === undefined || va === '') return 1;
        if (vb === null || vb === undefined || vb === '') return -1;
        return (typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb))) * signo;
    });
});

function ordenarPor(campo) {
    orden.value = orden.value.campo === campo
        ? { campo, dir: orden.value.dir === 'asc' ? 'desc' : 'asc' }
        : { campo, dir: typeof filas.value[0]?.[campo] === 'number' ? 'desc' : 'asc' };
}

// Totales SOBRE LO FILTRADO -- ratios siempre desde las sumas, nunca
// promediando (mismo criterio que el backend).
const totales = computed(() => {
    const suma = (campo) => filasFiltradas.value.reduce((s, f) => s + (Number(f[campo]) || 0), 0);
    const hayVenta = (campo) => filasFiltradas.value.some((f) => f[campo] !== null && f[campo] !== undefined);
    const cost = suma('cost');
    const installs = suma('installs');
    const nc = hayVenta('nc') ? suma('nc') : null;
    const orders = hayVenta('orders') ? suma('orders') : null;
    return {
        cost,
        impressions: suma('impressions'),
        clicks: suma('clicks'),
        installs,
        cpi: installs > 0 ? cost / installs : null,
        nc,
        cac: nc ? cost / nc : null,
        orders,
        cpo: orders ? cost / orders : null,
        anuncios: suma('anuncios'),
    };
});

function formato(valor, tipo) {
    if (valor === null || valor === undefined || valor === '') return '—';
    if (tipo === 'money') return formatMoneyExacto(Number(valor));
    if (tipo === 'num') return formatNumeroExacto(Number(valor));
    return valor;
}
function textoCelda(fila, col) {
    if (col.campo === 'funnel') return fila.funnel ? FUNNEL_LABELS[fila.funnel] || fila.funnel : 'Sin clasificar';
    if (col.campo === 'plataforma') return fila.plataforma === 'meta' ? 'Meta' : fila.plataforma === 'tiktok' ? 'TikTok' : fila.plataforma;
    return formato(fila[col.campo], col.tipo);
}

// CSV con BOM -- Excel lo abre directo con acentos correctos. Exporta lo
// filtrado y ordenado, números sin formato (para poder sumar en Excel).
function descargarCsv() {
    const columnas = [...columnasTexto.value, ...COLUMNAS_NUMERICAS];
    const escapar = (v) => {
        const s = v === null || v === undefined ? '' : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const valorCsv = (fila, col) => {
        if (col.campo === 'funnel' || col.campo === 'plataforma') return textoCelda(fila, col);
        const v = fila[col.campo];
        return typeof v === 'number' && col.tipo === 'money' ? v.toFixed(2) : v;
    };
    const lineas = [
        columnas.map((c) => escapar(c.label)).join(','),
        ...filasFiltradas.value.map((f) => columnas.map((c) => escapar(valorCsv(f, c))).join(',')),
    ];
    const blob = new Blob(['﻿' + lineas.join('\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `datos-${props.pais}-${mes.value}-${agrupar.value}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// --- Eliminar mes (solo superadmin) --------------------------------------
const modalEliminar = ref(false);
const confirmacion = ref('');
const eliminando = ref(false);
const eliminarError = ref('');
const eliminadoOk = ref('');

function abrirEliminar() {
    confirmacion.value = '';
    eliminarError.value = '';
    modalEliminar.value = true;
}
async function eliminarMes() {
    if (confirmacion.value !== mes.value || eliminando.value) return;
    eliminando.value = true;
    eliminarError.value = '';
    try {
        const { data } = await axios.delete(`/pais/${props.pais}/importar/mensual/${mes.value}`, {
            data: { confirmacion: confirmacion.value },
        });
        eliminadoOk.value = `Se eliminó ${etiquetaMes(data.mes)}: ${formatNumeroExacto(data.resultados)} resultado(s).`;
        modalEliminar.value = false;
        mes.value = null;
        await cargar();
    } catch (err) {
        eliminarError.value = err.response?.data?.message || 'No se pudo eliminar el mes.';
    } finally {
        eliminando.value = false;
    }
}
</script>

<template>
    <div class="datos-mensuales">
        <div class="resumen-filtros datos-filtros">
            <FiltroDropdown v-if="mesOpciones.length" label="Mes" :model-value="mes" :options="mesOpciones" @update:model-value="cambiarMes" />
            <FiltroDropdown label="Ver" :model-value="agrupar" :options="AGRUPAR_OPCIONES" @update:model-value="cambiarAgrupar" />
            <FiltroDropdown v-model="plataforma" label="Plataforma" :options="PLATAFORMA_OPCIONES" />
            <FiltroDropdown v-model="funnel" label="Etapa" :options="funnelOpciones" />
            <div class="datos-buscador">
                <label for="datosBuscador" class="filtro-label">Buscar</label>
                <input id="datosBuscador" v-model="busqueda" type="text" placeholder="Nombre, Ad ID, campaña…" />
            </div>
        </div>

        <div v-if="mes" class="datos-acciones">
            <span class="import-status">
                {{ formatNumeroExacto(filasFiltradas.length) }} fila(s)
                <template v-if="importaciones[0]">
                    · última importación {{ new Date(importaciones[0].creado_en).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) }}
                    ({{ importaciones[0].origen === 'csv' ? 'CSV' : 'API' }})
                </template>
            </span>
            <div class="datos-botones">
                <button type="button" class="modal-copy-btn" :disabled="!filasFiltradas.length" @click="descargarCsv">Descargar CSV</button>
                <button type="button" class="modal-copy-btn" @click="emit('actualizar', mes)">Actualizar mes</button>
                <button v-if="puedeEliminar" type="button" class="btn-eliminar" @click="abrirEliminar">Eliminar mes</button>
            </div>
        </div>
        <p v-if="eliminadoOk" class="datos-ok">{{ eliminadoOk }}</p>

        <p v-if="error" class="datos-error">{{ error }}</p>
        <p v-else-if="cargando && !filas.length" class="empty-note">Cargando…</p>
        <p v-else-if="!mes" class="empty-note">Este país todavía no tiene meses importados.</p>
        <p v-else-if="!filasFiltradas.length" class="empty-note">Ninguna fila coincide con los filtros.</p>

        <div v-else class="datos-tabla-wrap" :class="{ cargando }">
            <table class="datos-tabla">
                <thead>
                    <tr>
                        <th
                            v-for="col in [...columnasTexto, ...COLUMNAS_NUMERICAS]"
                            :key="col.campo"
                            :class="{ num: col.tipo, activa: orden.campo === col.campo }"
                            :aria-sort="orden.campo === col.campo ? (orden.dir === 'asc' ? 'ascending' : 'descending') : 'none'"
                        >
                            <button type="button" @click="ordenarPor(col.campo)">
                                {{ col.label }}
                                <span class="datos-flecha">{{ orden.campo === col.campo ? (orden.dir === 'asc' ? '▲' : '▼') : '' }}</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(fila, i) in filasFiltradas" :key="fila.adId || `${fila.nombreComun}-${fila.funnel}-${fila.plataforma}-${i}`">
                        <td
                            v-for="col in columnasTexto"
                            :key="col.campo"
                            :class="{ mono: col.mono, num: col.tipo, 'datos-campania': col.campo === 'campania' }"
                        >
                            {{ textoCelda(fila, col) }}
                            <span v-if="col.campo === 'nombreComun' && agrupar === 'anuncio' && !fila.conCosto" class="datos-sin-costo">sin costo</span>
                        </td>
                        <td v-for="col in COLUMNAS_NUMERICAS" :key="col.campo" class="num mono">{{ formato(fila[col.campo], col.tipo) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td v-for="(col, i) in columnasTexto" :key="col.campo" :class="{ num: col.tipo, mono: col.tipo }">
                            <template v-if="i === 0">Total</template>
                            <template v-else-if="col.campo === 'anuncios'">{{ formato(totales.anuncios, 'num') }}</template>
                        </td>
                        <td v-for="col in COLUMNAS_NUMERICAS" :key="col.campo" class="num mono">{{ formato(totales[col.campo], col.tipo) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="modalEliminar" class="modal-overlay" @click.self="modalEliminar = false">
            <div class="datos-modal" role="dialog" aria-modal="true" aria-labelledby="eliminarTitulo">
                <h2 id="eliminarTitulo">Eliminar {{ etiquetaMes(mes) }}</h2>
                <p class="hint">
                    Se borran los resultados de este mes para {{ pais }} (y sus datos diarios, si hay). Los creativos, sus
                    nombres amigables y los otros meses no se tocan. No se puede deshacer; para recuperarlo hay que volver a
                    importar el mes.
                </p>
                <label for="confirmacionInput" class="filtro-label">Escribí {{ mes }} para confirmar</label>
                <input id="confirmacionInput" v-model="confirmacion" type="text" :placeholder="mes" autocomplete="off" />
                <p v-if="eliminarError" class="datos-error">{{ eliminarError }}</p>
                <div class="import-actions">
                    <button type="button" class="btn-eliminar datos-btn-confirmar" :disabled="confirmacion !== mes || eliminando" @click="eliminarMes">
                        {{ eliminando ? 'Eliminando…' : 'Eliminar mes' }}
                    </button>
                    <button type="button" class="modal-copy-btn" @click="modalEliminar = false">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.datos-filtros {
    align-items: flex-end;
}
.datos-buscador {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1 1 220px;
}
.filtro-label {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.datos-buscador input,
.datos-modal input {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.85rem;
}
.datos-buscador input:focus,
.datos-modal input:focus {
    outline: none;
    border-color: var(--amber);
}
.datos-acciones {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.datos-botones {
    display: flex;
    gap: 8px;
}
.datos-botones .modal-copy-btn {
    font-size: 0.75rem;
    padding: 5px 10px;
}
.btn-eliminar {
    padding: 5px 10px;
    border: 1px solid var(--coral);
    border-radius: 6px;
    background: none;
    color: var(--coral);
    font-size: 0.75rem;
}
.btn-eliminar:hover:not(:disabled) {
    background: color-mix(in srgb, var(--coral) 12%, transparent);
}
.btn-eliminar:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.datos-tabla-wrap {
    overflow: auto;
    max-height: 70vh;
    border: 1px solid var(--border);
    border-radius: 12px;
    transition: opacity 0.15s ease;
}
.datos-tabla-wrap.cargando {
    opacity: 0.5;
}
.datos-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.datos-tabla th,
.datos-tabla td {
    padding: 9px 12px;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.datos-tabla .num {
    text-align: right;
}
.datos-tabla thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface-2);
    padding: 0;
}
.datos-tabla thead th button {
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
}
.datos-tabla thead th button:hover,
.datos-tabla thead th.activa button {
    color: var(--amber);
}
.datos-flecha {
    font-size: 0.6rem;
}
.datos-tabla tbody tr:hover td {
    background: color-mix(in srgb, var(--surface-2) 60%, transparent);
}
.datos-tabla tfoot td {
    position: sticky;
    bottom: 0;
    background: var(--surface-2);
    font-weight: 700;
}
.datos-campania {
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text-muted);
    font-size: 0.72rem;
}
.datos-sin-costo {
    margin-left: 6px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.6rem;
    text-transform: uppercase;
    color: var(--text-faint);
}
.datos-error {
    color: var(--coral);
    font-size: 0.82rem;
}
.datos-ok {
    color: var(--mint);
    font-size: 0.82rem;
    margin: 0 0 12px;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
    margin: 0;
}
.datos-modal {
    width: 100%;
    max-width: 460px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: var(--shadow);
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.datos-modal h2 {
    font-size: 1.05rem;
    margin: 0;
}
.datos-modal .hint {
    color: var(--text-muted);
    font-size: 0.82rem;
    margin: 0 0 6px;
}
.datos-btn-confirmar {
    padding: 9px 16px;
    font-size: 0.85rem;
}
</style>
