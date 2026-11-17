<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';

// Channel (2026-11-17, pedido explícito) -- réplica de channel.monthly.2026 y
// channel.weekly.2026: por canal, el año elegido contra el anterior. El
// servidor (PanelCanales) manda por año → mes|semana → periodo → canal →
// [installs, new customers, orders].
const props = defineProps({
    pais: { type: String, required: true },
    canales: { type: Array, default: () => [] },
    anios: { type: Array, default: () => [] },
    valores: { type: Object, default: () => ({}) },
    // origen[año][mes|semana][periodo] = 'af' | 'parcial' | 'manual'
    origen: { type: Object, default: () => ({}) },
    desde: { type: String, default: null },
    hasta: { type: String, default: null },
    tieneAfData: { type: Boolean, default: true },
});

const METRICAS = [
    { key: 'installs', titulo: 'Installs', i: 0 },
    { key: 'nc', titulo: 'New Customer', i: 1 },
    { key: 'ordenes', titulo: 'Orders', i: 2 },
    { key: 'or', titulo: 'OR', ayuda: 'New Customer / Installs', tasa: true },
];

const nf = new Intl.NumberFormat('es-MX');
const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

// --- Filtros -------------------------------------------------------------------
const GRANULARIDADES = [
    { key: 'mes', label: 'Mes' },
    { key: 'semana', label: 'Semana' },
    { key: 'dia', label: 'Día' },
];
const gran = ref('mes');
const ANIO_OPCIONES = computed(() => [...props.anios].reverse().filter((y) => props.anios.includes(y - 1) || props.valores[y - 1]).map((y) => ({ key: y, label: `${y} vs ${y - 1}` })));
const anio = ref(ANIO_OPCIONES.value[0]?.key ?? new Date().getFullYear());
const anterior = computed(() => anio.value - 1);
// Por día se ve un mes a la vez; arranca en el último mes con AF data.
const MES_OPCIONES = MESES.map((m, i) => ({ key: i + 1, label: m }));
const mesDia = ref(props.hasta ? +props.hasta.slice(5, 7) : new Date().getMonth() + 1);
const DIAS_SEMANA = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const dd = (n) => String(n).padStart(2, '0');

// --- Periodos (semanas como la hoja: la 1 va del 1 de enero al primer domingo) -
const fecha = (y, m, d) => new Date(Date.UTC(y, m, d));
const clave = (d) => d.toISOString().slice(0, 10);
const masDias = (d, n) => new Date(d.getTime() + n * 86400000);
const corto = (d) => `${d.getUTCDate()} ${MES_CORTO[d.getUTCMonth()]}`;
function semanasDe(y) {
    const ene1 = fecha(y, 0, 1);
    const desfase = (ene1.getUTCDay() + 6) % 7; // lunes = 0
    const dic31 = fecha(y, 11, 31);
    const salida = [];
    for (let k = 1; ; k++) {
        const inicio = k === 1 ? ene1 : masDias(ene1, 7 * (k - 1) - desfase);
        if (inicio > dic31) break;
        let fin = masDias(ene1, 7 * k - desfase - 1);
        if (fin > dic31) fin = dic31;
        salida.push({ inicio, fin });
    }
    return salida;
}
function rango(y, p) {
    if (gran.value === 'mes') return { inicio: fecha(y, p - 1, 1), fin: fecha(y, p, 0) };
    if (gran.value === 'dia') {
        const d = fecha(y, +p.slice(0, 2) - 1, +p.slice(3));
        return { inicio: d, fin: d };
    }
    return semanasDe(y)[p - 1] ?? null;
}
const periodos = computed(() => {
    if (gran.value === 'dia') {
        // Día contra el mismo día del año anterior (clave "MM-DD").
        const n = new Date(Date.UTC(anio.value, mesDia.value, 0)).getUTCDate();
        return Array.from({ length: n }, (_, i) => {
            const p = `${dd(mesDia.value)}-${dd(i + 1)}`;
            const r = rango(anio.value, p);
            const ra = rango(anterior.value, p);
            return { p, label: `${i + 1} ${MES_CORTO[mesDia.value - 1]}`, sub: `${DIAS_SEMANA[r.inicio.getUTCDay()]} · ${anterior.value}: ${DIAS_SEMANA[ra.inicio.getUTCDay()]}`, r, ra };
        });
    }
    const n = gran.value === 'mes' ? 12 : Math.max(semanasDe(anio.value).length, semanasDe(anterior.value).length);
    return Array.from({ length: n }, (_, i) => {
        const p = i + 1;
        const r = rango(anio.value, p);
        const ra = rango(anterior.value, p);
        return {
            p,
            label: gran.value === 'mes' ? MESES[i] : `Semana ${p}`,
            sub: r ? `${corto(r.inicio)} – ${corto(r.fin)}` : '',
            subAnterior: ra ? `${corto(ra.inicio)} – ${corto(ra.fin)}` : '',
            r,
            ra,
        };
    });
});

/** ¿Hay datos de ese año en ese periodo? (El servidor ya decidió de dónde salen.) */
const conDatos = (y, p) => !!props.valores[y]?.[gran.value]?.[p];
const origenDe = (y, p) => props.origen[y]?.[gran.value]?.[p] ?? null;

// --- Valores ---------------------------------------------------------------------
/** [installs, nc, ordenes] de un canal (o 'total') en un año/periodo; null = sin datos. */
function trio(y, p, canal) {
    if (!conDatos(y, p)) return null;
    const fila = props.valores[y]?.[gran.value]?.[p] ?? {};
    if (canal !== 'total') return fila[canal] ?? [0, 0, 0];
    return props.canales.reduce((t, c) => {
        const v = fila[c.id] ?? [0, 0, 0];
        return [t[0] + v[0], t[1] + v[1], t[2] + v[2]];
    }, [0, 0, 0]);
}
function valor(m, t) {
    if (!t) return null;
    if (m.tasa) return t[0] ? t[1] / t[0] : null;
    return t[m.i];
}
const sumarTrios = (lista) => lista.reduce((t, v) => [t[0] + v[0], t[1] + v[1], t[2] + v[2]], [0, 0, 0]);

const columnas = computed(() => [...props.canales, { id: 'total', nombre: 'Total', color: null }]);

/** Celdas por métrica: filas[periodo][columna] = { prev, cur, pct } y total a la fecha. */
const tablas = computed(() =>
    METRICAS.map((m) => {
        const filas = periodos.value.map((per) => ({
            ...per,
            celdas: columnas.value.map((c) => {
                const tp = trio(anterior.value, per.p, c.id);
                const tc = trio(anio.value, per.p, c.id);
                return celda(m, valor(m, tp), valor(m, tc));
            }),
        }));
        // Total: solo los periodos que ya tienen datos del año elegido, contra
        // los mismos periodos del anterior (comparación justa a la fecha).
        const conCur = periodos.value.filter((per) => conDatos(anio.value, per.p));
        const total = columnas.value.map((c) => {
            const prev = conCur.map((per) => trio(anterior.value, per.p, c.id)).filter(Boolean);
            const cur = conCur.map((per) => trio(anio.value, per.p, c.id)).filter(Boolean);
            return celda(m, prev.length ? valor(m, sumarTrios(prev)) : null, cur.length ? valor(m, sumarTrios(cur)) : null);
        });
        // Intensidad del tinte por columna (más alto = más intenso), solo del año elegido.
        const maximos = columnas.value.map((_, j) => Math.max(0, ...filas.map((f) => f.celdas[j].cur ?? 0), ...filas.map((f) => f.celdas[j].prev ?? 0)));
        return { ...m, filas, total, maximos };
    }),
);
function celda(m, prev, cur) {
    const pct = prev && cur !== null && prev !== null ? cur / prev - 1 : null;
    return { prev, cur, pct };
}

const fmt = (m, v) => (v === null || v === undefined ? '–' : m.tasa ? `${(v * 100).toFixed(2)}%` : nf.format(v));
const fmtPct = (x) => (x === null ? '–' : `${x >= 0 ? '+' : ''}${(x * 100).toFixed(0)}%`);
const clasePct = (x) => (x === null ? '' : x >= 0.005 ? 'sube' : x <= -0.005 ? 'baja' : '');
const tinte = (v, max) => (v === null || !max ? null : { '--tinte': `${Math.round(3 + (v / max) * 15)}%` });

const hayDatos = computed(() => !!props.valores[anio.value] || !!props.valores[anterior.value]);
const fechaLarga = (s) => (s ? `${+s.slice(8, 10)} ${MES_CORTO[+s.slice(5, 7) - 1]} ${s.slice(0, 4)}` : '');
const origenesAnterior = computed(() => new Set(Object.values(props.origen[anterior.value]?.[gran.value] ?? {})));
const anteriorSinDatos = computed(() => (gran.value === 'dia' ? !periodos.value.some((per) => conDatos(anterior.value, per.p)) : origenesAnterior.value.size === 0));

// Lleva la tabla al periodo en curso.
const scrolls = ref([]);
function centrar() {
    nextTick(() => {
        if (gran.value === 'mes') return;
        for (const cont of scrolls.value) {
            const fila = cont?.querySelector('tr.actual');
            if (cont && fila) cont.scrollTop = Math.max(0, fila.offsetTop - cont.clientHeight / 2);
        }
    });
}
watch([gran, anio, mesDia], centrar);
onMounted(centrar);
/** El periodo con datos que el export todavía no cubre completo. */
const esParcial = (per) => !!props.hasta && conDatos(anio.value, per.p) && props.valores[anio.value]?.origen?.[gran.value] !== 'manual' && clave(per.r.fin) > props.hasta;
const esActual = (per) => {
    const hoy = clave(new Date());
    return per.r && clave(per.r.inicio) <= hoy && hoy <= clave(per.r.fin);
};
</script>

<template>
    <Head :title="`Channel — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="channel">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Channel</h1>
                        <p class="subtitle">
                            Installs, New Customer, Orders y OR por canal, {{ anio }} contra {{ anterior }}.
                            El año en curso sale de AF data por media source<template v-if="hasta"> (hasta el {{ fechaLarga(hasta) }})</template>.
                        </p>
                    </div>
                </header>

                <div class="resumen-filtros">
                    <div class="periodo">
                        <span class="periodo-label mono">Ver por</span>
                        <div class="periodo-toggle" role="group" aria-label="Ver por">
                            <button v-for="g in GRANULARIDADES" :key="g.key" type="button" :class="{ activo: gran === g.key }" @click="gran = g.key">{{ g.label }}</button>
                        </div>
                    </div>
                    <FiltroDropdown v-if="ANIO_OPCIONES.length > 1" label="Año" v-model="anio" :options="ANIO_OPCIONES" />
                    <FiltroDropdown v-if="gran === 'dia'" label="Mes" v-model="mesDia" :options="MES_OPCIONES" />
                </div>

                <p v-if="!tieneAfData" class="aviso-panel">
                    Este país todavía no tiene AF data, así que {{ anio }} sale vacío; {{ anterior }} es lo que el equipo capturó en la hoja.
                </p>
                <p v-else-if="anteriorSinDatos" class="aviso-panel">
                    <template v-if="gran === 'dia'">{{ anterior }} no tiene AF data en {{ MESES[mesDia - 1].toLowerCase() }}: por día solo hay lo que se sube a AF data. Si suben {{ anterior }} en AF data, aquí aparece.</template>
                    <template v-else>{{ anterior }} no tiene datos {{ gran === 'semana' ? 'por semana' : 'por mes' }} para este país. Si suben {{ anterior }} en AF data, se llena solo.</template>
                </p>

                <p v-if="!hayDatos" class="empty-note">Todavía no hay datos de canales para este país.</p>

                <section v-for="(t, ti) in tablas" v-else :key="t.key" class="import-panel panel">
                    <h2>{{ t.titulo }}<small v-if="t.ayuda" class="mono">{{ t.ayuda }}</small></h2>
                    <div :ref="(el) => (scrolls[ti] = el)" class="tabla-scroll" :class="{ alta: gran !== 'mes' }">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th class="fija" rowspan="2">{{ GRANULARIDADES.find((g) => g.key === gran).label }}</th>
                                    <th v-for="c in columnas" :key="c.id" colspan="3" class="canal" :class="{ total: c.id === 'total' }" :style="c.color ? { '--color-canal': `var(--${c.color})` } : null">
                                        {{ c.nombre }}
                                    </th>
                                </tr>
                                <tr>
                                    <template v-for="c in columnas" :key="`a${c.id}`">
                                        <th class="sub">{{ anterior }}</th>
                                        <th class="sub">{{ anio }}</th>
                                        <th class="sub pct">%</th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="f in t.filas" :key="f.p" :class="{ actual: esActual(f) }">
                                    <th class="fija">
                                        {{ f.label }}<span v-if="esParcial(f)" class="parcial mono" :title="`Datos hasta el ${fechaLarga(hasta)}: compara días incompletos contra el periodo completo de ${anterior}.`">parcial</span>
                                        <small>{{ f.sub }}<template v-if="origenesAnterior.size > 1 && origenDe(anterior, f.p) === 'manual'"> · {{ anterior }} a mano</template></small>
                                    </th>
                                    <template v-for="(c, j) in f.celdas" :key="j">
                                        <td class="valor" :class="{ vacio: c.prev === null }" :style="tinte(c.prev, t.maximos[j])">{{ fmt(t, c.prev) }}</td>
                                        <td class="valor" :class="{ vacio: c.cur === null }" :style="tinte(c.cur, t.maximos[j])">{{ fmt(t, c.cur) }}</td>
                                        <td class="pct" :class="clasePct(c.pct)">{{ fmtPct(c.pct) }}</td>
                                    </template>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th class="fija">Total a la fecha</th>
                                    <template v-for="(c, j) in t.total" :key="`t${j}`">
                                        <td class="valor">{{ fmt(t, c.prev) }}</td>
                                        <td class="valor">{{ fmt(t, c.cur) }}</td>
                                        <td class="pct" :class="clasePct(c.pct)">{{ fmtPct(c.pct) }}</td>
                                    </template>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <p v-if="hayDatos" class="nota">
                    Cada canal suma AF data por media source (todas las fuentes, como la hoja). <template v-if="origenesAnterior.has('manual')">{{ anterior }} usa lo capturado a mano en la hoja donde todavía no hay AF data<template v-if="origenesAnterior.has('af')"> (los periodos con AF data ya salen de ahí)</template>.</template>
                    "Total a la fecha" compara solo los {{ gran === 'mes' ? 'meses' : gran === 'semana' ? 'semanas' : 'días' }} que ya tienen datos de {{ anio }} contra los mismos de {{ anterior }}.
                    Jampp no se cuenta como canal.
                </p>
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
    max-width: 720px;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
}
.aviso-panel {
    font-size: 0.8rem;
    color: var(--text-muted);
    background: color-mix(in srgb, var(--amber) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--amber) 30%, transparent);
    border-radius: 10px;
    padding: 10px 14px;
    margin: 0 0 18px;
}
.nota {
    font-size: 0.72rem;
    color: var(--text-faint);
    margin: 0;
}
.periodo {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.periodo-label {
    font-size: 10px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-faint);
}
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

.panel {
    min-width: 0;
    margin-bottom: 18px;
}
.panel h2 {
    margin: 0 0 12px;
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.panel h2 small {
    font-size: 0.68rem;
    font-weight: 400;
    color: var(--text-faint);
}

/* Tabla */
.tabla-scroll {
    overflow: auto;
    max-width: 100%;
    padding-bottom: 6px;
}
.tabla-scroll.alta {
    max-height: calc(560px / var(--zoom-factor, 1));
}
.tabla-scroll::-webkit-scrollbar {
    height: 10px;
    width: 10px;
}
.tabla-scroll::-webkit-scrollbar-track {
    background: var(--surface-2);
    border-radius: 999px;
}
.tabla-scroll::-webkit-scrollbar-thumb {
    background: var(--text-faint);
    border-radius: 999px;
}
.tabla-scroll::-webkit-scrollbar-thumb:hover {
    background: var(--amber);
}
.tabla {
    border-collapse: separate;
    border-spacing: 0;
    width: max-content;
    min-width: 100%;
    font-size: 0.8rem;
}
.tabla th,
.tabla td {
    padding: 7px 10px;
    text-align: right;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.tabla thead th {
    position: sticky;
    background: var(--surface);
    z-index: 2;
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    font-weight: 600;
}
.tabla thead tr:first-child th {
    top: 0;
}
.tabla thead tr:nth-child(2) th {
    top: 33px;
}
.tabla thead th.canal {
    text-align: center;
    color: var(--text);
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 0.8rem;
    letter-spacing: 0.02em;
    border-top: 3px solid var(--color-canal, var(--text-faint));
    border-left: 1px solid var(--border);
}
.tabla thead th.canal.total {
    border-top-color: var(--amber);
}
.tabla thead th.sub {
    min-width: 76px;
}
.tabla thead th.pct,
.tabla td.pct {
    min-width: 56px;
    border-right: 1px solid var(--border);
}
.tabla .fija {
    position: sticky;
    left: 0;
    z-index: 1;
    background: var(--surface);
    text-align: left;
    font-weight: 500;
    color: var(--text-muted);
    font-size: 0.78rem;
    text-transform: none;
    letter-spacing: 0;
    min-width: 150px;
    border-right: 1px solid var(--border);
}
.tabla thead .fija {
    z-index: 3;
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
}
.tabla .fija small {
    display: block;
    font-size: 0.62rem;
    color: var(--text-faint);
    font-weight: 400;
    font-family: 'JetBrains Mono', monospace;
}
.tabla td.valor {
    background: color-mix(in srgb, var(--sky) var(--tinte, 0%), transparent);
}
.tabla td.vacio {
    color: var(--text-faint);
}
.tabla td.pct {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.72rem;
    color: var(--text-faint);
}
.tabla td.pct.sube {
    color: var(--mint);
    background: color-mix(in srgb, var(--mint) 10%, transparent);
}
.tabla td.pct.baja {
    color: var(--coral);
    background: color-mix(in srgb, var(--coral) 10%, transparent);
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
    cursor: help;
}
.tabla tr.actual .fija {
    color: var(--amber);
}
.tabla tfoot th,
.tabla tfoot td {
    position: sticky;
    bottom: 0;
    background: var(--surface-2);
    font-weight: 700;
    color: var(--text);
    border-top: 1px solid var(--border);
}
.tabla tfoot .fija {
    z-index: 3;
    background: var(--surface-2);
    color: var(--text);
}

@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
}
</style>
