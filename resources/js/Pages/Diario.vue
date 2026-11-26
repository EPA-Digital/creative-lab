<script setup>
import AvisoCostosApi from '@/Components/AvisoCostosApi.vue';
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';

// Diario medio / Diario campaña (2026-11-18, pedido explícito) -- réplica de
// diario.medio.2026 y diario.campaña.2026: por día, la inversión de
// data.medios con installs / NC / purchase de AF data. Cada valor del
// servidor es [inversión, impresiones, clics, installs, nc, purchase].
const props = defineProps({
    pais: { type: String, required: true },
    modo: { type: String, default: 'medio' }, // medio | campania
    meses: { type: Array, default: () => [] },
    mes: { type: String, default: null },
    medios: { type: Array, default: () => [] },
    dias: { type: Object, default: () => ({}) },
    campanias: { type: Array, default: () => [] },
    hasta: { type: String, default: null },
});

const esMedio = computed(() => props.modo === 'medio');
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const DIA_LETRA = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
const etiquetaMes = (m) => `${MESES[+m.slice(5, 7) - 1].replace(/^./, (c) => c.toUpperCase())} ${m.slice(0, 4)}`;
const MES_OPCIONES = computed(() => props.meses.map((m) => ({ key: m, label: etiquetaMes(m) })));
const cambiarMes = (mes) => router.get(location.pathname, { mes }, { preserveScroll: true });

// --- Métricas (mismas columnas y fórmulas que la hoja) --------------------------
const dosDec = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dinero = { format: (v) => `${v < 0 ? '-' : ''}$${dosDec.format(Math.abs(v))}` };
const entero = new Intl.NumberFormat('es-MX');
const div = (a, b) => (b ? a / b : null);
const COLUMNAS = [
    { key: 'inv', label: 'Inversión', v: (x) => x[0], f: 'dinero' },
    { key: 'imp', label: 'Impresiones', v: (x) => x[1], f: 'entero' },
    { key: 'clk', label: 'Clicks', v: (x) => x[2], f: 'entero' },
    { key: 'ctr', label: 'CTR', v: (x) => div(x[2], x[1]), f: 'pct', ayuda: 'Clicks / Impresiones' },
    { key: 'cpc', label: 'CPC', v: (x) => div(x[0], x[2]), f: 'dinero', ayuda: 'Inversión / Clicks' },
    { key: 'cpm', label: 'CPM', v: (x) => div(x[0] * 1000, x[1]), f: 'dinero', ayuda: 'Inversión / Impresiones × 1,000' },
    { key: 'ins', label: 'Installs', v: (x) => x[3], f: 'entero', grupo: true },
    { key: 'cpi', label: 'CPI', v: (x) => div(x[0], x[3]), f: 'dinero', ayuda: 'Inversión / Installs' },
    { key: 'cr', label: 'CR', v: (x) => div(x[3], x[2]), f: 'pct', ayuda: 'Click to install: Installs / Clicks' },
    { key: 'nc', label: 'NC', v: (x) => x[4], f: 'entero', grupo: true, ayuda: 'First Purchase' },
    { key: 'cac', label: 'CAC', v: (x) => div(x[0], x[4]), f: 'dinero', ayuda: 'Inversión / NC' },
    { key: 'onb', label: 'Onboarding', v: (x) => div(x[4], x[3]), f: 'pct', ayuda: 'NC / Installs' },
    { key: 'pur', label: 'Purchase', v: (x) => x[5], f: 'entero', grupo: true, ayuda: 'Recompras (Order Submitted)' },
    { key: 'cpo', label: 'CPO', v: (x) => div(x[0], x[5]), f: 'dinero', ayuda: 'Inversión / Purchase' },
    { key: 'conv', label: 'Conv. rate', v: (x) => div(x[5], x[2]), f: 'pct', ayuda: 'Click to purchase: Purchase / Clicks' },
];
function fmt(c, x) {
    if (!x) return '–';
    const v = c.v(x);
    if (v === null || v === undefined || Number.isNaN(v)) return '–';
    if (c.f === 'dinero') return dinero.format(v);
    if (c.f === 'pct') return `${(v * 100).toFixed(2)}%`;
    return entero.format(v);
}
const sumar = (lista) => lista.filter(Boolean).reduce((t, v) => t.map((x, i) => x + v[i]), [0, 0, 0, 0, 0, 0]);

// --- Días del mes ---------------------------------------------------------------
const diasDelMes = computed(() => {
    if (!props.mes) return [];
    const [y, m] = props.mes.split('-').map(Number);
    const n = new Date(Date.UTC(y, m, 0)).getUTCDate();
    return Array.from({ length: n }, (_, i) => {
        const fecha = `${props.mes}-${String(i + 1).padStart(2, '0')}`;
        const d = new Date(Date.UTC(y, m - 1, i + 1));
        return { fecha, letra: DIA_LETRA[d.getUTCDay()], etiqueta: `${i + 1} ${MES_CORTO[m - 1]}`, finDeSemana: [0, 6].includes(d.getUTCDay()) };
    });
});

// --- Por medio ------------------------------------------------------------------
const medio = ref('total');
const MEDIO_OPCIONES = computed(() => [{ id: 'total', nombre: 'Total', color: null }, ...props.medios]);
const valoresDia = (fecha) => (esMedio.value ? props.dias[fecha]?.[medio.value] : campaniaSel.value?.dias?.[fecha]) ?? null;

// --- Por campaña ----------------------------------------------------------------
const busqueda = ref('');
const filtroMedio = ref('todos');
const FILTRO_MEDIO = computed(() => [{ key: 'todos', label: 'Todos' }, ...props.medios.map((m) => ({ key: m.id, label: m.nombre }))]);
const nombreMedio = (id) => props.medios.find((m) => m.id === id)?.nombre ?? id ?? '—';
const campaniasTot = computed(() => props.campanias.map((c) => ({ ...c, total: sumar(Object.values(c.dias)) })));
const campaniasFiltradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return campaniasTot.value.filter((c) => (filtroMedio.value === 'todos' || c.medio === filtroMedio.value) && (!q || c.nombre.toLowerCase().includes(q)));
});
const sel = ref(null);
const campaniaSel = computed(() => campaniasTot.value.find((c) => c.nombre === sel.value) ?? null);
watch(() => props.mes, () => { sel.value = null; });
const RESUMEN_CAMPANIA = ['inv', 'ins', 'cpi', 'nc', 'cac', 'pur', 'cpo'].map((k) => COLUMNAS.find((c) => c.key === k));

const totalMes = computed(() => sumar(diasDelMes.value.map((d) => valoresDia(d.fecha))));
const hayDatos = computed(() => (esMedio.value ? Object.keys(props.dias).length > 0 : props.campanias.length > 0));
const fechaLarga = (s) => (s ? `${+s.slice(8, 10)} ${MES_CORTO[+s.slice(5, 7) - 1]} ${s.slice(0, 4)}` : '');
const despuesDeHasta = (fecha) => !!props.hasta && fecha > props.hasta;
</script>

<template>
    <Head :title="`${esMedio ? 'Diario medio' : 'Diario campaña'} — ${pais}`" />

    <DashboardLayout :pais="pais" :vista-activa="esMedio ? 'diario-medio' : 'diario-campania'">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">{{ esMedio ? 'Diario medio' : 'Diario campaña' }}</h1>
                        <p class="subtitle">
                            Por día, la inversión, impresiones y clics de data.medios con los installs, NC y purchase de AF data
                            {{ esMedio ? 'por medio' : 'por campaña' }}<template v-if="hasta"> (datos hasta el {{ fechaLarga(hasta) }})</template>.
                        </p>
                        <AvisoCostosApi />
                    </div>
                </header>

                <p v-if="!meses.length" class="empty-note">
                    Todavía no hay datos. Sube data.medios en Datos → Medios data y AppsFlyer en AF data.
                </p>

                <template v-else>
                    <div class="resumen-filtros">
                        <FiltroDropdown label="Mes" :model-value="mes" :options="MES_OPCIONES" @update:model-value="cambiarMes" />
                        <div v-if="esMedio" class="periodo">
                            <span class="periodo-label mono">Medio</span>
                            <div class="chips" role="group" aria-label="Medio">
                                <button
                                    v-for="m in MEDIO_OPCIONES"
                                    :key="m.id"
                                    type="button"
                                    class="chip"
                                    :class="{ activo: medio === m.id }"
                                    :style="m.color ? { '--color-medio': `var(--${m.color})` } : null"
                                    @click="medio = m.id"
                                >
                                    <i v-if="m.color" class="punto" />{{ m.nombre }}
                                </button>
                            </div>
                        </div>
                        <template v-else>
                            <FiltroDropdown label="Medio" v-model="filtroMedio" :options="FILTRO_MEDIO" />
                            <label class="buscador">
                                <span class="periodo-label mono">Buscar campaña</span>
                                <input v-model="busqueda" type="search" autocomplete="off" placeholder="Nombre de la campaña" />
                            </label>
                        </template>
                    </div>

                    <p v-if="!hayDatos" class="empty-note">Este mes no tiene datos.</p>

                    <!-- Campañas del mes -->
                    <section v-if="!esMedio && hayDatos" class="import-panel panel">
                        <h2>Campañas <span class="mono cuenta">{{ campaniasFiltradas.length }}</span></h2>
                        <p class="hint">Total del mes. Toca una campaña para ver su detalle por día.</p>
                        <div class="tabla-scroll lista">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th class="fija">Campaña</th>
                                        <th class="izq">Medio</th>
                                        <th v-for="c in RESUMEN_CAMPANIA" :key="c.key" :title="c.ayuda">{{ c.label }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in campaniasFiltradas" :key="c.nombre" class="clic" :class="{ sel: sel === c.nombre }" @click="sel = sel === c.nombre ? null : c.nombre">
                                        <th class="fija nombre" :title="c.nombre">{{ c.nombre }}</th>
                                        <td class="izq">{{ nombreMedio(c.medio) }}</td>
                                        <td v-for="col in RESUMEN_CAMPANIA" :key="col.key">{{ fmt(col, c.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Detalle por día -->
                    <section v-if="hayDatos && (esMedio || campaniaSel)" class="import-panel panel">
                        <h2 class="detalle-titulo">
                            <template v-if="esMedio">{{ MEDIO_OPCIONES.find((m) => m.id === medio)?.nombre }} · {{ etiquetaMes(mes) }}</template>
                            <template v-else>{{ campaniaSel.nombre }}</template>
                        </h2>
                        <p v-if="esMedio && medio === 'total'" class="hint">Total: la inversión de todos los medios y los resultados de todo el negocio en AF data (también orgánico), como el bloque TOTAL MEDIOS de la hoja.</p>
                        <div class="tabla-scroll">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th class="fija">Día</th>
                                        <th v-for="c in COLUMNAS" :key="c.key" :class="{ grupo: c.grupo }" :title="c.ayuda">{{ c.label }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="d in diasDelMes" :key="d.fecha" :class="{ finde: d.finDeSemana, futuro: despuesDeHasta(d.fecha) }">
                                        <th class="fija"><span class="letra mono">{{ d.letra }}</span>{{ d.etiqueta }}</th>
                                        <td v-for="c in COLUMNAS" :key="c.key" :class="{ grupo: c.grupo }">{{ fmt(c, valoresDia(d.fecha)) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th class="fija">Total del mes</th>
                                        <td v-for="c in COLUMNAS" :key="c.key" :class="{ grupo: c.grupo }">{{ fmt(c, totalMes) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>
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
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 0.78rem;
    color: var(--text-muted);
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease;
}
.chip:hover {
    border-color: var(--amber);
    color: var(--text);
}
.chip.activo {
    border-color: var(--amber);
    color: var(--text);
    background: color-mix(in srgb, var(--amber) 12%, var(--surface));
}
.chip:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.punto {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--color-medio);
}
.buscador {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1 1 260px;
    max-width: 420px;
}
.buscador input {
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 0.85rem;
}
.buscador input:focus {
    outline: none;
    border-color: var(--amber);
}

.panel {
    min-width: 0;
    margin-bottom: 18px;
}
.panel h2 {
    margin: 0 0 4px;
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.cuenta {
    font-size: 0.72rem;
    color: var(--text-faint);
    font-weight: 400;
}
.detalle-titulo {
    word-break: break-all;
}
.hint {
    font-size: 0.75rem;
    color: var(--text-faint);
    margin: 0 0 12px;
}

/* Tabla */
.tabla-scroll {
    overflow: auto;
    max-width: 100%;
    max-height: calc(620px / var(--zoom-factor, 1));
}
.tabla-scroll.lista {
    max-height: calc(420px / var(--zoom-factor, 1));
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
    top: 0;
    z-index: 2;
    background: var(--surface);
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    font-weight: 600;
}
.tabla .izq {
    text-align: left;
}
.tabla .grupo {
    border-left: 1px solid var(--border);
}
.tabla .fija {
    position: sticky;
    left: 0;
    z-index: 1;
    background: var(--surface);
    text-align: left;
    font-weight: 500;
    color: var(--text-muted);
    border-right: 1px solid var(--border);
}
.tabla thead .fija {
    z-index: 3;
}
.tabla .nombre {
    max-width: 420px;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.7rem;
}
.letra {
    display: inline-block;
    width: 16px;
    color: var(--text-faint);
    font-size: 0.66rem;
}
.tabla tr.finde .fija {
    color: var(--text);
}
.tabla tr.futuro td {
    color: var(--text-faint);
}
.tabla tr.clic {
    cursor: pointer;
}
.tabla tr.clic:hover td,
.tabla tr.clic:hover .fija {
    background: var(--surface-2);
}
.tabla tr.sel td,
.tabla tr.sel .fija {
    background: color-mix(in srgb, var(--amber) 12%, var(--surface));
    color: var(--text);
}
.tabla tfoot th,
.tabla tfoot td {
    position: sticky;
    bottom: 0;
    background: var(--surface-2);
    font-weight: 700;
    color: var(--text);
}
.tabla tfoot .fija {
    z-index: 3;
    background: var(--surface-2);
}

@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
}
</style>
