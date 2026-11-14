<script setup>
import { ref, computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { confirmar, mensajeDeError, notificar } from '@/avisos';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import { formatNumeroExacto } from '@/motor';

// Medios data (2026-11-13, pedido explícito): el gasto del mes por medio y
// campaña, como la pestaña data.medios de la hoja. Facebook y TikTok vienen
// de las APIs; Apple Search Ads, RTB House, Google Ads y Jampp, de la
// captura manual en Fuentes.
const props = defineProps({
    pais: String,
    meses: { type: Array, default: () => [] },
    mes: { type: String, default: null },
    filas: { type: Array, default: () => [] },
    mediosManuales: { type: Array, default: () => [] },
});

// --- Gasto de medios sin API (Apple Search, RTB House, Google, Jampp) ---------
// Se captura aquí por fecha y de aquí lo toma Fuentes, igual que Facebook y TikTok.
const hoy = new Date().toISOString().slice(0, 10);
const nuevo = reactive({ medio: props.mediosManuales[0] ?? '', fecha: hoy, gasto: '', campania: '' });
const guardando = ref(false);
const errorGasto = ref('');
async function agregarGasto() {
    errorGasto.value = '';
    guardando.value = true;
    try {
        const { data } = await axios.post(`${location.pathname}/gasto`, { ...nuevo, gasto: String(nuevo.gasto).replace(/[^\d.]/g, '') });
        notificar({ tipo: 'exito', titulo: 'Gasto agregado', texto: `${nuevo.medio} · ${nuevo.fecha} · $${nuevo.gasto}` });
        nuevo.gasto = '';
        nuevo.campania = '';
        router.get(location.pathname, { mes: data.mes }, { preserveScroll: true });
    } catch (err) {
        errorGasto.value = mensajeDeError(err);
    } finally {
        guardando.value = false;
    }
}
function borrarGasto(f) {
    confirmar({
        tipo: 'peligro',
        titulo: 'Borrar gasto',
        texto: `${f.medio} · ${f.fecha} · ${usd(f.costo)}. Fuentes dejará de sumarlo.`,
        confirmar: 'Sí, borrar',
        accion: async () => {
            await axios.delete(`${location.pathname}/gasto/${f.id}`);
            router.reload({ preserveScroll: true });
        },
        exito: 'Gasto borrado',
    });
}

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const etiquetaMes = (m) => `${MESES[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}`;
const mesOpciones = computed(() => props.meses.map((m) => ({ key: m, label: etiquetaMes(m) })));
const cambiarMes = (mes) => router.get(location.pathname, { mes }, { preserveScroll: true });

const usd = (v) => (v == null ? '—' : `$${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
const nf = (v) => (v == null ? '—' : formatNumeroExacto(v));
const opciones = (valores, todos = 'Todos') => [{ key: 'TODOS', label: todos }, ...[...new Set(valores)].sort().map((v) => ({ key: v, label: v }))];

const filtroMedio = ref('TODOS');
const filtroMarca = ref('TODOS');
const busqueda = ref('');
const medioOpciones = computed(() => opciones(props.filas.map((f) => f.medio)));
const marcaOpciones = computed(() => opciones(props.filas.map((f) => f.marca), 'Todas'));

const filtradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.filas.filter((f) => (filtroMedio.value === 'TODOS' || f.medio === filtroMedio.value)
        && (filtroMarca.value === 'TODOS' || f.marca === filtroMarca.value)
        && (!q || (f.campania || '').toLowerCase().includes(q)));
});

// Resumen por medio y marca (DTC = TaDa performance; BRD = marcas BI).
const resumen = computed(() => {
    const porMedio = new Map();
    for (const f of filtradas.value) {
        const r = porMedio.get(f.medio) ?? { medio: f.medio, origen: f.origen, DTC: 0, BRD: 0, otro: 0, total: 0 };
        const k = f.marca === 'DTC' || f.marca === 'BRD' ? f.marca : 'otro';
        r[k] += f.costo;
        r.total += f.costo;
        porMedio.set(f.medio, r);
    }
    const filas = [...porMedio.values()].sort((a, b) => b.total - a.total);
    const t = filas.reduce((s, r) => ({ DTC: s.DTC + r.DTC, BRD: s.BRD + r.BRD, otro: s.otro + r.otro, total: s.total + r.total }), { DTC: 0, BRD: 0, otro: 0, total: 0 });
    return { filas, t };
});

const COLUMNAS = [
    { campo: 'medio', label: 'Medio' },
    { campo: 'fecha', label: 'Fecha' },
    { campo: 'marca', label: 'Marca' },
    { campo: 'campania', label: 'Campaña' },
    { campo: 'funnel', label: 'Etapa' },
    { campo: 'costo', label: 'Inversión', num: true },
    { campo: 'impresiones', label: 'Impresiones', num: true },
    { campo: 'clicks', label: 'Clicks', num: true },
    { campo: 'installs', label: 'Installs', num: true },
    { campo: 'anuncios', label: 'Anuncios', num: true },
];
const orden = ref({ campo: 'costo', dir: 'desc' });
function ordenarPor(campo) {
    orden.value = orden.value.campo === campo ? { campo, dir: orden.value.dir === 'desc' ? 'asc' : 'desc' } : { campo, dir: COLUMNAS.find((c) => c.campo === campo)?.num ? 'desc' : 'asc' };
}
const ordenadas = computed(() => {
    const { campo, dir } = orden.value;
    const s = dir === 'asc' ? 1 : -1;
    return [...filtradas.value].sort((a, b) => {
        const va = a[campo];
        const vb = b[campo];
        if (va == null) return 1;
        if (vb == null) return -1;
        return (typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb))) * s;
    });
});
const totales = computed(() => Object.fromEntries(COLUMNAS.filter((c) => c.num).map((c) => [c.campo, filtradas.value.reduce((t, f) => t + (f[c.campo] ?? 0), 0)])));

function descargarCsv() {
    const esc = (v) => {
        const t = String(v ?? '');
        return /[",\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
    };
    const lineas = [COLUMNAS.map((c) => c.label).join(','), ...ordenadas.value.map((f) => COLUMNAS.map((c) => esc(f[c.campo])).join(','))];
    const url = URL.createObjectURL(new Blob(['﻿' + lineas.join('\n')], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `medios-data-${props.pais}-${props.mes}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <Head :title="`Medios data — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="medios-data">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Medios data</h1>
                        <p class="subtitle">
                            Gasto por medio y campaña. Facebook y TikTok llegan por API; Apple Search Ads, RTB House, Google Ads y Jampp se
                            capturan aquí por fecha. Fuentes toma la inversión de esta vista.
                        </p>
                    </div>
                </header>

                <section v-if="mediosManuales.length" class="panel captura">
                    <h2 class="section-title">Agregar gasto <span class="origen manual mono">Manual</span></h2>
                    <p class="hint">Para los medios que todavía no tienen conexión. Puede ser por día o el total de un periodo en una sola fecha.</p>
                    <form class="captura-form" @submit.prevent="agregarGasto">
                        <label>
                            <span class="filtro-label mono">Medio</span>
                            <select v-model="nuevo.medio" required>
                                <option v-for="m in mediosManuales" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </label>
                        <label>
                            <span class="filtro-label mono">Fecha</span>
                            <input v-model="nuevo.fecha" type="date" required />
                        </label>
                        <label>
                            <span class="filtro-label mono">Gasto (USD)</span>
                            <input v-model="nuevo.gasto" type="text" inputmode="decimal" placeholder="0.00" required />
                        </label>
                        <label class="ancho">
                            <span class="filtro-label mono">Campaña (opcional)</span>
                            <input v-model="nuevo.campania" type="text" placeholder="ECU_DTC_TAD_…_ASA-INSTALL-IOS-BRAND" />
                        </label>
                        <button type="submit" class="btn-primary" :disabled="guardando || !nuevo.gasto">{{ guardando ? 'Guardando…' : 'Agregar' }}</button>
                    </form>
                    <p v-if="errorGasto" class="error">{{ errorGasto }}</p>
                </section>

                <p v-if="!meses.length" class="empty-note">Todavía no hay inversión importada para este país.</p>

                <template v-else>
                    <div class="resumen-filtros filtros">
                        <FiltroDropdown label="Mes" :model-value="mes" :options="mesOpciones" @update:model-value="cambiarMes" />
                        <FiltroDropdown v-model="filtroMedio" label="Medio" :options="medioOpciones" />
                        <FiltroDropdown v-model="filtroMarca" label="Marca" :options="marcaOpciones" />
                        <div class="buscador">
                            <label for="buscadorMedios" class="filtro-label mono">Buscar</label>
                            <input id="buscadorMedios" v-model="busqueda" type="search" placeholder="Campaña…" />
                        </div>
                    </div>

                    <section class="section">
                        <div class="section-header">
                            <h2 class="section-title">Por medio</h2>
                            <span class="section-count mono">{{ etiquetaMes(mes) }}</span>
                        </div>
                        <div class="tabla-scroll panel">
                            <table class="tabla resumen">
                                <thead>
                                    <tr>
                                        <th>Medio</th>
                                        <th>Origen</th>
                                        <th class="num">TaDa (DTC)</th>
                                        <th class="num">Marcas (BRD)</th>
                                        <th class="num">Sin clasificar</th>
                                        <th class="num">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in resumen.filas" :key="r.medio">
                                        <td>{{ r.medio }}</td>
                                        <td><span class="origen mono" :class="r.origen">{{ r.origen === 'api' ? 'API' : 'Manual' }}</span></td>
                                        <td class="num">{{ usd(r.DTC) }}</td>
                                        <td class="num">{{ usd(r.BRD) }}</td>
                                        <td class="num tenue">{{ usd(r.otro) }}</td>
                                        <td class="num fuerte">{{ usd(r.total) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td>Total</td>
                                        <td />
                                        <td class="num">{{ usd(resumen.t.DTC) }}</td>
                                        <td class="num">{{ usd(resumen.t.BRD) }}</td>
                                        <td class="num">{{ usd(resumen.t.otro) }}</td>
                                        <td class="num">{{ usd(resumen.t.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p class="hint">En Fuentes, la inversión de TaDa es la columna DTC (más lo sin clasificar); BRD es de las marcas (canal BI).</p>
                    </section>

                    <section class="section">
                        <div class="section-header">
                            <div class="section-header-titulo">
                                <h2 class="section-title">Por campaña</h2>
                                <span class="section-count mono">{{ formatNumeroExacto(filtradas.length) }} fila{{ filtradas.length === 1 ? '' : 's' }}</span>
                            </div>
                            <button type="button" class="modal-copy-btn" :disabled="!filtradas.length" @click="descargarCsv">Descargar CSV</button>
                        </div>
                        <div class="tabla-scroll panel excel">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th v-for="c in COLUMNAS" :key="c.campo" :class="{ num: c.num, ordenada: orden.campo === c.campo }" @click="ordenarPor(c.campo)">
                                            {{ c.label }}<span v-if="orden.campo === c.campo" class="flecha">{{ orden.dir === 'asc' ? '▲' : '▼' }}</span>
                                        </th>
                                    </tr>
                                    <tr class="fila-total">
                                        <th v-for="(c, i) in COLUMNAS" :key="c.campo" :class="{ num: c.num }">
                                            {{ c.num ? (c.campo === 'costo' ? usd(totales[c.campo]) : nf(totales[c.campo])) : i === 0 ? 'Total' : '' }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(f, i) in ordenadas" :key="i">
                                        <td>{{ f.medio }}<span v-if="f.origen === 'manual'" class="origen manual mono">Manual</span></td>
                                        <td class="mono tenue">{{ f.fecha ?? 'Mes' }}</td>
                                        <td>{{ f.marca }}</td>
                                        <td class="campania" :title="f.campania">{{ f.campania }}</td>
                                        <td class="mono tenue">{{ f.funnel ?? '—' }}</td>
                                        <td class="num fuerte">{{ usd(f.costo) }}</td>
                                        <td class="num">{{ nf(f.impresiones) }}</td>
                                        <td class="num">{{ nf(f.clicks) }}</td>
                                        <td class="num">{{ nf(f.installs) }}</td>
                                        <td class="num tenue">
                                            <button v-if="f.origen === 'manual'" type="button" class="borrar" :aria-label="`Borrar gasto de ${f.medio} del ${f.fecha}`" @click="borrarGasto(f)">Borrar</button>
                                            <template v-else>{{ nf(f.anuncios) }}</template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="!filtradas.length" class="empty-note">Ninguna fila coincide con los filtros.</p>
                        </div>
                    </section>
                </template>
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
    max-width: 1400px;
    margin: 0 auto;
    padding: 32px 32px 64px;
}
.header {
    margin-bottom: 24px;
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
    margin: 0 0 4px;
}
.subtitle {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0;
    max-width: 720px;
}
.filtros {
    align-items: flex-end;
    margin-bottom: 24px;
}
.buscador {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1 1 220px;
    max-width: 320px;
}
.filtro-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.buscador input {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.85rem;
}
.buscador input:focus {
    outline: none;
    border-color: var(--amber);
}
.section {
    margin-bottom: 32px;
}
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.section-header-titulo {
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.section-title {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 600;
    font-size: 16px;
    margin: 0;
}
.section-count {
    font-size: 11px;
    color: var(--text-muted);
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
}
.hint {
    font-size: 0.76rem;
    color: var(--text-muted);
    margin: 8px 0 0;
}
.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
}
.tabla-scroll {
    overflow: auto;
}
.excel {
    max-height: calc(70vh / var(--zoom-factor));
}
.tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}
.tabla th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface);
    text-align: left;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
    padding: 10px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
    cursor: pointer;
    user-select: none;
}
.tabla.resumen th {
    cursor: default;
}
.tabla th.num {
    text-align: right;
}
.tabla th.ordenada {
    color: var(--amber);
}
.flecha {
    margin-left: 4px;
    font-size: 0.6rem;
}
.tabla .fila-total th {
    top: 37px;
    color: var(--text);
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.78rem;
    background: var(--surface-2);
    cursor: default;
}
.tabla td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.tabla tbody tr:hover td {
    background: color-mix(in srgb, var(--amber) 6%, transparent);
}
.tabla tfoot td {
    font-weight: 700;
    border-top: 2px solid var(--border);
    border-bottom: none;
}
.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.tenue {
    color: var(--text-muted);
}
.fuerte {
    font-weight: 600;
}
.campania {
    max-width: 460px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.origen {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 7px;
    border-radius: 6px;
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
}
.origen.api {
    margin-left: 0;
    background: var(--amber);
    color: #fff;
}
.origen.manual {
    border: 1px dashed var(--serie-2);
    background: color-mix(in srgb, var(--serie-2) 14%, transparent);
    color: var(--text);
}
.captura {
    padding: 16px 18px;
    margin-bottom: 24px;
    border-style: dashed;
}
.captura .section-title {
    display: flex;
    align-items: center;
    gap: 6px;
}
.captura-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    margin-top: 12px;
}
.captura-form label {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.captura-form label.ancho {
    flex: 1 1 260px;
}
.captura-form input,
.captura-form select {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 11px;
    font-size: 0.85rem;
}
.captura-form input:focus,
.captura-form select:focus {
    outline: none;
    border-color: var(--amber);
}
.error {
    color: var(--coral);
    font-size: 0.8rem;
    margin: 8px 0 0;
}
.borrar {
    border: 1px solid var(--coral);
    color: var(--coral);
    background: transparent;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 0.7rem;
    cursor: pointer;
}
@media (max-width: 720px) {
    .page-inner {
        padding: 24px 16px 48px;
    }
}
</style>
