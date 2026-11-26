<script setup>
import AvisoCostosApi from '@/Components/AvisoCostosApi.vue';
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import { mensajeDeError, notificar } from '@/avisos';
import { etiquetaFunnel } from '@/motor';

// Control de gasto (2026-11-18, pedido explícito; diseño "Daily Control de
// Gasto" de México llevado a todos los países): alertas arriba en carrusel,
// cómo le fue al día de corte contra anteayer / semana / mes pasado,
// histórico y metas del mes. Cada campaña trae 62 días de
// [gasto, installs, first orders, recompras, orders] (ControlGasto.php).
const props = defineProps({
    pais: { type: String, required: true },
    fechas: { type: Array, default: () => [] },
    corte: { type: String, required: true },
    sugerido: { type: String, default: null },
    ultimoCosto: { type: String, default: null },
    ultimoAf: { type: String, default: null },
    medios: { type: Array, default: () => [] },
    campanias: { type: Array, default: () => [] },
    plan: { type: Object, default: () => ({}) },
    revisadas: { type: Object, default: () => ({}) },
    // Campañas de AF data sin gasto en nuestras cuentas (no se muestran).
    sinGastoPropio: { type: Number, default: 0 },
    // Historial de campañas quitadas / restauradas.
    historialExcluidas: { type: Array, default: () => [] },
    // Optimizaciones del rango (2026-11-18): marcas en el histórico y alertas.
    optimizaciones: { type: Array, default: () => [] },
    // Alertas de la cuenta (2026-11-20): campaña creada fuera del equipo,
    // base subida ≥ 50%, gasto arriba de lo planeado (AlertasCuenta.php).
    alertasCuenta: { type: Array, default: () => [] },
    // Equipo en Meta (2026-11-24): nombres marcados como del equipo y usuarios EPA para ligarlos.
    equipoMeta: { type: Array, default: () => [] },
    usuariosEquipo: { type: Array, default: () => [] },
});

// --- Optimizaciones --------------------------------------------------------------
const NOMBRE_TIPO = {
    creacion_campania: 'Creación de campaña', creacion_grupo: 'Creación de grupo', creacion_anuncios: 'Creación de anuncios',
    activacion_campania: 'Activación de campaña', pauta_anuncios: 'Pauta de anuncios', ajuste_dinero: 'Ajuste de dinero',
    ajuste_anuncios: 'Ajuste de anuncios', ajuste_estrategia: 'Ajuste de estrategia', ajuste_metas: 'Ajuste de metas',
    ajuste_optimizacion: 'Ajuste / optimización', apagado_campania: 'Apagado de campaña', apagado_grupo: 'Apagado de grupo',
    apagado_anuncio: 'Apagado de anuncio', otro: 'Otro',
};
/** Optimizaciones que tocan una campaña (por nombre), un medio o toda la cuenta. */
function optisDe({ campania = null, medio = null } = {}) {
    return props.optimizaciones.filter((o) => {
        if (campania) return o.campanias.some((c) => c.trim().toLowerCase() === campania);
        if (medio) return o.medio === medio;
        return true;
    });
}

// --- Quitar campañas que no son nuestras (con historial) -------------------------
const quitando = ref(null); // { campania, medio, nombre }
const motivo = ref('');
function pedirQuitar(c) {
    quitando.value = { campania: c.campania, medio: c.medio, nombre: etiqueta(c) };
    motivo.value = '';
}
async function confirmarQuitar() {
    const c = quitando.value;
    try {
        await axios.post(`${location.pathname}/excluir`, { campania: c.campania, medio: c.medio, motivo: motivo.value.trim() || null });
        notificar({ tipo: 'exito', titulo: 'Campaña quitada', texto: `${c.nombre}. Queda en el historial y se puede restaurar.` });
        quitando.value = null;
        router.reload({ preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo quitar', texto: mensajeDeError(err) });
    }
}
async function restaurar(m) {
    try {
        await axios.post(`${location.pathname}/restaurar/${m.id}`);
        notificar({ tipo: 'exito', titulo: 'Campaña restaurada', texto: m.campania });
        router.reload({ preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo restaurar', texto: mensajeDeError(err) });
    }
}
const hrefOptimizaciones = location.pathname.replace(/control-gasto$/, 'optimizaciones');
const hrefHistorial = `${location.pathname.replace(/control-gasto$/, 'historial')}?area=control`;
const quitadasActivas = computed(() => props.historialExcluidas.filter((m) => !m.restaurada_en).length);
// El servidor guarda en UTC: se muestra en la hora local de quien lo ve.
const fechaHora = (s) => {
    if (!s) return '';
    const d = new Date(`${s.replace(' ', 'T')}:00Z`);
    return `${d.getDate()} ${MESES[d.getMonth()]} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

// --- Métricas -------------------------------------------------------------------
// tipo: spend (gasto, ni bueno ni malo), res (más es mejor), cost (menos es mejor)
const MET = [
    { k: 'sp', l: 'Gasto', t: 'spend' },
    { k: 'inst', l: 'Instalaciones', t: 'res' },
    { k: 'fo', l: 'First Orders', t: 'res' },
    { k: 'rec', l: 'Recompras', t: 'res' },
    { k: 'ord', l: 'Orders', t: 'res' },
    { k: 'cpi', l: 'CPI', t: 'cost' },
    { k: 'cac', l: 'CAC', t: 'cost' },
    { k: 'cpo', l: 'CPO', t: 'cost' },
    { k: 'cpa', l: 'CPA', t: 'cost' },
];
const metDe = (k) => MET.find((m) => m.k === k);
const IDX = { sp: 0, inst: 1, fo: 2, rec: 3, ord: 4 };
const KPIS_CABEZA = ['sp', 'inst', 'fo', 'rec', 'ord', 'cpa'];
const VOLUMEN_MIN = 45; // como el diseño: sin volumen, la variación no alerta

const L = computed(() => props.fechas.length - 1);
const sumaRango = (e, k, f, t) => {
    let s = 0;
    for (let i = Math.max(0, f); i <= t; i++) s += e.serie[i]?.[IDX[k]] ?? 0;
    return s;
};
function valor(e, k, f, t) {
    if (k in IDX) return sumaRango(e, k, f, t);
    const sp = sumaRango(e, 'sp', f, t);
    const den = k === 'cpi' ? sumaRango(e, 'inst', f, t) : k === 'cac' ? sumaRango(e, 'fo', f, t) : k === 'cpo' ? sumaRango(e, 'ord', f, t) : sumaRango(e, 'fo', f, t) + sumaRango(e, 'rec', f, t);
    return den > 0 ? sp / den : null;
}
const variacion = (c, b) => (c === null || b === null || b === undefined ? null : b === 0 ? (c === 0 ? 0 : null) : (c / b - 1) * 100);
const nf = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 });
const fv = (k, v) => {
    if (v === null || v === undefined) return '—';
    const t = metDe(k).t;
    return t === 'res' ? nf.format(Math.round(v)) : t === 'spend' ? `$${nf.format(Math.round(v))}` : `$${v.toFixed(2)}`;
};
const fp = (d) => (d === null ? 'n/d' : Math.abs(d) < 0.5 ? '— sin cambio' : `${d > 0 ? '▲' : '▼'} ${Math.abs(d).toFixed(Math.abs(d) < 10 ? 1 : 0)}%`);
/** Clase del cambio: el gasto solo se marca; resultados suben = bien; costos bajan = bien. */
const tono = (d, t) => (d === null || Math.abs(d) < 0.5 ? 'neutro' : t === 'spend' ? 'gasto' : (t === 'res' ? d > 0 : d < 0) ? 'bien' : 'mal');

// --- Filtros ----------------------------------------------------------------------
const umbral = ref(10);
const UMBRAL_OPCIONES = [5, 10, 15, 20, 25, 30].map((u) => ({ key: u, label: `≥ ${u}%` }));
const base = ref('d1');
const BASES = [
    { key: 'd1', label: 'vs anteayer', off: 1, largo: 'anteayer' },
    { key: 'w1', label: 'vs semana pasada', off: 7, largo: 'el mismo día de la semana pasada' },
    { key: 'm1', label: 'vs mes pasado', off: 28, largo: 'el mismo día de hace 4 semanas' },
];
const baseSel = computed(() => BASES.find((b) => b.key === base.value));
const pestana = ref('general');

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const fechaCorta = (s) => (s ? `${+s.slice(8, 10)} ${MESES[+s.slice(5, 7) - 1]}` : '—');
const conDia = (s) => {
    if (!s) return '—';
    const d = new Date(`${s}T00:00:00Z`);
    return `${DIAS[d.getUTCDay()]} ${fechaCorta(s)}`;
};
const verAlDia = (al) => router.get(location.pathname, al ? { al } : {}, { preserveScroll: true, preserveState: true });
const sumarDias = (fecha, n) => {
    const d = new Date(`${fecha}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
};
const ayerReal = sumarDias(new Date().toISOString().slice(0, 10), -1);
const corteSinDatos = computed(() => !props.ultimoCosto || !props.ultimoAf || props.corte > props.ultimoCosto || props.corte > props.ultimoAf);

// --- Entidades --------------------------------------------------------------------
const nombreMedio = (id) => props.medios.find((m) => m.id === id)?.nombre ?? id;
const colorMedio = (id) => props.medios.find((m) => m.id === id)?.color ?? null;
const etiqueta = (c) => c.nombre || c.campania;
const pausada = (c) => ['EN PAUSA', 'FINALIZADA'].includes(c.status);
function agregar(lista, nombre, extra = {}) {
    const serie = props.fechas.map((_, i) => [0, 1, 2, 3, 4].map((j) => lista.reduce((t, c) => t + (c.serie[i]?.[j] ?? 0), 0)));
    const kpi = [null, 1, 2, 3, 4].map((j) => (j === null ? null : lista.reduce((t, c) => t + (Number(c.kpi?.[j]) || 0), 0) || null));
    return { nombre, serie, budget: lista.reduce((t, c) => t + (Number(c.budget) || 0), 0), kpi, campanias: lista, ...extra };
}
const mediosConDatos = computed(() => {
    const ids = [...new Set([...props.medios.map((m) => m.id), ...props.campanias.map((c) => c.medio)])];
    return ids.filter((id) => props.campanias.some((c) => c.medio === id));
});
const campaniasDe = (medio) => props.campanias.filter((c) => c.medio === medio);

// --- Análisis contra la base --------------------------------------------------------
function analizar(e) {
    const off = baseSel.value.off;
    const i = L.value;
    const m = {};
    const banderas = [];
    const den = (k, x) => (k === 'cpi' ? sumaRango(e, 'inst', x, x) : k === 'cac' ? sumaRango(e, 'fo', x, x) : k === 'cpo' ? sumaRango(e, 'ord', x, x) : sumaRango(e, 'fo', x, x) + sumaRango(e, 'rec', x, x));
    for (const met of MET) {
        const c = valor(e, met.k, i, i);
        const b = valor(e, met.k, i - off, i - off);
        const d = variacion(c, b);
        const ok = met.t === 'spend' || (met.t === 'cost' ? Math.min(den(met.k, i), den(met.k, i - off)) >= VOLUMEN_MIN : Math.max(c || 0, b || 0) >= VOLUMEN_MIN);
        m[met.k] = { c, b, d };
        if (!e.pausada && d !== null && Math.abs(d) >= umbral.value && ok) banderas.push({ ...met, d });
    }
    return { m, banderas };
}
const estadoDe = (a, revisada = false) => (revisada ? 'revisada' : a.pausada ? 'pausa' : a.banderas.length === 0 ? 'estable' : a.banderas.length >= 3 ? 'alerta' : 'revisar');
const ESTADO = { estable: 'Estable', revisar: 'Revisar', alerta: 'Alerta', pausa: 'En pausa', revisada: 'Revisada' };

const analisis = computed(() => {
    const porCampania = new Map(props.campanias.map((c) => [c.k, { ...analizar({ ...c, pausada: pausada(c) }), pausada: pausada(c) }]));
    return porCampania;
});
const revisada = (k) => !!props.revisadas[k];
const alertasPendientesDe = (lista) => lista.filter((c) => analisis.value.get(c.k).banderas.length && !revisada(c.k)).length;

const PESTANAS = computed(() => [
    { id: 'general', label: 'General de la cuenta', color: null, n: alertasPendientesDe(props.campanias) },
    ...mediosConDatos.value.map((id) => ({ id, label: nombreMedio(id), color: colorMedio(id), n: alertasPendientesDe(campaniasDe(id)) })),
]);
const esGeneral = computed(() => pestana.value === 'general');
const campaniasVista = computed(() => (esGeneral.value ? props.campanias : campaniasDe(pestana.value)));
const alcance = computed(() => agregar(campaniasVista.value, esGeneral.value ? 'Cuenta completa' : nombreMedio(pestana.value)));
const analisisAlcance = computed(() => analizar(alcance.value));

// --- Alertas (carrusel) ----------------------------------------------------------
const alertas = computed(() => {
    const lista = campaniasVista.value
        .map((c) => ({ c, a: analisis.value.get(c.k) }))
        .filter(({ a }) => a.banderas.length)
        .map(({ c, a }) => {
            const hayGasto = a.banderas.some((f) => f.t === 'spend');
            const hayRes = a.banderas.some((f) => f.t !== 'spend');
            return {
                c,
                k: c.k,
                estado: estadoDe(a, revisada(c.k)),
                revisadaPor: props.revisadas[c.k] ?? null,
                cambios: a.banderas.map((f) => ({ l: f.l, d: fp(f.d), tono: tono(f.d, f.t) })),
                pista: hayGasto && hayRes ? 'Cambió el gasto y los resultados.' : hayGasto ? 'Cambió solo el gasto.' : 'Cambiaron resultados o costos sin cambio de gasto.',
            };
        });
    const orden = { alerta: 0, revisar: 1, revisada: 2 };
    return lista.sort((a, b) => orden[a.estado] - orden[b.estado] || b.cambios.length - a.cambios.length);
});
// --- Alertas de la cuenta ------------------------------------------------------------
const TIPO_ALERTA_CUENTA = { externo: 'Fuera del equipo', base: 'Base +50%', sobre_plan: 'Ppto vs planeado', plan: 'Gasto vs planeado' };
const alertasCuentaVista = computed(() =>
    props.alertasCuenta
        .filter((a) => esGeneral.value || a.medio === pestana.value)
        .map((a) => ({ ...a, revisadaPor: props.revisadas[a.clave] ?? null }))
        .sort((a, b) => !!a.revisadaPor - !!b.revisadaPor || b.fecha.localeCompare(a.fecha)),
);
const alertasCuentaPendientes = computed(() => alertasCuentaVista.value.filter((a) => !a.revisadaPor).length);
async function alternarRevisadaCuenta(a) {
    try {
        await axios.post(`${location.pathname}/revisar`, { fecha: a.fecha, clave: a.clave, revisada: !a.revisadaPor });
        router.reload({ only: ['revisadas'], preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se guardó', texto: mensajeDeError(err) });
    }
}

// "Es del equipo": liga el nombre de Meta a un usuario del dashboard (o a ninguno).
const marcando = ref(null);
const usuarioElegido = ref('');
const guardandoEquipo = ref(false);
function abrirEquipo(a) {
    marcando.value = a.clave;
    usuarioElegido.value = a.sugeridos?.[0]?.id ?? '';
}
const otrosUsuarios = (a) => props.usuariosEquipo.filter((u) => !(a.sugeridos ?? []).some((s) => s.id === u.id));
async function guardarEquipo(a) {
    guardandoEquipo.value = true;
    try {
        await axios.post(`${location.pathname}/equipo`, { nombre: a.quien, user_id: usuarioElegido.value || null });
        marcando.value = null;
        notificar({ tipo: 'exito', titulo: 'Listo', texto: `${a.quien} ya cuenta como del equipo en Meta.` });
        router.reload({ only: ['alertasCuenta', 'equipoMeta'], preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se guardó', texto: mensajeDeError(err) });
    } finally {
        guardandoEquipo.value = false;
    }
}
async function quitarEquipo(n) {
    try {
        await axios.delete(`${location.pathname}/equipo/${n.id}`);
        router.reload({ only: ['alertasCuenta', 'equipoMeta'], preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se quitó', texto: mensajeDeError(err) });
    }
}

const carrusel = ref(null);
const moverCarrusel = (dir) => carrusel.value?.scrollBy({ left: dir * 340, behavior: 'smooth' });
async function alternarRevisada(al) {
    try {
        await axios.post(`${location.pathname}/revisar`, { fecha: props.corte, clave: al.k, revisada: al.estado !== 'revisada' });
        router.reload({ only: ['revisadas'], preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se guardó', texto: mensajeDeError(err) });
    }
}

// --- 1. KPIs del día ---------------------------------------------------------------
const kpisDia = computed(() =>
    KPIS_CABEZA.map((k) => {
        const met = metDe(k);
        const x = analisisAlcance.value.m[k];
        const serie = [];
        for (let i = L.value - 13; i <= L.value; i++) serie.push(valor(alcance.value, k, i, i) ?? 0);
        const mx = Math.max(...serie);
        const mn = Math.min(...serie);
        const y = (v) => 25 - ((v - mn) / (mx - mn || 1)) * 22;
        return {
            k,
            label: met.l,
            valor: fv(k, x.c),
            delta: fp(x.d),
            tono: tono(x.d, met.t),
            ref: `${baseSel.value.label} (${fv(k, x.b)})`,
            alerta: x.d !== null && Math.abs(x.d) >= umbral.value,
            spark: serie.map((v, i) => `${i ? 'L' : 'M'}${((i * 100) / 13).toFixed(1)},${y(v).toFixed(1)}`).join(' '),
            yUltimo: y(serie.at(-1)),
        };
    }),
);
const resumenDia = computed(() => {
    const activas = campaniasVista.value.filter((c) => !pausada(c)).length;
    const con = campaniasVista.value.filter((c) => analisis.value.get(c.k).banderas.length).length;
    return `Comparado con ${baseSel.value.largo} (${conDia(props.fechas[L.value - baseSel.value.off])}). ${con} de ${activas} campañas activas cambiaron ≥ ${umbral.value}% en gasto, resultados o costos.`;
});

// --- 2. Tabla ----------------------------------------------------------------------
const soloAlerta = ref(false);
const sel = ref(null);
const filas = computed(() => {
    const items = esGeneral.value
        ? mediosConDatos.value.map((id) => {
              const lista = campaniasDe(id);
              const e = agregar(lista, nombreMedio(id));
              const a = analizar(e);
              return { id, nombre: e.nombre, sub: `${lista.length} campañas · ${alertasPendientesDe(lista)} con alerta`, a, color: colorMedio(id), estado: estadoDe(a) };
          })
        : campaniasDe(pestana.value).map((c) => {
              const a = analisis.value.get(c.k);
              return { id: c.k, c, nombre: etiqueta(c), sub: [etiquetaFunnel(c.funnel) || 'Sin funnel', c.responsable, c.status && c.status !== 'ACTIVA' ? c.status.toLowerCase() : null].filter(Boolean).join(' · '), a, estado: estadoDe(a, a.banderas.length > 0 && revisada(c.k)) };
          });
    const orden = { alerta: 0, revisar: 1, revisada: 2, estable: 3, pausa: 4 };
    return items
        .map((it) => ({
            ...it,
            celdas: KPIS_CABEZA.map((k) => {
                const x = it.a.m[k];
                const t = metDe(k).t;
                return { v: fv(k, x.c), d: fp(x.d), tono: tono(x.d, t), marcada: !it.a.pausada && x.d !== null && Math.abs(x.d) >= umbral.value };
            }),
        }))
        .filter((f) => !soloAlerta.value || f.a.banderas.length)
        .sort((a, b) => orden[a.estado] - orden[b.estado]);
});
function abrirFila(f) {
    if (esGeneral.value) {
        pestana.value = f.id;
        sel.value = null;
    } else {
        sel.value = sel.value === f.id ? null : f.id;
    }
}
function verHistorico(al) {
    if (esGeneral.value) pestana.value = al.c.medio;
    sel.value = al.k;
    document.getElementById('historico')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
watch(pestana, () => { sel.value = null; });

// --- 3. Histórico ----------------------------------------------------------------
const metrica = ref('sp');
const rango = ref(14);
const fantasma = ref('m');
const RANGOS = [
    { key: 7, label: '7d' },
    { key: 14, label: '14d' },
    { key: 30, label: '30d' },
    { key: 'mtd', label: 'Mes' },
];
const FANTASMAS = [
    { key: 'none', label: 'Nada' },
    { key: 'w', label: 'Semana pasada' },
    { key: 'm', label: 'Mes pasado' },
];
const inicioMes = computed(() => Math.max(0, props.fechas.findIndex((f) => f >= (props.plan?.inicio ?? props.corte.slice(0, 8) + '01'))));
const G = { W: 900, H: 230, x0: 48, alto: 190 };
const historico = computed(() => {
    const ent = sel.value ? props.campanias.find((c) => c.k === sel.value) ?? alcance.value : alcance.value;
    const met = metDe(metrica.value);
    const dias = rango.value === 'mtd' ? L.value - inicioMes.value + 1 : rango.value;
    const desde = L.value - dias + 1;
    const off = fantasma.value === 'w' ? 7 : fantasma.value === 'm' ? 28 : 0;
    const serie = [];
    const sombra = [];
    for (let i = desde; i <= L.value; i++) {
        serie.push(valor(ent, met.k, i, i));
        sombra.push(off ? valor(ent, met.k, i - off, i - off) : null);
    }
    const mx = Math.max(...serie.map((v) => v || 0), ...sombra.map((v) => v || 0), 1) * 1.15;
    const ancho = (G.W - G.x0) / dias;
    const Y = (v) => 8 + G.alto - ((v || 0) / mx) * G.alto;
    const barras = [];
    const puntos = [];
    const etiquetas = [];
    serie.forEach((v, i) => {
        const x = G.x0 + i * ancho;
        const bw = off ? ancho * 0.36 : ancho * 0.62;
        const gx = x + ancho * 0.1;
        const cx = off ? gx + bw + 1 : x + ancho * 0.19;
        if (off && sombra[i] !== null) barras.push({ x: gx, w: bw, y: Y(sombra[i]), h: Math.max(0, 8 + G.alto - Y(sombra[i])), clase: 'sombra' });
        barras.push({ x: cx, w: bw, y: Y(v), h: Math.max(0, 8 + G.alto - Y(v)), clase: i === dias - 1 ? 'ultimo' : 'periodo', titulo: `${conDia(props.fechas[desde + i])}: ${fv(met.k, v)}` });
        const d = off ? variacion(v, sombra[i]) : null;
        if (d !== null && Math.abs(d) >= umbral.value && sombra[i] > 0) puntos.push({ x: cx + bw / 2, y: Y(Math.max(v || 0, sombra[i] || 0)) - 7 });
        if (dias <= 14 || i % 2 === (dias - 1) % 2) {
            const f = props.fechas[desde + i];
            etiquetas.push({ x: x + ancho / 2, t: dias <= 14 ? conDia(f).replace(/ \w+$/, '') : String(+f.slice(8, 10)) });
        }
    });
    const lineas = [0, 0.5, 1].map((f) => ({ y: Y((mx / 1.15) * f * 1.15), t: fv(met.k, mx * f) }));
    const total = valor(ent, met.k, desde, L.value);
    const totalSombra = off ? valor(ent, met.k, desde - off, L.value - off) : null;
    const dTotal = variacion(total, totalSombra);
    const ultimo = serie.at(-1);
    const dUltimo = variacion(ultimo, valor(ent, met.k, L.value - baseSel.value.off, L.value - baseSel.value.off));
    const prom = met.t === 'cost' ? total : total / dias;
    const nombreSombra = fantasma.value === 'w' ? 'semana pasada' : fantasma.value === 'm' ? 'mes pasado' : null;
    const diasVar = off ? serie.filter((v, i) => sombra[i] > 0 && variacion(v, sombra[i]) !== null && Math.abs(variacion(v, sombra[i])) >= umbral.value).length : null;
    // Acumulado por medio / campaña
    const fuentes = esGeneral.value ? mediosConDatos.value.map((id) => ({ id, e: agregar(campaniasDe(id), nombreMedio(id)) })) : campaniasDe(pestana.value).map((c) => ({ id: c.k, e: { ...c, nombre: etiqueta(c) } }));
    const acum = fuentes.map((f) => ({ id: f.id, nombre: f.e.nombre, v: valor(f.e, met.k, desde, L.value), g: off ? valor(f.e, met.k, desde - off, L.value - off) : null }));
    const accMax = Math.max(...acum.map((a) => Math.max(a.v || 0, a.g || 0)), 1);
    // Días con optimización de lo que se está viendo.
    const optis = optisDe(sel.value ? { campania: sel.value } : esGeneral.value ? {} : { medio: pestana.value });
    const marcas = [];
    for (let i = 0; i < dias; i++) {
        const f = props.fechas[desde + i];
        const delDia = optis.filter((o) => o.fecha === f);
        if (delDia.length) marcas.push({ x: G.x0 + i * ancho + ancho / 2, n: delDia.length, titulo: `${conDia(f)}: ${delDia.map((o) => NOMBRE_TIPO[o.tipo] ?? o.tipo).join(', ')}` });
    }
    const optisRango = optis.filter((o) => o.fecha >= props.fechas[desde] && o.fecha <= props.fechas[L.value]);
    return {
        marcas,
        optisRango,
        entidad: sel.value ? etiqueta(props.campanias.find((c) => c.k === sel.value) ?? { campania: '' }) : alcance.value.nombre,
        met,
        dias,
        barras,
        puntos,
        etiquetas,
        lineas,
        nombreSombra,
        stats: [
            { l: met.t === 'cost' ? 'Costo del periodo' : 'Acumulado del periodo', v: fv(met.k, total), d: off ? `${fp(dTotal)} vs ${nombreSombra} (${fv(met.k, totalSombra)})` : 'Sin comparativo', tono: tono(dTotal, met.t) },
            { l: `El ${fechaCorta(props.corte)}`, v: fv(met.k, ultimo), d: `${fp(dUltimo)} ${baseSel.value.label}`, tono: tono(dUltimo, met.t) },
            { l: 'Promedio diario', v: fv(met.k, prom), d: `Último día vs promedio: ${fp(variacion(ultimo, prom))}`, tono: 'neutro' },
            { l: 'Días con variación', v: off ? `${diasVar} de ${dias}` : '—', d: off ? `≥ ${umbral.value}% vs ${nombreSombra}` : 'Activa una comparación', tono: 'neutro' },
        ],
        acumulado: acum
            .sort((a, b) => (b.v || 0) - (a.v || 0))
            .map((a) => ({ ...a, vf: fv(met.k, a.v), d: off ? fp(variacion(a.v, a.g)) : '', tono: tono(variacion(a.v, a.g), met.t), w: `${((a.v || 0) / accMax) * 100}%`, gw: off ? `${((a.g || 0) / accMax) * 100}%` : '0%' })),
    };
});
function clicAcumulado(a) {
    if (esGeneral.value) pestana.value = a.id;
    else sel.value = sel.value === a.id ? null : a.id;
}

// --- 4. Metas -----------------------------------------------------------------------
const ideal = computed(() => {
    const ini = new Date(`${props.plan?.inicio ?? props.corte}T00:00:00Z`);
    const fin = new Date(`${props.plan?.fin ?? props.corte}T00:00:00Z`);
    const hoy = new Date(`${props.corte}T00:00:00Z`);
    const total = Math.round((fin - ini) / 86400000) + 1;
    const pasados = Math.max(0, Math.round((hoy - ini) / 86400000) + 1);
    return total > 0 ? Math.min(1, pasados / total) : 0;
});
const METAS = [
    { k: 'inst', l: 'Instalaciones', j: 1 },
    { k: 'fo', l: 'First Orders', j: 2 },
    { k: 'rec', l: 'Recompras', j: 3 },
    { k: 'ord', l: 'Orders', j: 4 },
];
const estadoMeta = (pj) => (pj === null ? ['Sin meta', 'neutro'] : pj >= 1 ? ['Va en meta', 'bien'] : pj >= 0.85 ? ['En riesgo', 'ojo'] : ['No se logrará', 'mal']);
function metasDe(e, metasPlan = null) {
    return METAS.map((m) => {
        const real = valor(e, m.k, inicioMes.value, L.value);
        const meta = Number(metasPlan?.[m.j]) || Number(e.kpi?.[m.j]) || null;
        const ach = meta ? real / meta : null;
        const pj = ach === null || !ideal.value ? null : ach / ideal.value;
        const [estado, t] = estadoMeta(pj);
        return { ...m, real, meta, ach, pj, estado, tono: t };
    });
}
function presupuestoDe(e) {
    const gasto = valor(e, 'sp', inicioMes.value, L.value);
    const c = e.budget ? gasto / e.budget : null;
    const dif = c === null ? null : (c - ideal.value) * 100;
    const t = dif === null ? 'neutro' : dif > 3 ? 'mal' : dif < -3 ? 'ojo' : 'bien';
    return { gasto, c, dif, tono: t, estado: dif === null ? 'Sin budget' : dif > 3 ? 'Sobreritmo' : dif < -3 ? 'Subejecución' : 'En ritmo' };
}
const metasAlcance = computed(() => ({ pres: presupuestoDe(alcance.value), metas: metasDe(alcance.value, esGeneral.value ? props.plan?.metas : null) }));
const metasTabla = computed(() =>
    (esGeneral.value
        ? mediosConDatos.value.map((id) => ({ e: agregar(campaniasDe(id), nombreMedio(id)), sub: `${campaniasDe(id).length} campañas` }))
        : campaniasDe(pestana.value).map((c) => ({ e: { ...c, nombre: etiqueta(c) }, sub: etiquetaFunnel(c.funnel) || 'Sin funnel' }))
    ).map((it) => ({ nombre: it.e.nombre, sub: it.sub, pres: presupuestoDe(it.e), metas: metasDe(it.e) })),
);
const anchoMeta = (v) => `${(Math.min(v ?? 0, 1.2) / 1.2) * 100}%`;
const pctEntero = (v) => (v === null || v === undefined ? '—' : `${Math.round(v * 100)}%`);
</script>

<template>
    <Head :title="`Control de gasto — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="control-gasto">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Control de gasto</h1>
                        <p class="subtitle">Qué cambió el último día con datos: alertas por campaña, comparación contra días anteriores, histórico y avance de metas.</p>
                        <AvisoCostosApi />
                    </div>
                </header>

                <div class="resumen-filtros controles">
                    <div class="periodo">
                        <span class="periodo-label mono">Día</span>
                        <div class="rango-control">
                            <button type="button" class="paso" aria-label="Día anterior" @click="verAlDia(sumarDias(corte, -1))">‹</button>
                            <input type="date" aria-label="Día" :max="ayerReal" :value="corte" @change="verAlDia($event.target.value)" />
                            <button type="button" class="paso" aria-label="Día siguiente" :disabled="corte >= ayerReal" @click="verAlDia(sumarDias(corte, 1))">›</button>
                            <button type="button" class="chip" :class="{ activo: corte === sugerido }" @click="verAlDia(null)">Último con datos</button>
                        </div>
                    </div>
                    <div class="periodo">
                        <span class="periodo-label mono">Comparar contra</span>
                        <div class="periodo-toggle" role="group" aria-label="Comparar contra">
                            <button v-for="b in BASES" :key="b.key" type="button" :class="{ activo: base === b.key }" @click="base = b.key">{{ b.label }}</button>
                        </div>
                    </div>
                    <FiltroDropdown label="Alerta si cambia" v-model="umbral" :options="UMBRAL_OPCIONES" />
                </div>
                <p class="delay mono" :class="{ alerta: corteSinDatos }">
                    <template v-if="corteSinDatos">El {{ fechaCorta(corte) }} todavía no tiene datos completos (costos hasta {{ fechaCorta(ultimoCosto) }}, AF data hasta {{ fechaCorta(ultimoAf) }}).</template>
                    <template v-else>Costos hasta {{ fechaCorta(ultimoCosto) }} · AF data hasta {{ fechaCorta(ultimoAf) }}. Por el delay, se muestra el último día con los dos.</template>
                </p>

                <p v-if="!campanias.length" class="empty-note">Todavía no hay datos. Sube data.medios en Medios data y AppsFlyer en AF data.</p>

                <template v-else>
                    <nav class="pestanas" aria-label="Medio">
                        <button v-for="t in PESTANAS" :key="t.id" type="button" :class="{ activa: pestana === t.id }" @click="pestana = t.id">
                            <i v-if="t.color" class="punto" :style="{ background: `var(--${t.color})` }" />{{ t.label }}
                            <span v-if="t.n" class="burbuja mono">{{ t.n }}</span>
                        </button>
                    </nav>

                    <p class="alcance-nota">
                        Solo campañas que gastan desde nuestras cuentas (data.medios) o están en el Daily<template v-if="sinGastoPropio">; {{ sinGastoPropio }} de AF data sin gasto nuestro no se muestran</template>.
                        <template v-if="quitadasActivas"> {{ quitadasActivas }} quitada{{ quitadasActivas === 1 ? '' : 's' }} por el equipo (ver historial abajo).</template>
                    </p>

                    <!-- Alertas de la cuenta -->
                    <section v-if="alertasCuentaVista.length || equipoMeta.length" class="import-panel panel alertas-cuenta">
                        <div class="cabeza">
                            <div>
                                <h2>Alertas de la cuenta <span class="mono cuenta">{{ alertasCuentaPendientes }} pendientes</span></h2>
                                <p class="hint">Campañas creadas en Meta por alguien fuera del equipo EPA; presupuestos subidos ≥ 50% de un jalón o que dejan la campaña ≥ 50% arriba de su daily planeado (últimos 14 días, de quien sea); y campañas que van a gastar ≥ 50% más de lo planeado en el Daily.</p>
                            </div>
                        </div>
                        <ul class="lista-cuenta">
                            <li v-for="a in alertasCuentaVista" :key="a.clave" class="fila-cuenta" :class="[`c-${a.tipo}`, { hecha: a.revisadaPor }]">
                                <span class="tipo-cuenta mono">{{ TIPO_ALERTA_CUENTA[a.tipo] }}</span>
                                <div class="cuerpo-cuenta">
                                    <p class="titulo-cuenta">
                                        {{ a.titulo }}<template v-if="a.medio"> · {{ nombreMedio(a.medio) }}</template>
                                        <span v-if="a.tipo !== 'plan'" class="mono fecha-cuenta">{{ fechaCorta(a.fecha) }}</span>
                                    </p>
                                    <p class="campania-cuenta mono" :title="a.campania">{{ a.campania }}</p>
                                    <p class="detalle-cuenta">{{ a.detalle }}<template v-if="a.quien && a.tipo !== 'externo'"> · {{ a.quien }}</template></p>
                                    <p v-if="a.tipo === 'externo' && a.sugeridos?.length && marcando !== a.clave" class="sugerido-cuenta">
                                        ¿Es {{ a.sugeridos.map((u) => u.nombre).join(' o ') }}?
                                    </p>
                                    <div v-if="marcando === a.clave" class="marcar-equipo">
                                        <select v-model="usuarioElegido" class="select-equipo">
                                            <optgroup v-if="a.sugeridos?.length" label="Parecidos">
                                                <option v-for="u in a.sugeridos" :key="u.id" :value="u.id">{{ u.nombre }} · {{ u.parecido }}%</option>
                                            </optgroup>
                                            <optgroup label="Usuarios del dashboard">
                                                <option v-for="u in otrosUsuarios(a)" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                                            </optgroup>
                                            <option value="">Sin usuario del dashboard</option>
                                        </select>
                                        <button type="button" class="modal-copy-btn" :disabled="guardandoEquipo" @click="guardarEquipo(a)">{{ guardandoEquipo ? 'Guardando…' : 'Guardar' }}</button>
                                        <button type="button" class="modal-copy-btn" @click="marcando = null">Cancelar</button>
                                    </div>
                                </div>
                                <button v-if="a.tipo === 'externo' && marcando !== a.clave" type="button" class="modal-copy-btn" @click="abrirEquipo(a)">Es del equipo</button>
                                <button type="button" class="revisar" :class="{ hecho: a.revisadaPor }" @click="alternarRevisadaCuenta(a)">
                                    <span class="caja">{{ a.revisadaPor ? '✓' : '' }}</span>
                                    {{ a.revisadaPor ? `Revisada por ${a.revisadaPor}` : 'Marcar revisada' }}
                                </button>
                            </li>
                        </ul>
                        <div v-if="equipoMeta.length" class="equipo-meta">
                            <span class="label-equipo mono">Equipo en Meta</span>
                            <span v-for="n in equipoMeta" :key="n.id" class="chip-equipo">
                                {{ n.nombre }}<template v-if="n.usuario && n.usuario !== n.nombre"> → {{ n.usuario }}</template>
                                <button type="button" class="quitar-equipo" :title="`Quitar a ${n.nombre} del equipo`" @click="quitarEquipo(n)">×</button>
                            </span>
                        </div>
                    </section>

                    <!-- Alertas: carrusel -->
                    <section class="import-panel panel alertas">
                        <div class="cabeza">
                            <div>
                                <h2>Alertas · revisar en plataforma <span class="mono cuenta">{{ alertas.filter((a) => a.estado !== 'revisada').length }} pendientes</span></h2>
                                <p class="hint">Gasto, instalaciones, first orders, recompras, orders o costos que cambiaron ≥ {{ umbral }}% {{ baseSel.label }}. Sin volumen suficiente no alerta.</p>
                            </div>
                            <div v-if="alertas.length" class="flechas">
                                <button type="button" class="paso" aria-label="Alertas anteriores" @click="moverCarrusel(-1)">‹</button>
                                <button type="button" class="paso" aria-label="Más alertas" @click="moverCarrusel(1)">›</button>
                            </div>
                        </div>
                        <p v-if="!alertas.length" class="sin-alertas">Sin alertas para esta comparación. Todo estable.</p>
                        <div v-else ref="carrusel" class="carrusel">
                            <article v-for="al in alertas" :key="al.k" class="tarjeta-alerta" :class="`t-${al.estado}`">
                                <div class="alerta-cabeza">
                                    <span class="alerta-nombre" :title="al.c.campania">
                                        <template v-if="esGeneral">{{ nombreMedio(al.c.medio) }} · </template>{{ etiqueta(al.c) }}
                                    </span>
                                    <span class="estado mono" :class="al.estado">{{ ESTADO[al.estado] }}</span>
                                </div>
                                <ul class="cambios">
                                    <li v-for="x in al.cambios" :key="x.l"><span>{{ x.l }}</span><b :class="`tono-${x.tono}`">{{ x.d }}</b></li>
                                </ul>
                                <p class="pista">{{ al.pista }}</p>
                                <p v-for="o in optisDe({ campania: al.k }).filter((o) => o.fecha >= fechas[fechas.length - 1 - baseSel.off]).slice(0, 2)" :key="o.id" class="opti-alerta">
                                    <b>{{ NOMBRE_TIPO[o.tipo] ?? o.tipo }}</b> el {{ fechaCorta(o.fecha) }}<template v-if="o.responsable"> · {{ o.responsable }}</template>
                                </p>
                                <p v-if="al.c.responsable" class="responsable">Responsable: {{ al.c.responsable }}</p>
                                <div class="alerta-acciones">
                                    <button type="button" class="revisar" :class="{ hecho: al.estado === 'revisada' }" @click="alternarRevisada(al)">
                                        <span class="caja">{{ al.estado === 'revisada' ? '✓' : '' }}</span>
                                        {{ al.estado === 'revisada' ? `Revisada por ${al.revisadaPor}` : 'Marcar revisada' }}
                                    </button>
                                    <span class="acciones-der">
                                        <button type="button" class="link" @click="verHistorico(al)">Ver histórico</button>
                                        <button type="button" class="link quitar-link" title="No es nuestra" @click="pedirQuitar(al.c)">Quitar</button>
                                    </span>
                                </div>
                            </article>
                        </div>
                    </section>

                    <!-- 1. Cómo le fue -->
                    <section class="seccion">
                        <div class="seccion-cabeza">
                            <div>
                                <p class="eyebrow mono">1 · Cómo le fue el {{ conDia(corte) }} · {{ alcance.nombre }}</p>
                                <p class="frase">{{ resumenDia }}</p>
                            </div>
                        </div>
                        <div class="kpis-dia">
                            <div v-for="k in kpisDia" :key="k.k" class="kpi-dia" :class="{ marcado: k.alerta }">
                                <div class="kpi-dia-cabeza">
                                    <span class="kpi-label">{{ k.label }}</span>
                                    <span v-if="k.alerta" class="mono marca">ALERTA</span>
                                </div>
                                <b class="num">{{ k.valor }}</b>
                                <div class="kpi-dia-pie">
                                    <div>
                                        <span :class="`tono-${k.tono}`">{{ k.delta }}</span>
                                        <small>{{ k.ref }}</small>
                                    </div>
                                    <svg viewBox="0 0 100 28" class="spark" aria-hidden="true">
                                        <path :d="k.spark" fill="none" stroke="var(--text-faint)" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                                        <circle cx="100" :cy="k.yUltimo" r="2.5" fill="var(--amber)" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Tabla -->
                    <section class="import-panel panel">
                        <div class="cabeza">
                            <div>
                                <h2>2 · {{ baseSel.label.replace('vs', 'Contra') }} · {{ esGeneral ? 'medios' : 'campañas' }}</h2>
                                <p class="hint">Celda resaltada = cambió ≥ {{ umbral }}%. Toca {{ esGeneral ? 'un medio para abrir su pestaña' : 'una campaña para verla en el histórico' }}.</p>
                            </div>
                            <button type="button" class="chip" :class="{ activo: soloAlerta }" @click="soloAlerta = !soloAlerta">Solo con alerta</button>
                        </div>
                        <div class="tabla-scroll">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th class="fija izq">{{ esGeneral ? 'Medio' : 'Campaña' }}</th>
                                        <th v-for="k in KPIS_CABEZA" :key="k">{{ metDe(k).l }}</th>
                                        <th class="izq">Estado</th>
                                        <th v-if="!esGeneral" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="f in filas" :key="f.id" class="clic" :class="[`fila-${f.estado}`, { sel: sel === f.id }]" @click="abrirFila(f)">
                                        <th class="fija izq">
                                            <span class="nombre"><i v-if="f.color" class="punto" :style="{ background: `var(--${f.color})` }" />{{ f.nombre }}</span>
                                            <small>{{ f.sub }}</small>
                                        </th>
                                        <td v-for="(c, i) in f.celdas" :key="i" :class="{ marcada: c.marcada, [`fondo-${c.tono}`]: c.marcada }">
                                            <b>{{ c.v }}</b>
                                            <small :class="`tono-${c.tono}`">{{ c.d }}</small>
                                        </td>
                                        <td class="izq"><span class="estado mono" :class="f.estado">{{ ESTADO[f.estado] }}</span></td>
                                        <td v-if="!esGeneral">
                                            <button type="button" class="quitar" title="No es nuestra: quitar de Control de gasto" :aria-label="`Quitar ${f.nombre}`" @click.stop="pedirQuitar(f.c)">×</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="!filas.length" class="empty-note">Nada con alerta.</p>
                        </div>
                    </section>

                    <!-- 3. Histórico -->
                    <section id="historico" class="import-panel panel">
                        <div class="cabeza">
                            <div>
                                <h2>3 · ¿Ya había pasado? Histórico por día</h2>
                                <p class="hint">
                                    Viendo <b>{{ historico.entidad }}</b> · {{ historico.met.l }} · {{ rango === 'mtd' ? 'mes a la fecha' : `últimos ${historico.dias} días` }}.
                                    <button v-if="sel" type="button" class="link" @click="sel = null">× Ver {{ esGeneral ? 'cuenta' : 'medio completo' }}</button>
                                </p>
                            </div>
                        </div>
                        <div class="resumen-filtros historico-filtros">
                            <div class="periodo">
                                <span class="periodo-label mono">Métrica</span>
                                <div class="periodo-toggle envuelve">
                                    <button v-for="m in MET" :key="m.k" type="button" :class="{ activo: metrica === m.k }" @click="metrica = m.k">{{ m.l }}</button>
                                </div>
                            </div>
                            <div class="periodo">
                                <span class="periodo-label mono">Periodo</span>
                                <div class="periodo-toggle">
                                    <button v-for="r in RANGOS" :key="r.key" type="button" :class="{ activo: rango === r.key }" @click="rango = r.key">{{ r.label }}</button>
                                </div>
                            </div>
                            <div class="periodo">
                                <span class="periodo-label mono">Superponer</span>
                                <div class="periodo-toggle">
                                    <button v-for="g in FANTASMAS" :key="g.key" type="button" :class="{ activo: fantasma === g.key }" @click="fantasma = g.key">{{ g.label }}</button>
                                </div>
                            </div>
                        </div>
                        <div class="stats">
                            <div v-for="s in historico.stats" :key="s.l" class="stat">
                                <span class="kpi-label">{{ s.l }}</span>
                                <b class="num">{{ s.v }}</b>
                                <small :class="`tono-${s.tono}`">{{ s.d }}</small>
                            </div>
                        </div>
                        <div class="historico">
                            <div class="grafica">
                                <div class="leyenda">
                                    <span><i class="cuadro periodo" />Periodo</span>
                                    <span><i class="cuadro ultimo" />{{ fechaCorta(corte) }}</span>
                                    <span v-if="historico.nombreSombra"><i class="cuadro sombra" />{{ historico.nombreSombra }}</span>
                                    <span v-if="historico.nombreSombra"><i class="circulo" />Día con cambio ≥ {{ umbral }}%</span>
                                    <span v-if="historico.marcas.length"><i class="rombo" />Optimización</span>
                                </div>
                                <svg :viewBox="`0 0 ${G.W} ${G.H}`" role="img" :aria-label="`${historico.met.l} por día`">
                                    <g v-for="(g, i) in historico.lineas" :key="`l${i}`">
                                        <line :x1="G.x0" :x2="G.W" :y1="g.y" :y2="g.y" class="eje" />
                                        <text x="0" :y="g.y + 3">{{ g.t }}</text>
                                    </g>
                                    <rect v-for="(b, i) in historico.barras" :key="`b${i}`" :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="2" :class="b.clase">
                                        <title v-if="b.titulo">{{ b.titulo }}</title>
                                    </rect>
                                    <circle v-for="(d, i) in historico.puntos" :key="`d${i}`" :cx="d.x" :cy="d.y" r="3.5" class="punto-var" />
                                    <g v-for="(m, i) in historico.marcas" :key="`o${i}`" class="marca-opti">
                                        <line :x1="m.x" :x2="m.x" y1="8" :y2="G.alto + 8" />
                                        <rect :x="m.x - 5" y="0" width="10" height="10" rx="2" />
                                        <title>{{ m.titulo }}</title>
                                    </g>
                                    <text v-for="(x, i) in historico.etiquetas" :key="`x${i}`" :x="x.x" y="224" text-anchor="middle">{{ x.t }}</text>
                                </svg>
                            </div>
                            <div v-if="historico.optisRango.length" class="optis-lista">
                                <p class="periodo-label mono">Optimizaciones en el periodo · {{ historico.optisRango.length }}</p>
                                <ul>
                                    <li v-for="o in historico.optisRango.slice(0, 12)" :key="o.id">
                                        <span class="mono">{{ fechaCorta(o.fecha) }}</span>
                                        <b>{{ NOMBRE_TIPO[o.tipo] ?? o.tipo }}</b>
                                        <span class="texto" :title="o.descripcion">{{ o.descripcion }}</span>
                                    </li>
                                </ul>
                                <a :href="hrefOptimizaciones" class="link">Ver todas en Optimizaciones</a>
                            </div>
                            <div class="acumulado">
                                <p class="periodo-label mono">{{ historico.met.t === 'cost' ? 'Costo' : 'Acumulado' }} por {{ esGeneral ? 'medio' : 'campaña' }}</p>
                                <button v-for="a in historico.acumulado" :key="a.id" type="button" class="acum" :class="{ sel: sel === a.id }" @click="clicAcumulado(a)">
                                    <span class="acum-cabeza"><span class="acum-nombre">{{ a.nombre }}</span><span><b>{{ a.vf }}</b> <small :class="`tono-${a.tono}`">{{ a.d }}</small></span></span>
                                    <span class="pista"><i class="g" :style="{ width: a.gw }" /><i class="v" :style="{ width: a.w }" /></span>
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- 4. Metas -->
                    <section class="metas-grid">
                        <div class="import-panel panel">
                            <h2>4 · Metas del mes · {{ esGeneral ? 'cuenta' : alcance.nombre }}</h2>
                            <p class="hint">Al {{ fechaCorta(corte) }} el avance ideal es {{ pctEntero(ideal) }}. Marca = ideal · línea gris = 100% de la meta. Metas y budgets salen del Daily.</p>
                            <div class="meta">
                                <div class="meta-cabeza"><b>Presupuesto consumido</b><span class="estado mono" :class="`t-${metasAlcance.pres.tono}`">{{ metasAlcance.pres.estado }}</span></div>
                                <div class="pista meta-pista">
                                    <i :class="`fondo-solido-${metasAlcance.pres.tono}`" :style="{ width: anchoMeta(metasAlcance.pres.c) }" />
                                    <span class="cien" :style="{ left: anchoMeta(1) }" /><span class="ideal" :style="{ left: anchoMeta(ideal) }" />
                                </div>
                                <small>{{ fv('sp', metasAlcance.pres.gasto) }} de {{ fv('sp', alcance.budget || null) }} · {{ pctEntero(metasAlcance.pres.c) }}<template v-if="metasAlcance.pres.dif !== null"> · {{ metasAlcance.pres.dif >= 0 ? '+' : '−' }}{{ Math.abs(metasAlcance.pres.dif).toFixed(1) }} pp vs ideal</template></small>
                            </div>
                            <div v-for="m in metasAlcance.metas" :key="m.k" class="meta">
                                <div class="meta-cabeza"><b>{{ m.l }}</b><span class="estado mono" :class="`t-${m.tono}`">{{ m.estado }}</span></div>
                                <div class="pista meta-pista">
                                    <i :class="`fondo-solido-${m.tono}`" :style="{ width: anchoMeta(m.ach) }" />
                                    <span class="cien" :style="{ left: anchoMeta(1) }" /><span class="ideal" :style="{ left: anchoMeta(ideal) }" />
                                </div>
                                <small>
                                    <template v-if="m.meta">{{ fv(m.k, m.real) }} de {{ fv(m.k, m.meta) }} · {{ pctEntero(m.ach) }} · proyección {{ pctEntero(m.pj) }}</template>
                                    <template v-else>{{ fv(m.k, m.real) }} · sin meta en el Daily</template>
                                </small>
                            </div>
                        </div>
                        <div class="import-panel panel">
                            <h2>Metas por {{ esGeneral ? 'medio' : 'campaña' }}</h2>
                            <p class="hint">% alcanzado de la meta del mes. Verde: va en meta · ámbar: en riesgo · rojo: no se logrará.</p>
                            <div class="tabla-scroll">
                                <table class="tabla">
                                    <thead>
                                        <tr>
                                            <th class="fija izq">{{ esGeneral ? 'Medio' : 'Campaña' }}</th>
                                            <th class="izq">Presupuesto</th>
                                            <th v-for="m in METAS" :key="m.k">{{ m.l }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in metasTabla" :key="r.nombre">
                                            <th class="fija izq"><span class="nombre">{{ r.nombre }}</span><small>{{ r.sub }}</small></th>
                                            <td class="izq">
                                                <div class="pista chica"><i :class="`fondo-solido-${r.pres.tono}`" :style="{ width: anchoMeta(r.pres.c) }" /><span class="ideal" :style="{ left: anchoMeta(ideal) }" /></div>
                                                <small :class="`tono-${r.pres.tono === 'bien' ? 'bien' : r.pres.tono === 'neutro' ? 'neutro' : 'mal'}`">{{ pctEntero(r.pres.c) }}<template v-if="r.pres.dif !== null"> · {{ r.pres.dif >= 0 ? '+' : '−' }}{{ Math.abs(r.pres.dif).toFixed(1) }} pp</template></small>
                                            </td>
                                            <td v-for="m in r.metas" :key="m.k"><span class="estado mono" :class="`t-${m.tono}`">{{ pctEntero(m.ach) }}</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                    <details class="import-panel panel historial" :open="false">
                        <summary>
                            <h2>Campañas quitadas <span class="mono cuenta">{{ quitadasActivas }} quitadas · {{ historialExcluidas.length }} movimientos</span></h2>
                            <span class="hint">Las que el equipo marcó como que no son nuestras. Quién, cuándo y por qué; se pueden restaurar. Todos los cambios del país están en <a :href="hrefHistorial" class="link">Historial de cambios</a>.</span>
                        </summary>
                        <p v-if="!historialExcluidas.length" class="empty-note">Nadie ha quitado campañas.</p>
                        <table v-else class="tabla historial-tabla">
                            <thead>
                                <tr>
                                    <th class="izq">Campaña</th>
                                    <th class="izq">Quitada</th>
                                    <th class="izq">Motivo</th>
                                    <th class="izq">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in historialExcluidas" :key="m.id">
                                    <td class="izq"><span class="nombre mono">{{ m.campania }}</span><small>{{ m.medio ? nombreMedio(m.medio) : '' }}</small></td>
                                    <td class="izq">{{ m.por ?? '—' }}<small>{{ fechaHora(m.en) }}</small></td>
                                    <td class="izq motivo">{{ m.motivo || '—' }}</td>
                                    <td class="izq">
                                        <template v-if="m.restaurada_en">Restaurada por {{ m.restauro ?? '—' }}<small>{{ fechaHora(m.restaurada_en) }}</small></template>
                                        <button v-else type="button" class="chip" @click="restaurar(m)">Restaurar</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </details>

                    <p class="nota">CPI = gasto / instalaciones · CAC = gasto / first orders · CPO = gasto / orders · CPA = gasto / (first orders + recompras). Verde = mejora, rojo = empeora; el gasto solo se marca.</p>
                </template>
            </div>
        </div>

        <div v-if="quitando" class="modal-overlay" @click.self="quitando = null" @keydown.esc="quitando = null">
            <div class="modal modal-quitar" role="dialog" aria-modal="true" aria-label="Quitar campaña">
                <button type="button" class="modal-close" aria-label="Cerrar" @click="quitando = null">✕</button>
                <h2>Quitar campaña de Control de gasto</h2>
                <p class="mono campania-quitar">{{ quitando.campania }}</p>
                <p class="hint">Deja de verse aquí para todo el equipo. Queda en el historial con tu nombre y se puede restaurar.</p>
                <label class="motivo-campo">
                    <span class="periodo-label mono">Motivo (opcional)</span>
                    <input v-model="motivo" type="text" maxlength="300" placeholder="Ej. es de la agencia de marca, no la manejamos" @keydown.enter.prevent="confirmarQuitar" />
                </label>
                <div class="modal-acciones">
                    <button type="button" class="modal-copy-btn" @click="quitando = null">Cancelar</button>
                    <button type="button" class="btn-primary peligro" @click="confirmarQuitar">Quitar</button>
                </div>
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
    margin-bottom: 20px;
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
    margin: 2px 0 0;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
}
.nota {
    font-size: 0.7rem;
    color: var(--text-faint);
}
.num {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
}
.kpi-label,
.periodo-label {
    font-size: 0.66rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.cuenta {
    font-size: 0.72rem;
    color: var(--text-faint);
    font-weight: 400;
}
.link {
    background: none;
    border: none;
    color: var(--amber);
    font: inherit;
    font-size: 0.74rem;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
}

/* Tonos: verde mejora, rojo empeora, gasto en azul cielo, neutro gris */
.tono-bien {
    color: var(--mint);
}
.tono-mal {
    color: var(--coral);
}
.tono-gasto {
    color: var(--sky);
}
.tono-ojo {
    color: var(--yellow);
}
.tono-neutro {
    color: var(--text-faint);
}

/* Controles */
.controles {
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
    white-space: nowrap;
}
.chip:hover,
.chip.activo {
    border-color: var(--amber);
    color: var(--text);
}
.periodo-toggle {
    display: flex;
    gap: 4px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 3px;
}
.periodo-toggle.envuelve {
    flex-wrap: wrap;
}
.periodo-toggle button {
    background: transparent;
    border: none;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 12px;
    color: var(--text-muted);
    cursor: pointer;
    white-space: nowrap;
}
.periodo-toggle button.activo {
    background: var(--amber);
    color: var(--amber-ink);
    font-weight: 600;
}
.delay {
    font-size: 0.68rem;
    color: var(--text-faint);
    margin: 0 0 14px;
}
.delay.alerta {
    color: var(--yellow);
}

/* Pestañas */
.pestanas {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
    overflow-x: auto;
}
.pestanas button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 14px;
    font-size: 0.84rem;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    white-space: nowrap;
}
.pestanas button.activa {
    color: var(--text);
    border-bottom-color: var(--amber);
}
.punto {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 2px;
    margin-right: 6px;
    flex: none;
}
.burbuja {
    font-size: 0.64rem;
    font-weight: 700;
    background: color-mix(in srgb, var(--coral) 18%, transparent);
    color: var(--coral);
    border-radius: 999px;
    padding: 1px 7px;
}

.panel {
    min-width: 0;
    margin-bottom: 16px;
}
.import-panel.panel {
    display: block;
}
.panel h2 {
    margin: 0;
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}
.cabeza {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
}

/* Alertas de la cuenta */
.lista-cuenta {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.fila-cuenta {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-left: 3px solid var(--coral);
    border-radius: 12px;
    padding: 10px 14px;
}
.fila-cuenta.c-plan {
    border-left-color: var(--yellow);
}
.fila-cuenta.hecha {
    border-left-color: var(--border);
    opacity: 0.65;
}
.tipo-cuenta {
    flex: 0 0 auto;
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 3px 8px;
    border-radius: 999px;
    color: var(--coral);
    background: color-mix(in srgb, var(--coral) 20%, transparent);
}
.c-plan .tipo-cuenta {
    color: var(--yellow);
    background: color-mix(in srgb, var(--yellow) 20%, transparent);
}
.cuerpo-cuenta {
    flex: 1;
    min-width: 0;
}
.cuerpo-cuenta p {
    margin: 0;
}
.titulo-cuenta {
    font-weight: 600;
    font-size: 0.82rem;
}
.fecha-cuenta {
    margin-left: 6px;
    font-size: 0.7rem;
    color: var(--text-faint);
    font-weight: 400;
}
.campania-cuenta {
    font-size: 0.7rem;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.detalle-cuenta {
    font-size: 0.76rem;
    color: var(--text-muted);
}

.sugerido-cuenta {
    font-size: 0.74rem;
    color: var(--amber);
    margin-top: 2px !important;
}
.marcar-equipo {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
}
.select-equipo {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 5px 8px;
    font-size: 0.78rem;
}
.select-equipo:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.equipo-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}
.label-equipo {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
}
.chip-equipo {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--surface-2);
    border-radius: 999px;
    padding: 3px 6px 3px 10px;
    font-size: 0.74rem;
}
.quitar-equipo {
    background: none;
    border: none;
    color: var(--text-faint);
    cursor: pointer;
    font-size: 0.9rem;
    line-height: 1;
    padding: 0 2px;
}
.quitar-equipo:hover {
    color: var(--coral);
}

/* Alertas en carrusel */
.flechas {
    display: flex;
    gap: 6px;
}
.sin-alertas {
    color: var(--mint);
    font-size: 0.85rem;
    margin: 0;
}
.carrusel {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    padding-bottom: 6px;
}
.carrusel::-webkit-scrollbar {
    height: 8px;
}
.carrusel::-webkit-scrollbar-thumb {
    background: var(--surface-2);
    border-radius: 999px;
}
.tarjeta-alerta {
    flex: 0 0 320px;
    scroll-snap-align: start;
    background: var(--bg);
    border: 1px solid var(--border);
    border-left: 3px solid var(--yellow);
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.tarjeta-alerta.t-alerta {
    border-left-color: var(--coral);
}
.tarjeta-alerta.t-revisar {
    border-left-color: var(--yellow);
}
.tarjeta-alerta.t-revisada {
    border-left-color: var(--border);
    opacity: 0.65;
}
.alerta-cabeza {
    display: flex;
    justify-content: space-between;
    gap: 8px;
}
.alerta-nombre {
    font-weight: 600;
    font-size: 0.82rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cambios {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
    font-size: 0.76rem;
}
.cambios li {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    color: var(--text-muted);
}
.cambios b {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.72rem;
}
.pista {
    font-size: 0.72rem;
    color: var(--text-faint);
    margin: 0;
}
.responsable {
    font-size: 0.7rem;
    color: var(--text-faint);
    margin: 0;
}
.alerta-acciones {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-top: auto;
}
.revisar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: none;
    border: none;
    color: var(--text);
    font-size: 0.74rem;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
}
.revisar .caja {
    width: 16px;
    height: 16px;
    border: 1px solid var(--text-faint);
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
}
.revisar.hecho .caja {
    background: var(--mint);
    border-color: var(--mint);
    color: var(--bg);
}

/* Estados */
.estado {
    display: inline-flex;
    align-items: center;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border-radius: 999px;
    padding: 2px 8px;
    white-space: nowrap;
    background: var(--surface-2);
    color: var(--text-muted);
}
.estado.alerta,
.estado.t-mal {
    background: color-mix(in srgb, var(--coral) 18%, transparent);
    color: var(--coral);
}
.estado.revisar,
.estado.t-ojo {
    background: color-mix(in srgb, var(--yellow) 18%, transparent);
    color: var(--yellow);
}
.estado.estable,
.estado.t-bien {
    background: color-mix(in srgb, var(--mint) 18%, transparent);
    color: var(--mint);
}

/* 1. KPIs del día */
.seccion {
    margin-bottom: 16px;
}
.frase {
    font-size: 0.95rem;
    font-weight: 500;
    margin: 4px 0 12px;
}
.kpis-dia {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
}
.kpi-dia {
    background: var(--surface);
    border: 1px solid var(--border);
    border-top: 2px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.kpi-dia.marcado {
    border-top-color: var(--amber);
}
.kpi-dia-cabeza {
    display: flex;
    justify-content: space-between;
}
.marca {
    font-size: 0.6rem;
    font-weight: 700;
    color: var(--amber);
}
.kpi-dia b {
    font-size: 1.5rem;
    font-weight: 600;
}
.kpi-dia-pie {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 8px;
}
.kpi-dia-pie div {
    display: flex;
    flex-direction: column;
    font-size: 0.78rem;
    font-weight: 600;
}
.kpi-dia-pie small {
    font-size: 0.66rem;
    color: var(--text-faint);
    font-weight: 400;
}
.spark {
    width: 80px;
    height: 26px;
    flex: none;
}

/* Tablas */
.tabla-scroll {
    overflow: auto;
    max-width: 100%;
    max-height: calc(560px / var(--zoom-factor, 1));
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
    padding: 8px 10px;
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
.tabla .fija {
    position: sticky;
    left: 0;
    z-index: 1;
    background: var(--surface);
    border-right: 1px solid var(--border);
    max-width: 320px;
}
.tabla thead .fija {
    z-index: 3;
}
.tabla .nombre {
    display: block;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
}
.tabla th small,
.tabla td small {
    display: block;
    font-size: 0.66rem;
    color: var(--text-faint);
    font-weight: 500;
}
.tabla td b {
    font-weight: 600;
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
    background: color-mix(in srgb, var(--amber) 10%, var(--surface));
}
.tabla td.fondo-bien {
    background: color-mix(in srgb, var(--mint) 10%, transparent);
}
.tabla td.fondo-mal {
    background: color-mix(in srgb, var(--coral) 10%, transparent);
}
.tabla td.fondo-gasto {
    background: color-mix(in srgb, var(--sky) 10%, transparent);
}

/* 3. Histórico */
.historico-filtros {
    margin-bottom: 12px;
}
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}
.stat {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.stat b {
    font-size: 1.15rem;
}
.stat small {
    font-size: 0.7rem;
}
.historico {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr);
    gap: 20px;
    align-items: start;
}
.leyenda {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-bottom: 6px;
}
.leyenda span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.cuadro {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    display: inline-block;
}
.cuadro.periodo {
    background: color-mix(in srgb, var(--amber) 40%, transparent);
}
.cuadro.ultimo {
    background: var(--amber);
}
.cuadro.sombra {
    background: var(--surface-2);
    border: 1px solid var(--border);
}
.circulo {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--yellow);
    display: inline-block;
}
.grafica svg {
    width: 100%;
    height: auto;
    display: block;
}
.grafica text {
    fill: var(--text-faint);
    font-size: 10px;
    font-family: 'JetBrains Mono', monospace;
}
.grafica .eje {
    stroke: var(--border);
}
.grafica rect.periodo {
    fill: color-mix(in srgb, var(--amber) 40%, transparent);
}
.grafica rect.ultimo {
    fill: var(--amber);
}
.grafica rect.sombra {
    fill: var(--surface-2);
    stroke: var(--border);
}
.grafica .punto-var {
    fill: var(--yellow);
}
.acumulado {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}
.acum {
    display: flex;
    flex-direction: column;
    gap: 4px;
    background: none;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 6px 8px;
    color: var(--text);
    text-align: left;
    cursor: pointer;
    font: inherit;
}
.acum:hover,
.acum.sel {
    border-color: var(--amber);
}
.acum-cabeza {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 0.76rem;
}
.acum-nombre {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.acum .pista,
.meta-pista,
.pista.chica {
    position: relative;
    display: block;
    height: 8px;
    border-radius: 999px;
    background: var(--surface-2);
    overflow: hidden;
}
.acum .pista i {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    border-radius: 999px;
}
.acum .pista .g {
    background: var(--border);
}
.acum .pista .v {
    background: var(--amber);
    opacity: 0.85;
}

/* Optimizaciones en el histórico y en las alertas */
.grafica .marca-opti line {
    stroke: var(--serie-6);
    stroke-dasharray: 2 3;
    opacity: 0.6;
}
.grafica .marca-opti rect {
    fill: var(--serie-6);
    transform: rotate(45deg);
    transform-box: fill-box;
}
.rombo {
    width: 8px;
    height: 8px;
    background: var(--serie-6);
    transform: rotate(45deg);
    display: inline-block;
}
.opti-alerta {
    font-size: 0.7rem;
    color: var(--serie-6);
    margin: 0;
}
.optis-lista {
    grid-column: 1 / -1;
    order: 3;
    border-top: 1px solid var(--border);
    padding-top: 10px;
}
.optis-lista ul {
    list-style: none;
    margin: 6px 0 8px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.optis-lista li {
    display: grid;
    grid-template-columns: 52px 170px minmax(0, 1fr);
    gap: 10px;
    font-size: 0.74rem;
    color: var(--text-muted);
}
.optis-lista li b {
    color: var(--serie-6);
    font-weight: 600;
}
.optis-lista .texto {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* 4. Metas */
.metas-grid {
    display: grid;
    grid-template-columns: minmax(300px, 1fr) minmax(0, 2fr);
    gap: 16px;
    align-items: start;
}
.meta {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-top: 14px;
}
.meta-cabeza {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    font-size: 0.82rem;
}
.meta small {
    font-size: 0.72rem;
    color: var(--text-faint);
}
.meta-pista {
    height: 10px;
    overflow: visible;
}
.meta-pista i,
.pista.chica i {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    border-radius: 999px;
}
.pista.chica {
    width: 140px;
    height: 6px;
    overflow: visible;
}
.cien {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 1px;
    background: var(--text-faint);
}
.ideal {
    position: absolute;
    top: -2px;
    bottom: -2px;
    width: 2px;
    background: var(--text);
    border-radius: 2px;
}
.fondo-solido-bien {
    background: var(--mint);
}
.fondo-solido-ojo {
    background: var(--yellow);
}
.fondo-solido-mal {
    background: var(--coral);
}
.fondo-solido-neutro {
    background: var(--text-faint);
}

.alcance-nota {
    font-size: 0.72rem;
    color: var(--text-faint);
    margin: -8px 0 14px;
}
.acciones-der {
    display: inline-flex;
    gap: 12px;
}
.quitar-link {
    color: var(--coral);
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
.historial summary {
    cursor: pointer;
    list-style: none;
}
.historial summary::-webkit-details-marker {
    display: none;
}
.historial summary h2::before {
    content: '▸';
    color: var(--text-faint);
    margin-right: 4px;
}
.historial[open] summary h2::before {
    content: '▾';
}
.historial-tabla {
    margin-top: 12px;
}
.historial-tabla td {
    white-space: normal;
    vertical-align: top;
}
.historial-tabla .nombre {
    font-size: 0.7rem;
    word-break: break-all;
}
.historial-tabla .motivo {
    max-width: 320px;
    color: var(--text-muted);
}
.modal.modal-quitar {
    display: block;
    max-width: 560px;
    padding: 24px;
    color: var(--text);
}
.modal-quitar h2 {
    margin: 0 0 8px;
    font-size: 1.05rem;
}
.campania-quitar {
    font-size: 0.72rem;
    word-break: break-all;
    color: var(--text-muted);
    margin: 0 0 6px;
}
.motivo-campo {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 14px 0 18px;
}
.motivo-campo input {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 0.85rem;
}
.motivo-campo input:focus {
    outline: none;
    border-color: var(--amber);
}
.modal-acciones {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.btn-primary.peligro {
    border-color: var(--coral);
    color: var(--coral);
}

@media (max-width: 1100px) {
    .historico,
    .metas-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
}
</style>
