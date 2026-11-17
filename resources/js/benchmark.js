// Benchmark del periodo en Creativos (2026-11-17, pedido explícito): el
// mismo cálculo que el PDF (app/Services/Reportes/BenchmarkMes.php) --
// cada creativo contra los DEMÁS del periodo, misma plataforma, tipo de
// cuenta y etapa, en los KPIs de su etapa. Media simple, mejor del grupo,
// "en la media" = ±10%, y los mismos pisos de volumen. Si cambias algo
// aquí, cámbialo también en BenchmarkMes.php.
import { etiquetaFunnel } from '@/motor';

export const KPIS_POR_FUNNEL = {
    AWA: ['cpm', 'ctr'],
    CON: ['cpi', 'ctr', 'cpm'],
    CONS: ['cpi', 'ctr', 'cpm'],
    CNV: ['cac', 'ctr', 'cpm'],
    LOY: ['cpo', 'ctr', 'cpm'],
};
const KPIS_SIN_FUNNEL = ['cpm', 'ctr', 'cpi', 'cpo', 'cac'];
export const ETIQUETA_KPI = { cpm: 'CPM', ctr: 'CTR', cpi: 'CPI', cac: 'CAC', cpo: 'CPO' };
const VOLUMEN_MINIMO = {
    cpm: ['impressions', 1000],
    ctr: ['impressions', 1000],
    cpi: ['installs', 30],
    cac: ['nc', 10],
    cpo: ['orders', 10],
};
const MARGEN_EN_LA_MEDIA = 10;

export const mayorEsMejor = (campo) => campo === 'ctr';
export const kpisDe = (funnel) => KPIS_POR_FUNNEL[funnel] ?? KPIS_SIN_FUNNEL;
/** KPI de costo de la etapa: CPM (Awareness), CPI (Consideración), CAC (Conversión), CPO (Loyalty). */
export const kpiCostoDe = (funnel) => kpisDe(funnel)[0];

const num = (v) => (v === null || v === undefined || v === '' ? null : Number(v));
function fila(creativo) {
    const r = creativo.resultados?.[0] ?? {};
    return {
        cost: num(r.cost) ?? 0,
        impressions: num(r.impressions) ?? 0,
        installs: num(r.installs) ?? 0,
        nc: num(r.nc) ?? 0,
        orders: num(r.orders) ?? 0,
        cpm: num(r.cpm),
        ctr: num(r.ctr),
        cpi: num(r.cpi),
        cac: num(r.cac),
        cpo: num(r.cpo),
    };
}
function cuenta(f, campo) {
    const [volumen, minimo] = VOLUMEN_MINIMO[campo];
    return f[campo] !== null && f[volumen] >= minimo && (campo === 'ctr' || f.cost > 0);
}
const clave = (c) => `${c.plataforma}|${c.tipo_cuenta || 'SIN'}|${c.funnel || 'SIN'}`;

function estado(valor, media, campo) {
    if (valor === null || !media) return [null, null];
    const pct = ((valor - media) / media) * 100;
    if (Math.abs(pct) <= MARGEN_EN_LA_MEDIA) return [Math.round(Math.abs(pct)), 'media'];
    return [Math.round(Math.abs(pct)), (pct > 0) === mayorEsMejor(campo) ? 'mejor' : 'peor'];
}

/**
 * @param {Array} creativos todos los del periodo (sin los filtros de la vista)
 */
export function crearBenchmark(creativos) {
    const grupos = new Map();
    for (const c of creativos) {
        const k = clave(c);
        if (!grupos.has(k)) grupos.set(k, []);
        grupos.get(k).push({ c, f: fila(c) });
    }
    const cache = new Map();

    function mediaYMejor(demas, campo) {
        const conValor = demas.filter((d) => cuenta(d.f, campo));
        if (!conValor.length) return { media: null, mejor: null, mejorNombre: null };
        conValor.sort((a, b) => (mayorEsMejor(campo) ? b.f[campo] - a.f[campo] : a.f[campo] - b.f[campo]));
        const m = conValor[0];
        return {
            media: conValor.reduce((t, d) => t + d.f[campo], 0) / conValor.length,
            mejor: m.f[campo],
            mejorNombre: m.c.nombre_amigable || m.c.nombre_comun || m.c.nombre_completo,
        };
    }

    /** Un creativo contra los demás de su grupo. */
    function comparar(creativo) {
        if (!creativo) return null;
        if (cache.has(creativo.id)) return cache.get(creativo.id);
        const f = fila(creativo);
        const demas = (grupos.get(clave(creativo)) ?? []).filter((d) => d.c.id !== creativo.id);
        const kpis = kpisDe(creativo.funnel).map((campo) => {
            const valor = f[campo];
            const { media, mejor, mejorNombre } = mediaYMejor(demas, campo);
            const [pct, est] = estado(valor, media, campo);
            const suficiente = cuenta(f, campo);
            const esElMejor = suficiente && valor !== null && mejor !== null && (mayorEsMejor(campo) ? valor >= mejor : valor <= mejor);
            return { campo, valor, media, mejor, mejorNombre, pct, estado: est, esElMejor, pocoVolumen: valor !== null && !suficiente };
        });
        const resultado = { comparables: demas.length, kpis, veredicto: veredicto(kpis) };
        cache.set(creativo.id, resultado);
        return resultado;
    }

    /**
     * Para ordenar por costo: el KPI de costo de su etapa, su diferencia
     * contra la media del grupo (negativo = más barato) y si tiene volumen
     * suficiente para rankear.
     */
    function costo(creativo) {
        const b = comparar(creativo);
        const k = b.kpis[0];
        const relativo = k.valor !== null && k.media ? (k.valor - k.media) / k.media : null;
        return { campo: k.campo, valor: k.valor, media: k.media, relativo, suficiente: k.valor !== null && !k.pocoVolumen };
    }

    return { comparar, costo };
}

/**
 * Bueno / promedio / malo -- mismo puntaje que BenchmarkMes::veredicto:
 * el KPI de costo de la etapa pesa el triple; >= 2 bueno, <= -2 malo.
 */
function veredicto(kpis) {
    const con = kpis.filter((k) => k.estado !== null);
    if (!con.length) return { calificacion: null, etiqueta: 'Sin comparables' };
    const principal = kpis[0].campo;
    let puntaje = 0;
    for (const k of con) {
        const peso = k.campo === principal ? 3 : 1;
        if (k.estado === 'mejor') puntaje += peso;
        else if (k.estado === 'peor') puntaje -= peso;
    }
    const calificacion = puntaje >= 2 ? 'bueno' : puntaje <= -2 ? 'malo' : 'promedio';
    const etiqueta = { bueno: 'Sobre la media', promedio: 'En la media', malo: 'Bajo la media' }[calificacion];
    return { calificacion, etiqueta, cautela: kpis[0].pocoVolumen };
}

/** "CAC: el más barato de su etapa" -- texto corto para notas de la vista. */
export const DESCRIPCION_COSTO = 'el más barato de su etapa: CPM en Awareness, CPI en Consideración, CAC en Conversión y CPO en Loyalty';
export const nombreEtapa = (f) => etiquetaFunnel(f) || f || 'Sin etapa';
