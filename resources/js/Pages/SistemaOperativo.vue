<script setup>
import AvisoCostosApi from '@/Components/AvisoCostosApi.vue';
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';

// SO (2026-11-18, pedido explícito) -- réplica de so.2026 / ✅SO / SO ✅:
// cada métrica por sistema operativo, con los periodos en columnas como la
// hoja. El servidor (PanelSistemaOperativo) manda días; aquí se suma.
const props = defineProps({
    pais: { type: String, required: true },
    // [fecha, so (0 android | 1 ios), paid (0|1), installs, nc, recompras, registros]
    dias: { type: Array, default: () => [] },
    // [fecha, so (0 android | 1 ios | 2 web), costo]
    inversion: { type: Array, default: () => [] },
    anios: { type: Array, default: () => [] },
    hasta: { type: String, default: null },
    tieneAfData: { type: Boolean, default: true },
    fuentePaid: { type: String, default: 'TADA PERFORMANCE' },
});

const nf = new Intl.NumberFormat('es-MX');
const usd = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const SO = [
    { i: 0, nombre: 'Android', color: 'serie-1' },
    { i: 1, nombre: 'iOS', color: 'serie-3' },
    { i: 2, nombre: 'Web', color: 'serie-5' },
];

// --- Filtros -------------------------------------------------------------------
const ALCANCES = [
    { key: 'total', label: 'Total negocio' },
    { key: 'paid', label: 'Paid' },
];
const GRANULARIDADES = [
    { key: 'mes', label: 'Mes' },
    { key: 'semana', label: 'Semana' },
];
const alcance = ref('total');
const gran = ref('mes');
const ANIO_OPCIONES = computed(() => props.anios.map((y) => ({ key: y, label: String(y) })));
const anio = ref(props.anios[0] ?? new Date().getFullYear());

// --- Periodos (semana 1 = del 1 de enero al primer domingo, como la hoja) --------
const fecha = (y, m, d) => new Date(Date.UTC(y, m, d));
const masDias = (d, n) => new Date(d.getTime() + n * 86400000);
const corto = (d) => `${d.getUTCDate()} ${MES_CORTO[d.getUTCMonth()]}`;
function semanasDe(y) {
    const ene1 = fecha(y, 0, 1);
    const desfase = (ene1.getUTCDay() + 6) % 7;
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
const periodos = computed(() => {
    if (gran.value === 'mes') {
        return MESES.map((m, i) => ({ p: i + 1, label: m, sub: '', inicio: fecha(anio.value, i, 1), fin: fecha(anio.value, i + 1, 0) }));
    }
    return semanasDe(anio.value).map((s, i) => ({ p: i + 1, label: `Semana ${i + 1}`, sub: `${corto(s.inicio)} – ${corto(s.fin)}`, ...s }));
});
/** Índice del periodo de una fecha "Y-m-d" del año elegido (null si es de otro año). */
function periodoDe(f) {
    if (+f.slice(0, 4) !== anio.value) return null;
    if (gran.value === 'mes') return +f.slice(5, 7) - 1;
    const d = fecha(+f.slice(0, 4), +f.slice(5, 7) - 1, +f.slice(8, 10));
    const desfase = (fecha(anio.value, 0, 1).getUTCDay() + 6) % 7;
    const doy = Math.round((d - fecha(anio.value, 0, 1)) / 86400000);
    return Math.floor((doy + desfase) / 7);
}

// --- Sumas -----------------------------------------------------------------------
const METRICAS = [
    { key: 'inversion', titulo: 'Inversión', soloPaid: true, dinero: true, sos: 3 },
    { key: 'installs', titulo: 'Installs', col: 3, sos: 2 },
    { key: 'nc', titulo: 'New Customer', col: 4, sos: 2 },
    { key: 'recompras', titulo: 'Recompras', col: 5, sos: 2 },
    { key: 'registros', titulo: 'Registration', col: 6, sos: 2 },
];
const metricas = computed(() => METRICAS.filter((m) => !m.soloPaid || alcance.value === 'paid'));

/** acc[metrica][so][periodo] y si el periodo trae datos. */
const sumas = computed(() => {
    const n = periodos.value.length;
    const vacio = () => SO.map(() => Array(n).fill(0));
    const acc = Object.fromEntries(METRICAS.map((m) => [m.key, vacio()]));
    const conDatos = Object.fromEntries(METRICAS.map((m) => [m.key, Array(n).fill(false)]));
    for (const d of props.dias) {
        if (alcance.value === 'paid' && !d[2]) continue;
        const p = periodoDe(d[0]);
        if (p === null || p >= n) continue;
        for (const m of METRICAS) {
            if (!m.col) continue;
            acc[m.key][d[1]][p] += d[m.col];
            conDatos[m.key][p] = true;
        }
    }
    for (const d of props.inversion) {
        const p = periodoDe(d[0]);
        if (p === null || p >= n) continue;
        acc.inversion[d[1]][p] += d[2];
        conDatos.inversion[p] = true;
    }
    return { acc, conDatos };
});

/** Filas de cada tabla: un renglón por SO, Total y los % (participación por mes, cambio vs la semana anterior por semana). */
const tablas = computed(() =>
    metricas.value.map((m) => {
        const acc = sumas.value.acc[m.key];
        const con = sumas.value.conDatos[m.key];
        const sos = SO.slice(0, m.sos);
        const total = periodos.value.map((_, p) => (con[p] ? sos.reduce((t, s) => t + acc[s.i][p], 0) : null));
        const valores = sos.map((s) => periodos.value.map((_, p) => (con[p] ? acc[s.i][p] : null)));
        const pct = sos.map((s, k) =>
            periodos.value.map((_, p) => {
                const v = valores[k][p];
                if (v === null) return null;
                if (gran.value === 'mes') return total[p] ? v / total[p] : null;
                const prev = p > 0 ? valores[k][p - 1] : null;
                return prev ? (v - prev) / prev : null;
            }),
        );
        // Resumen del año: participación de cada SO en lo que va.
        const anual = sos.map((s, k) => valores[k].reduce((t, v) => t + (v ?? 0), 0));
        const anualTotal = anual.reduce((a, b) => a + b, 0);
        const maximo = Math.max(1, ...total.filter((v) => v !== null));
        return { ...m, sos, valores, total, pct, maximo, anual, anualTotal };
    }),
);
const hayDatos = computed(() => tablas.value.some((t) => t.total.some((v) => v !== null)));

const fmt = (t, v) => (v === null ? '–' : t.dinero ? usd.format(v) : nf.format(Math.round(v)));
const fmtPct = (v) => (v === null || !isFinite(v) ? '' : `${gran.value === 'semana' && v > 0 ? '+' : ''}${(v * 100).toFixed(0)}%`);
const clasePct = (v) => (gran.value !== 'semana' || v === null ? '' : v > 0.005 ? 'sube' : v < -0.005 ? 'baja' : '');
const tinte = (v, max) => (v === null ? null : { '--tinte': `${Math.round((v / max) * 22)}%` });

// --- Periodo en curso y scroll hacia él --------------------------------------------
const hoyClave = computed(() => props.hasta ?? new Date().toISOString().slice(0, 10));
const actual = computed(() => periodoDe(hoyClave.value));
const esParcial = (p) => p === actual.value && props.hasta && periodos.value[p] && periodos.value[p].fin.toISOString().slice(0, 10) > props.hasta;
const scrolls = ref([]);
function alActual() {
    nextTick(() => {
        for (const el of scrolls.value) {
            if (!el) continue;
            const celda = el.querySelector('th.actual');
            if (celda) el.scrollLeft = Math.max(0, celda.offsetLeft - el.clientWidth + celda.offsetWidth + 40);
        }
    });
}
onMounted(alActual);
watch([gran, anio, alcance], alActual);

const fechaLarga = (f) => `${+f.slice(8, 10)} de ${MESES[+f.slice(5, 7) - 1].toLowerCase()} ${f.slice(0, 4)}`;
</script>

<template>
    <Head :title="`SO — ${pais}`" />
    <DashboardLayout :pais="pais" vista-activa="so">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Sistema operativo</h1>
                        <p class="subtitle">
                            Android contra iOS en installs, new customers, recompras y registros, del negocio completo o solo Paid ({{ fuentePaid }}).
                            Sale de AF data<template v-if="hasta"> (hasta el {{ fechaLarga(hasta) }})</template>; la inversión, de data.medios.
                        </p>
                        <AvisoCostosApi />
                    </div>
                </header>

                <div class="resumen-filtros">
                    <div class="periodo">
                        <span class="periodo-label mono">Alcance</span>
                        <div class="periodo-toggle" role="group" aria-label="Alcance">
                            <button v-for="a in ALCANCES" :key="a.key" type="button" :class="{ activo: alcance === a.key }" @click="alcance = a.key">{{ a.label }}</button>
                        </div>
                    </div>
                    <div class="periodo">
                        <span class="periodo-label mono">Ver por</span>
                        <div class="periodo-toggle" role="group" aria-label="Ver por">
                            <button v-for="g in GRANULARIDADES" :key="g.key" type="button" :class="{ activo: gran === g.key }" @click="gran = g.key">{{ g.label }}</button>
                        </div>
                    </div>
                    <FiltroDropdown v-if="ANIO_OPCIONES.length > 1" v-model="anio" label="Año" :options="ANIO_OPCIONES" />
                </div>

                <p v-if="!tieneAfData" class="aviso-panel">
                    Este país todavía no tiene AF data: installs, new customers, recompras y registros salen vacíos hasta que se configure su limpieza.<template v-if="alcance === 'paid'"> La inversión sí sale de data.medios.</template>
                </p>

                <p v-if="!hayDatos" class="empty-note">Todavía no hay datos de {{ anio }} para este país.</p>

                <template v-else>
                    <section v-for="(t, ti) in tablas" :key="t.key" class="import-panel panel">
                        <div class="panel-head">
                            <h2>{{ t.titulo }}</h2>
                            <div v-if="t.anualTotal" class="reparto" :aria-label="`Reparto de ${anio}`">
                                <div class="reparto-barra">
                                    <span v-for="(s, k) in t.sos" v-show="t.anual[k]" :key="s.i" :style="{ width: `${(t.anual[k] / t.anualTotal) * 100}%`, background: `var(--${s.color})` }" />
                                </div>
                                <span v-for="(s, k) in t.sos" :key="`l${s.i}`" class="reparto-item mono">
                                    <i :style="{ background: `var(--${s.color})` }" />{{ s.nombre }} {{ ((t.anual[k] / t.anualTotal) * 100).toFixed(0) }}%
                                </span>
                                <span class="reparto-item mono total">{{ anio }}: {{ fmt(t, t.anualTotal) }}</span>
                            </div>
                        </div>
                        <div :ref="(el) => (scrolls[ti] = el)" class="tabla-scroll">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th class="fija">{{ t.titulo }}</th>
                                        <th v-for="(per, p) in periodos" :key="per.p" :class="{ actual: p === actual }">
                                            {{ per.label }}<span v-if="esParcial(p)" class="parcial mono" :title="`Datos hasta el ${fechaLarga(hasta)}`">parcial</span>
                                            <small v-if="per.sub">{{ per.sub }}</small>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(s, k) in t.sos" :key="s.i">
                                        <th class="fija"><i class="punto" :style="{ background: `var(--${s.color})` }" />{{ s.nombre }}</th>
                                        <td v-for="(v, p) in t.valores[k]" :key="p" class="valor" :class="{ vacio: v === null }" :style="tinte(v, t.maximo)">{{ fmt(t, v) }}</td>
                                    </tr>
                                    <tr class="fila-total">
                                        <th class="fija">Total</th>
                                        <td v-for="(v, p) in t.total" :key="p" class="valor" :class="{ vacio: v === null }">{{ fmt(t, v) }}</td>
                                    </tr>
                                    <tr v-for="(s, k) in t.sos" :key="`p${s.i}`" class="fila-pct">
                                        <th class="fija">% {{ s.nombre }}<small>{{ gran === 'mes' ? 'del total' : 'vs semana anterior' }}</small></th>
                                        <td v-for="(v, p) in t.pct[k]" :key="p" class="pct" :class="clasePct(v)">{{ fmtPct(v) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <p class="nota">
                        El sistema operativo sale de la app en AF data. Paid = la fuente {{ fuentePaid }}. La inversión se reparte por el nombre de la campaña (IOS → iOS, WEB → Web, el resto Android; Apple Search Ads siempre iOS).
                        Por semana, los % son el cambio contra la semana anterior; por mes, la participación de cada sistema.
                    </p>
                </template>
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
.panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.panel-head h2 {
    margin: 0;
}
.reparto {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.reparto-barra {
    display: flex;
    gap: 2px;
    width: 160px;
    height: 8px;
    border-radius: 999px;
    overflow: hidden;
    background: var(--surface-2);
}
.reparto-barra span {
    height: 100%;
}
.reparto-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.7rem;
    color: var(--text-muted);
}
.reparto-item i,
.punto {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 999px;
}
.reparto-item.total {
    color: var(--text-faint);
}
.punto {
    margin-right: 8px;
}

/* Tabla */
.tabla-scroll {
    overflow-x: auto;
    max-width: 100%;
    padding-bottom: 6px;
}
.tabla-scroll::-webkit-scrollbar {
    height: 10px;
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
    background: var(--surface);
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    font-weight: 600;
    min-width: 84px;
}
.tabla thead th small {
    display: block;
    font-size: 0.6rem;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
    font-family: 'JetBrains Mono', monospace;
}
.tabla thead th.actual {
    color: var(--amber);
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
    z-index: 2;
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
}
.tabla .fija small {
    display: block;
    font-size: 0.6rem;
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
.tabla tr.fila-total th,
.tabla tr.fila-total td {
    font-weight: 700;
    color: var(--text);
    background: var(--surface-2);
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

@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
}
</style>
