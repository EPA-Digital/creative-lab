<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';

// Funnel de conversión (2026-11-17, pedido explícito) -- réplica de las
// hojas funnel.2026 y funnel.medio.2026 (TaDa ECU | Daily Cuentas) con el
// diseño de dashboard_funnel V2. El servidor manda AF data por día
// (FunnelFuentes); aquí se agrupa por mes / semana / día y por segmento.
const props = defineProps({
    pais: { type: String, required: true },
    fuentePaid: { type: String, default: 'TADA PERFORMANCE' },
    fuentes: { type: Array, default: () => [] },
    medios: { type: Array, default: () => [] },
    // [fecha, índice de fuente, índice de medio (-1 fuera de Paid), ...9 eventos en el orden de EVENTOS]
    dias: { type: Array, default: () => [] },
});

// --- Eventos y tasas (fórmulas de la hoja) -----------------------------------
const EVENTOS = [
    { key: 'installs', label: 'Installs' },
    { key: 'app_opened', label: 'App Opened' },
    { key: 'sessions', label: 'Sessions' },
    { key: 'product_viewed', label: 'Product Details Viewed' },
    { key: 'product_added', label: 'Product Added' },
    { key: 'payment', label: 'Payment Method Selected' },
    { key: 'order_submitted', label: 'Order Submitted' },
    { key: 'first_order', label: 'First Order' },
    { key: 'total_order', label: 'Total Order' },
];
const IDX = Object.fromEntries(EVENTOS.map((e, i) => [e.key, i]));
// a / b. "veces": Installs > App Opened pasa de 100% de sobra, se lee mejor como ×.
const TASAS = [
    { key: 'r_ao', label: 'Installs > App Opened', a: 'app_opened', b: 'installs', veces: true },
    { key: 'r_s', label: 'App Opened > Sessions', a: 'sessions', b: 'app_opened' },
    { key: 'r_pv', label: 'Sessions > Product View', a: 'product_viewed', b: 'sessions' },
    { key: 'r_pa', label: 'Product View > Product Added', a: 'product_added', b: 'product_viewed' },
    { key: 'r_pm', label: 'Product Added > Payment', a: 'payment', b: 'product_added' },
    { key: 'r_to', label: 'Payment > Total Order', a: 'total_order', b: 'payment' },
    { key: 'or', label: 'OR', a: 'first_order', b: 'installs', ayuda: 'First Order / Installs' },
    { key: 'cr', label: 'CR', a: 'total_order', b: 'sessions', ayuda: 'Total Order / Sessions' },
    { key: 'ir', label: 'IR', a: 'installs', b: 'sessions', ayuda: 'Installs / Sessions' },
];
const TASA = Object.fromEntries(TASAS.map((t) => [t.key, t]));
// Tasa contra la etapa anterior, en la fila de cada etapa del funnel.
const TASA_DE_ETAPA = { app_opened: 'r_ao', sessions: 'r_s', product_viewed: 'r_pv', product_added: 'r_pa', payment: 'r_pm', total_order: 'r_to' };
const ETAPAS_FUNNEL = EVENTOS.map((e) => e.key);

const nf = new Intl.NumberFormat('es-MX');
const cf = new Intl.NumberFormat('es-MX', { notation: 'compact', maximumFractionDigits: 1 });
const esTasa = (k) => !!TASA[k];
function fmt(k, v) {
    if (v === null || v === undefined || Number.isNaN(v)) return '–';
    if (!esTasa(k)) return nf.format(v);
    return TASA[k].veces ? `${v.toFixed(1)}×` : `${(v * 100).toFixed(1)}%`;
}
const fmtCorto = (k, v) => (esTasa(k) ? fmt(k, v) : cf.format(v));
const etiqueta = (k) => EVENTOS[IDX[k]]?.label ?? TASA[k]?.label ?? k;

// --- Segmentos -----------------------------------------------------------------
const idxPaid = computed(() => props.fuentes.indexOf(props.fuentePaid));
const idxOrganico = computed(() => props.fuentes.findIndex((f) => f.toUpperCase() === 'ORGANIC'));
const SEGMENTOS = computed(() => [
    { key: 'total', label: 'Total negocio', filtro: () => true },
    { key: 'paid', label: 'Paid (EPA)', filtro: (d) => d[1] === idxPaid.value },
    { key: 'organico', label: 'Orgánico', filtro: (d) => d[1] === idxOrganico.value },
    ...props.medios.map((m) => ({ key: `medio:${m.id}`, label: `Paid · ${m.label}`, filtro: (d) => d[2] === m.i })),
    ...props.fuentes
        .map((f, i) => ({ f, i }))
        .filter(({ i }) => i !== idxPaid.value && i !== idxOrganico.value)
        .sort((a, b) => a.f.localeCompare(b.f))
        .map(({ f, i }) => ({ key: `fuente:${f}`, label: `Otras · ${f}`, filtro: (d) => d[1] === i })),
]);
const segmento = ref('total');
const segmentoActual = computed(() => SEGMENTOS.value.find((s) => s.key === segmento.value) ?? SEGMENTOS.value[0]);

// --- Periodos ------------------------------------------------------------------
const GRANULARIDADES = [
    { key: 'mes', label: 'Mes' },
    { key: 'semana', label: 'Semana' },
    { key: 'dia', label: 'Día' },
];
const gran = ref('mes');
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MESES_LARGO = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const DIAS_SEMANA = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

// Fechas en UTC para no correr el día por zona horaria.
const aFecha = (s) => new Date(`${s}T00:00:00Z`);
const aClave = (d) => d.toISOString().slice(0, 10);
const masDias = (d, n) => new Date(d.getTime() + n * 86400000);
const lunesDe = (d) => masDias(d, -((d.getUTCDay() + 6) % 7));
function semanaIso(d) {
    const jueves = masDias(d, 3 - ((d.getUTCDay() + 6) % 7));
    const enero4 = new Date(Date.UTC(jueves.getUTCFullYear(), 0, 4));
    return 1 + Math.round((jueves - lunesDe(enero4)) / (7 * 86400000));
}
const diaCorto = (d) => `${d.getUTCDate()} ${MESES[d.getUTCMonth()]}`;

const ultimoDia = computed(() => props.dias.at(-1)?.[0] ?? null);
const primerDia = computed(() => props.dias[0]?.[0] ?? null);

// Año completo (2026-11-17, pedido explícito): las tablas muestran todos
// los meses / semanas del año aunque todavía no tengan datos ("–"), y se
// van llenando conforme se carga AF data.
const anios = computed(() => [...new Set(props.dias.map((d) => d[0].slice(0, 4)))].sort());
const anio = ref(anios.value.at(-1) ?? String(new Date().getUTCFullYear()));
const ANIO_OPCIONES = computed(() => [...anios.value].reverse().map((y) => ({ key: y, label: y })));

// Mes a mostrar en granularidad "Día" (un mes a la vez, del año elegido).
const MES_OPCIONES = computed(() => MESES_LARGO.map((m, i) => ({ key: `${anio.value}-${String(i + 1).padStart(2, '0')}`, label: m })));
const mesDia = ref((ultimoDia.value ?? '').slice(0, 7) || `${anio.value}-01`);
watch(anio, (y) => { mesDia.value = `${y}${mesDia.value.slice(4)}`; });

/** Clave de periodo de un día según la granularidad. */
function clavePeriodo(fecha) {
    if (gran.value === 'mes') return fecha.slice(0, 7);
    if (gran.value === 'semana') return aClave(lunesDe(aFecha(fecha)));
    return fecha;
}

/** Todos los periodos del año (o del mes, en "Día"), con o sin datos. */
const periodos = computed(() => {
    const y = +anio.value;
    const salida = [];
    const finDe = (desde, hasta) => ({ desde: aClave(desde), hasta: aClave(hasta) });
    if (gran.value === 'mes') {
        for (let m = 1; m <= 12; m++) {
            salida.push({
                clave: `${y}-${String(m).padStart(2, '0')}`,
                l: MESES[m - 1].replace(/^./, (c) => c.toUpperCase()),
                s: String(y),
                full: `${MESES_LARGO[m - 1]} ${y}`,
                ...finDe(new Date(Date.UTC(y, m - 1, 1)), new Date(Date.UTC(y, m, 0))),
            });
        }
    } else if (gran.value === 'semana') {
        // Semanas de lunes a domingo; la primera es la que tiene el 1 de enero.
        const fin = new Date(Date.UTC(y, 11, 31));
        for (let d = lunesDe(new Date(Date.UTC(y, 0, 1))); d <= fin; d = masDias(d, 7)) {
            const dom = masDias(d, 6);
            const n = semanaIso(d);
            salida.push({ clave: aClave(d), l: `S${n}`, s: diaCorto(d), full: `Semana ${n} · ${diaCorto(d)} – ${diaCorto(dom)} ${dom.getUTCFullYear()}`, ...finDe(d, dom) });
        }
    } else if (mesDia.value) {
        const [yy, m] = mesDia.value.split('-').map(Number);
        for (let d = new Date(Date.UTC(yy, m - 1, 1)); d.getUTCMonth() === m - 1; d = masDias(d, 1)) {
            salida.push({ clave: aClave(d), l: String(d.getUTCDate()), s: DIAS_SEMANA[d.getUTCDay()], full: `${DIAS_SEMANA[d.getUTCDay()]} ${diaCorto(d)} ${yy}`, ...finDe(d, d) });
        }
    }
    return salida;
});

/** Suma de eventos por periodo para el segmento elegido: { clave: [9 números] }. */
const sumas = computed(() => {
    const filtro = segmentoActual.value.filtro;
    const acc = {};
    for (const d of props.dias) {
        if (!filtro(d)) continue;
        const k = clavePeriodo(d[0]);
        const fila = (acc[k] ??= new Array(EVENTOS.length).fill(0));
        for (let i = 0; i < EVENTOS.length; i++) fila[i] += d[3 + i];
    }
    return acc;
});

/** Un periodo tiene datos si toca el rango cargado en AF data. */
const conDatos = (p) => !!primerDia.value && p.hasta >= primerDia.value && p.desde <= ultimoDia.value;

/** Serie por métrica (evento o tasa), alineada con `periodos`; null = sin datos. */
const series = computed(() => {
    const vacio = new Array(EVENTOS.length).fill(0);
    const filas = periodos.value.map((p) => (conDatos(p) ? sumas.value[p.clave] ?? vacio : null));
    const s = {};
    EVENTOS.forEach((e, i) => { s[e.key] = filas.map((f) => (f ? f[i] : null)); });
    TASAS.forEach((t) => { s[t.key] = filas.map((f) => (f && f[IDX[t.b]] ? f[IDX[t.a]] / f[IDX[t.b]] : null)); });
    return s;
});

// Periodo elegido: el último con datos al cambiar de año / granularidad / mes.
const sel = ref(0);
watch(periodos, () => {
    let i = periodos.value.length - 1;
    while (i > 0 && !conDatos(periodos.value[i])) i--;
    sel.value = Math.max(0, i);
}, { immediate: true });
const periodoSel = computed(() => periodos.value[sel.value] ?? null);
const variacion = (a, i) => (i > 0 && a[i - 1] && a[i] !== null && a[i - 1] !== null ? a[i] / a[i - 1] - 1 : null);
const hayDatos = computed(() => Object.keys(sumas.value).length > 0);

// El último periodo con datos suele estar a medias (el export llega hasta cierto día).
const periodoIncompleto = computed(() => {
    const p = periodoSel.value;
    return !!p && gran.value !== 'dia' && conDatos(p) && ultimoDia.value < p.hasta;
});

// --- KPIs ----------------------------------------------------------------------
const KPIS = ['total_order', 'first_order', 'or', 'cr', 'ir'];
function sparkline(a) {
    const v = a.filter((x) => x !== null);
    const n = v.length;
    if (!n) return '';
    const max = Math.max(...v);
    const min = Math.min(...v);
    const r = max - min || 1;
    return v.map((x, i) => `${n > 1 ? (i * 100) / (n - 1) : 50},${24 - ((x - min) / r) * 22}`).join(' ');
}

// --- Gráficas (como la de la hoja, pero en dos: cada una con su escala) ----------
// Barras: lo que se cuenta en miles (Installs y órdenes). Líneas: la
// actividad dentro de la app, que va en cientos de miles. Juntas en una sola
// gráfica necesitarían dos ejes y las escalas engañan.
const SERIES_BARRAS = ['installs', 'order_submitted', 'first_order'];
const SERIES_LINEAS = ['app_opened', 'sessions', 'product_viewed', 'product_added', 'payment'];
const color = (i) => `var(--serie-${i + 1})`;
const G = { W: 640, H: 260, l: 54, r: 12, t: 14, b: 28 };

function maxBonito(max) {
    if (max <= 0) return 1;
    const p = 10 ** Math.floor(Math.log10(max));
    const paso = [1, 2, 2.5, 5, 10].map((m) => m * p).find((x) => max / x <= 4) ?? 10 * p;
    return paso * 4;
}
function base(keys) {
    const n = periodos.value.length;
    const max = maxBonito(Math.max(0, ...keys.flatMap((k) => series.value[k])));
    const banda = (G.W - G.l - G.r) / Math.max(n, 1);
    const centro = (i) => G.l + banda * (i + 0.5);
    const Y = (v) => G.t + (G.H - G.t - G.b) * (1 - v / max);
    const ejes = [0, 1, 2, 3, 4].map((t) => ({ y: Y((max * t) / 4), texto: cf.format((max * t) / 4) }));
    const paso = Math.ceil(n / 12);
    const etiquetasX = periodos.value.map((p, i) => ({ x: centro(i), texto: p.l, ver: i % paso === 0 }));
    return { n, banda, centro, Y, ejes, etiquetasX, piso: Y(0) };
}
const barras = computed(() => {
    const b = base(SERIES_BARRAS);
    const ancho = Math.max(2, Math.min(16, (b.banda * 0.78 - 2 * (SERIES_BARRAS.length - 1)) / SERIES_BARRAS.length));
    const grupo = ancho * SERIES_BARRAS.length + 2 * (SERIES_BARRAS.length - 1);
    const rects = [];
    periodos.value.forEach((p, i) => {
        SERIES_BARRAS.forEach((k, j) => {
            const v = series.value[k][i];
            if (!v) return;
            const x = b.centro(i) - grupo / 2 + j * (ancho + 2);
            const y = b.Y(v);
            const r = Math.min(3, ancho / 2, b.piso - y);
            rects.push({ id: `${p.clave}-${k}`, color: color(j), d: `M${x},${b.piso}V${y + r}Q${x},${y} ${x + r},${y}H${x + ancho - r}Q${x + ancho},${y} ${x + ancho},${y + r}V${b.piso}Z` });
        });
    });
    return { ...b, rects };
});
const lineas = computed(() => {
    const b = base(SERIES_LINEAS);
    const trazos = SERIES_LINEAS.map((k, j) => ({
        k,
        color: color(j),
        puntos: series.value[k].map((v, i) => (v === null ? null : { x: b.centro(i), y: b.Y(v) })),
    }));
    // Tramos continuos: la línea se corta en los periodos sin datos.
    for (const t of trazos) {
        t.tramos = [];
        let actual = [];
        for (const p of t.puntos) {
            if (p) actual.push(`${p.x},${p.y}`);
            else if (actual.length) { t.tramos.push(actual.join(' ')); actual = []; }
        }
        if (actual.length) t.tramos.push(actual.join(' '));
    }
    return { ...b, trazos };
});
const hoverBarras = ref(null);
const hoverLineas = ref(null);
const posTooltip = (g, i) => ({ left: `${(g.centro(i) / G.W) * 100}%`, transform: g.centro(i) > G.W * 0.6 ? 'translateX(calc(-100% - 12px))' : 'translateX(12px)' });

// --- Tablas ----------------------------------------------------------------------
const tablaEventos = ref(null);
const tablaTasas = ref(null);
// Lleva la columna elegida al centro (al cargar y al cambiar de vista).
function centrarSel() {
    nextTick(() => {
        for (const cont of [tablaEventos.value, tablaTasas.value]) {
            const th = cont?.querySelector('th.sel');
            if (cont && th) cont.scrollLeft = Math.max(0, th.offsetLeft - cont.clientWidth / 2);
        }
    });
}
watch([sel, periodos], centrarSel);
onMounted(centrarSel);
const TABLAS = [
    { id: 'eventos', titulo: 'Eventos de la app', filas: EVENTOS.map((e) => e.key), ref: tablaEventos },
    { id: 'tasas', titulo: 'Tasas de conversión', filas: TASAS.map((t) => t.key), ref: tablaTasas },
];

const claseVar = (x) => (x === null ? '' : x >= 0.0005 ? 'sube' : x <= -0.0005 ? 'baja' : '');
const textoVar = (x) => (x === null ? '–' : `${x >= 0 ? '▲' : '▼'} ${Math.abs(x * 100).toFixed(1)}%`);
const fechaLarga = (s) => (s ? `${+s.slice(8, 10)} ${MESES[+s.slice(5, 7) - 1]} ${s.slice(0, 4)}` : '');
</script>

<template>
    <Head :title="`Funnel — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="funnel">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Funnel de conversión</h1>
                        <p class="subtitle">
                            Los eventos de la app etapa por etapa, de Installs a Total Order, con sus tasas de conversión.
                            Sale de AF data<template v-if="ultimoDia"> (hasta el {{ fechaLarga(ultimoDia) }})</template>.
                        </p>
                    </div>
                </header>

                <p v-if="!dias.length" class="empty-note">
                    Todavía no hay AF data para este país. Carga el export de AppsFlyer en Datos → AF data.
                </p>

                <template v-else>
                    <div class="resumen-filtros">
                        <FiltroDropdown label="Segmento" v-model="segmento" :options="SEGMENTOS" />
                        <div class="periodo">
                            <span class="periodo-label mono">Ver por</span>
                            <div class="periodo-toggle" role="group" aria-label="Ver por">
                                <button v-for="g in GRANULARIDADES" :key="g.key" type="button" :class="{ activo: gran === g.key }" @click="gran = g.key">{{ g.label }}</button>
                            </div>
                        </div>
                        <FiltroDropdown label="Año" v-model="anio" :options="ANIO_OPCIONES" />
                        <FiltroDropdown v-if="gran === 'dia'" label="Mes" v-model="mesDia" :options="MES_OPCIONES" />
                        <div v-if="hayDatos" class="periodo periodo-sel">
                            <span class="periodo-label mono">
                                Periodo<span v-if="periodoIncompleto" class="aviso"> · en curso, datos hasta el {{ fechaLarga(ultimoDia) }}</span>
                            </span>
                            <div class="stepper">
                                <button type="button" aria-label="Periodo anterior" :disabled="sel <= 0" @click="sel--">‹</button>
                                <span class="stepper-label">{{ periodoSel?.full }}</span>
                                <button type="button" aria-label="Periodo siguiente" :disabled="sel >= periodos.length - 1" @click="sel++">›</button>
                            </div>
                        </div>
                    </div>

                    <p v-if="!hayDatos" class="empty-note">Este segmento no tiene eventos en el periodo.</p>

                    <template v-else>

                        <section class="kpis">
                            <div v-for="k in KPIS" :key="k" class="kpi">
                                <span class="kpi-label" :title="TASA[k]?.ayuda">{{ etiqueta(k) }}</span>
                                <span class="kpi-value">{{ fmt(k, series[k][sel]) }}</span>
                                <span class="kpi-sub">
                                    <span class="var" :class="claseVar(variacion(series[k], sel))">{{ textoVar(variacion(series[k], sel)) }}</span>
                                    vs periodo anterior
                                </span>
                                <svg class="spark" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true">
                                    <polyline :points="sparkline(series[k])" fill="none" stroke="var(--amber)" stroke-width="1.6" vector-effect="non-scaling-stroke" />
                                </svg>
                            </div>
                        </section>

                        <section v-for="t in TABLAS" :key="t.id" class="import-panel panel">
                            <h2>{{ t.titulo }}</h2>
                            <div :ref="(el) => (t.ref.value = el)" class="tabla-scroll">
                                <table class="tabla">
                                    <thead>
                                        <tr>
                                            <th class="fija" />
                                            <th v-for="(p, i) in periodos" :key="p.clave" :class="{ sel: i === sel, vacio: !conDatos(p) }" @click="sel = i">
                                                {{ p.l }}<small>{{ p.s }}</small>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="k in t.filas" :key="k" :class="{ total: k === 'total_order' }">
                                            <th class="fija" :title="TASA[k]?.ayuda">
                                                {{ etiqueta(k) }}<small v-if="TASA[k]?.ayuda">{{ TASA[k].ayuda }}</small>
                                            </th>
                                            <td v-for="(p, i) in periodos" :key="p.clave" :class="{ sel: i === sel, vacio: !conDatos(p) }" @click="sel = i">
                                                {{ fmt(k, series[k][i]) }}
                                                <span class="var" :class="claseVar(variacion(series[k], i))">{{ textoVar(variacion(series[k], i)) }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <p class="nota nota-tablas">
                            Total Order = Order Submitted + First Order. Cada tasa es contra la etapa anterior; la variación (▲▼) es contra el periodo anterior.
                            Toca una columna para ver ese periodo arriba.
                        </p>

                        <div class="grid">
                            <section class="import-panel panel">
                                <h2>Installs y órdenes</h2>
                                <div class="leyenda">
                                    <span v-for="(k, j) in SERIES_BARRAS" :key="k"><i class="muestra cuadro" :style="{ background: color(j) }" />{{ etiqueta(k) }}</span>
                                </div>
                                <div class="grafica" @mouseleave="hoverBarras = null">
                                    <svg :viewBox="`0 0 ${G.W} ${G.H}`" role="img" aria-label="Installs, Order Submitted y First Order por periodo">
                                        <g v-for="(e, i) in barras.ejes" :key="`e${i}`">
                                            <line :x1="G.l" :x2="G.W - G.r" :y1="e.y" :y2="e.y" class="eje" />
                                            <text :x="G.l - 6" :y="e.y + 4" text-anchor="end">{{ e.texto }}</text>
                                        </g>
                                        <rect v-if="sel !== null" class="banda-sel" :x="barras.centro(sel) - barras.banda / 2" :y="G.t" :width="barras.banda" :height="barras.piso - G.t" />
                                        <path v-for="r in barras.rects" :key="r.id" :d="r.d" :fill="r.color" />
                                        <template v-for="(x, i) in barras.etiquetasX" :key="`x${i}`">
                                            <text v-if="x.ver" :x="x.x" :y="G.H - 8" text-anchor="middle">{{ x.texto }}</text>
                                        </template>
                                        <rect
                                            v-for="(p, i) in periodos"
                                            :key="`h${p.clave}`"
                                            class="zona"
                                            :x="barras.centro(i) - barras.banda / 2"
                                            y="0"
                                            :width="barras.banda"
                                            :height="G.H"
                                            @mouseenter="hoverBarras = i"
                                            @click="sel = i"
                                        />
                                    </svg>
                                    <div v-if="hoverBarras !== null" class="tooltip" :style="posTooltip(barras, hoverBarras)">
                                        <strong>{{ periodos[hoverBarras]?.full }}</strong>
                                        <span v-for="(k, j) in SERIES_BARRAS" :key="k"><i class="muestra cuadro" :style="{ background: color(j) }" />{{ etiqueta(k) }} <b>{{ fmt(k, series[k][hoverBarras]) }}</b></span>
                                    </div>
                                </div>
                            </section>

                            <section class="import-panel panel">
                                <h2>Actividad en la app</h2>
                                <div class="leyenda">
                                    <span v-for="(k, j) in SERIES_LINEAS" :key="k"><i class="muestra" :style="{ background: color(j) }" />{{ etiqueta(k) }}</span>
                                </div>
                                <div class="grafica" @mouseleave="hoverLineas = null">
                                    <svg :viewBox="`0 0 ${G.W} ${G.H}`" role="img" aria-label="App Opened, Sessions, Product Details Viewed, Product Added y Payment Method Selected por periodo">
                                        <g v-for="(e, i) in lineas.ejes" :key="`e${i}`">
                                            <line :x1="G.l" :x2="G.W - G.r" :y1="e.y" :y2="e.y" class="eje" />
                                            <text :x="G.l - 6" :y="e.y + 4" text-anchor="end">{{ e.texto }}</text>
                                        </g>
                                        <line v-if="sel !== null" class="guia" :x1="lineas.centro(sel)" :x2="lineas.centro(sel)" :y1="G.t" :y2="lineas.piso" />
                                        <line v-if="hoverLineas !== null" class="guia hover" :x1="lineas.centro(hoverLineas)" :x2="lineas.centro(hoverLineas)" :y1="G.t" :y2="lineas.piso" />
                                        <g v-for="tr in lineas.trazos" :key="tr.k">
                                            <polyline v-for="(t, ti) in tr.tramos" :key="ti" :points="t" fill="none" :stroke="tr.color" stroke-width="2" stroke-linejoin="round" />
                                            <template v-for="(p, i) in tr.puntos" :key="i">
                                            <circle
                                                v-if="p && (periodos.length <= 16 || i === hoverLineas || i === sel)"
                                                :cx="p.x"
                                                :cy="p.y"
                                                r="4"
                                                :fill="tr.color"
                                                stroke="var(--surface)"
                                                stroke-width="2"
                                            />
                                            </template>
                                        </g>
                                        <template v-for="(x, i) in lineas.etiquetasX" :key="`x${i}`">
                                            <text v-if="x.ver" :x="x.x" :y="G.H - 8" text-anchor="middle">{{ x.texto }}</text>
                                        </template>
                                        <rect
                                            v-for="(p, i) in periodos"
                                            :key="`h${p.clave}`"
                                            class="zona"
                                            :x="lineas.centro(i) - lineas.banda / 2"
                                            y="0"
                                            :width="lineas.banda"
                                            :height="G.H"
                                            @mouseenter="hoverLineas = i"
                                            @click="sel = i"
                                        />
                                    </svg>
                                    <div v-if="hoverLineas !== null" class="tooltip" :style="posTooltip(lineas, hoverLineas)">
                                        <strong>{{ periodos[hoverLineas]?.full }}</strong>
                                        <span v-for="(k, j) in SERIES_LINEAS" :key="k"><i class="muestra" :style="{ background: color(j) }" />{{ etiqueta(k) }} <b>{{ fmt(k, series[k][hoverLineas]) }}</b></span>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </template>
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
    max-width: 720px;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
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
.num {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
}

/* Variación vs periodo anterior */
.var {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.68rem;
    color: var(--text-faint);
}
.var.sube {
    color: var(--mint);
}
.var.baja {
    color: var(--coral);
}

/* KPIs */
.kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 18px;
}
.kpi {
    background: var(--surface);
    padding: 16px 18px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.kpi-label {
    font-size: 0.68rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.kpi-value {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.6rem;
    font-weight: 600;
    line-height: 1.1;
}
.kpi-sub {
    font-size: 0.72rem;
    color: var(--text-faint);
}
.spark {
    display: block;
    width: 100%;
    height: 26px;
    margin-top: 6px;
}

/* Paneles */
.grid {
    display: grid;
    grid-template-columns: 1.25fr 1fr;
    gap: 18px;
}
.panel {
    min-width: 0;
    margin-bottom: 18px;
}
.panel-cabeza {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.panel-cabeza h2 {
    margin: 0;
}
.nota {
    font-size: 0.72rem;
    color: var(--text-faint);
    margin: 12px 0 0;
}
.aviso {
    font-size: 0.7rem;
    color: var(--amber);
    margin: 0 0 10px;
}

/* Stepper de periodo */
.stepper {
    display: flex;
    align-items: center;
    gap: 6px;
}
.stepper button {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    cursor: pointer;
    transition: border-color 0.15s ease;
}
.stepper button:hover:not(:disabled) {
    border-color: var(--amber);
}
.stepper button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.stepper button:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.stepper-label {
    min-width: 170px;
    text-align: center;
    font-size: 0.82rem;
    font-weight: 600;
}

/* Gráfica */
.grafica {
    position: relative;
}
.grafica svg {
    display: block;
    width: 100%;
    height: auto;
}
.grafica text {
    fill: var(--text-faint);
    font-size: 11px;
    font-family: 'JetBrains Mono', monospace;
}
.grafica .valor-punto {
    fill: var(--text);
    font-weight: 600;
    font-size: 12px;
}
.grafica .eje {
    stroke: var(--border);
}
.grafica .guia {
    stroke: var(--amber);
    stroke-dasharray: 3 3;
}
.grafica .zona {
    fill: transparent;
    cursor: pointer;
}
.tooltip {
    position: absolute;
    top: 6px;
    min-width: 200px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 0.72rem;
    color: var(--text-muted);
    pointer-events: none;
    z-index: 2;
    box-shadow: var(--shadow);
}
.tooltip strong {
    color: var(--text);
}


/* Toggle Mes / Semana / Día (mismo look que en Creativos) */
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
.periodo-sel {
    margin-left: auto;
    align-items: flex-end;
}
.periodo-sel .stepper {
    height: 38px;
}
.periodo-sel .aviso {
    margin: 0;
    color: var(--amber);
}
.nota-tablas {
    margin: -6px 0 18px;
}
.panel h2 {
    margin: 0 0 12px;
}

/* Leyendas y tooltip */
.leyenda {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-bottom: 8px;
}
.leyenda span,
.tooltip span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.muestra {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 999px;
    flex: none;
}
.muestra.cuadro {
    border-radius: 2px;
}
.tooltip b {
    color: var(--text);
    margin-left: auto;
    padding-left: 10px;
    font-family: 'JetBrains Mono', monospace;
    font-weight: 600;
}
.grafica .banda-sel {
    fill: color-mix(in srgb, var(--amber) 10%, transparent);
}
.grafica .guia.hover {
    stroke: var(--text-faint);
}
.tabla tbody th small {
    display: block;
    font-size: 0.62rem;
    color: var(--text-faint);
    font-weight: 400;
}
.tabla tr.total td,
.tabla tr.total th {
    font-weight: 700;
    color: var(--text);
}

/* Tabla */
.tabla-scroll {
    overflow-x: scroll;
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
.tabla td.vacio,
.tabla th.vacio {
    color: var(--text-faint);
}
.tabla {
    border-collapse: collapse;
    width: max-content;
    font-size: 0.8rem;
}
.tabla th,
.tabla td {
    padding: 8px 12px;
    min-width: 92px;
    text-align: right;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.tabla thead th {
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    font-weight: 600;
    cursor: pointer;
}
.tabla th small {
    display: block;
    font-size: 0.62rem;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
}
.tabla td {
    cursor: pointer;
}
.tabla td .var {
    display: block;
}
.tabla .fija {
    position: sticky;
    left: 0;
    z-index: 1;
    background: var(--surface);
    min-width: 230px;
    text-align: left;
    font-weight: 500;
    color: var(--text-muted);
    font-size: 0.78rem;
    text-transform: none;
    letter-spacing: 0;
}
.tabla .sel {
    background: color-mix(in srgb, var(--amber) 12%, transparent);
}
.tabla thead th.sel {
    color: var(--amber);
}

@media (max-width: 1100px) {
    .grid {
        grid-template-columns: 1fr;
    }
    .kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
}
</style>
