<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import InfoTip from '@/Components/InfoTip.vue';
import { formatNumeroExacto } from '@/motor';
import { mensajeDeError, notificar } from '@/avisos';

// AF data (2026-11-13, pedido explícito; empieza con Ecuador): el export
// del pivot de AppsFlyer ya limpio -- sin subtotales, con NC / recompras /
// órdenes y la Fuente final por reglas -- en una tabla tipo Excel, más el
// resumen por canal que alimenta la vista de canales.
const props = defineProps({
    pais: String,
    tieneReglas: Boolean,
    canalesPaid: { type: Array, default: () => [] },
    meses: { type: Array, default: () => [] },
    mes: { type: String, default: null },
    filas: { type: Array, default: () => [] },
});

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const etiquetaMes = (m) => `${MESES[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}`;
const mesOpciones = computed(() => props.meses.map((m) => ({ key: m.mes, label: etiquetaMes(m.mes) })));
function cambiarMes(mes) {
    router.get(`${location.pathname}`, { mes }, { preserveScroll: true });
}

// --- Importar ---------------------------------------------------------------
const archivo = ref(null);
const subiendo = ref(false);
async function importar(e) {
    const f = e.target.files?.[0];
    if (!f) return;
    subiendo.value = true;
    try {
        const datos = new FormData();
        datos.append('archivo', f);
        const { data } = await axios.post(`${location.pathname}/importar`, datos);
        notificar({
            tipo: 'exito',
            titulo: `${formatNumeroExacto(data.filas)} filas importadas`,
            texto: `Del ${data.desde} al ${data.hasta}. Se quitaron ${formatNumeroExacto(data.subtotales)} renglones de subtotal.`,
        });
        router.get(location.pathname, { mes: data.mes }, { preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo importar', texto: mensajeDeError(err) });
    } finally {
        subiendo.value = false;
        if (archivo.value) archivo.value.value = '';
    }
}

// --- Filtros ----------------------------------------------------------------
const esIos = (app) => /\bid\d+/.test(app || '');
const appCorta = (app) => (esIos(app) ? 'iOS' : 'Android');
const opciones = (campo, valores) => [{ key: 'TODOS', label: 'Todos' }, ...[...new Set(valores)].sort().map((v) => ({ key: v, label: v }))];

const filtroFuente = ref('TODOS');
const filtroCanal = ref('TODOS');
const filtroApp = ref('TODOS');
const busqueda = ref('');
const fuenteOpciones = computed(() => opciones('fuente', props.filas.map((f) => f.fuente_final)));
const canalOpciones = computed(() => opciones('canal', props.filas.map((f) => f.canal)));
const APP_OPCIONES = [{ key: 'TODOS', label: 'Todas' }, { key: 'Android', label: 'Android' }, { key: 'iOS', label: 'iOS' }];

const sinAcentos = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const filtradas = computed(() => {
    const q = sinAcentos(busqueda.value.trim());
    return props.filas.filter((f) => {
        if (filtroFuente.value !== 'TODOS' && f.fuente_final !== filtroFuente.value) return false;
        if (filtroCanal.value !== 'TODOS' && f.canal !== filtroCanal.value) return false;
        if (filtroApp.value !== 'TODOS' && appCorta(f.app) !== filtroApp.value) return false;
        if (!q) return true;
        return [f.media_source, f.campania, f.campania_id].some((v) => sinAcentos(v).includes(q));
    });
});

// --- Resumen por canal (del mes, con filtro de app y búsqueda) ----------------
const resumen = computed(() => {
    const porCanal = new Map();
    for (const f of filtradas.value) {
        const c = porCanal.get(f.canal) ?? { canal: f.canal, installs: 0, nc: 0, ordenes: 0 };
        c.installs += f.installs;
        c.nc += f.nc;
        c.ordenes += f.ordenes;
        porCanal.set(f.canal, c);
    }
    const total = { installs: 0, nc: 0, ordenes: 0 };
    porCanal.forEach((c) => { total.installs += c.installs; total.nc += c.nc; total.ordenes += c.ordenes; });
    const paid = new Set(props.canalesPaid);
    const grupo = (esPaid) => {
        const canales = esPaid
            ? props.canalesPaid.map((c) => porCanal.get(c) ?? { canal: c, installs: 0, nc: 0, ordenes: 0 })
            : [...porCanal.values()].filter((c) => !paid.has(c.canal)).sort((a, b) => b.ordenes - a.ordenes);
        const suma = canales.reduce((t, c) => ({ installs: t.installs + c.installs, nc: t.nc + c.nc, ordenes: t.ordenes + c.ordenes }), { installs: 0, nc: 0, ordenes: 0 });
        return { titulo: esPaid ? 'Propias · Paid EPA' : 'No propias · Total negocio', canales, suma };
    };
    return { grupos: [grupo(true), grupo(false)], total };
});
const pct = (v, t) => (t ? `${((v / t) * 100).toFixed(1)}%` : '—');

// --- Tabla tipo Excel ---------------------------------------------------------
const columnasOtros = computed(() => {
    const llaves = new Set();
    props.filas.forEach((f) => Object.keys(f.otros || {}).forEach((k) => llaves.add(k)));
    return [...llaves];
});
const COLUMNAS_BASE = [
    { campo: 'fecha', label: 'Fecha' },
    { campo: 'app', label: 'App' },
    { campo: 'media_source', label: 'Media source' },
    { campo: 'campania', label: 'Campaña' },
    { campo: 'campania_id', label: 'Campaign ID' },
    { campo: 'fuente_final', label: 'Fuente final' },
    { campo: 'canal', label: 'Canal' },
    { campo: 'installs', label: 'Installs', num: true },
    { campo: 'nc', label: 'NC (first order)', num: true },
    { campo: 'recompras', label: 'Recompras', num: true },
    { campo: 'ordenes', label: 'Órdenes', num: true },
];
const columnas = computed(() => [...COLUMNAS_BASE, ...columnasOtros.value.map((k) => ({ campo: `otros.${k}`, label: k, num: true }))]);
const valor = (f, campo) => (campo.startsWith('otros.') ? (f.otros || {})[campo.slice(6)] ?? 0 : campo === 'app' ? appCorta(f.app) : f[campo]);

const orden = ref({ campo: null, dir: 'desc' });
function ordenarPor(campo) {
    orden.value = orden.value.campo === campo
        ? { campo, dir: orden.value.dir === 'desc' ? 'asc' : 'desc' }
        : { campo, dir: columnas.value.find((c) => c.campo === campo)?.num ? 'desc' : 'asc' };
}
const ordenadas = computed(() => {
    const { campo, dir } = orden.value;
    if (!campo) return filtradas.value;
    const s = dir === 'asc' ? 1 : -1;
    return [...filtradas.value].sort((a, b) => {
        const va = valor(a, campo);
        const vb = valor(b, campo);
        return (typeof va === 'number' ? va - vb : String(va ?? '').localeCompare(String(vb ?? ''))) * s;
    });
});
const totales = computed(() => {
    const t = {};
    columnas.value.filter((c) => c.num).forEach((c) => { t[c.campo] = filtradas.value.reduce((s, f) => s + (valor(f, c.campo) || 0), 0); });
    return t;
});

const PASO = 200;
const visibles = ref(PASO);
watch([filtroFuente, filtroCanal, filtroApp, busqueda, () => props.mes], () => { visibles.value = PASO; });
const enPantalla = computed(() => ordenadas.value.slice(0, visibles.value));

function descargarCsv() {
    const esc = (v) => {
        const t = String(v ?? '');
        return /[",\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
    };
    const lineas = [columnas.value.map((c) => esc(c.label)).join(',')];
    ordenadas.value.forEach((f) => lineas.push(columnas.value.map((c) => esc(c.campo === 'app' ? f.app : valor(f, c.campo))).join(',')));
    const url = URL.createObjectURL(new Blob(['﻿' + lineas.join('\n')], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `af-data-${props.pais}-${props.mes}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

const CLASE_FUENTE = { 'TADA PERFORMANCE': 'f-paid', BI: 'f-bi', CRM: 'f-crm', ORGANIC: 'f-organic', MGM: 'f-mgm' };
</script>

<template>
    <Head :title="`AF data — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="af-data">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">AF data</h1>
                        <p class="subtitle">
                            Datos de AppsFlyer por día, media source y campaña, ya limpios y clasificados por canal.
                        </p>
                    </div>
                    <div v-if="tieneReglas" class="header-acciones">
                        <label class="btn-primary subir" :class="{ deshabilitado: subiendo }">
                            {{ subiendo ? 'Importando…' : 'Importar CSV de AppsFlyer' }}
                            <input ref="archivo" type="file" accept=".csv,text/csv" :disabled="subiendo" @change="importar" />
                        </label>
                        <InfoTip etiqueta="Qué archivo subir" lado="izquierda">
                            AppsFlyer → Analyze → Pivot → "Ecuador | DATA WEEKLY", elige las fechas y descarga el CSV. Se
                            quitan solos los subtotales; volver a subir un periodo reemplaza esos días.
                        </InfoTip>
                    </div>
                </header>

                <p v-if="!tieneReglas" class="empty-note">Este país todavía no tiene reglas para limpiar AppsFlyer. Por ahora solo Ecuador.</p>
                <p v-else-if="!meses.length" class="empty-note">Todavía no hay datos. Sube el CSV del pivot de AppsFlyer para empezar.</p>

                <template v-else>
                    <div class="resumen-filtros filtros">
                        <FiltroDropdown label="Mes" :model-value="mes" :options="mesOpciones" @update:model-value="cambiarMes" />
                        <FiltroDropdown v-model="filtroFuente" label="Fuente final" :options="fuenteOpciones" />
                        <FiltroDropdown v-model="filtroCanal" label="Canal" :options="canalOpciones" />
                        <FiltroDropdown v-model="filtroApp" label="App" :options="APP_OPCIONES" />
                        <div class="buscador">
                            <label for="buscadorFuentes" class="filtro-label mono">Buscar</label>
                            <input id="buscadorFuentes" v-model="busqueda" type="search" placeholder="Media source, campaña, ID…" />
                        </div>
                    </div>

                    <section class="section">
                        <div class="section-header">
                            <div class="section-header-titulo">
                                <h2 class="section-title">Por canal</h2>
                                <span class="section-count mono">AppsFlyer · {{ etiquetaMes(mes) }}</span>
                            </div>
                        </div>
                        <div class="tabla-scroll panel">
                            <table class="tabla resumen">
                                <thead>
                                    <tr>
                                        <th>Canal</th>
                                        <th class="num">Installs</th>
                                        <th class="num">NC</th>
                                        <th class="num">Órdenes</th>
                                        <th class="num">Participación</th>
                                    </tr>
                                </thead>
                                <tbody v-for="g in resumen.grupos" :key="g.titulo">
                                    <tr class="grupo">
                                        <td>{{ g.titulo }}</td>
                                        <td class="num">{{ formatNumeroExacto(g.suma.installs) }}</td>
                                        <td class="num">{{ formatNumeroExacto(g.suma.nc) }}</td>
                                        <td class="num">{{ formatNumeroExacto(g.suma.ordenes) }}</td>
                                        <td class="num">{{ pct(g.suma.ordenes, resumen.total.ordenes) }}</td>
                                    </tr>
                                    <tr v-for="c in g.canales" :key="c.canal">
                                        <td class="sangria">{{ c.canal }}</td>
                                        <td class="num">{{ formatNumeroExacto(c.installs) }}</td>
                                        <td class="num">{{ formatNumeroExacto(c.nc) }}</td>
                                        <td class="num">{{ formatNumeroExacto(c.ordenes) }}</td>
                                        <td class="num">
                                            <span class="barra"><span :style="{ width: pct(c.ordenes, resumen.total.ordenes) }" /></span>
                                            {{ pct(c.ordenes, resumen.total.ordenes) }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td>Total AppsFlyer</td>
                                        <td class="num">{{ formatNumeroExacto(resumen.total.installs) }}</td>
                                        <td class="num">{{ formatNumeroExacto(resumen.total.nc) }}</td>
                                        <td class="num">{{ formatNumeroExacto(resumen.total.ordenes) }}</td>
                                        <td class="num">100%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-header">
                            <div class="section-header-titulo">
                                <h2 class="section-title">Detalle</h2>
                                <span class="section-count mono">{{ formatNumeroExacto(filtradas.length) }} fila{{ filtradas.length === 1 ? '' : 's' }}</span>
                            </div>
                            <button type="button" class="modal-copy-btn" :disabled="!filtradas.length" @click="descargarCsv">Descargar CSV</button>
                        </div>
                        <div class="tabla-scroll panel excel">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th
                                            v-for="c in columnas"
                                            :key="c.campo"
                                            :class="{ num: c.num, ordenada: orden.campo === c.campo }"
                                            @click="ordenarPor(c.campo)"
                                        >
                                            {{ c.label }}<span v-if="orden.campo === c.campo" class="flecha">{{ orden.dir === 'asc' ? '▲' : '▼' }}</span>
                                        </th>
                                    </tr>
                                    <tr class="fila-total">
                                        <th v-for="(c, i) in columnas" :key="c.campo" :class="{ num: c.num }">
                                            {{ c.num ? formatNumeroExacto(totales[c.campo]) : i === 0 ? 'Total' : '' }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="f in enPantalla" :key="f.id">
                                        <td class="mono">{{ f.fecha }}</td>
                                        <td>{{ appCorta(f.app) }}</td>
                                        <td>{{ f.media_source }}</td>
                                        <td class="campania" :title="f.campania">{{ f.campania }}</td>
                                        <td class="mono tenue">{{ f.campania_id ?? '—' }}</td>
                                        <td><span class="pill-fuente" :class="CLASE_FUENTE[f.fuente_final] ?? 'f-otro'">{{ f.fuente_final }}</span></td>
                                        <td>{{ f.canal }}</td>
                                        <td class="num">{{ formatNumeroExacto(f.installs) }}</td>
                                        <td class="num">{{ formatNumeroExacto(f.nc) }}</td>
                                        <td class="num">{{ formatNumeroExacto(f.recompras) }}</td>
                                        <td class="num fuerte">{{ formatNumeroExacto(f.ordenes) }}</td>
                                        <td v-for="k in columnasOtros" :key="k" class="num tenue">{{ formatNumeroExacto((f.otros || {})[k] ?? 0) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="!filtradas.length" class="empty-note">Ninguna fila coincide con los filtros.</p>
                        </div>
                        <div v-if="ordenadas.length > visibles" class="mas">
                            <button type="button" class="modal-copy-btn" @click="visibles += PASO">
                                Mostrar {{ Math.min(PASO, ordenadas.length - visibles) }} más (de {{ formatNumeroExacto(ordenadas.length) }})
                            </button>
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
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
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
    max-width: 640px;
}
.header-acciones {
    display: flex;
    align-items: center;
    gap: 8px;
}
.subir {
    position: relative;
    cursor: pointer;
}
.subir input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}
.subir.deshabilitado {
    opacity: 0.5;
    cursor: not-allowed;
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
.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.tabla th.num {
    text-align: right;
}
.tenue {
    color: var(--text-muted);
}
.fuerte {
    font-weight: 600;
}
.campania {
    max-width: 380px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.resumen .grupo td {
    font-weight: 600;
    background: var(--surface-2);
}
.resumen .sangria {
    padding-left: 24px;
}
.resumen tfoot td {
    font-weight: 700;
    border-bottom: none;
    border-top: 2px solid var(--border);
}
.barra {
    display: inline-block;
    width: 60px;
    height: 6px;
    margin-right: 8px;
    vertical-align: middle;
    background: var(--surface-2);
    border-radius: 999px;
    overflow: hidden;
}
.barra span {
    display: block;
    height: 100%;
    background: var(--amber);
}
.pill-fuente {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.64rem;
    font-weight: 700;
    text-transform: uppercase;
    background: color-mix(in srgb, var(--text-muted) 18%, transparent);
    color: var(--text-muted);
}
.f-paid {
    background: color-mix(in srgb, var(--amber) 20%, transparent);
    color: var(--accent-text);
}
.f-bi {
    background: color-mix(in srgb, var(--violet) 20%, transparent);
    color: var(--violet);
}
.f-crm {
    background: color-mix(in srgb, var(--sky) 20%, transparent);
    color: var(--sky);
}
.f-organic {
    background: color-mix(in srgb, var(--mint) 20%, transparent);
    color: var(--mint);
}
.f-mgm {
    background: color-mix(in srgb, var(--yellow) 20%, transparent);
    color: var(--yellow);
}
.mas {
    display: flex;
    justify-content: center;
    margin-top: 12px;
}
@media (max-width: 720px) {
    .page-inner {
        padding: 24px 16px 48px;
    }
    .header {
        flex-direction: column;
    }
}
</style>
