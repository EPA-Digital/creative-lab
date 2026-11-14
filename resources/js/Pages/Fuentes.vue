<script setup>
import { ref, computed, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import InfoTip from '@/Components/InfoTip.vue';
import { mensajeDeError, notificar } from '@/avisos';

// Fuentes (2026-11-13, pedido explícito; empieza con Ecuador). Puerto de la
// vista "TaDa Ecuador · Desempeño de marketing" al dashboard: solo el total
// real del mes se captura a mano; el desglose por canal se calcula con el
// mix de AppsFlyer (AF data): canal real = AF del canal × (real ÷ AF total).
// La inversión es la de Meta y TikTok que ya tiene el dashboard (sin BRD).
const props = defineProps({
    pais: String,
    tieneReglas: Boolean,
    canalesPaid: { type: Array, default: () => [] },
    mediosManuales: { type: Array, default: () => [] },
    meses: { type: Array, default: () => [] },
    semanas: { type: Array, default: () => [] },
    ultimoDia: { type: String, default: null },
});

const MES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MES_LARGO = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const dia = (f) => Number(f.slice(8, 10));
const mesIdx = (f) => Number(f.slice(5, 7)) - 1;
const rango = (d, h) => (mesIdx(d) === mesIdx(h) ? `${dia(d)}–${dia(h)} ${MES[mesIdx(d)]}` : `${dia(d)} ${MES[mesIdx(d)]}–${dia(h)} ${MES[mesIdx(h)]}`);

// --- Venta real capturada (editable) -------------------------------------------
// La venta real mensual y la semanal se capturan por separado (pedido
// explícito): llave "mes|2026-10" o "semana|2026-10-26".
const llave = (tipo, p) => `${tipo}|${p.clave}`;
const location = window.location;
const ventas = reactive({
    ...Object.fromEntries(props.meses.map((m) => [llave('mes', m), { ordenes: m.ventaReal?.ordenes ?? null, nc: m.ventaReal?.nc ?? null }])),
    ...Object.fromEntries(props.semanas.map((s) => [llave('semana', s), { ordenes: s.ventaReal?.ordenes ?? null, nc: s.ventaReal?.nc ?? null }])),
});
const guardando = ref(null);
// campo: 'ordenes' | 'nc'. La inversión no se captura aquí: viene de Medios data.
async function guardarVenta(tipo, p, campo, evento) {
    const k = llave(tipo, p);
    const limpio = evento.target.value.replace(/[^\d]/g, '');
    const valor = limpio === '' ? null : Number(limpio);
    if (ventas[k][campo] === valor) return;
    ventas[k][campo] = valor;
    guardando.value = k;
    try {
        await axios.post(`${location.pathname}/venta-real`, { tipo, clave: p.clave, ordenes: ventas[k].ordenes, nc: ventas[k].nc });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se guardó', texto: mensajeDeError(err) });
    } finally {
        guardando.value = null;
    }
}
const mostrarCaptura = ref(true);

// --- Canales --------------------------------------------------------------------
const totalAf = (af) => Object.values(af).reduce((t, c) => ({ installs: t.installs + c.installs, nc: t.nc + c.nc, ordenes: t.ordenes + c.ordenes }), { installs: 0, nc: 0, ordenes: 0 });
const canales = computed(() => {
    const vistos = new Map();
    props.meses.forEach((m) => Object.entries(m.af).forEach(([c, v]) => vistos.set(c, (vistos.get(c) ?? 0) + v.ordenes)));
    const paid = props.canalesPaid.map((c) => ({ key: c, tipo: 'paid' }));
    const negocio = [...vistos.entries()].filter(([c]) => !props.canalesPaid.includes(c)).sort((a, b) => b[1] - a[1]).map(([c]) => ({ key: c, tipo: 'neg' }));
    return [...paid, ...negocio];
});
const esPaid = (c) => props.canalesPaid.includes(c);
const seleccion = ref([...props.canalesPaid]);
function alternarCanal(c) {
    seleccion.value = seleccion.value.includes(c) ? seleccion.value.filter((x) => x !== c) : [...seleccion.value, c];
}

// --- Periodos -------------------------------------------------------------------
const vista = ref('mes');
const base = ref('paid'); // base de eficiencia: Paid EPA o total negocio
const capturaCols = computed(() => (vista.value === 'mes' ? props.meses : props.semanas));
// Factor real ÷ AppsFlyer del mismo periodo (mes con su venta mensual,
// semana con su venta semanal).
function factorReal(tipo, p) {
    const v = ventas[llave(tipo, p)];
    const t = totalAf(p.af);
    return {
        o: v?.ordenes != null && t.ordenes ? v.ordenes / t.ordenes : null,
        n: v?.nc != null && t.nc ? v.nc / t.nc : null,
    };
}

const periodos = computed(() => {
    const lista = vista.value === 'mes' ? props.meses : props.semanas;
    return lista.map((p) => {
        const mesClave = vista.value === 'mes' ? p.clave : p.mes;
        const f = factorReal(vista.value, p);
        const af = p.af;
        const real = Object.fromEntries(Object.entries(af).map(([c, v]) => [c, {
            ordenes: f.o != null ? v.ordenes * f.o : v.ordenes,
            nc: f.n != null ? v.nc * f.n : v.nc,
        }]));
        const etiqueta = vista.value === 'mes'
            ? `${MES_LARGO[mesIdx(p.clave + '-01')]} ${p.clave.slice(0, 4)}`
            : `S${p.semana} · ${rango(p.desde, p.hasta)}`;
        // Semanas: el eje lleva la fecha de inicio (una semana cortada por el
        // cambio de mes aparece dos veces con el mismo número).
        const eje = vista.value === 'mes' ? MES[mesIdx(p.clave + '-01')] : `${dia(p.desde)} ${MES[mesIdx(p.desde)]}`;
        return { ...p, mesClave, af, real, tieneRealO: f.o != null, tieneRealN: f.n != null, etiqueta, eje };
    });
});

const idx = ref(null);
watch(vista, () => { idx.value = null; });
const iActual = computed(() => (idx.value == null || idx.value >= periodos.value.length ? periodos.value.length - 1 : idx.value));
const actual = computed(() => periodos.value[iActual.value]);
const anterior = computed(() => periodos.value[iActual.value - 1] ?? null);
const VENTANA = { mes: 12, semana: 16 };
const ventana = computed(() => {
    const n = VENTANA[vista.value];
    const fin = Math.max(iActual.value + 1, Math.min(periodos.value.length, n));
    const ini = Math.max(0, fin - n);
    return periodos.value.slice(ini, fin).map((p, i) => ({ ...p, i: ini + i }));
});

// --- Métricas por periodo ----------------------------------------------------------
const suma = (obj, campo, filtro) => Object.entries(obj).reduce((t, [c, v]) => (filtro(c) ? t + (v[campo] ?? 0) : t), 0);
function metricas(p) {
    if (!p) return null;
    const todos = () => true;
    const paid = (c) => esPaid(c);
    const sel = (c) => seleccion.value.includes(c);
    const filtro = base.value === 'paid' ? paid : todos;
    const inv = p.inversion?.total || null; // sin inversión importada: sin costos (no $0)
    const oR = suma(p.real, 'ordenes', filtro);
    const oA = suma(p.af, 'ordenes', filtro);
    const nR = suma(p.real, 'nc', filtro);
    const nA = suma(p.af, 'nc', filtro);
    const iB = suma(p.af, 'installs', filtro);
    const dv = (a, b) => (a != null && b ? a / b : null);
    return {
        inv,
        oRT: suma(p.real, 'ordenes', todos), oAT: suma(p.af, 'ordenes', todos), oRP: suma(p.real, 'ordenes', paid),
        nRT: suma(p.real, 'nc', todos), nAT: suma(p.af, 'nc', todos), nRP: suma(p.real, 'nc', paid),
        iT: suma(p.af, 'installs', todos), iP: suma(p.af, 'installs', paid),
        cpoR: dv(inv, oR), cpoA: dv(inv, oA), cacR: dv(inv, nR), cacA: dv(inv, nA), cpi: dv(inv, iB),
        // Onboarding rate: NC de TaDa Performance (canales paid) ÷ sus instalaciones.
        obR: dv(suma(p.real, 'nc', paid), suma(p.af, 'installs', paid)),
        obA: dv(suma(p.af, 'nc', paid), suma(p.af, 'installs', paid)),
        pO: dv(suma(p.real, 'ordenes', sel), suma(p.real, 'ordenes', todos)),
        pN: dv(suma(p.real, 'nc', sel), suma(p.real, 'nc', todos)),
        pI: dv(suma(p.af, 'installs', sel), suma(p.af, 'installs', todos)),
    };
}
const M = computed(() => metricas(actual.value));
const Q = computed(() => metricas(anterior.value));

// --- Formato -----------------------------------------------------------------------
const nf = (v) => (v == null ? '—' : Math.round(v).toLocaleString('en-US'));
const usd0 = (v) => (v == null ? '—' : `$${Math.round(v).toLocaleString('en-US')}`);
const usd2 = (v) => (v == null ? '—' : `$${v.toFixed(2)}`);
const pc = (v, d = 1) => (v == null ? '—' : `${(v * 100).toFixed(d)}%`);
function delta(a, b, menosEsMejor = false, puntos = false) {
    if (a == null || b == null || (!b && !puntos)) return { texto: 'sin periodo previo', clase: 'neutro' };
    const d = puntos ? (a - b) * 100 : ((a - b) / b) * 100;
    const bueno = menosEsMejor ? d < 0 : d > 0;
    return { texto: `${d >= 0 ? '▲' : '▼'} ${Math.abs(d).toFixed(1)}${puntos ? ' pp' : '%'}`, clase: Math.abs(d) < 0.05 ? 'neutro' : bueno ? 'bueno' : 'malo' };
}

const kpis = computed(() => {
    const m = M.value;
    const q = Q.value;
    if (!m) return [];
    const realO = actual.value.tieneRealO;
    const realN = actual.value.tieneRealN;
    const baseTxt = base.value === 'paid' ? 'paid' : 'total';
    return [
        { label: 'Inversión', origen: 'auto', valor: usd0(m.inv), d: delta(m.inv, q?.inv), sub: 'Facebook y TikTok por API (sin BRD) + medios capturados', foot: vista.value === 'semana' ? 'Semanal: estimada por días del mes' : 'Suma de todos los medios' },
        { label: 'Órdenes', origen: realO ? 'manual' : 'auto', valor: nf(m.oRT), d: delta(m.oRT, q?.oRT), sub: `Paid EPA: ${nf(m.oRP)} · AppsFlyer ref. ${nf(m.oAT)}`, foot: realO ? 'Venta real' : 'Sin venta real: se muestra AppsFlyer' },
        { label: 'New customers', origen: realN ? 'manual' : 'auto', valor: nf(m.nRT), d: delta(m.nRT, q?.nRT), sub: `Paid EPA: ${nf(m.nRP)} · AppsFlyer ref. ${nf(m.nAT)}`, foot: realN ? 'Venta real' : 'Sin venta real: se muestra AppsFlyer' },
        { label: 'Instalaciones', origen: 'auto', valor: nf(m.iT), d: delta(m.iT, q?.iT), sub: `Paid EPA: ${nf(m.iP)} · ${pc(m.iT ? m.iP / m.iT : null)} del total`, foot: 'AppsFlyer' },
        { label: 'CPO', origen: 'calc', valor: usd2(m.cpoR), d: delta(m.cpoR, q?.cpoR, true), sub: `AppsFlyer: ${usd2(m.cpoA)}`, foot: `Inversión ÷ órdenes (${baseTxt})` },
        { label: 'CAC', origen: 'calc', valor: usd2(m.cacR), d: delta(m.cacR, q?.cacR, true), sub: `AppsFlyer: ${usd2(m.cacA)}`, foot: `Inversión ÷ new customers (${baseTxt})` },
        { label: 'CPI', origen: 'auto', valor: usd2(m.cpi), d: delta(m.cpi, q?.cpi, true), sub: `Paid EPA: ${nf(m.iP)} instalaciones`, foot: `Inversión ÷ instalaciones (${baseTxt})` },
        { label: 'Onboarding rate', origen: realN ? 'calc' : 'auto', valor: pc(m.obR), d: delta(m.obR, q?.obR, false, true), sub: `AppsFlyer: ${pc(m.obA)}`, foot: 'NC TaDa Performance ÷ instalaciones TaDa Performance' },
        { label: '% participación', origen: 'calc', valor: pc(m.pO), d: delta(m.pO, q?.pO, false, true), sub: `NC: ${pc(m.pN)} · Instalaciones: ${pc(m.pI)}`, foot: `Órdenes de: ${seleccion.value.join(', ') || 'ninguno'}` },
    ];
});

const corte = computed(() => {
    const p = actual.value;
    if (!p) return null;
    if (vista.value === 'mes') {
        const nombre = `${MES_LARGO[mesIdx(p.clave + '-01')]}`;
        return p.parcial
            ? { tag: 'Corte parcial', titulo: `${nombre[0].toUpperCase()}${nombre.slice(1)}: acumulado al día ${dia(p.hasta)} (${rango(p.desde, p.hasta)})`, sub: `Faltan ${p.diasMes - dia(p.hasta)} días para cerrar el mes. AppsFlyer sigue atribuyendo días atrás, así que el mes puede cambiar al volver a importar.` }
            : { tag: 'Mes completo', titulo: `${nombre[0].toUpperCase()}${nombre.slice(1)}: ${rango(p.desde, p.hasta)}`, sub: 'Mes cerrado.' };
    }
    return {
        tag: p.parcial ? 'Semana parcial' : 'Semana completa',
        titulo: `S${p.semana}: ${rango(p.desde, p.hasta)}${p.parcial ? ` · ${p.dias} días por el corte de mes` : ''}`,
        sub: 'La venta real de la semana se captura aparte de la mensual.',
    };
});

// --- AppsFlyer vs venta real ----------------------------------------------------------
const brechaMetrica = ref('ordenes');
const brecha = computed(() => {
    const campo = brechaMetrica.value;
    const filas = ventana.value.map((p) => {
        const af = totalAf(p.af)[campo];
        const re = suma(p.real, campo, () => true);
        const tiene = campo === 'ordenes' ? p.tieneRealO : p.tieneRealN;
        return { p, af, re, tiene, pct: tiene && af ? re / af - 1 : null };
    });
    const max = Math.max(1, ...filas.map((f) => Math.max(f.af, f.re)));
    const sel = filas.find((f) => f.p.i === iActual.value);
    return { filas, max, sel };
});

// --- Inversión por medio / funnel ------------------------------------------------------
const invModo = ref('medio');
const MEDIOS = props.canalesPaid;
const FUNNEL = [['AWA', 'Awareness'], ['CONS', 'Consideration'], ['CNV', 'Conversion'], ['LOY', 'Loyalty'], ['SIN', 'Sin etapa']];
const COLOR_MEDIO = { 'Facebook Ads': 'var(--serie-1)', 'Google Ads': 'var(--serie-2)', 'Apple Search Ads': 'var(--serie-3)', TikTok: 'var(--serie-4)', 'RTB House': 'var(--serie-5)', Jampp: 'var(--serie-6)' };
const COLOR_FUNNEL = { AWA: 'var(--serie-1)', CONS: 'var(--serie-2)', CNV: 'var(--serie-3)', LOY: 'var(--serie-4)', SIN: 'var(--text-faint)' };
const inversion = computed(() => {
    const llaves = invModo.value === 'medio' ? MEDIOS.map((m) => [m, m]) : FUNNEL;
    const colores = invModo.value === 'medio' ? COLOR_MEDIO : COLOR_FUNNEL;
    const valor = (p, k) => p.inversion?.[invModo.value]?.[k] ?? 0;
    const barras = ventana.value.map((p) => ({ p, total: p.inversion?.total ?? 0, segs: llaves.map(([k, l]) => ({ k, l, v: valor(p, k), c: colores[k] })).filter((s) => s.v > 0) }));
    const max = Math.max(1, ...barras.map((b) => b.total));
    const t = actual.value?.inversion?.total ?? 0;
    const desglose = llaves.map(([k, l]) => ({ k, l, v: valor(actual.value, k), c: colores[k] })).filter((s) => invModo.value === 'medio' || s.v > 0);
    return { barras, max, total: t, desglose };
});

// --- Por canal: órdenes, new customers o instalaciones -----------------------------------
const metricaCanal = ref('ordenes');
const METRICAS_CANAL = [['ordenes', 'Órdenes'], ['nc', 'New customers'], ['installs', 'Instalaciones']];
const tablaCanales = computed(() => {
    const p = actual.value;
    const q = anterior.value;
    if (!p) return null;
    const campo = metricaCanal.value;
    // Instalaciones solo vienen de AppsFlyer.
    const realDe = (per, c) => (campo === 'installs' ? per.af[c]?.installs ?? 0 : per.real[c]?.[campo] ?? 0);
    const sumaReal = (per) => Object.keys(per.af).reduce((t, c) => t + realDe(per, c), 0);
    const totalReal = sumaReal(p);
    const sinDelta = { texto: '—', clase: 'neutro' };
    const fila = (c) => {
        const re = realDe(p, c);
        return { c, af: p.af[c]?.[campo] ?? 0, re, share: totalReal ? re / totalReal : 0, d: q ? delta(re, realDe(q, c)) : sinDelta };
    };
    const grupo = (tipo, titulo) => {
        const filas = canales.value.filter((c) => c.tipo === tipo).map((c) => fila(c.key));
        const re = filas.reduce((t, f) => t + f.re, 0);
        const prev = q ? filas.reduce((t, f) => t + realDe(q, f.c), 0) : null;
        return { titulo, tipo, filas, af: filas.reduce((t, f) => t + f.af, 0), re, share: totalReal ? re / totalReal : 0, d: q ? delta(re, prev) : sinDelta };
    };
    return {
        grupos: [grupo('paid', 'Propias · Paid EPA'), grupo('neg', 'No propias · Total negocio')],
        total: { af: totalAf(p.af)[campo], re: totalReal, d: q ? delta(totalReal, sumaReal(q)) : sinDelta },
        conReal: campo === 'installs' ? null : campo === 'ordenes' ? p.tieneRealO : p.tieneRealN,
        titulo: METRICAS_CANAL.find(([k]) => k === campo)[1],
    };
});

// --- Líneas (participación y eficiencia) --------------------------------------------------
const X = (i, n) => ((i + 0.5) / n) * 100;
function puntos(valores, max, alto) {
    const n = valores.length;
    return valores.map((v, i) => (v == null ? null : `${X(i, n).toFixed(2)},${(alto - (v / max) * alto * 0.88).toFixed(2)}`)).filter(Boolean).join(' ');
}
const serieMetricas = computed(() => ventana.value.map((p) => ({ p, m: metricas(p) })));
const posSel = computed(() => ventana.value.findIndex((p) => p.i === iActual.value));

const participacion = computed(() => {
    const series = [
        { k: 'pO', l: 'Órdenes', c: 'var(--serie-1)', dash: '' },
        { k: 'pN', l: 'New customers', c: 'var(--serie-2)', dash: '' },
        { k: 'pI', l: 'Instalaciones', c: 'var(--serie-3)', dash: '4 3' },
    ];
    const max = Math.min(1, Math.max(0.01, ...serieMetricas.value.flatMap(({ m }) => series.map((s) => m[s.k] ?? 0))) * 1.08);
    return { max, series: series.map((s) => ({ ...s, pts: puntos(serieMetricas.value.map(({ m }) => m[s.k]), max, 100), v: M.value?.[s.k] })) };
});

const eficiencia = computed(() => {
    const defs = [
        { t: 'CPO', a: 'cpoA', r: 'cpoR', f: usd2, formula: 'Inversión ÷ órdenes' },
        { t: 'CAC', a: 'cacA', r: 'cacR', f: usd2, formula: 'Inversión ÷ new customers' },
        { t: 'CPI', a: 'cpi', r: null, f: usd2, formula: 'Inversión ÷ instalaciones (AppsFlyer)' },
        { t: 'Onboarding rate', a: 'obA', r: 'obR', f: (v) => pc(v), formula: 'NC ÷ instalaciones (TaDa Performance)' },
    ];
    return defs.map((d) => {
        const vals = serieMetricas.value.flatMap(({ m }) => [d.a ? m[d.a] : null, d.r ? m[d.r] : null]).filter((v) => v != null && isFinite(v));
        const max = Math.max(...vals, 0) * 1.08 || 1;
        const lineas = [];
        if (d.a) lineas.push({ c: 'var(--serie-1)', dash: '', pts: puntos(serieMetricas.value.map(({ m }) => m[d.a]), max, 60) });
        if (d.r) lineas.push({ c: 'var(--serie-2)', dash: '5 3', pts: puntos(serieMetricas.value.map(({ m }) => m[d.r]), max, 60) });
        return { ...d, max, lineas, vA: d.a ? d.f(M.value?.[d.a]) : null, vR: d.r ? d.f(M.value?.[d.r]) : null };
    });
});

// --- Resumen por periodo (tipo fuentes.2026), en pestañas -----------------------------
// Una tabla por tema en vez de todas juntas: periodos en columnas; valores o %.
const fuenteTabla = ref('real');
const tabHoja = ref('ordenes');
const modoHoja = ref('valor');
const TABS_HOJA = [
    ['inversion', 'Inversión'],
    ['ordenes', 'Órdenes'],
    ['nc', 'New customers'],
    ['installs', 'Instalaciones'],
    ['eficiencia', 'Eficiencia'],
];
const FUNNEL_FILAS = [['AWA', 'Awareness'], ['CONS', 'Consideración'], ['CNV', 'Conversión'], ['LOY', 'Loyalty'], ['SIN', 'Sin etapa']];
const tablaHoja = computed(() => {
    const cols = periodos.value;
    // Instalaciones solo existen en AppsFlyer (no hay "venta real" de installs).
    const datos = (p, campo) => (fuenteTabla.value === 'af' || campo === 'installs' ? p.af : p.real);
    const canalesNeg = canales.value.filter((c) => c.tipo === 'neg').map((c) => c.key);
    const paid = props.canalesPaid;
    const val = (p, c, campo) => datos(p, campo)[c]?.[campo] ?? 0;
    const sumaCanales = (p, lista, campo) => lista.reduce((t, c) => t + val(p, c, campo), 0);
    const total = (p, campo) => sumaCanales(p, Object.keys(p.af), campo);
    const inv = (p) => p.inversion?.total ?? 0;
    const invMedio = (p, m) => p.inversion?.medio?.[m] ?? 0;
    const div = (a, b) => (b ? a / b : null);
    const pct = modoHoja.value === 'pct';
    const filas = [];
    const seccion = (label) => filas.push({ seccion: true, label });
    const fila = (label, fn, fmt, extra = {}) => filas.push({ label, valores: cols.map((p) => fmt(fn(p))), ...extra });

    if (tabHoja.value === 'inversion') {
        seccion('Por medio');
        fila('TaDa', (p) => (pct ? 1 : inv(p)), pct ? (v) => pc(v, 0) : usd0, { fuerte: true });
        paid.forEach((m) => fila(m, (p) => (pct ? div(invMedio(p, m), inv(p)) : invMedio(p, m)), pct ? (v) => pc(v, 0) : usd0, { nivel: 1 }));
        seccion('Por funnel');
        FUNNEL_FILAS.forEach(([k, l]) => {
            if (k === 'SIN' && !cols.some((p) => (p.inversion?.funnel?.SIN ?? 0) > 0)) return;
            const v = (p) => p.inversion?.funnel?.[k] ?? 0;
            fila(l, (p) => (pct ? div(v(p), inv(p)) : v(p)), pct ? (x) => pc(x, 0) : usd0, { nivel: 1 });
        });
    } else if (tabHoja.value === 'eficiencia') {
        const costo = (titulo, campo) => {
            seccion(titulo);
            fila('TaDa Performance', (p) => (inv(p) ? div(inv(p), sumaCanales(p, paid, campo)) : null), usd2, { fuerte: true });
            paid.forEach((m) => fila(m, (p) => (invMedio(p, m) ? div(invMedio(p, m), val(p, m, campo)) : null), usd2, { nivel: 1 }));
        };
        costo('CPO · inversión ÷ órdenes', 'ordenes');
        costo('CAC · inversión ÷ new customers', 'nc');
        costo('CPI · inversión ÷ instalaciones', 'installs');
        seccion('Onboarding rate · new customers ÷ instalaciones');
        fila('TaDa Performance', (p) => div(sumaCanales(p, paid, 'nc'), sumaCanales(p, paid, 'installs')), (v) => pc(v), { fuerte: true });
        paid.forEach((m) => fila(m, (p) => div(val(p, m, 'nc'), val(p, m, 'installs')), (v) => pc(v), { nivel: 1 }));
    } else {
        const campo = tabHoja.value;
        const fmt = pct ? (v) => pc(v, 1) : nf;
        const v = (fn) => (p) => (pct ? div(fn(p), total(p, campo)) : fn(p));
        fila('TaDa Performance', v((p) => sumaCanales(p, paid, campo)), fmt, { fuerte: true, grupo: 'paid' });
        paid.forEach((c) => fila(c, v((p) => val(p, c, campo)), fmt, { nivel: 1 }));
        fila('No propias', v((p) => sumaCanales(p, canalesNeg, campo)), fmt, { fuerte: true, grupo: 'neg' });
        canalesNeg.forEach((c) => fila(c, v((p) => val(p, c, campo)), fmt, { nivel: 1 }));
        fila('Total', v((p) => total(p, campo)), fmt, { fuerte: true, total: true });
    }
    return { cols, filas };
});

function seleccionar(i) {
    idx.value = i;
}
function mover(paso) {
    idx.value = Math.min(periodos.value.length - 1, Math.max(0, iActual.value + paso));
}
</script>

<template>
    <Head :title="`Fuentes — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="fuentes">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Fuentes</h1>
                        <p class="subtitle">Venta real por canal: el total del negocio se reparte con el mix de AppsFlyer, y de ahí a los anuncios.</p>
                    </div>
                    <div v-if="periodos.length" class="periodo-controles">
                        <div class="segmentado" role="group" aria-label="Vista">
                            <button type="button" :class="{ activo: vista === 'mes' }" @click="vista = 'mes'">Mensual</button>
                            <button type="button" :class="{ activo: vista === 'semana' }" @click="vista = 'semana'">Semanal</button>
                        </div>
                        <div class="navegador">
                            <button type="button" class="flecha-btn" aria-label="Periodo anterior" :disabled="iActual === 0" @click="mover(-1)">‹</button>
                            <select :value="iActual" aria-label="Periodo" @change="seleccionar(Number($event.target.value))">
                                <option v-for="(p, i) in periodos" :key="p.clave" :value="i">{{ p.etiqueta }}{{ p.parcial ? ' · parcial' : '' }}</option>
                            </select>
                            <button type="button" class="flecha-btn" aria-label="Periodo siguiente" :disabled="iActual === periodos.length - 1" @click="mover(1)">›</button>
                        </div>
                    </div>
                </header>

                <p v-if="!tieneReglas" class="empty-note">Este país todavía no tiene reglas para AppsFlyer. Por ahora solo Ecuador.</p>
                <p v-else-if="!periodos.length" class="empty-note">Todavía no hay datos. Sube el CSV de AppsFlyer en <strong>AF data</strong> para empezar.</p>

                <template v-else>
                    <div class="fila-corte">
                        <div class="corte" :class="{ parcial: actual.parcial }">
                            <span class="corte-tag mono">{{ corte.tag }}</span>
                            <div>
                                <p class="corte-titulo">{{ corte.titulo }}</p>
                                <p class="corte-sub">{{ corte.sub }}</p>
                            </div>
                        </div>
                        <div class="origen panel">
                            <span class="origen-titulo mono">Origen del dato</span>
                            <span class="origen-item"><span class="origen-badge auto mono">Auto</span>AppsFlyer / plataformas</span>
                            <span class="origen-item"><span class="origen-badge manual mono">Manual</span>Venta real</span>
                            <span class="origen-item"><span class="origen-badge calc mono">Calculado</span>Usa datos manuales</span>
                        </div>
                    </div>

                    <!-- Captura de venta real -->
                    <section class="panel captura">
                        <div class="captura-header">
                            <div>
                                <h2 class="section-title"><span class="origen-badge manual mono">Manual</span> Venta real · {{ vista === 'mes' ? 'mensual' : 'semanal' }}</h2>
                                <p class="hint">Solo se captura el <strong>total {{ vista === 'mes' ? 'del mes' : 'de cada semana' }}</strong> de órdenes y new customers ({{ vista === 'mes' ? 'la semanal se captura aparte, en la vista Semanal' : 'aparte de la mensual' }}). El desglose por canal se calcula: AppsFlyer del canal × (total real ÷ total AppsFlyer).</p>
                            </div>
                            <button type="button" class="modal-copy-btn" @click="mostrarCaptura = !mostrarCaptura">{{ mostrarCaptura ? 'Ocultar' : 'Mostrar' }}</button>
                        </div>
                        <div v-if="mostrarCaptura" class="tabla-scroll">
                            <table class="captura-tabla">
                                <thead>
                                    <tr>
                                        <th />
                                        <th v-for="m in capturaCols" :key="m.clave" class="mono">{{ vista === 'mes' ? `${MES[mesIdx(m.clave + '-01')]} ${m.clave.slice(2, 4)}` : `S${m.semana} · ${rango(m.desde, m.hasta)}` }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="campo in [['ordenes', 'Órdenes'], ['nc', 'New customers']]" :key="campo[0]">
                                        <th>{{ campo[1] }}</th>
                                        <td v-for="m in capturaCols" :key="m.clave">
                                            <input
                                                type="text"
                                                inputmode="numeric"
                                                :value="ventas[llave(vista, m)][campo[0]] == null ? '' : nf(ventas[llave(vista, m)][campo[0]])"
                                                :placeholder="nf(totalAf(m.af)[campo[0]])"
                                                :aria-label="`${campo[1]} reales de ${m.clave}`"
                                                :class="{ capturado: ventas[llave(vista, m)][campo[0]] != null }"
                                                @change="guardarVenta(vista, m, campo[0], $event)"
                                                @keydown.enter="$event.target.blur()"
                                            />
                                            <span class="ref mono">AF {{ nf(totalAf(m.af)[campo[0]]) }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="hint">Vacío = sin capturar (se usa AppsFlyer). {{ guardando ? 'Guardando…' : 'Se guarda al salir de la celda.' }}</p>
                        </div>
                    </section>

                    <!-- KPIs -->
                    <div class="kpis">
                        <article v-for="k in kpis" :key="k.label" class="kpi panel">
                            <header class="kpi-top">
                                <span class="kpi-label mono">{{ k.label }}</span>
                                <span class="origen-badge mono" :class="k.origen">{{ { auto: 'Auto', manual: 'Manual', calc: 'Calculado' }[k.origen] }}</span>
                            </header>
                            <p class="kpi-valor">{{ k.valor }}</p>
                            <span class="delta" :class="k.d.clase">{{ k.d.texto }}<template v-if="anterior"> vs {{ anterior.eje }}</template></span>
                            <p class="kpi-sub">{{ k.sub }}</p>
                            <p class="kpi-foot">{{ k.foot }}</p>
                        </article>
                    </div>

                    <div class="base-toggle">
                        <span class="mono">Base de eficiencia</span>
                        <div class="segmentado" role="group" aria-label="Base de eficiencia">
                            <button type="button" :class="{ activo: base === 'paid' }" @click="base = 'paid'">Paid EPA</button>
                            <button type="button" :class="{ activo: base === 'total' }" @click="base = 'total'">Total negocio</button>
                        </div>
                        <InfoTip etiqueta="Qué cambia">CPO, CAC y CPI se calculan con los volúmenes de los canales Paid EPA o con el total del negocio.</InfoTip>
                    </div>

                    <!-- Resumen por periodo (tipo fuentes.2026), en pestañas -->
                    <section class="panel bloque">
                        <div class="bloque-header">
                            <div>
                                <h2 class="section-title">{{ vista === 'mes' ? 'Resumen mensual' : 'Resumen semanal' }}</h2>
                                <p class="hint">{{ fuenteTabla === 'real' ? 'Venta real prorrateada por canal con el mix de AppsFlyer' : 'Tal cual AppsFlyer' }}{{ tabHoja === 'installs' ? ' (las instalaciones siempre son de AppsFlyer)' : '' }}.</p>
                            </div>
                            <div class="controles-hoja">
                                <div v-if="tabHoja !== 'eficiencia'" class="segmentado">
                                    <button type="button" :class="{ activo: modoHoja === 'valor' }" @click="modoHoja = 'valor'">Valores</button>
                                    <button type="button" :class="{ activo: modoHoja === 'pct' }" @click="modoHoja = 'pct'">% del total</button>
                                </div>
                                <div class="segmentado">
                                    <button type="button" :class="{ activo: fuenteTabla === 'af' }" @click="fuenteTabla = 'af'">AppsFlyer</button>
                                    <button type="button" :class="{ activo: fuenteTabla === 'real' }" @click="fuenteTabla = 'real'">Venta real</button>
                                </div>
                            </div>
                        </div>
                        <div class="tabs" role="tablist" aria-label="Tema del resumen">
                            <button v-for="[k, l] in TABS_HOJA" :key="k" type="button" role="tab" :aria-selected="tabHoja === k" :class="{ activo: tabHoja === k }" @click="tabHoja = k">{{ l }}</button>
                        </div>
                        <div class="tabla-scroll hoja-scroll">
                            <table class="tabla hoja">
                                <thead>
                                    <tr>
                                        <th class="col-fija" />
                                        <th v-for="p in tablaHoja.cols" :key="p.clave" class="num" :class="{ sel: p === actual }">
                                            {{ vista === 'mes' ? `${MES[mesIdx(p.clave + '-01')]} ${p.clave.slice(2, 4)}` : p.eje }}
                                            <small v-if="p.parcial" class="mono">al {{ dia(p.hasta) }}</small>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="(f, i) in tablaHoja.filas" :key="i">
                                        <tr v-if="f.seccion" class="hoja-seccion"><td class="col-fija" :colspan="tablaHoja.cols.length + 1">{{ f.label }}</td></tr>
                                        <tr v-else :class="[{ fuerte: f.fuerte, tenue: f.tenue, total: f.total }, f.grupo ? `grupo-${f.grupo}` : '']">
                                            <td class="col-fija" :class="{ sangria: f.nivel }">{{ f.label }}</td>
                                            <td v-for="(v, j) in f.valores" :key="j" class="num" :class="{ sel: tablaHoja.cols[j] === actual }">{{ v }}</td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- AppsFlyer vs venta real -->
                    <section class="panel bloque">
                        <div class="bloque-header">
                            <div>
                                <h2 class="section-title">AppsFlyer vs venta real</h2>
                                <p class="hint">AppsFlyer solo es referencia para órdenes y new customers; la venta real se captura a mano.</p>
                            </div>
                            <div class="segmentado">
                                <button type="button" :class="{ activo: brechaMetrica === 'ordenes' }" @click="brechaMetrica = 'ordenes'">Órdenes</button>
                                <button type="button" :class="{ activo: brechaMetrica === 'nc' }" @click="brechaMetrica = 'nc'">New customers</button>
                            </div>
                        </div>
                        <div class="brecha">
                            <div class="brecha-resumen">
                                <p class="mono tenue">{{ actual.etiqueta }}</p>
                                <div class="caja"><span class="leyenda"><i style="background: var(--serie-1)" />AppsFlyer · referencia</span><b>{{ nf(brecha.sel?.af) }}</b></div>
                                <div class="caja manual-caja"><span class="leyenda"><i style="background: var(--serie-2)" />Venta real</span><b>{{ brecha.sel?.tiene ? nf(brecha.sel.re) : 'Sin capturar' }}</b></div>
                                <div v-if="brecha.sel?.tiene" class="brecha-diff">
                                    <span>Brecha (real − AppsFlyer)</span>
                                    <b>{{ brecha.sel.re - brecha.sel.af >= 0 ? '+' : '' }}{{ nf(brecha.sel.re - brecha.sel.af) }}</b>
                                    <small>{{ pc(brecha.sel.pct) }} sobre AppsFlyer</small>
                                </div>
                            </div>
                            <div class="barras">
                                <button
                                    v-for="f in brecha.filas"
                                    :key="f.p.clave"
                                    type="button"
                                    class="barra-grupo"
                                    :class="{ sel: f.p.i === iActual }"
                                    :title="`${f.p.etiqueta}\nAppsFlyer: ${nf(f.af)}\nVenta real: ${f.tiene ? nf(f.re) : 'sin capturar'}`"
                                    @click="seleccionar(f.p.i)"
                                >
                                    <span class="barra-pct mono">{{ f.pct == null ? '' : `${f.pct >= 0 ? '+' : ''}${(f.pct * 100).toFixed(0)}%` }}</span>
                                    <span class="barra-par">
                                        <span class="barra" :style="{ height: `${(f.af / brecha.max) * 100}%`, background: 'var(--serie-1)' }" />
                                        <span v-if="f.tiene" class="barra" :style="{ height: `${(f.re / brecha.max) * 100}%`, background: 'var(--serie-2)' }" />
                                    </span>
                                    <span class="barra-eje mono">{{ f.p.eje }}</span>
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Inversión -->
                    <section class="panel bloque">
                        <div class="bloque-header">
                            <div>
                                <h2 class="section-title">Inversión {{ invModo === 'medio' ? 'por medio' : 'por funnel' }}</h2>
                                <p class="hint">Facebook y TikTok llegan por API (cuentas de performance, sin BRD); Google, Apple Search, RTB House y Jampp vienen de Medios data.</p>
                            </div>
                            <div class="segmentado">
                                <button type="button" :class="{ activo: invModo === 'medio' }" @click="invModo = 'medio'">Por medio</button>
                                <button type="button" :class="{ activo: invModo === 'funnel' }" @click="invModo = 'funnel'">Por funnel</button>
                            </div>
                        </div>
                        <div class="inversion">
                            <div class="barras">
                                <button
                                    v-for="b in inversion.barras"
                                    :key="b.p.clave"
                                    type="button"
                                    class="barra-grupo"
                                    :class="{ sel: b.p.i === iActual }"
                                    :title="`${b.p.etiqueta}: ${usd0(b.total)}\n${b.segs.map((s) => `${s.l}: ${usd0(s.v)}`).join('\n')}`"
                                    @click="seleccionar(b.p.i)"
                                >
                                    <span class="barra-pila" :style="{ height: `${(b.total / inversion.max) * 100}%` }">
                                        <span v-for="s in b.segs" :key="s.k" :style="{ flexGrow: s.v, background: s.c }" />
                                    </span>
                                    <span class="barra-eje mono">{{ b.p.eje }}</span>
                                </button>
                            </div>
                            <div class="desglose">
                                <div class="desglose-top"><span class="mono tenue">{{ actual.etiqueta }}</span><b>{{ usd0(inversion.total) }}</b></div>
                                <div v-for="s in inversion.desglose" :key="s.k" class="desglose-fila">
                                    <span class="leyenda"><i :style="{ background: s.c }" />{{ s.l }}</span>
                                    <span class="num">{{ usd0(s.v) }}</span>
                                    <span class="num tenue">{{ pc(inversion.total ? s.v / inversion.total : null, 0) }}</span>
                                    <span class="pista"><span :style="{ width: `${inversion.total ? (s.v / inversion.total) * 100 : 0}%`, background: s.c }" /></span>
                                </div>
                                <p v-if="!inversion.total" class="hint">Sin inversión importada para este periodo.</p>
                            </div>
                        </div>
                    </section>

                    <!-- Órdenes por canal -->
                    <section class="panel bloque">
                        <div class="bloque-header">
                            <div>
                                <h2 class="section-title">Por canal de atribución</h2>
                                <p class="hint">{{ actual.etiqueta }} · {{ tablaCanales.conReal === null ? 'instalaciones de AppsFlyer' : tablaCanales.conReal ? 'solo el total es manual; los canales se prorratean con el mix de AppsFlyer' : 'sin venta real capturada: se muestra AppsFlyer' }}</p>
                            </div>
                            <div class="segmentado">
                                <button v-for="[k, l] in METRICAS_CANAL" :key="k" type="button" :class="{ activo: metricaCanal === k }" @click="metricaCanal = k">{{ l }}</button>
                            </div>
                        </div>
                        <div class="tabla-scroll">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th>Canal</th>
                                        <th class="num">AppsFlyer (ref.)</th>
                                        <th class="num">{{ metricaCanal === 'installs' ? 'Instalaciones' : 'Venta real' }}</th>
                                        <th class="num">Participación</th>
                                        <th class="num">Δ vs anterior</th>
                                    </tr>
                                </thead>
                                <tbody v-for="g in tablaCanales.grupos" :key="g.titulo">
                                    <tr class="grupo" :class="g.tipo">
                                        <td>{{ g.titulo }}</td>
                                        <td class="num">{{ nf(g.af) }}</td>
                                        <td class="num">{{ nf(g.re) }}</td>
                                        <td class="num">{{ pc(g.share) }}</td>
                                        <td class="num"><span class="delta" :class="g.d.clase">{{ g.d.texto }}</span></td>
                                    </tr>
                                    <tr v-for="f in g.filas" :key="f.c">
                                        <td class="sangria">{{ f.c }}</td>
                                        <td class="num tenue">{{ nf(f.af) }}</td>
                                        <td class="num">{{ nf(f.re) }}</td>
                                        <td class="num"><span class="pista-fila"><span :style="{ width: `${f.share * 100}%` }" /></span>{{ pc(f.share) }}</td>
                                        <td class="num"><span class="delta" :class="f.d.clase">{{ f.d.texto }}</span></td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td>Total</td>
                                        <td class="num">{{ nf(tablaCanales.total.af) }}</td>
                                        <td class="num">{{ nf(tablaCanales.total.re) }}</td>
                                        <td class="num">100%</td>
                                        <td class="num"><span class="delta" :class="tablaCanales.total.d.clase">{{ tablaCanales.total.d.texto }}</span></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>

                    <div class="dos-columnas">
                        <!-- Participación -->
                        <section class="panel bloque">
                            <h2 class="section-title">Evolución del % de participación</h2>
                            <p class="hint">Canales seleccionados ÷ total. Por ejemplo, Facebook = órdenes de Facebook ÷ órdenes totales.</p>
                            <div class="chips">
                                <button v-for="c in canales" :key="c.key" type="button" class="chip" :class="{ activo: seleccion.includes(c.key) }" @click="alternarCanal(c.key)">{{ c.key }}</button>
                                <button type="button" class="chip-link" @click="seleccion = [...canalesPaid]">Solo Paid EPA</button>
                            </div>
                            <div class="leyendas">
                                <span v-for="s in participacion.series" :key="s.k" class="leyenda"><i :style="{ background: s.c }" />{{ s.l }} <b>{{ pc(s.v) }}</b></span>
                            </div>
                            <div class="linea-chart alto">
                                <span class="eje-max mono">{{ pc(participacion.max, 0) }}</span>
                                <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                    <line v-for="y in [25, 50, 75]" :key="y" x1="0" x2="100" :y1="y" :y2="y" class="grid" />
                                    <line v-if="posSel >= 0" :x1="X(posSel, ventana.length)" :x2="X(posSel, ventana.length)" y1="0" y2="100" class="cursor" />
                                    <polyline v-for="s in participacion.series" :key="s.k" :points="s.pts" :stroke="s.c" :stroke-dasharray="s.dash" class="trazo" />
                                </svg>
                                <div class="eje-x">
                                    <button v-for="p in ventana" :key="p.clave" type="button" class="mono" :class="{ sel: p.i === iActual }" @click="seleccionar(p.i)">{{ p.eje }}</button>
                                </div>
                            </div>
                        </section>

                        <!-- Eficiencia -->
                        <section class="panel bloque">
                            <h2 class="section-title">Eficiencia · CPO, CAC y CPI</h2>
                            <p class="hint">Inversión ÷ volúmenes {{ base === 'paid' ? 'Paid EPA' : 'del total del negocio' }}.</p>
                            <div class="leyendas">
                                <span class="leyenda"><i style="background: var(--serie-1)" />Versión AppsFlyer</span>
                                <span class="leyenda"><i class="punteada" style="background: var(--serie-2)" />Versión venta real</span>
                            </div>
                            <div class="mini-grid">
                                <div v-for="e in eficiencia" :key="e.t" class="mini">
                                    <div class="mini-top">
                                        <span class="mono">{{ e.t }}</span>
                                        <span class="mini-vals"><span v-if="e.vA">AF {{ e.vA }}</span><span v-if="e.vR">Real {{ e.vR }}</span></span>
                                    </div>
                                    <div class="linea-chart">
                                        <span class="eje-max mono">{{ e.f(e.max) }}</span>
                                        <svg viewBox="0 0 100 60" preserveAspectRatio="none" aria-hidden="true">
                                            <line v-if="posSel >= 0" :x1="X(posSel, ventana.length)" :x2="X(posSel, ventana.length)" y1="0" y2="60" class="cursor" />
                                            <polyline v-for="(l, i) in e.lineas" :key="i" :points="l.pts" :stroke="l.c" :stroke-dasharray="l.dash" class="trazo" />
                                        </svg>
                                    </div>
                                    <p class="mini-foot">{{ e.formula }}</p>
                                </div>
                            </div>
                        </section>
                    </div>
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
    flex-wrap: wrap;
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
    max-width: 640px;
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
.section-title {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 600;
    font-size: 16px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.hint {
    font-size: 0.76rem;
    color: var(--text-muted);
    margin: 4px 0 0;
}
.tenue {
    color: var(--text-muted);
}
.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

/* Controles de periodo */
.periodo-controles {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.segmentado {
    display: inline-flex;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 3px;
    gap: 2px;
}
.segmentado button {
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 0.8rem;
    padding: 7px 12px;
    border-radius: 7px;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.segmentado button.activo {
    background: var(--amber);
    color: #fff;
    font-weight: 600;
}
.segmentado button:focus-visible,
.flecha-btn:focus-visible,
.navegador select:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.navegador {
    display: flex;
    gap: 6px;
    align-items: center;
}
.navegador select {
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 0.82rem;
}
.flecha-btn {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
}
.flecha-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Corte y origen */
.fila-corte {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 14px;
    margin-bottom: 16px;
}
.corte {
    display: flex;
    gap: 14px;
    align-items: center;
    padding: 14px 18px;
    border-radius: 14px;
    background: color-mix(in srgb, var(--amber) 16%, var(--surface));
    border: 1px solid color-mix(in srgb, var(--amber) 45%, transparent);
}
.corte-tag {
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 4px 8px;
    border-radius: 6px;
    background: var(--amber);
    color: #fff;
    white-space: nowrap;
}
.corte-titulo {
    margin: 0;
    font-weight: 600;
}
.corte-sub {
    margin: 2px 0 0;
    font-size: 0.76rem;
    color: var(--text-muted);
}
.origen {
    display: flex;
    gap: 16px;
    align-items: center;
    padding: 12px 18px;
    flex-wrap: wrap;
    font-size: 0.78rem;
}
.origen-titulo {
    font-size: 0.66rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-faint);
}
.origen-item {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    color: var(--text-muted);
}
.origen-badge {
    display: inline-block;
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 2px 7px;
    border-radius: 6px;
    letter-spacing: 0.04em;
}
.origen-badge.auto {
    background: var(--amber);
    color: #fff;
}
.origen-badge.manual {
    border: 1px dashed var(--serie-2);
    color: var(--text);
    background: color-mix(in srgb, var(--serie-2) 14%, transparent);
}
.origen-badge.calc {
    border: 1px solid var(--text-muted);
    color: var(--text);
}

/* Captura */
.captura {
    padding: 16px 18px;
    margin-bottom: 16px;
    border-style: dashed;
}
.captura-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 12px;
}
.tabla-scroll {
    overflow-x: auto;
}
.captura-tabla {
    border-collapse: separate;
    border-spacing: 6px 4px;
}
.captura-tabla th {
    font-size: 0.7rem;
    text-transform: uppercase;
    color: var(--text-faint);
    text-align: center;
    font-weight: 600;
}
.captura-tabla tbody th {
    text-align: left;
    text-transform: none;
    font-size: 0.82rem;
    color: var(--text);
    white-space: nowrap;
    padding-right: 10px;
}
.captura-tabla td {
    text-align: center;
}
.captura-tabla input {
    width: 104px;
    text-align: right;
    font-variant-numeric: tabular-nums;
    padding: 7px 9px;
    border-radius: 8px;
    border: 1px dashed var(--border);
    background: var(--bg);
    color: var(--text);
    font-size: 0.85rem;
}
.captura-tabla input.capturado {
    border-color: var(--serie-2);
    background: color-mix(in srgb, var(--serie-2) 10%, var(--bg));
}
.captura-tabla input:focus {
    outline: 2px solid var(--amber);
    outline-offset: 1px;
}
.ref {
    display: block;
    font-size: 0.64rem;
    color: var(--text-faint);
    margin-top: 2px;
    text-align: right;
}

/* KPIs */
.kpis {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}
.kpi {
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.kpi-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.kpi-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
    font-weight: 600;
}
.kpi-valor {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 700;
    font-size: 1.7rem;
    margin: 2px 0;
}
.kpi-sub {
    font-size: 0.76rem;
    color: var(--text-muted);
    margin: 6px 0 0;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}
.kpi-foot {
    font-size: 0.7rem;
    color: var(--text-faint);
    margin: 0;
}
.delta {
    align-self: flex-start;
    font-size: 0.74rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    white-space: nowrap;
}
.delta.bueno {
    color: var(--mint);
    background: color-mix(in srgb, var(--mint) 14%, transparent);
}
.delta.malo {
    color: var(--coral);
    background: color-mix(in srgb, var(--coral) 14%, transparent);
}
.delta.neutro {
    color: var(--text-muted);
}
.base-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 4px 0 18px;
    font-size: 0.7rem;
    text-transform: uppercase;
    color: var(--text-faint);
}

/* Bloques */
.bloque {
    padding: 18px 20px;
    margin-bottom: 16px;
}
.bloque-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.leyenda {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: var(--text-muted);
}
.leyenda i {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    display: inline-block;
}
.leyenda i.punteada {
    height: 3px;
    width: 14px;
    border-radius: 2px;
}
.leyenda b {
    color: var(--text);
}
.leyendas {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin: 10px 0;
}

/* Brecha */
.brecha {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 20px;
}
.brecha-resumen {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.brecha-resumen p {
    margin: 0;
    font-size: 0.76rem;
}
.caja {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 14px;
    border-radius: 10px;
    background: var(--surface-2);
}
.caja b {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.5rem;
}
.manual-caja {
    border: 1px dashed var(--serie-2);
}
.brecha-diff {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 0.78rem;
    color: var(--text-muted);
}
.brecha-diff b {
    font-size: 1.2rem;
    color: var(--text);
}
.barras {
    display: flex;
    align-items: stretch;
    gap: 6px;
    height: 220px;
    border-bottom: 1px solid var(--border);
}
.barra-grupo {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    gap: 4px;
    background: transparent;
    border: none;
    border-radius: 8px 8px 0 0;
    padding: 4px 2px 0;
    cursor: pointer;
    color: var(--text-muted);
    min-width: 0;
}
.barra-grupo.sel {
    background: color-mix(in srgb, var(--amber) 7%, transparent);
}
.barra-grupo:hover {
    background: color-mix(in srgb, var(--amber) 6%, transparent);
}
.barra-pct {
    font-size: 0.66rem;
    color: var(--text);
    min-height: 1em;
}
.barra-par {
    flex: 1;
    width: 100%;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 2px;
}
.barra-par .barra {
    width: min(40%, 26px);
    border-radius: 4px 4px 0 0;
    min-height: 1px;
}
.barra-eje {
    font-size: 0.66rem;
    padding-bottom: 4px;
}
.barra-grupo.sel .barra-eje {
    color: var(--accent-text);
    font-weight: 700;
}

/* Inversión */
.inversion {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 24px;
}
.barra-pila {
    width: min(60%, 44px);
    display: flex;
    flex-direction: column-reverse;
    gap: 2px;
    border-radius: 4px 4px 0 0;
    overflow: hidden;
    min-height: 1px;
}
.barra-pila span {
    min-height: 1px;
}
.inversion .barras {
    gap: 6px;
}
.inversion .barra-grupo {
    justify-content: flex-end;
}
.desglose-top {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 10px;
    font-size: 0.78rem;
}
.desglose-top b {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.4rem;
}
.desglose-fila {
    display: grid;
    grid-template-columns: 1fr auto 44px;
    gap: 4px 10px;
    align-items: center;
    font-size: 0.8rem;
    margin-bottom: 10px;
}
.pista {
    grid-column: 1 / -1;
    height: 6px;
    border-radius: 999px;
    background: var(--surface-2);
    overflow: hidden;
    display: block;
}
.pista span {
    display: block;
    height: 100%;
    border-radius: 999px;
}
.pista-fila {
    display: inline-block;
    width: 60px;
    height: 6px;
    margin-right: 8px;
    vertical-align: middle;
    border-radius: 999px;
    background: var(--surface-2);
    overflow: hidden;
}
.pista-fila span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--serie-1);
}

/* Tabla canales */
.tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.tabla th {
    text-align: left;
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
    padding: 10px;
    border-bottom: 1px solid var(--border);
    font-weight: 600;
}
.tabla th.num {
    text-align: right;
}
.tabla td {
    padding: 9px 10px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.tabla .grupo td {
    font-weight: 600;
}
.tabla .grupo.paid td {
    background: color-mix(in srgb, var(--amber) 22%, var(--surface));
}
.tabla .grupo.neg td {
    background: var(--surface-2);
}
.tabla .sangria {
    padding-left: 24px;
}
.tabla tfoot td {
    font-weight: 700;
    border-top: 2px solid var(--border);
    border-bottom: none;
}

/* Tabla tipo hoja */
.controles-hoja {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 8px;
    overflow-x: auto;
}
.tabs button {
    border: none;
    background: none;
    color: var(--text-muted);
    font-size: 0.82rem;
    padding: 8px 12px;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    white-space: nowrap;
}
.tabs button.activo {
    color: var(--text);
    border-bottom-color: var(--amber);
    font-weight: 600;
}
.tabs button:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: -2px;
}
.hoja tr.grupo-paid td {
    background: color-mix(in srgb, var(--amber) 14%, var(--surface));
}
.hoja tr.grupo-neg td {
    background: var(--surface-2);
}
.hoja-scroll {
    max-height: calc(75vh / var(--zoom-factor));
    overflow: auto;
}
.hoja th,
.hoja td {
    padding: 6px 12px;
}
.hoja thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--surface);
}
.hoja thead th small {
    display: block;
    font-size: 0.6rem;
    color: var(--text-faint);
    text-transform: none;
}
.hoja .col-fija {
    position: sticky;
    left: 0;
    z-index: 1;
    background: var(--surface);
    min-width: 170px;
}
.hoja thead .col-fija {
    z-index: 3;
}
.hoja .hoja-seccion td {
    background: var(--surface-2);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    padding-top: 10px;
}
.hoja tr.fuerte td {
    font-weight: 600;
}
.hoja tr.total td {
    border-top: 2px solid var(--border);
}
.hoja tr.tenue td {
    color: var(--text-muted);
}
.hoja .sel {
    background: color-mix(in srgb, var(--amber) 8%, transparent);
}
.valor-auto.manual {
    background: color-mix(in srgb, var(--serie-2) 10%, transparent);
    border: 1px dashed var(--serie-2);
}
.captura-sep a {
    color: var(--accent-text);
}
.valor-auto {
    display: block;
    width: 104px;
    padding: 7px 9px;
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-size: 0.85rem;
    border-radius: 8px;
    background: color-mix(in srgb, var(--amber) 10%, transparent);
    color: var(--text);
}
.captura-sep th {
    padding-top: 12px;
    text-align: left !important;
    text-transform: none !important;
    font-size: 0.72rem !important;
    color: var(--text-muted) !important;
}

/* Líneas */
.dos-columnas {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 12px;
}
.chip {
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-muted);
    border-radius: 999px;
    padding: 5px 11px;
    font-size: 0.76rem;
    cursor: pointer;
}
.chip.activo {
    background: var(--amber);
    border-color: var(--amber);
    color: #fff;
}
.chip-link {
    border: none;
    background: none;
    color: var(--accent-text);
    font-size: 0.76rem;
    cursor: pointer;
}
.linea-chart {
    position: relative;
    height: 120px;
    margin-top: 6px;
}
.linea-chart.alto {
    height: 220px;
    padding-bottom: 24px;
}
.linea-chart svg {
    width: 100%;
    height: 100%;
    overflow: visible;
    border-bottom: 1px solid var(--border);
}
.eje-max {
    position: absolute;
    top: -2px;
    left: 0;
    font-size: 0.62rem;
    color: var(--text-faint);
}
.grid {
    stroke: var(--border);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}
.cursor {
    stroke: var(--amber);
    stroke-width: 1;
    stroke-dasharray: 3 3;
    vector-effect: non-scaling-stroke;
}
.trazo {
    fill: none;
    stroke-width: 2;
    stroke-linejoin: round;
    stroke-linecap: round;
    vector-effect: non-scaling-stroke;
}
.eje-x {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
}
.eje-x button {
    flex: 1;
    border: none;
    background: none;
    color: var(--text-faint);
    font-size: 0.64rem;
    cursor: pointer;
    padding: 4px 0 0;
}
.eje-x button.sel {
    color: var(--accent-text);
    font-weight: 700;
}
.mini-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.mini {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 12px;
}
.mini-top {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 0.72rem;
    font-weight: 600;
}
.mini-vals {
    display: flex;
    gap: 8px;
    color: var(--text);
}
.mini .linea-chart {
    height: 90px;
    margin-top: 14px;
}
.mini-foot {
    font-size: 0.68rem;
    color: var(--text-faint);
    margin: 6px 0 0;
}

@media (max-width: 1000px) {
    .brecha,
    .inversion,
    .dos-columnas,
    .fila-corte {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 720px) {
    .page-inner {
        padding: 24px 16px 48px;
    }
    .mini-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
