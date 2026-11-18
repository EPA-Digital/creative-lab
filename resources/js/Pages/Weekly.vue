<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import { FUNNEL_LABELS, FUNNEL_COLOR_VAR } from '@/motor.js';

// Weekly (2026-11-18, pedido explícito) -- réplica de weekly.fer (Ecuador y
// Panamá): global semanal Paid, por tipo de campaña (mes al corte y semana) y
// la dispersión gasto vs NC con su R². El servidor (PanelWeekly) manda días.
const props = defineProps({
    pais: { type: String, required: true },
    anio: { type: Number, required: true },
    anios: { type: Array, default: () => [] },
    // [fecha, gasto, nc, ordenes, installs] -- Paid
    global: { type: Array, default: () => [] },
    // [{ id, campania, medio, tipo, auto, editado, oculta, color }]
    campanias: { type: Array, default: () => [] },
    // [fecha, idx campaña, gasto, nc, ordenes]
    porCampania: { type: Array, default: () => [] },
    corte: { type: String, default: null },
    fuentePaid: { type: String, default: 'TADA PERFORMANCE' },
});

const nf = new Intl.NumberFormat('es-MX');
const usd0 = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
const usd2 = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

// --- Semanas (la 1 va del 1 de enero al primer domingo, como la hoja) ----------
const fecha = (y, m, d) => new Date(Date.UTC(y, m, d));
const masDias = (d, n) => new Date(d.getTime() + n * 86400000);
const iso = (d) => d.toISOString().slice(0, 10);
const corto = (f) => `${+f.slice(8, 10)} ${MES_CORTO[+f.slice(5, 7) - 1]}`;
const semanas = computed(() => {
    const y = props.anio;
    const ene1 = fecha(y, 0, 1);
    const desfase = (ene1.getUTCDay() + 6) % 7;
    const dic31 = fecha(y, 11, 31);
    const salida = [];
    for (let k = 1; ; k++) {
        const inicio = k === 1 ? ene1 : masDias(ene1, 7 * (k - 1) - desfase);
        if (inicio > dic31) break;
        let fin = masDias(ene1, 7 * k - desfase - 1);
        if (fin > dic31) fin = dic31;
        salida.push({ n: k, inicio: iso(inicio), fin: iso(fin) });
    }
    return salida;
});
const semanaDe = (f) => semanas.value.find((s) => f >= s.inicio && f <= s.fin)?.n ?? null;

// --- Corte y filtros -------------------------------------------------------------
const corte = computed(() => props.corte ?? new Date().toISOString().slice(0, 10));
const mesCorte = computed(() => (+corte.value.slice(0, 4) === props.anio ? +corte.value.slice(5, 7) : 12));
const MES_OPCIONES = computed(() => MESES.slice(0, mesCorte.value).map((m, i) => ({ key: i + 1, label: m })).reverse());
const mes = ref(mesCorte.value);
// Semana: por omisión la última completa (al corte); se puede elegir otra.
const ultimaCompleta = computed(() => {
    const s = semanas.value.filter((x) => x.fin <= corte.value);
    return s.length ? s[s.length - 1].n : 1;
});
const SEMANA_OPCIONES = computed(() =>
    semanas.value.filter((s) => s.inicio <= corte.value).map((s) => ({ key: s.n, label: `Semana ${s.n} · ${corto(s.inicio)} – ${corto(s.fin)}` })).reverse(),
);
const semana = ref(ultimaCompleta.value);
// Al cambiar de mes, la semana pasa a la última de ese mes (con datos).
watch(mes, (m) => {
    const delMes = semanas.value.filter((s) => +s.fin.slice(5, 7) === m && s.inicio <= corte.value);
    if (delMes.length) semana.value = delMes[delMes.length - 1].n;
});
const ANIO_OPCIONES = computed(() => props.anios.map((y) => ({ key: y, label: String(y) })));
const anioSel = ref(props.anio);
watch(anioSel, (y) => router.get(location.pathname, { anio: y }, { preserveScroll: true }));

// --- Global Paid -------------------------------------------------------------------
function metricas(g, nc, o, i) {
    return { gasto: g, nc, ordenes: o, installs: i, cpo: o ? g / o : null, cac: nc ? g / nc : null, onboarding: i ? nc / i : null, cpi: i ? g / i : null };
}
function sumarGlobal(desde, hasta) {
    let g = 0, nc = 0, o = 0, i = 0, hay = false;
    for (const d of props.global) {
        if (d[0] < desde || d[0] > hasta) continue;
        g += d[1]; nc += d[2]; o += d[3]; i += d[4]; hay = true;
    }
    return hay ? metricas(g, nc, o, i) : null;
}
const filasSemana = computed(() =>
    semanas.value
        .filter((s) => s.inicio <= corte.value)
        .map((s) => ({ ...s, parcial: s.fin > corte.value, mes: +s.fin.slice(5, 7), v: sumarGlobal(s.inicio, s.fin) }))
        .reverse(),
);
// Global por mes (meses anteriores de un vistazo).
const vistaGlobal = ref('semana');
const filasMes = computed(() =>
    MESES.slice(0, mesCorte.value)
        .map((m, i) => {
            const ini = `${props.anio}-${String(i + 1).padStart(2, '0')}-01`;
            const finMes = iso(fecha(props.anio, i + 1, 0));
            const fin = finMes > corte.value ? corte.value : finMes;
            return { n: i + 1, label: m, inicio: ini, fin, parcial: finMes > corte.value, v: sumarGlobal(ini, fin) };
        })
        .reverse(),
);
const filasGlobal = computed(() => (vistaGlobal.value === 'mes' ? filasMes.value : filasSemana.value));
function elegirFila(f) {
    if (vistaGlobal.value === 'mes') mes.value = f.n;
    else semana.value = f.n;
}

const rangoMes = computed(() => {
    const ini = `${props.anio}-${String(mes.value).padStart(2, '0')}-01`;
    const fin = iso(fecha(props.anio, mes.value, 0));
    return { ini, fin: fin > corte.value ? corte.value : fin, parcial: fin > corte.value };
});
const kpisMes = computed(() => sumarGlobal(rangoMes.value.ini, rangoMes.value.fin));
// Mismo número de días del mes anterior, para comparar a la fecha.
const kpisMesAnterior = computed(() => {
    if (mes.value === 1) return null;
    const dias = +rangoMes.value.fin.slice(8, 10);
    const ini = `${props.anio}-${String(mes.value - 1).padStart(2, '0')}-01`;
    const ultimo = fecha(props.anio, mes.value - 1, 0).getUTCDate();
    return sumarGlobal(ini, `${ini.slice(0, 8)}${String(Math.min(dias, ultimo)).padStart(2, '0')}`);
});
const KPIS = [
    { key: 'gasto', label: 'Gasto', fmt: (v) => usd0.format(v), neutro: true },
    { key: 'nc', label: 'Primeras compras', fmt: (v) => nf.format(v) },
    { key: 'ordenes', label: 'Órdenes', fmt: (v) => nf.format(v) },
    { key: 'cac', label: 'CAC', fmt: (v) => usd2.format(v), menorEsMejor: true },
    { key: 'cpo', label: 'CPO', fmt: (v) => usd2.format(v), menorEsMejor: true },
    { key: 'onboarding', label: 'Onboarding rate', fmt: (v) => `${(v * 100).toFixed(1)}%`, ayuda: 'Primeras compras / Installs' },
    { key: 'installs', label: 'Installs', fmt: (v) => nf.format(v) },
    { key: 'cpi', label: 'CPI', fmt: (v) => usd2.format(v), menorEsMejor: true },
];
function delta(k) {
    const a = kpisMesAnterior.value?.[k.key];
    const b = kpisMes.value?.[k.key];
    if (a == null || b == null || !a) return null;
    const d = (b - a) / a;
    const bueno = k.neutro ? null : k.menorEsMejor ? d < 0 : d > 0;
    return { texto: `${d > 0 ? '+' : ''}${(d * 100).toFixed(0)}%`, clase: bueno === null ? '' : bueno ? 'sube' : 'baja' };
}

// --- Por tipo de campaña --------------------------------------------------------------
const mostrarOcultas = ref(false);
function porTipo(desde, hasta, funnel) {
    const acc = new Map();
    for (const d of props.porCampania) {
        if (d[0] < desde || d[0] > hasta) continue;
        const c = props.campanias[d[1]];
        if (!c || (c.oculta && !mostrarOcultas.value) || c.funnel !== funnel) continue;
        const t = acc.get(c.tipo) ?? { tipo: c.tipo, color: c.color, gasto: 0, nc: 0, ordenes: 0, campanias: new Set(), oculta: c.oculta };
        t.gasto += d[2];
        t.nc += d[3];
        t.ordenes += d[4];
        t.campanias.add(c.campania);
        acc.set(c.tipo, t);
    }
    // Sin gasto real en el periodo no se lista (la hoja solo pone lo que corrió).
    const filas = [...acc.values()].filter((t) => t.gasto >= 1).map((t) => ({ ...t, cac: t.nc ? t.gasto / t.nc : null, campanias: [...t.campanias] }));
    filas.sort((a, b) => b.gasto - a.gasto);
    const total = filas.reduce((s, t) => ({ gasto: s.gasto + t.gasto, nc: s.nc + t.nc, ordenes: s.ordenes + t.ordenes }), { gasto: 0, nc: 0, ordenes: 0 });
    return { filas, total: { ...total, cac: total.nc ? total.gasto / total.nc : null } };
}
const semanaSel = computed(() => semanas.value.find((s) => s.n === semana.value) ?? semanas.value[0]);
const finSemana = computed(() => (semanaSel.value.fin > corte.value ? corte.value : semanaSel.value.fin));
const periodosTabla = computed(() => [
    { key: 'mes', titulo: `${MESES[mes.value - 1]}${rangoMes.value.parcial ? ' al corte' : ''}`, sub: `${corto(rangoMes.value.ini)} – ${corto(rangoMes.value.fin)}`, desde: rangoMes.value.ini, hasta: rangoMes.value.fin },
    { key: 'semana', titulo: `Semana ${semanaSel.value.n}`, sub: `${corto(semanaSel.value.inicio)} – ${corto(finSemana.value)}`, desde: semanaSel.value.inicio, hasta: finSemana.value },
]);
// Tablas divididas por funnel (pedido explícito): cada etapa junta las
// campañas de todos los medios. Solo salen las etapas con gasto.
const ETAPAS = ['AWA', 'CONS', 'CNV', 'LOY', 'SIN'];
// Ver el conglomerado del mes, la semana o los dos lado a lado.
const VISTAS_TABLA = [
    { key: 'ambos', label: 'Mes y semana' },
    { key: 'mes', label: 'Solo mes' },
    { key: 'semana', label: 'Solo semana' },
];
const vistaTabla = ref('ambos');
watch(vistaTabla, (v) => {
    if (v !== 'ambos') bloqueGrafica.value = v;
});
const nombreEtapa = (f) => (f === 'SIN' ? 'Sin funnel en el nombre' : FUNNEL_LABELS[f]);
const colorEtapa = (f) => FUNNEL_COLOR_VAR[f] ?? 'var(--text-faint)';
const etapas = computed(() =>
    ETAPAS.map((f) => ({ funnel: f, nombre: nombreEtapa(f), color: colorEtapa(f), bloques: periodosTabla.value.map((p) => ({ ...p, ...porTipo(p.desde, p.hasta, f) })) })).filter((e) =>
        e.bloques.some((b) => b.filas.length),
    ),
);

// --- Dispersión gasto vs NC (R²) ---------------------------------------------------------
const bloqueGrafica = ref('mes');
function regresion(p) {
    const n = p.length;
    if (n < 3) return null;
    const mx = p.reduce((s, t) => s + t.gasto, 0) / n;
    const my = p.reduce((s, t) => s + t.nc, 0) / n;
    let sxy = 0, sxx = 0, syy = 0;
    for (const t of p) {
        sxy += (t.gasto - mx) * (t.nc - my);
        sxx += (t.gasto - mx) ** 2;
        syy += (t.nc - my) ** 2;
    }
    if (!sxx || !syy) return null;
    const m = sxy / sxx;
    return { m, b: my - m * mx, r2: (sxy * sxy) / (sxx * syy) };
}
const nivelR2 = (r2) => (r2 >= 0.8 ? ['Fuerte', 'sube'] : r2 >= 0.5 ? ['Moderada', 'medio'] : ['Débil', 'baja']);

const G = { w: 720, h: 280, l: 56, r: 24, t: 20, b: 44 };
function ticks(max) {
    const paso = 10 ** Math.floor(Math.log10(max / 4 || 1));
    const n = [1, 2, 2.5, 5, 10].map((k) => k * paso).find((k) => max / k <= 5) ?? paso * 10;
    return Array.from({ length: Math.floor(max / n) + 1 }, (_, i) => i * n);
}
/** Puntos, escala, línea de tendencia y R² de una etapa en el periodo de la gráfica. */
function grafica(etapa) {
    const puntos = etapa.bloques.find((b) => b.key === bloqueGrafica.value)?.filas ?? [];
    const maxX = Math.max(1, ...puntos.map((t) => t.gasto)) * 1.1;
    const maxY = Math.max(1, ...puntos.map((t) => t.nc)) * 1.15;
    const x = (v) => G.l + (v / maxX) * (G.w - G.l - G.r);
    const y = (v) => G.h - G.b - (v / maxY) * (G.h - G.t - G.b);
    const r = regresion(puntos);
    const clamp = (v) => Math.max(0, Math.min(maxY, v));
    const linea = r ? { x1: x(0), y1: y(clamp(r.b)), x2: x(maxX), y2: y(clamp(r.m * maxX + r.b)) } : null;
    return { puntos, x, y, tx: ticks(maxX), ty: ticks(maxY), r, linea };
}
const graficas = computed(() => Object.fromEntries(etapas.value.map((e) => [e.funnel, grafica(e)])));
const hover = ref(null); // { funnel, t }

// --- Editar tipos -------------------------------------------------------------------------
const editando = ref(false);
const filtroEdicion = ref('');
const borrador = ref({});
const guardando = ref(null);
const error = ref('');
const campaniasMes = computed(() => {
    const gasto = new Map();
    for (const d of props.porCampania) {
        if (d[0] < rangoMes.value.ini || d[0] > rangoMes.value.fin) continue;
        gasto.set(d[1], (gasto.get(d[1]) ?? 0) + d[2]);
    }
    const q = filtroEdicion.value.trim().toLowerCase();
    return props.campanias
        .filter((c) => (gasto.get(c.id) ?? 0) >= 1)
        .filter((c) => !q || c.campania.toLowerCase().includes(q) || c.tipo.toLowerCase().includes(q))
        .map((c) => ({ ...c, gastoMes: gasto.get(c.id) ?? 0 }))
        .sort((a, b) => b.gastoMes - a.gastoMes);
});
function abrirEdicion() {
    borrador.value = Object.fromEntries(props.campanias.map((c) => [c.id, { tipo: c.editado ? c.tipo : '', oculta: c.oculta }]));
    error.value = '';
    editando.value = true;
}
async function guardar(c) {
    guardando.value = c.id;
    error.value = '';
    try {
        await axios.patch(`${location.pathname}/tipo`, { campania: c.campania, tipo: borrador.value[c.id].tipo || null, oculta: borrador.value[c.id].oculta });
        router.reload({ only: ['campanias'], preserveScroll: true });
    } catch (err) {
        error.value = err.response?.data?.message ?? 'No se pudo guardar.';
    } finally {
        guardando.value = null;
    }
}

const fmtUsd = (v) => (v == null ? '–' : usd0.format(v));
const fmtUsd2 = (v) => (v == null ? '–' : usd2.format(v));
const fmtN = (v) => (v == null ? '–' : nf.format(v));
const fmtPct = (v) => (v == null ? '–' : `${(v * 100).toFixed(0)}%`);
const fechaLarga = (f) => `${+f.slice(8, 10)} de ${MESES[+f.slice(5, 7) - 1].toLowerCase()}`;
</script>

<template>
    <Head :title="`Weekly — ${pais}`" />
    <DashboardLayout :pais="pais" vista-activa="weekly">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Weekly</h1>
                        <p class="subtitle">
                            Cómo vamos en Paid cada semana y cada mes, por tipo de campaña y qué tanto explica el gasto las primeras compras (R²).
                            <template v-if="props.corte">Datos al {{ fechaLarga(props.corte) }} (último día con data.medios y AF data).</template>
                        </p>
                    </div>
                    <button type="button" class="modal-copy-btn" @click="abrirEdicion">Tipos de campaña</button>
                </header>

                <div class="resumen-filtros">
                    <FiltroDropdown v-if="ANIO_OPCIONES.length > 1" v-model="anioSel" label="Año" :options="ANIO_OPCIONES" />
                    <FiltroDropdown v-model="mes" label="Mes" :options="MES_OPCIONES" />
                    <FiltroDropdown v-model="semana" label="Semana" :options="SEMANA_OPCIONES" />
                </div>

                <p v-if="!global.length" class="empty-note">Todavía no hay data.medios ni AF data de {{ anio }} para este país.</p>

                <template v-else>
                    <!-- Cómo vamos en el mes -->
                    <section class="import-panel panel">
                        <div class="panel-head">
                            <h2>{{ MESES[mes - 1] }} {{ anio }}<small v-if="rangoMes.parcial" class="mono">al {{ fechaLarga(rangoMes.fin) }}</small></h2>
                            <span v-if="kpisMesAnterior" class="hint-chico">% contra los mismos días de {{ MESES[mes - 2].toLowerCase() }}</span>
                        </div>
                        <div v-if="kpisMes" class="kpis">
                            <div v-for="k in KPIS" :key="k.key" class="kpi" :title="k.ayuda">
                                <span class="kpi-label">{{ k.label }}</span>
                                <span class="kpi-value">{{ kpisMes[k.key] == null ? '–' : k.fmt(kpisMes[k.key]) }}</span>
                                <span v-if="delta(k)" class="kpi-delta mono" :class="delta(k).clase">{{ delta(k).texto }}</span>
                            </div>
                        </div>
                        <p v-else class="cols-hint">Sin datos en este mes.</p>
                    </section>

                    <!-- Por funnel: campañas de todos los medios de cada etapa -->
                    <div class="funnel-barra">
                        <h2 class="seccion">Por funnel</h2>
                        <div class="periodo-toggle" role="group" aria-label="Qué tablas ver">
                            <button v-for="v in VISTAS_TABLA" :key="v.key" type="button" :class="{ activo: vistaTabla === v.key }" @click="vistaTabla = v.key">{{ v.label }}</button>
                        </div>
                        <div v-if="vistaTabla === 'ambos'" class="periodo-toggle" role="group" aria-label="Periodo de las gráficas">
                            <button v-for="p in periodosTabla" :key="p.key" type="button" :class="{ activo: bloqueGrafica === p.key }" @click="bloqueGrafica = p.key">Gráficas: {{ p.titulo }}</button>
                        </div>
                    </div>
                    <p v-if="!etapas.length" class="empty-note">Sin campañas con gasto en {{ MESES[mes - 1].toLowerCase() }}.</p>

                    <section v-for="e in etapas" :key="e.funnel" class="import-panel panel etapa" :style="{ '--color-etapa': e.color }">
                        <div class="panel-head">
                            <h2><i class="punto grande" :style="{ background: e.color }" />{{ e.nombre }}</h2>
                            <div v-if="graficas[e.funnel].r" class="r2">
                                <span class="r2-valor">R² {{ (graficas[e.funnel].r.r2 * 100).toFixed(0) }}%</span>
                                <span class="r2-nivel mono" :class="nivelR2(graficas[e.funnel].r.r2)[1]">{{ nivelR2(graficas[e.funnel].r.r2)[0] }}</span>
                            </div>
                            <span v-else class="hint-chico">R²: hacen falta al menos 3 campañas con gasto</span>
                        </div>

                        <div :class="vistaTabla === 'ambos' ? 'dos' : 'uno'">
                            <div v-for="b in e.bloques.filter((x) => vistaTabla === 'ambos' || x.key === vistaTabla)" :key="b.key" class="bloque">
                                <p class="bloque-titulo">{{ b.titulo }}<small class="mono">{{ b.sub }}</small></p>
                                <div class="tabla-scroll">
                                    <table class="tabla">
                                        <thead>
                                            <tr>
                                                <th class="izq">Tipo de campaña</th>
                                                <th>Gasto</th>
                                                <th>NC</th>
                                                <th>Órdenes</th>
                                                <th>CAC</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="t in b.filas" :key="t.tipo" :class="{ oculta: t.oculta }">
                                                <th class="izq" :title="t.campanias.join('\n')"><i class="punto" :style="{ background: `var(--${t.color})` }" />{{ t.tipo }}</th>
                                                <td>{{ fmtUsd2(t.gasto) }}</td>
                                                <td>{{ fmtN(t.nc) }}</td>
                                                <td>{{ fmtN(t.ordenes) }}</td>
                                                <td>{{ fmtUsd2(t.cac) }}</td>
                                            </tr>
                                            <tr v-if="!b.filas.length">
                                                <td colspan="5" class="vacio-fila">Sin gasto en este periodo.</td>
                                            </tr>
                                        </tbody>
                                        <tfoot v-if="b.filas.length">
                                            <tr>
                                                <th class="izq">Total</th>
                                                <td>{{ fmtUsd2(b.total.gasto) }}</td>
                                                <td>{{ fmtN(b.total.nc) }}</td>
                                                <td>{{ fmtN(b.total.ordenes) }}</td>
                                                <td>{{ fmtUsd2(b.total.cac) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div v-if="graficas[e.funnel].puntos.length" class="grafica">
                            <p v-if="graficas[e.funnel].r" class="hint-chico lectura">
                                {{ periodosTabla.find((p) => p.key === bloqueGrafica).titulo }}: cada $100 más de gasto ≈ {{ (graficas[e.funnel].r.m * 100).toFixed(1) }} primeras compras más.
                            </p>
                            <svg :viewBox="`0 0 ${G.w} ${G.h}`" role="img" :aria-label="`${e.nombre}: dispersión gasto contra primeras compras`">
                                <g class="ejes">
                                    <g v-for="t in graficas[e.funnel].ty" :key="`y${t}`">
                                        <line :x1="G.l" :x2="G.w - G.r" :y1="graficas[e.funnel].y(t)" :y2="graficas[e.funnel].y(t)" class="grid" />
                                        <text :x="G.l - 8" :y="graficas[e.funnel].y(t) + 4" text-anchor="end">{{ nf.format(t) }}</text>
                                    </g>
                                    <text v-for="t in graficas[e.funnel].tx" :key="`x${t}`" :x="graficas[e.funnel].x(t)" :y="G.h - G.b + 18" text-anchor="middle">{{ usd0.format(t) }}</text>
                                    <text :x="(G.l + G.w - G.r) / 2" :y="G.h - 6" text-anchor="middle" class="titulo-eje">Gasto</text>
                                    <text :x="14" :y="(G.t + G.h - G.b) / 2" text-anchor="middle" class="titulo-eje" :transform="`rotate(-90 14 ${(G.t + G.h - G.b) / 2})`">Primeras compras</text>
                                </g>
                                <line v-if="graficas[e.funnel].linea" v-bind="graficas[e.funnel].linea" class="tendencia" />
                                <g v-for="t in graficas[e.funnel].puntos" :key="t.tipo" class="punto-g" @mouseenter="hover = { funnel: e.funnel, t }" @mouseleave="hover = null">
                                    <circle :cx="graficas[e.funnel].x(t.gasto)" :cy="graficas[e.funnel].y(t.nc)" r="14" class="hit" />
                                    <circle :cx="graficas[e.funnel].x(t.gasto)" :cy="graficas[e.funnel].y(t.nc)" r="6" :style="{ fill: `var(--${t.color})` }" class="marca" />
                                    <text v-if="graficas[e.funnel].puntos.length <= 8" :x="graficas[e.funnel].x(t.gasto) + 10" :y="graficas[e.funnel].y(t.nc) - 8" class="etiqueta">{{ t.tipo }}</text>
                                </g>
                            </svg>
                            <div v-if="hover && hover.funnel === e.funnel" class="tooltip">
                                <strong>{{ hover.t.tipo }}</strong>
                                <span>Gasto {{ fmtUsd2(hover.t.gasto) }}</span>
                                <span>NC {{ fmtN(hover.t.nc) }} · CAC {{ fmtUsd2(hover.t.cac) }}</span>
                                <span v-if="graficas[e.funnel].r">Esperado por la tendencia: {{ fmtN(Math.max(0, Math.round(graficas[e.funnel].r.m * hover.t.gasto + graficas[e.funnel].r.b))) }} NC</span>
                            </div>
                        </div>
                    </section>

                    <!-- Global semanal Paid -->
                    <section class="import-panel panel">
                        <div class="panel-head">
                            <h2>Global Paid<small class="mono">{{ fuentePaid }}</small></h2>
                            <div class="periodo-toggle" role="group" aria-label="Ver global por">
                                <button type="button" :class="{ activo: vistaGlobal === 'semana' }" @click="vistaGlobal = 'semana'">Semana</button>
                                <button type="button" :class="{ activo: vistaGlobal === 'mes' }" @click="vistaGlobal = 'mes'">Mes</button>
                            </div>
                        </div>
                        <div class="tabla-scroll alta">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th class="izq">{{ vistaGlobal === 'mes' ? 'Mes' : 'Semana' }}</th>
                                        <th>Gasto</th>
                                        <th>Primeras compras</th>
                                        <th>Órdenes</th>
                                        <th>CPO</th>
                                        <th>CAC</th>
                                        <th>Onboarding</th>
                                        <th>Installs</th>
                                        <th>CPI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="s in filasGlobal"
                                        :key="`${vistaGlobal}${s.n}`"
                                        :class="vistaGlobal === 'mes' ? { elegida: s.n === mes } : { 'del-mes': s.mes === mes, elegida: s.n === semana }"
                                        @click="elegirFila(s)"
                                    >
                                        <th class="izq">
                                            {{ s.label ?? s.n }}<span v-if="s.parcial" class="parcial mono">{{ vistaGlobal === 'mes' ? 'al corte' : 'parcial' }}</span>
                                            <small>{{ corto(s.inicio) }} – {{ corto(s.fin) }}</small>
                                        </th>
                                        <template v-if="s.v">
                                            <td>{{ fmtUsd(s.v.gasto) }}</td>
                                            <td>{{ fmtN(s.v.nc) }}</td>
                                            <td>{{ fmtN(s.v.ordenes) }}</td>
                                            <td>{{ fmtUsd2(s.v.cpo) }}</td>
                                            <td>{{ fmtUsd2(s.v.cac) }}</td>
                                            <td>{{ fmtPct(s.v.onboarding) }}</td>
                                            <td>{{ fmtN(s.v.installs) }}</td>
                                            <td>{{ fmtUsd2(s.v.cpi) }}</td>
                                        </template>
                                        <td v-else colspan="8" class="vacio-fila">Sin datos</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <p class="nota">
                        Gasto = data.medios sin campañas _BRD_; primeras compras, órdenes e installs = AF data de {{ fuentePaid }}. Por tipo de campaña, el gasto incluye todas las campañas
                        (también BI) y NC / órdenes se cruzan por nombre de campaña. El nombre de cada tipo se puede cambiar en "Tipos de campaña".
                    </p>
                </template>
            </div>
        </div>

        <!-- Tipos de campaña -->
        <div v-if="editando" class="modal-overlay" @click.self="editando = false">
            <div class="modal tipos-modal">
                <button type="button" class="modal-close" aria-label="Cerrar" @click="editando = false">×</button>
                <h2>Tipos de campaña</h2>
                <p class="hint">Campañas con gasto en {{ MESES[mes - 1].toLowerCase() }}. Las que tengan el mismo nombre se suman en una sola fila. Vacío = nombre automático.</p>
                <div class="tipos-top">
                    <input v-model="filtroEdicion" type="search" placeholder="Buscar campaña o tipo…" class="buscar" />
                    <label class="check"><input v-model="mostrarOcultas" type="checkbox" /> Mostrar ocultas en las tablas</label>
                </div>
                <p v-if="error" class="error">{{ error }}</p>
                <div class="tipos-lista">
                    <div v-for="c in campaniasMes" :key="c.id" class="tipo-fila" :class="{ oculta: borrador[c.id]?.oculta }">
                        <div class="tipo-campania">
                            <span class="mono">{{ c.campania }}</span>
                            <small>{{ fmtUsd(c.gastoMes) }} en el mes</small>
                        </div>
                        <input v-model="borrador[c.id].tipo" type="text" :placeholder="c.auto" maxlength="120" @keydown.enter="guardar(c)" />
                        <label class="check"><input v-model="borrador[c.id].oculta" type="checkbox" /> Ocultar</label>
                        <button type="button" class="modal-copy-btn" :disabled="guardando === c.id" @click="guardar(c)">{{ guardando === c.id ? 'Guardando…' : 'Guardar' }}</button>
                    </div>
                    <p v-if="!campaniasMes.length" class="cols-hint">No hay campañas con gasto en este mes.</p>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>

<style scoped>
/* Page chrome -- scoped y repetido por página a propósito (convención del proyecto). */
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
    margin-bottom: 28px;
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
    max-width: 760px;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
}
.nota {
    font-size: 0.72rem;
    color: var(--text-faint);
    margin: 0;
}
.hint-chico {
    font-size: 0.72rem;
    color: var(--text-faint);
}
.panel {
    min-width: 0;
    margin-bottom: 18px;
}
.panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.panel-head h2 {
    margin: 0;
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.panel-head h2 small {
    font-size: 0.68rem;
    font-weight: 400;
    color: var(--text-faint);
}
.dos {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}
@media (max-width: 1100px) {
    .dos {
        grid-template-columns: 1fr;
    }
}

/* KPIs */
.kpis {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
}
.kpi {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.kpi-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
}
.kpi-value {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.35rem;
    font-weight: 700;
}
.kpi-delta {
    font-size: 0.7rem;
    color: var(--text-faint);
}
.kpi-delta.sube {
    color: var(--mint);
}
.kpi-delta.baja {
    color: var(--coral);
}

/* Toggle */
.periodo-toggle {
    display: flex;
    gap: 4px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 3px;
}
.periodo-toggle button {
    background: transparent;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    color: var(--text-muted);
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.periodo-toggle button:hover {
    color: var(--text);
}
.periodo-toggle button.activo {
    background: var(--amber);
    color: var(--amber-ink);
    font-weight: 600;
}
.periodo-toggle button:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}

/* Tablas */
.tabla-scroll {
    overflow: auto;
    max-width: 100%;
}
.tabla-scroll.alta {
    max-height: calc(520px / var(--zoom-factor, 1));
}
.tabla {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    font-size: 0.8rem;
}
.tabla th,
.tabla td {
    padding: 8px 10px;
    text-align: right;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.tabla thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface);
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    font-weight: 600;
}
.tabla .izq {
    text-align: left;
    font-weight: 500;
    color: var(--text);
}
.tabla tbody th.izq small {
    display: block;
    font-size: 0.6rem;
    color: var(--text-faint);
    font-weight: 400;
    font-family: 'JetBrains Mono', monospace;
}
.tabla tfoot th,
.tabla tfoot td {
    font-weight: 700;
    background: var(--surface-2);
}
.tabla tr.oculta {
    opacity: 0.5;
}
.tabla tr.del-mes th.izq {
    box-shadow: inset 3px 0 0 var(--amber);
}
.tabla tbody tr {
    cursor: default;
}
.tabla-scroll.alta tbody tr {
    cursor: pointer;
}
.tabla-scroll.alta tbody tr:hover td,
.tabla-scroll.alta tbody tr:hover th {
    background: color-mix(in srgb, var(--amber) 6%, transparent);
}
.tabla tr.elegida td,
.tabla tr.elegida th {
    background: color-mix(in srgb, var(--amber) 12%, transparent);
}
.vacio-fila {
    text-align: center !important;
    color: var(--text-faint);
}
.punto {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 999px;
    margin-right: 8px;
}
.parcial {
    margin-left: 6px;
    font-size: 0.58rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--amber);
    border: 1px solid color-mix(in srgb, var(--amber) 40%, transparent);
    border-radius: 999px;
    padding: 0 6px;
}

/* Por funnel */
.funnel-barra {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin: 28px 0 14px;
}
.funnel-barra .seccion {
    margin-right: auto;
}
.seccion {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}
.etapa {
    border-top: 3px solid var(--color-etapa);
}
.punto.grande {
    width: 10px;
    height: 10px;
}
.bloque {
    min-width: 0;
}
.bloque-titulo {
    margin: 0 0 6px;
    font-size: 0.8rem;
    font-weight: 600;
    display: flex;
    gap: 8px;
    align-items: baseline;
}
.bloque-titulo small {
    font-size: 0.66rem;
    font-weight: 400;
    color: var(--text-faint);
}
.lectura {
    margin: 16px 0 4px;
}

/* R² */
.r2 {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.r2-valor {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.6rem;
    font-weight: 700;
}
.r2-nivel {
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-radius: 999px;
    padding: 2px 10px;
}
.r2-nivel.sube {
    color: var(--mint);
    background: color-mix(in srgb, var(--mint) 18%, transparent);
}
.r2-nivel.medio {
    color: var(--yellow);
    background: color-mix(in srgb, var(--yellow) 18%, transparent);
}
.r2-nivel.baja {
    color: var(--coral);
    background: color-mix(in srgb, var(--coral) 18%, transparent);
}
.r2 .hint-chico {
    flex: 1 1 320px;
}

/* Gráfica */
.grafica {
    position: relative;
}
.grafica svg {
    width: 100%;
    height: auto;
    display: block;
}
.ejes text {
    font-size: 11px;
    fill: var(--text-faint);
    font-family: 'JetBrains Mono', monospace;
}
.ejes .titulo-eje {
    font-family: 'Inter', sans-serif;
    fill: var(--text-muted);
}
.grid {
    stroke: var(--border);
    stroke-width: 1;
}
.tendencia {
    stroke: var(--amber);
    stroke-width: 2;
    stroke-dasharray: 6 5;
}
.marca {
    stroke: var(--surface);
    stroke-width: 2;
}
.hit {
    fill: transparent;
    cursor: pointer;
}
.punto-g:hover .marca {
    r: 8;
}
.etiqueta {
    font-size: 11px;
    fill: var(--text-muted);
    pointer-events: none;
}
.tooltip {
    position: absolute;
    top: 8px;
    right: 8px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 0.76rem;
    color: var(--text-muted);
    box-shadow: var(--shadow);
    pointer-events: none;
}
.tooltip strong {
    color: var(--text);
}

/* Modal tipos */
.modal-overlay {
    display: block;
}
.tipos-modal {
    max-width: 980px;
    width: calc(100% - 32px);
    margin: 6vh auto;
    max-height: calc(86vh / var(--zoom-factor, 1));
    display: flex;
    flex-direction: column;
}
.tipos-modal h2 {
    margin: 0 0 4px;
}
.tipos-modal .hint {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin: 0 0 12px;
}
.tipos-top {
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.buscar,
.tipo-fila input[type='text'] {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    padding: 7px 10px;
    font-size: 0.8rem;
}
.buscar {
    flex: 1 1 260px;
}
.buscar:focus-visible,
.tipo-fila input[type='text']:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.check {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    font-size: 0.78rem;
    color: var(--text-muted);
    white-space: nowrap;
}
.tipos-lista {
    overflow: auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.tipo-fila {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) auto auto;
    gap: 10px;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}
.tipo-fila.oculta .tipo-campania {
    opacity: 0.5;
}
.tipo-campania {
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.tipo-campania .mono {
    font-size: 0.72rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tipo-campania small {
    font-size: 0.68rem;
    color: var(--text-faint);
}
.modal-copy-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.error {
    color: var(--coral);
    font-size: 0.8rem;
    margin: 0 0 8px;
}

@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
    .tipo-fila {
        grid-template-columns: 1fr;
    }
}
</style>
