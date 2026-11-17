<script setup>
import { ref, computed, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import CeldaEditable from '@/Components/Daily/CeldaEditable.vue';
import { confirmar, mensajeDeError, notificar } from '@/avisos';
import { etiquetaFunnel } from '@/motor';

// Daily (2026-11-18, pedido explícito): la pestaña daily del Daily Cuentas
// con el diseño híbrido elegido -- arriba el pacing del mes y las metas,
// barras por medio y funnel; abajo las campañas agrupadas por medio. Lo
// morado de la hoja (budget, status, funnel, daily, KPIs, metas) se edita
// aquí mismo y se guarda al salir del campo.
const props = defineProps({
    pais: { type: String, required: true },
    mes: { type: String, required: true },
    meses: { type: Array, default: () => [] },
    medios: { type: Array, default: () => [] },
    status: { type: Array, default: () => [] },
    plan: { type: Object, default: null },
    fechas: { type: Object, required: true },
    campanias: { type: Array, default: () => [] },
    disponibles: { type: Array, default: () => [] },
    hayAnterior: { type: Boolean, default: false },
});

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const etiquetaMes = (m) => `${MESES[+m.slice(5, 7) - 1].replace(/^./, (c) => c.toUpperCase())} ${m.slice(0, 4)}`;
const MES_OPCIONES = computed(() => props.meses.map((m) => ({ key: m, label: etiquetaMes(m) })));
const cambiarMes = (mes) => router.get(location.pathname, { mes }, { preserveScroll: true });
// "Ver al día": recalcula el Daily a ese corte (null = último día con datos completos).
const verAlDia = (al) => router.get(location.pathname, { mes: props.mes, ...(al ? { al } : {}) }, { preserveScroll: true, preserveState: true });
const sumarDias = (fecha, n) => {
    const d = new Date(`${fecha}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
};
const corteSinDatos = computed(() => !!props.fechas.ayer && (!props.fechas.ultimoCosto || !props.fechas.ultimoAf || props.fechas.ayer > props.fechas.ultimoCosto || props.fechas.ayer > props.fechas.ultimoAf));
const fechaCorta = (s) => (s ? `${+s.slice(8, 10)} ${MES_CORTO[+s.slice(5, 7) - 1]}` : '—');

const dosDec = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sinDec = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 });
const dinero = (v) => (v === null || v === undefined ? '—' : `${v < 0 ? '-' : ''}$${sinDec.format(Math.abs(v))}`);
const dinero2 = (v) => (v === null || v === undefined || !Number.isFinite(v) ? '—' : `$${dosDec.format(v)}`);
const entero = (v) => (v === null || v === undefined ? '—' : sinDec.format(v));
const pct = (v, d = 1) => (v === null || v === undefined || !Number.isFinite(v) ? '—' : `${(v * 100).toFixed(d)}%`);
const pp = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${(v * 100).toFixed(1)} pp`);
const div = (a, b) => (b ? a / b : null);

// Semáforo de pacing: dentro de ±5 pp va bien, hasta ±15 pp ojo, más allá alerta.
const ritmo = (dif) => (dif === null || dif === undefined ? 'neutro' : Math.abs(dif) <= 0.05 ? 'bien' : Math.abs(dif) <= 0.15 ? 'ojo' : 'mal');

const nombreMedio = (id) => props.medios.find((m) => m.id === id)?.nombre ?? id;
const colorMedio = (id) => props.medios.find((m) => m.id === id)?.color;
const FUNNELS = ['AWA', 'CONS', 'CNV', 'LOY'];
const nombreFunnel = (f) => (f ? etiquetaFunnel(f) || f : 'Sin funnel');
const STATUS_EDITAR = computed(() => props.status.map((s) => ({ key: s, label: s.charAt(0) + s.slice(1).toLowerCase() })));
const FUNNEL_EDITAR = [{ key: '', label: 'Sin funnel' }, ...FUNNELS.map((f) => ({ key: f, label: nombreFunnel(f) }))];

// --- Empezar el mes ---------------------------------------------------------------
const iniciando = ref(false);
async function iniciar(desde) {
    iniciando.value = true;
    try {
        await axios.post(`${location.pathname}/${props.mes}/iniciar`, { desde });
        router.reload({ preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo empezar el mes', texto: mensajeDeError(err) });
    } finally {
        iniciando.value = false;
    }
}

// --- Guardar lo morado ----------------------------------------------------------
const guardando = ref(0);
async function guardar(url, datos, metodo = 'patch') {
    guardando.value++;
    try {
        await axios[metodo](url, datos);
        router.reload({ preserveScroll: true, only: ['campanias', 'plan', 'disponibles', 'fechas'] });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se guardó', texto: mensajeDeError(err) });
        router.reload({ preserveScroll: true });
    } finally {
        guardando.value--;
    }
}
const numero = (v) => {
    const s = String(v ?? '').replace(/[^\d.]/g, '');
    return s === '' ? null : Number(s);
};
function editarCampania(c, campo, valor) {
    let v = valor;
    if (['budget', 'daily_plataforma'].includes(campo)) v = numero(valor) ?? (campo === 'budget' ? 0 : null);
    if (campo.startsWith('kpi_')) v = numero(valor) === null ? null : Math.round(numero(valor));
    if (['nombre_comun', 'region'].includes(campo)) v = String(valor ?? '').trim() || null;
    if (v === (c[campo] ?? null)) return;
    guardar(`${location.pathname}/campanias/${c.id}`, { [campo]: v });
}
function editarPlan(campo, valor) {
    let v = valor;
    if (campo.startsWith('meta_')) v = numero(valor) === null ? null : Math.round(numero(valor));
    if (campo === 'notas') v = String(valor ?? '').trim() || null;
    if (v === (props.plan?.[campo] ?? null) || (campo.startsWith('fecha_') && !v)) return;
    guardar(`${location.pathname}/${props.mes}/plan`, { [campo]: v });
}
function quitar(c) {
    confirmar({
        tipo: 'peligro',
        titulo: 'Quitar campaña del Daily',
        texto: `${c.nombre_comun || c.campania}. Se quita de la planeación de ${etiquetaMes(props.mes)}; sus datos de data.medios y AF data no se borran.`,
        confirmar: 'Sí, quitar',
        accion: async () => {
            await axios.delete(`${location.pathname}/campanias/${c.id}`);
            router.reload({ preserveScroll: true });
        },
        exito: 'Campaña quitada',
    });
}

// --- Agregar campaña ------------------------------------------------------------
const modalAgregar = ref(false);
const buscarDisp = ref('');
const nueva = reactive({ medio: '', campania: '', campania_id: '', budget: '' });
const disponiblesFiltradas = computed(() => {
    const q = buscarDisp.value.trim().toLowerCase();
    return props.disponibles.filter((d) => (d.costo > 0 || d.installs > 0) && (!q || d.campania.toLowerCase().includes(q))).slice(0, 60);
});
function abrirAgregar(medio = '') {
    Object.assign(nueva, { medio: medio || props.medios[0]?.id || '', campania: '', campania_id: '', budget: '' });
    buscarDisp.value = '';
    modalAgregar.value = true;
}
async function agregar(d = null) {
    const datos = d
        ? { medio: d.medio, campania: d.campania, campania_id: d.campania_id, funnel: d.funnel }
        : { medio: nueva.medio, campania: nueva.campania.trim(), campania_id: nueva.campania_id.trim() || null, budget: numero(nueva.budget) };
    if (!datos.campania || !datos.medio) return;
    try {
        await axios.post(`${location.pathname}/${props.mes}/campanias`, datos);
        notificar({ tipo: 'exito', titulo: 'Campaña agregada', texto: datos.campania });
        if (!d) modalAgregar.value = false;
        router.reload({ preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo agregar', texto: mensajeDeError(err) });
    }
}

// --- Filtros --------------------------------------------------------------------
const filtroStatus = ref('TODOS');
const filtroFunnel = ref('TODOS');
const busqueda = ref('');
const masMetricas = ref(false);
const STATUS_OPCIONES = computed(() => [{ key: 'TODOS', label: 'Todos' }, ...props.status.map((s) => ({ key: s, label: s.charAt(0) + s.slice(1).toLowerCase() }))]);
const FUNNEL_OPCIONES = computed(() => [{ key: 'TODOS', label: 'Todos' }, ...FUNNELS.map((f) => ({ key: f, label: nombreFunnel(f) })), { key: 'SIN', label: 'Sin funnel' }]);
const visibles = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.campanias.filter(
        (c) =>
            (filtroStatus.value === 'TODOS' || c.status === filtroStatus.value) &&
            (filtroFunnel.value === 'TODOS' || (filtroFunnel.value === 'SIN' ? !c.funnel : c.funnel === filtroFunnel.value)) &&
            (!q || `${c.campania} ${c.nombre_comun ?? ''}`.toLowerCase().includes(q)),
    );
});

// --- Totales ------------------------------------------------------------------
const CAMPOS_SUMA = ['budget', 'costo', 'costo_ayer', 'daily_plataforma', 'clicks', 'impresiones', 'installs', 'nc', 'recompras', 'orders', 'remanente', 'kpi_installs', 'kpi_nc', 'kpi_recompras', 'kpi_orders'];
function total(lista) {
    const t = Object.fromEntries(CAMPOS_SUMA.map((k) => [k, lista.reduce((s, c) => s + (Number(c[k]) || 0), 0)]));
    t.consumido = t.budget > 0 ? t.costo / t.budget : null;
    t.dif = t.consumido !== null ? t.consumido - props.fechas.ideal : null;
    t.daily_ideal = props.fechas.restantes > 0 ? t.remanente / props.fechas.restantes : null;
    return t;
}
const totalGeneral = computed(() => total(props.campanias));
const grupos = computed(() => {
    const orden = [...props.medios.map((m) => m.id), ...new Set(props.campanias.map((c) => c.medio))];
    return [...new Set(orden)]
        .map((id) => ({ id, nombre: nombreMedio(id), color: colorMedio(id), filas: visibles.value.filter((c) => c.medio === id), todas: props.campanias.filter((c) => c.medio === id) }))
        .filter((g) => g.todas.length);
});
function barras(clave, nombre) {
    const mapa = new Map();
    for (const c of props.campanias) {
        const k = c[clave] ?? '';
        if (!mapa.has(k)) mapa.set(k, []);
        mapa.get(k).push(c);
    }
    return [...mapa.entries()].map(([k, lista]) => ({ k, nombre: nombre(k), ...total(lista) })).sort((a, b) => b.budget - a.budget);
}
const porMedio = computed(() => barras('medio', (k) => nombreMedio(k)).map((b) => ({ ...b, color: colorMedio(b.k) })));
const porFunnel = computed(() => barras('funnel', (k) => nombreFunnel(k || null)).sort((a, b) => FUNNELS.indexOf(a.k) - FUNNELS.indexOf(b.k)));
const porRegion = computed(() => (props.campanias.some((c) => c.region) ? barras('region', (k) => k || 'Sin región') : []));

const METAS = [
    { campo: 'meta_installs', label: 'Installs', real: 'installs' },
    { campo: 'meta_nc', label: 'New Customers', real: 'nc' },
    { campo: 'meta_orders', label: 'Orders', real: 'orders' },
];

// --- KPIs por campaña: KPI · llevamos · alcanzado · gráfico (como la hoja) -------
const metricasKpi = computed(() => [
    { k: 'installs', kpi: 'kpi_installs', label: 'Instalaciones', corto: 'Installs' },
    { k: 'nc', kpi: 'kpi_nc', label: 'First orders (NC)', corto: 'NC' },
    ...(masMetricas.value ? [{ k: 'recompras', kpi: 'kpi_recompras', label: 'Recompras', corto: 'Recompras' }] : []),
    { k: 'orders', kpi: 'kpi_orders', label: 'Orders', corto: 'Orders' },
]);
/** Alcance contra el ideal al día de corte (para el semáforo); null si no hay KPI. */
const alcance = (real, kpi) => (kpi ? real / kpi - props.fechas.ideal : null);

// --- Ajustes por día de la semana -------------------------------------------------
const vistaTabla = ref('pacing');
const DIAS_SEMANA = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const diaHoy = (new Date().getDay() + 6) % 7; // lunes = 0
/** Lo que gasta la campaña ese día: su ajuste o, si no tiene, su daily de plataforma. Solo activas. */
const gastoDia = (c, i) => (c.status === 'ACTIVA' ? c.ajustes[i] ?? c.daily_plataforma ?? 0 : 0);
const sumaDia = (lista, i) => lista.reduce((t, c) => t + gastoDia(c, i), 0);
const repartido = computed(() => DIAS_SEMANA.map((_, i) => sumaDia(props.campanias, i)));
const cambioAjuste = (c, i) => (c.ajustes[i] === null || !c.daily_plataforma ? null : c.ajustes[i] / c.daily_plataforma - 1);
const claseCambio = (x) => (x === null ? '' : x > 0.005 ? 'sube' : x < -0.005 ? 'baja' : '');
function editarAjuste(c, dia, valor) {
    guardar(`${location.pathname}/campanias/${c.id}`, { ajuste: { dia, valor: numero(valor) } });
}
function editarTotalDia(dia, valor) {
    guardar(`${location.pathname}/${props.mes}/plan`, { total_dia: { dia, valor: numero(valor) } });
}

// Cambiar de mes reinicia filtros.
watch(() => props.mes, () => { busqueda.value = ''; filtroStatus.value = 'TODOS'; filtroFunnel.value = 'TODOS'; });
const claseStatus = (s) => ({ ACTIVA: 'activa', 'EN PAUSA': 'pausa', FINALIZADA: 'finalizada', PENDIENTE: 'pendiente' })[s] ?? '';
const anchoBarra = (v) => `${Math.min(100, Math.max(0, (v ?? 0) * 100))}%`;
</script>

<template>
    <Head :title="`Daily — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="daily">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Daily</h1>
                        <p class="subtitle">
                            Presupuesto y pacing del mes por campaña. Lo que en la hoja era morado se edita aquí: pasa el mouse y toca el
                            <span class="manual-muestra">lápiz</span>. Costo y resultados salen de data.medios y AF data.
                        </p>
                    </div>
                    <div class="header-derecha">
                        <span v-if="guardando" class="guardando mono">Guardando…</span>
                        <FiltroDropdown label="Mes" :model-value="mes" :options="MES_OPCIONES" @update:model-value="cambiarMes" />
                    </div>
                </header>

                <!-- Mes sin planeación -->
                <section v-if="!plan" class="import-panel vacio">
                    <h2>{{ etiquetaMes(mes) }} todavía no tiene planeación</h2>
                    <p class="hint">Elige cómo empezar. Después puedes agregar, quitar o pausar campañas y ajustar budgets.</p>
                    <div class="opciones-inicio">
                        <button v-if="hayAnterior" type="button" class="btn-primary" :disabled="iniciando" @click="iniciar('anterior')">Copiar campañas y budgets del mes anterior</button>
                        <button type="button" class="btn-primary" :disabled="iniciando" @click="iniciar('datos')">Empezar con las campañas que ya tienen gasto</button>
                        <button type="button" class="modal-copy-btn" :disabled="iniciando" @click="iniciar('vacio')">Empezar vacío</button>
                    </div>
                </section>

                <template v-else>
                    <!-- Periodo y día de corte -->
                    <div class="resumen-filtros periodo-fila">
                        <div class="periodo">
                            <span class="periodo-label mono">Periodo</span>
                            <div class="rango-control">
                                <input type="date" aria-label="Inicio" :value="fechas.inicio" @change="editarPlan('fecha_inicio', $event.target.value)" />
                                <span class="rango-flecha">→</span>
                                <input type="date" aria-label="Fin" :value="fechas.fin" @change="editarPlan('fecha_fin', $event.target.value)" />
                            </div>
                        </div>
                        <div class="periodo">
                            <span class="periodo-label mono">Ver al día</span>
                            <div class="rango-control">
                                <button type="button" class="paso" aria-label="Día anterior" :disabled="fechas.ayer <= fechas.inicio" @click="verAlDia(sumarDias(fechas.ayer, -1))">‹</button>
                                <input type="date" aria-label="Ver al día" :min="fechas.inicio" :max="fechas.fin" :value="fechas.ayer" @change="verAlDia($event.target.value)" />
                                <button type="button" class="paso" aria-label="Día siguiente" :disabled="fechas.ayer >= fechas.fin" @click="verAlDia(sumarDias(fechas.ayer, 1))">›</button>
                                <button type="button" class="chip" :class="{ activo: fechas.ayer === fechas.sugerido }" @click="verAlDia(null)">Último con datos</button>
                            </div>
                        </div>
                        <div class="dia-actual">
                            <b class="num">Día {{ fechas.transcurridos }} de {{ fechas.dias }}</b>
                            <span>{{ pct(fechas.ideal, 0) }} del mes · quedan {{ fechas.restantes }} días</span>
                            <div class="pista dias"><i :style="{ width: anchoBarra(fechas.ideal) }" /></div>
                        </div>
                    </div>
                    <p class="delay mono" :class="{ alerta: corteSinDatos }">
                        <template v-if="corteSinDatos">
                            Al {{ fechaCorta(fechas.ayer) }} todavía no hay datos completos: costos hasta {{ fechaCorta(fechas.ultimoCosto) }}, AF data hasta {{ fechaCorta(fechas.ultimoAf) }}. Lo que falta cuenta como $0.
                        </template>
                        <template v-else>
                            Datos: costos hasta {{ fechaCorta(fechas.ultimoCosto) }} · AF data hasta {{ fechaCorta(fechas.ultimoAf) }}. Por el delay, el Daily se para en el último día que tiene los dos.
                        </template>
                    </p>

                    <!-- Pacing -->
                    <section class="kpis">
                        <div class="kpi">
                            <span class="kpi-label">Presupuesto</span>
                            <span class="kpi-value">{{ dinero(totalGeneral.budget) }}</span>
                            <span class="kpi-sub">Suma de los budgets de las campañas</span>
                        </div>
                        <div class="kpi">
                            <span class="kpi-label">Invertido</span>
                            <span class="kpi-value">{{ dinero(totalGeneral.costo) }}</span>
                            <span class="kpi-sub">{{ pct(totalGeneral.consumido) }} consumido · el {{ fechaCorta(fechas.ayer) }}: {{ dinero(totalGeneral.costo_ayer) }}</span>
                        </div>
                        <div class="kpi">
                            <span class="kpi-label">Remanente</span>
                            <span class="kpi-value">{{ dinero(totalGeneral.remanente) }}</span>
                            <span class="kpi-sub">Daily ideal {{ dinero(totalGeneral.daily_ideal) }} · daily plataforma {{ dinero(totalGeneral.daily_plataforma) }}</span>
                        </div>
                        <div class="kpi" :class="`ritmo-${ritmo(totalGeneral.dif)}`">
                            <span class="kpi-label">Pacing</span>
                            <span class="kpi-value">{{ pp(totalGeneral.dif) }}</span>
                            <span class="kpi-sub">{{ pct(totalGeneral.consumido) }} consumido vs {{ pct(fechas.ideal) }} ideal al {{ fechaCorta(fechas.ayer) }}</span>
                        </div>
                    </section>

                    <!-- Metas -->
                    <section class="metas">
                        <div v-for="m in METAS" :key="m.campo" class="meta">
                            <div class="meta-cabeza">
                                <span class="kpi-label">{{ m.label }}</span>
                                <span class="meta-input mono">
                                    Meta
                                    <CeldaEditable :valor="plan[m.campo]" tipo="numero" :formato="entero" vacio="Agregar" :etiqueta="`Meta de ${m.label}`" @guardar="editarPlan(m.campo, $event)" />
                                </span>
                            </div>
                            <div class="meta-valor">
                                <b class="num">{{ entero(totalGeneral[m.real]) }}</b>
                                <span :class="`ritmo-texto-${ritmo(plan[m.campo] ? div(totalGeneral[m.real], plan[m.campo]) - fechas.ideal : null)}`">
                                    {{ plan[m.campo] ? pct(div(totalGeneral[m.real], plan[m.campo])) + ' de la meta' : 'Sin meta' }}
                                </span>
                            </div>
                            <div class="pista">
                                <i :style="{ width: anchoBarra(div(totalGeneral[m.real], plan[m.campo])) }" />
                                <span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" :title="`Ideal al ${fechaCorta(fechas.ayer)}: ${pct(fechas.ideal)}`" />
                            </div>
                        </div>
                    </section>

                    <!-- Por medio / funnel / región -->
                    <div class="grid-barras">
                        <section v-for="bloque in [{ t: 'Por medio', d: porMedio }, { t: 'Por funnel', d: porFunnel }, ...(porRegion.length ? [{ t: 'Por región', d: porRegion }] : [])]" :key="bloque.t" class="import-panel panel">
                            <h2>{{ bloque.t }}</h2>
                            <p class="hint">Costo vs budget; la línea es el % ideal al {{ fechaCorta(fechas.ayer) }} ({{ pct(fechas.ideal) }}).</p>
                            <div v-for="b in bloque.d" :key="b.k" class="barra">
                                <div class="barra-cabeza">
                                    <span><i v-if="b.color" class="punto" :style="{ background: `var(--${b.color})` }" />{{ b.nombre }}</span>
                                    <span class="mono">{{ dinero(b.costo) }} / {{ dinero(b.budget) }} · <b :class="`ritmo-texto-${ritmo(b.dif)}`">{{ pct(b.consumido) }}</b></span>
                                </div>
                                <div class="pista">
                                    <i :class="`ritmo-fondo-${ritmo(b.dif)}`" :style="{ width: anchoBarra(b.consumido) }" />
                                    <span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" />
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Campañas -->
                    <section class="import-panel panel">
                        <div class="tabla-cabeza">
                            <h2>Campañas <span class="mono cuenta">{{ visibles.length }} de {{ campanias.length }}</span></h2>
                            <div class="tabla-acciones">
                                <div class="periodo-toggle" role="group" aria-label="Vista de la tabla">
                                    <button type="button" :class="{ activo: vistaTabla === 'pacing' }" @click="vistaTabla = 'pacing'">Pacing</button>
                                    <button type="button" :class="{ activo: vistaTabla === 'ajustes' }" @click="vistaTabla = 'ajustes'">Ajustes por día</button>
                                </div>
                                <label v-if="vistaTabla === 'pacing'" class="ver-mas"><input v-model="masMetricas" type="checkbox" /> Más métricas</label>
                                <button type="button" class="btn-primary" @click="abrirAgregar()">Agregar campaña</button>
                            </div>
                        </div>
                        <div class="resumen-filtros filtros">
                            <FiltroDropdown label="Status" v-model="filtroStatus" :options="STATUS_OPCIONES" />
                            <FiltroDropdown label="Funnel" v-model="filtroFunnel" :options="FUNNEL_OPCIONES" />
                            <label class="buscador">
                                <span class="periodo-label mono">Buscar</span>
                                <input v-model="busqueda" type="search" autocomplete="off" placeholder="Campaña o nombre común" />
                            </label>
                        </div>

                        <p v-if="!campanias.length" class="empty-note">No hay campañas en la planeación. Agrega la primera.</p>

                        <div v-else-if="vistaTabla === 'pacing'" class="tabla-scroll">
                            <table class="tabla pacing">
                                <thead>
                                    <tr class="grupos-cabeza">
                                        <th class="fija izq" rowspan="2">Campaña</th>
                                        <th class="izq" rowspan="2">Status</th>
                                        <th class="izq" rowspan="2">Funnel</th>
                                        <th colspan="4" class="grupo">Presupuesto</th>
                                        <th colspan="4" class="grupo">Pacing</th>
                                        <th v-for="m in metricasKpi" :key="`g${m.k}`" colspan="4" class="grupo">{{ m.label }}</th>
                                        <th :colspan="masMetricas ? 4 : 3" class="grupo">Costos</th>
                                        <th v-if="masMetricas" colspan="9" class="grupo">Métricas secundarias</th>
                                        <th rowspan="2" />
                                    </tr>
                                    <tr>
                                        <th class="grupo">Budget</th>
                                        <th>Costo</th>
                                        <th>%</th>
                                        <th class="izq">Gráfico</th>
                                        <th class="grupo" title="% consumido - % ideal al día de corte">Dif.</th>
                                        <th :title="`Costo del ${fechaCorta(fechas.ayer)}`">Del día</th>
                                        <th>Daily plataforma</th>
                                        <th title="Remanente / días restantes">Daily ideal</th>
                                        <template v-for="m in metricasKpi" :key="`h${m.k}`">
                                            <th class="grupo">KPI</th>
                                            <th>{{ m.corto }}</th>
                                            <th>Alcanzado</th>
                                            <th class="izq">Gráfico</th>
                                        </template>
                                        <th class="grupo">CPI</th>
                                        <th>CAC</th>
                                        <th>CPO</th>
                                        <th v-if="masMetricas">CPA</th>
                                        <template v-if="masMetricas">
                                            <th class="grupo">Clicks</th>
                                            <th>Impresiones</th>
                                            <th>CTR</th>
                                            <th>CPC</th>
                                            <th>CPM</th>
                                            <th title="NC / Installs">OR</th>
                                            <th title="Orders / Clicks">CR</th>
                                            <th title="Installs / Clicks">IR</th>
                                            <th class="izq">Región</th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody v-for="g in grupos" :key="g.id">
                                    <tr class="medio-fila">
                                        <th class="fija izq">
                                            <i v-if="g.color" class="punto" :style="{ background: `var(--${g.color})` }" />{{ g.nombre }}
                                            <button type="button" class="mini" :title="`Agregar campaña de ${g.nombre}`" @click="abrirAgregar(g.id)">+ Agregar</button>
                                        </th>
                                        <td colspan="2" />
                                        <template v-for="t in [total(g.todas)]" :key="'t'">
                                            <td class="grupo">{{ dinero(t.budget) }}</td>
                                            <td>{{ dinero(t.costo) }}</td>
                                            <td :class="`ritmo-texto-${ritmo(t.dif)}`">{{ pct(t.consumido, 0) }}</td>
                                            <td class="izq"><div class="pista chica"><i :class="`ritmo-fondo-${ritmo(t.dif)}`" :style="{ width: anchoBarra(t.consumido) }" /><span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" /></div></td>
                                            <td class="grupo" :class="`ritmo-texto-${ritmo(t.dif)}`">{{ pp(t.dif) }}</td>
                                            <td>{{ dinero(t.costo_ayer) }}</td>
                                            <td>{{ dinero(t.daily_plataforma) }}</td>
                                            <td>{{ dinero(t.daily_ideal) }}</td>
                                            <template v-for="m in metricasKpi" :key="`s${m.k}`">
                                                <td class="grupo">{{ t[m.kpi] ? entero(t[m.kpi]) : '—' }}</td>
                                                <td>{{ entero(t[m.k]) }}</td>
                                                <td :class="`ritmo-texto-${ritmo(alcance(t[m.k], t[m.kpi]))}`">{{ t[m.kpi] ? pct(div(t[m.k], t[m.kpi]), 0) : '—' }}</td>
                                                <td class="izq"><div v-if="t[m.kpi]" class="pista chica"><i :class="`ritmo-fondo-${ritmo(alcance(t[m.k], t[m.kpi]))}`" :style="{ width: anchoBarra(div(t[m.k], t[m.kpi])) }" /><span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" /></div></td>
                                            </template>
                                            <td class="grupo">{{ dinero2(div(t.costo, t.installs)) }}</td>
                                            <td>{{ dinero2(div(t.costo, t.nc)) }}</td>
                                            <td>{{ dinero2(div(t.costo, t.orders)) }}</td>
                                            <td v-if="masMetricas">{{ dinero2(div(t.costo, t.recompras)) }}</td>
                                            <td v-if="masMetricas" colspan="9" class="grupo" />
                                        </template>
                                        <td />
                                    </tr>
                                    <tr v-for="c in g.filas" :key="c.id" :class="{ apagada: c.status === 'FINALIZADA' }">
                                        <th class="fija izq campania">
                                            <CeldaEditable
                                                class="nombre-comun"
                                                :valor="c.nombre_comun"
                                                alinear="izquierda"
                                                vacio="Sin nombre común"
                                                etiqueta="Nombre común"
                                                @guardar="editarCampania(c, 'nombre_comun', $event)"
                                            />
                                            <small class="mono" :title="c.campania">{{ c.campania }}</small>
                                            <small v-if="!c.con_datos" class="sin-datos mono">Sin datos en el periodo</small>
                                        </th>
                                        <td class="izq">
                                            <CeldaEditable :valor="c.status" tipo="select" :opciones="STATUS_EDITAR" alinear="izquierda" :clase-valor="`estado ${claseStatus(c.status)}`" etiqueta="Status" @guardar="editarCampania(c, 'status', $event)" />
                                        </td>
                                        <td class="izq">
                                            <CeldaEditable :valor="c.funnel" tipo="select" :opciones="FUNNEL_EDITAR" alinear="izquierda" etiqueta="Funnel" @guardar="editarCampania(c, 'funnel', $event || null)" />
                                        </td>
                                        <td class="grupo"><CeldaEditable :valor="c.budget || null" tipo="numero" :formato="dinero" vacio="—" etiqueta="Budget" @guardar="editarCampania(c, 'budget', $event)" /></td>
                                        <td>{{ dinero2(c.costo) }}</td>
                                        <td :class="`ritmo-texto-${ritmo(c.dif)}`">{{ pct(c.consumido, 0) }}</td>
                                        <td class="izq"><div class="pista chica"><i :class="`ritmo-fondo-${ritmo(c.dif)}`" :style="{ width: anchoBarra(c.consumido) }" /><span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" /></div></td>
                                        <td class="grupo" :class="`ritmo-texto-${ritmo(c.dif)}`">{{ pp(c.dif) }}</td>
                                        <td>{{ dinero2(c.costo_ayer) }}</td>
                                        <td><CeldaEditable :valor="c.daily_plataforma" tipo="numero" :formato="dinero" etiqueta="Daily de plataforma" @guardar="editarCampania(c, 'daily_plataforma', $event)" /></td>
                                        <td>{{ dinero2(c.daily_ideal) }}</td>
                                        <template v-for="m in metricasKpi" :key="`c${m.k}`">
                                            <td class="grupo"><CeldaEditable :valor="c[m.kpi]" tipo="numero" :formato="entero" vacio="—" :etiqueta="`KPI de ${m.label}`" @guardar="editarCampania(c, m.kpi, $event)" /></td>
                                            <td>{{ entero(c[m.k]) }}</td>
                                            <td :class="`ritmo-texto-${ritmo(alcance(c[m.k], c[m.kpi]))}`">{{ c[m.kpi] ? pct(div(c[m.k], c[m.kpi]), 0) : '—' }}</td>
                                            <td class="izq"><div v-if="c[m.kpi]" class="pista chica"><i :class="`ritmo-fondo-${ritmo(alcance(c[m.k], c[m.kpi]))}`" :style="{ width: anchoBarra(div(c[m.k], c[m.kpi])) }" /><span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" /></div></td>
                                        </template>
                                        <td class="grupo">{{ dinero2(div(c.costo, c.installs)) }}</td>
                                        <td>{{ dinero2(div(c.costo, c.nc)) }}</td>
                                        <td>{{ dinero2(div(c.costo, c.orders)) }}</td>
                                        <td v-if="masMetricas">{{ dinero2(div(c.costo, c.recompras)) }}</td>
                                        <template v-if="masMetricas">
                                            <td class="grupo">{{ entero(c.clicks) }}</td>
                                            <td>{{ entero(c.impresiones) }}</td>
                                            <td>{{ pct(div(c.clicks, c.impresiones), 2) }}</td>
                                            <td>{{ dinero2(div(c.costo, c.clicks)) }}</td>
                                            <td>{{ dinero2(div(c.costo * 1000, c.impresiones)) }}</td>
                                            <td>{{ pct(div(c.nc, c.installs)) }}</td>
                                            <td>{{ pct(div(c.orders, c.clicks), 2) }}</td>
                                            <td>{{ pct(div(c.installs, c.clicks), 2) }}</td>
                                            <td class="izq"><CeldaEditable :valor="c.region" alinear="izquierda" etiqueta="Región" @guardar="editarCampania(c, 'region', $event)" /></td>
                                        </template>
                                        <td><button type="button" class="quitar" :aria-label="`Quitar ${c.campania}`" title="Quitar del Daily" @click="quitar(c)">×</button></td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th class="fija izq">Total</th>
                                        <td colspan="2" />
                                        <template v-for="t in [totalGeneral]" :key="'tg'">
                                            <td class="grupo">{{ dinero(t.budget) }}</td>
                                            <td>{{ dinero(t.costo) }}</td>
                                            <td :class="`ritmo-texto-${ritmo(t.dif)}`">{{ pct(t.consumido, 0) }}</td>
                                            <td class="izq"><div class="pista chica"><i :class="`ritmo-fondo-${ritmo(t.dif)}`" :style="{ width: anchoBarra(t.consumido) }" /><span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" /></div></td>
                                            <td class="grupo" :class="`ritmo-texto-${ritmo(t.dif)}`">{{ pp(t.dif) }}</td>
                                            <td>{{ dinero(t.costo_ayer) }}</td>
                                            <td>{{ dinero(t.daily_plataforma) }}</td>
                                            <td>{{ dinero(t.daily_ideal) }}</td>
                                            <template v-for="m in metricasKpi" :key="`f${m.k}`">
                                                <td class="grupo">{{ t[m.kpi] ? entero(t[m.kpi]) : '—' }}</td>
                                                <td>{{ entero(t[m.k]) }}</td>
                                                <td :class="`ritmo-texto-${ritmo(alcance(t[m.k], t[m.kpi]))}`">{{ t[m.kpi] ? pct(div(t[m.k], t[m.kpi]), 0) : '—' }}</td>
                                                <td class="izq"><div v-if="t[m.kpi]" class="pista chica"><i :class="`ritmo-fondo-${ritmo(alcance(t[m.k], t[m.kpi]))}`" :style="{ width: anchoBarra(div(t[m.k], t[m.kpi])) }" /><span class="marca-ideal" :style="{ left: anchoBarra(fechas.ideal) }" /></div></td>
                                            </template>
                                            <td class="grupo">{{ dinero2(div(t.costo, t.installs)) }}</td>
                                            <td>{{ dinero2(div(t.costo, t.nc)) }}</td>
                                            <td>{{ dinero2(div(t.costo, t.orders)) }}</td>
                                            <td v-if="masMetricas">{{ dinero2(div(t.costo, t.recompras)) }}</td>
                                            <td v-if="masMetricas" colspan="9" class="grupo" />
                                        </template>
                                        <td />
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Ajustes por día de la semana (como Ajuste Lunes...Domingo de la hoja) -->
                        <template v-else>
                            <p class="hint">
                                El daily de cada campaña por día de la semana. Vacío = usa su daily de plataforma. % = cambio contra el daily de plataforma.
                                "Total a gastar" es lo que quieren invertir ese día en el país; el restante es lo que falta repartir.
                            </p>
                            <div class="tabla-scroll">
                                <table class="tabla ajustes">
                                    <thead>
                                        <tr class="totales-dia">
                                            <th class="fija izq">Total a gastar</th>
                                            <td />
                                            <td v-for="(d, i) in DIAS_SEMANA" :key="`t${i}`" colspan="2" class="grupo" :class="{ hoy: i === diaHoy }">
                                                <CeldaEditable :valor="plan.total_dia[i]" tipo="numero" :formato="dinero" vacio="—" :etiqueta="`Total a gastar el ${d}`" @guardar="editarTotalDia(i, $event)" />
                                            </td>
                                        </tr>
                                        <tr class="totales-dia">
                                            <th class="fija izq">Repartido · restante</th>
                                            <td />
                                            <td v-for="(d, i) in DIAS_SEMANA" :key="`r${i}`" colspan="2" class="grupo" :class="{ hoy: i === diaHoy }">
                                                <span>{{ dinero(repartido[i]) }}</span>
                                                <small v-if="plan.total_dia[i] !== null" class="mono" :class="Math.abs(plan.total_dia[i] - repartido[i]) < 1 ? 'ritmo-texto-bien' : 'ritmo-texto-ojo'">
                                                    {{ dinero(plan.total_dia[i] - repartido[i]) }} · {{ pct(div(repartido[i], plan.total_dia[i]), 0) }}
                                                </small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="fija izq">Campaña</th>
                                            <th>Daily plataforma</th>
                                            <template v-for="(d, i) in DIAS_SEMANA" :key="`h${i}`">
                                                <th class="grupo" :class="{ hoy: i === diaHoy }">{{ d }}</th>
                                                <th :class="{ hoy: i === diaHoy }">%</th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody v-for="g in grupos" :key="`a${g.id}`">
                                        <tr class="medio-fila">
                                            <th class="fija izq"><i v-if="g.color" class="punto" :style="{ background: `var(--${g.color})` }" />{{ g.nombre }}</th>
                                            <td>{{ dinero(total(g.todas).daily_plataforma) }}</td>
                                            <template v-for="(d, i) in DIAS_SEMANA" :key="`s${i}`">
                                                <td class="grupo" :class="{ hoy: i === diaHoy }">{{ dinero(sumaDia(g.todas, i)) }}</td>
                                                <td :class="{ hoy: i === diaHoy }" />
                                            </template>
                                        </tr>
                                        <tr v-for="c in g.filas" :key="`a${c.id}`" :class="{ apagada: c.status !== 'ACTIVA' }">
                                            <th class="fija izq campania">
                                                <span class="nombre-ajuste">{{ c.nombre_comun || c.campania }}</span>
                                                <small class="mono">{{ c.status.charAt(0) + c.status.slice(1).toLowerCase() }} · {{ nombreFunnel(c.funnel) }}</small>
                                            </th>
                                            <td><CeldaEditable :valor="c.daily_plataforma" tipo="numero" :formato="dinero" etiqueta="Daily de plataforma" @guardar="editarCampania(c, 'daily_plataforma', $event)" /></td>
                                            <template v-for="(d, i) in DIAS_SEMANA" :key="`c${i}`">
                                                <td class="grupo" :class="{ hoy: i === diaHoy }">
                                                    <CeldaEditable
                                                        :valor="c.ajustes[i]"
                                                        tipo="numero"
                                                        :formato="dinero"
                                                        :vacio="c.daily_plataforma !== null ? dinero(c.daily_plataforma) : '—'"
                                                        :etiqueta="`Ajuste del ${d}`"
                                                        @guardar="editarAjuste(c, i, $event)"
                                                    />
                                                </td>
                                                <td class="mono pct-ajuste" :class="[{ hoy: i === diaHoy }, claseCambio(cambioAjuste(c, i))]">{{ cambioAjuste(c, i) === null ? '' : pct(cambioAjuste(c, i), 0) }}</td>
                                            </template>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </section>

                    <!-- Notas -->
                    <section class="import-panel panel">
                        <h2>Notas</h2>
                        <textarea class="notas" rows="3" :value="plan.notas ?? ''" placeholder="Cambios del mes, apagados, pruebas…" @change="editarPlan('notas', $event.target.value)" />
                    </section>
                </template>
            </div>
        </div>

        <div v-if="modalAgregar" class="modal-overlay" @click.self="modalAgregar = false" @keydown.esc="modalAgregar = false">
            <div class="modal modal-agregar" role="dialog" aria-modal="true" aria-label="Agregar campaña">
                <button type="button" class="modal-close" aria-label="Cerrar" @click="modalAgregar = false">✕</button>
                <h2>Agregar campaña</h2>
                <p class="hint">Campañas con gasto o resultados en el periodo que todavía no están en el Daily.</p>
                <input v-model="buscarDisp" class="buscar-disp" type="search" autocomplete="off" placeholder="Buscar campaña" />
                <ul v-if="disponiblesFiltradas.length" class="disponibles">
                    <li v-for="d in disponiblesFiltradas" :key="d.campania">
                        <div>
                            <span class="mono">{{ d.campania }}</span>
                            <small>{{ nombreMedio(d.medio) }} · {{ nombreFunnel(d.funnel) }} · {{ dinero2(d.costo) }} · {{ entero(d.installs) }} installs</small>
                        </div>
                        <button type="button" class="modal-copy-btn" @click="agregar(d)">Agregar</button>
                    </li>
                </ul>
                <p v-else class="hint">No hay campañas pendientes con datos en el periodo.</p>
                <h3>O escríbela a mano</h3>
                <form class="agregar-form" @submit.prevent="agregar()">
                    <select v-model="nueva.medio" required>
                        <option v-for="m in medios" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                    </select>
                    <input v-model="nueva.campania" type="text" placeholder="Nombre de la campaña" required />
                    <input v-model="nueva.campania_id" type="text" placeholder="ID (opcional)" />
                    <input v-model="nueva.budget" type="text" inputmode="decimal" placeholder="Budget" />
                    <button type="submit" class="btn-primary">Agregar</button>
                </form>
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
    margin-bottom: 20px;
}
.header-derecha {
    display: flex;
    align-items: flex-end;
    gap: 12px;
}
.guardando {
    font-size: 0.7rem;
    color: var(--amber);
    padding-bottom: 10px;
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
.hint {
    font-size: 0.75rem;
    color: var(--text-faint);
    margin: 0 0 12px;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
}
.num {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
}
.periodo-label {
    font-size: 10px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-faint);
}

/* Lo morado = manual (como en la hoja). */
.manual-muestra {
    color: var(--serie-6);
    font-weight: 600;
}

.vacio h2 {
    margin: 0 0 4px;
}
.opciones-inicio {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}


/* Periodo y día de corte (mismo control que el rango de días de Creativos) */
.periodo-fila {
    align-items: flex-end;
    margin-bottom: 6px;
}
.periodo {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.rango-control {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.rango-flecha {
    color: var(--text-faint);
}
.rango-control input[type='date'] {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    color-scheme: dark light;
}
.rango-control input[type='date']:focus {
    outline: none;
    border-color: var(--amber);
}
.paso {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    cursor: pointer;
}
.paso:hover:not(:disabled) {
    border-color: var(--amber);
}
.paso:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.chip {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 0.74rem;
    color: var(--text-muted);
    cursor: pointer;
}
.chip:hover,
.chip.activo {
    border-color: var(--amber);
    color: var(--text);
}
.dia-actual {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 200px;
    margin-left: auto;
}
.dia-actual b {
    font-size: 1.05rem;
}
.dia-actual span {
    font-size: 0.72rem;
    color: var(--text-faint);
}
.pista.dias {
    margin-top: 4px;
}
.delay {
    font-size: 0.68rem;
    color: var(--text-faint);
    margin: 0 0 14px;
}
.delay.alerta {
    color: var(--yellow);
}

/* KPIs y metas */
.kpis,
.metas {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 14px;
}
.metas {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.kpi,
.meta {
    background: var(--surface);
    padding: 14px 18px;
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
.kpi.ritmo-bien .kpi-value {
    color: var(--mint);
}
.kpi.ritmo-ojo .kpi-value {
    color: var(--yellow);
}
.kpi.ritmo-mal .kpi-value {
    color: var(--coral);
}
.meta-cabeza {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.meta-input {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.66rem;
    text-transform: uppercase;
    color: var(--text-faint);
}
.meta-input :deep(.texto) {
    font-size: 0.85rem;
    text-transform: none;
    color: var(--text);
}
.meta-input span {
    font-size: 0.6rem;
    text-transform: uppercase;
    color: var(--text-faint);
}
.meta-valor {
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.meta-valor b {
    font-size: 1.5rem;
}
.meta-valor span {
    font-size: 0.75rem;
}

/* Semáforo */
.ritmo-texto-bien {
    color: var(--mint);
}
.ritmo-texto-ojo {
    color: var(--yellow);
}
.ritmo-texto-mal {
    color: var(--coral);
}
.ritmo-texto-neutro {
    color: var(--text-faint);
}
.ritmo-fondo-bien {
    background: var(--mint) !important;
}
.ritmo-fondo-ojo {
    background: var(--yellow) !important;
}
.ritmo-fondo-mal {
    background: var(--coral) !important;
}
.ritmo-fondo-neutro {
    background: var(--text-faint) !important;
}

/* Barras */
.pista {
    position: relative;
    height: 8px;
    border-radius: 999px;
    background: var(--surface-2);
    margin-top: 6px;
}
.pista i {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--amber);
}
.pista.chica {
    height: 6px;
    margin-top: 0;
    flex: 1;
    min-width: 60px;
}
.marca-ideal {
    position: absolute;
    top: -3px;
    bottom: -3px;
    width: 2px;
    background: var(--text);
    border-radius: 2px;
    opacity: 0.7;
}
.grid-barras {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 14px;
}
.panel {
    min-width: 0;
    margin-bottom: 14px;
}
.panel h2 {
    margin: 0 0 4px;
}
.barra + .barra {
    margin-top: 12px;
}
.barra-cabeza {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 0.8rem;
}
.barra-cabeza .mono {
    font-size: 0.72rem;
    color: var(--text-muted);
}
.punto {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 999px;
    margin-right: 6px;
}

/* Tabla de campañas */
.tabla-cabeza {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.tabla-cabeza h2 {
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.cuenta {
    font-size: 0.72rem;
    color: var(--text-faint);
    font-weight: 400;
}
.tabla-acciones {
    display: flex;
    align-items: center;
    gap: 12px;
}
.ver-mas {
    font-size: 0.78rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.filtros {
    margin: 12px 0;
}
.buscador {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1 1 240px;
    max-width: 380px;
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
.tabla-scroll {
    overflow: auto;
    max-width: 100%;
    max-height: calc(720px / var(--zoom-factor, 1));
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
.tabla {
    border-collapse: separate;
    border-spacing: 0;
    width: max-content;
    min-width: 100%;
    font-size: 0.78rem;
}
.tabla th,
.tabla td {
    padding: 6px 8px;
    text-align: right;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.tabla thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--surface);
    font-size: 0.66rem;
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
    border-right: 1px solid var(--border);
}
.tabla thead .fija {
    z-index: 3;
}
.medio-fila th,
.medio-fila td {
    background: var(--surface-2);
    font-weight: 600;
    color: var(--text);
}
.medio-fila .fija {
    background: var(--surface-2);
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 0.85rem;
}
.mini {
    margin-left: 10px;
    font-size: 0.66rem;
    background: none;
    border: 1px solid var(--border);
    color: var(--text-muted);
    border-radius: 999px;
    padding: 2px 8px;
    cursor: pointer;
}
.mini:hover {
    border-color: var(--amber);
    color: var(--text);
}
.campania {
    min-width: 260px;
    max-width: 320px;
    font-weight: 400;
}
.campania small {
    display: block;
    font-size: 0.6rem;
    color: var(--text-faint);
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 300px;
    margin-top: 3px;
}
.campania .sin-datos {
    color: var(--yellow);
}
.nombre-comun {
    font-size: 0.8rem;
    font-weight: 500;
}
.kpi {
    font-size: 0.66rem;
    color: var(--text-muted);
}
:deep(.estado.activa) {
    color: var(--mint);
}
:deep(.estado.pausa) {
    color: var(--yellow);
}
:deep(.estado.finalizada) {
    color: var(--text-faint);
}
:deep(.estado.pendiente) {
    color: var(--sky);
}
.consumo {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 120px;
}
.consumo .mono {
    font-size: 0.7rem;
    width: 38px;
    text-align: right;
}
.con-kpi {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
}
.con-kpi small {
    font-size: 0.6rem;
}
tr.apagada td,
tr.apagada .fija small {
    opacity: 0.55;
}
.quitar {
    background: none;
    border: 1px solid transparent;
    color: var(--text-faint);
    border-radius: 6px;
    width: 24px;
    height: 24px;
    cursor: pointer;
}
.quitar:hover {
    color: var(--coral);
    border-color: var(--coral);
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
}
.notas {
    width: 100%;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    font: inherit;
    padding: 10px 12px;
    font-size: 0.85rem;
    resize: vertical;
}

/* Encabezado en dos filas: grupos (Presupuesto, Instalaciones...) y columnas */
.pacing .grupos-cabeza th {
    text-align: center;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
}
.pacing .grupos-cabeza th.fija,
.pacing .grupos-cabeza th.izq {
    text-align: left;
    vertical-align: bottom;
}
.pacing thead tr:nth-child(2) th {
    top: 27px;
}

/* Ajustes por día */
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
.ajustes .totales-dia th,
.ajustes .totales-dia td {
    position: static;
    background: var(--surface-2);
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.78rem;
    color: var(--text);
    text-align: center;
}
.ajustes .totales-dia .fija {
    position: sticky;
    text-align: left;
    font-size: 0.7rem;
    text-transform: uppercase;
    color: var(--text-faint);
}
.ajustes .totales-dia small {
    display: block;
    font-size: 0.64rem;
}
.ajustes .hoy {
    background: color-mix(in srgb, var(--amber) 8%, transparent);
}
.ajustes thead th.hoy {
    color: var(--amber);
}
.nombre-ajuste {
    display: block;
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 500;
}
.pct-ajuste {
    font-size: 0.66rem;
    color: var(--text-faint);
    min-width: 44px;
}
.pct-ajuste.sube {
    color: var(--mint);
}
.pct-ajuste.baja {
    color: var(--coral);
}

/* Modal agregar */
.modal.modal-agregar {
    display: block;
    max-width: 760px;
    padding: 24px;
    color: var(--text);
    overflow: auto;
}
.modal-agregar h2 {
    margin: 0 0 4px;
}
.modal-agregar h3 {
    font-size: 0.85rem;
    margin: 18px 0 8px;
}
.buscar-disp,
.agregar-form input,
.agregar-form select {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 0.82rem;
}
.buscar-disp {
    width: 100%;
    margin-bottom: 10px;
}
.disponibles {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 320px;
    overflow: auto;
}
.disponibles li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}
.disponibles .mono {
    font-size: 0.7rem;
    word-break: break-all;
}
.disponibles small {
    display: block;
    font-size: 0.68rem;
    color: var(--text-faint);
    margin-top: 2px;
}
.agregar-form {
    display: grid;
    grid-template-columns: 130px 1fr 150px 100px auto;
    gap: 8px;
}

@media (max-width: 1100px) {
    .kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .metas {
        grid-template-columns: 1fr;
    }
    .agregar-form {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
}
</style>
