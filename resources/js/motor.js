// Puerto literal de los formatters y tablas de referencia de
// dashboard/shared/motor.js (proyecto Node real, fuente de verdad) --
// compartido por CreativeCard/CreativeCarousel/PodiumTop3/CreativeModal/
// AnalisisCreativo para no triplicar la misma lógica en cada componente.

export function trimZero(n) {
    return String(Math.round(n * 10) / 10);
}
export function compactNumber(n) {
    const abs = Math.abs(n);
    if (abs >= 1_000_000) return trimZero(n / 1_000_000) + 'M';
    if (abs >= 1000) return trimZero(n / 1000) + 'K';
    return trimZero(n);
}
export function formatNumber(n) {
    if (n === null || n === undefined) return '—';
    return Math.abs(n) >= 1000 ? compactNumber(n) : String(Math.round(n));
}
export function formatMoney(n) {
    if (n === null || n === undefined) return '—';
    return '$' + (Math.abs(n) >= 1000 ? compactNumber(n) : n.toFixed(2));
}
export function formatPercent(n) {
    if (n === null || n === undefined) return '—';
    return n.toFixed(2) + '%';
}

// Exactos, sin compactar a K/M -- para el detalle del modal, donde el
// negocio necesita auditar el número real, no un redondeo que sirve para la
// card pero no para auditar el dato. 'en-US' fijo (no el locale del
// navegador), igual que el resto del dashboard.
export function formatNumeroExacto(n) {
    if (n === null || n === undefined) return '—';
    return Math.round(n).toLocaleString('en-US');
}
export function formatMoneyExacto(n) {
    if (n === null || n === undefined) return '—';
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// FUNNEL_COLOR_VAR/FUNNEL_LABELS/FUNNEL_ORDER reales -- claves AWA/CON/CONS/
// CNV/LOY. El esquema Laravel solo persiste CONS (no CON suelto, ver
// FUNNEL_SEGMENTO_A_FUNNEL en ClasificadorNombres.php), así que los
// creativos CONS caen a 'CONS' en estas tablas -- var(--sky), "Consideración
// (BI)" -- igual que en producción, no es un bug.
export const FUNNEL_ORDER = ['AWA', 'CON', 'CONS', 'CNV', 'LOY', 'Sin clasificar'];

export const FUNNEL_LABELS = {
    AWA: 'Awareness',
    CON: 'Consideración',
    CONS: 'Consideración (BI)',
    CNV: 'Conversión',
    LOY: 'Loyalty',
    'Sin clasificar': 'Sin clasificar',
};

export const FUNNEL_COLOR_VAR = {
    AWA: 'var(--violet)',
    CON: 'var(--amber)',
    CONS: 'var(--sky)',
    CNV: 'var(--mint)',
    LOY: 'var(--coral)',
    'Sin clasificar': 'var(--text-faint)',
};

// TIPO_CUENTA_OPCIONES real (motor.js) -- compartido entre el filtro de
// AnalisisCreativo.vue y el preview de solo lectura de ImportarDatos.vue.
export const TIPO_CUENTA_OPCIONES = [
    { key: 'DTC', label: 'TaDa (DTC)' },
    { key: 'BRD', label: 'BI (Marca)' },
    { key: 'SIN_CLASIFICAR', label: 'Sin clasificar' },
];

export function placeholderPorTipo(tipo) {
    if (tipo === 'VIDEO') return { glyph: '▶', texto: 'Video — sin thumbnail disponible' };
    if (tipo === 'CATALOGO') return { glyph: '▦', texto: 'Catálogo dinámico — sin productos resueltos' };
    return { glyph: '🖼', texto: 'Sin imagen' };
}

// statsParaCard (motor.js:1079-1119) -- switch literal sobre 'AWA'/'CON'/
// 'CNV'/'LOY'. Espera un `card` con campos ya numéricos (null u number).
export function statsParaCard(c) {
    switch (c.etapaFunnel) {
        case 'AWA':
            return [
                { label: 'Impresiones', value: formatNumber(c.impressions) },
                { label: 'CPM', value: formatMoney(c.cpm) },
                { label: 'CTR', value: formatPercent(c.ctr) },
            ];
        case 'CON':
            return [
                { label: 'Installs', value: formatNumber(c.installs) },
                { label: 'CPI', value: formatMoney(c.cpi) },
                { label: 'CTR', value: formatPercent(c.ctr) },
            ];
        case 'CNV':
            return [
                { label: 'NC', value: formatNumber(c.newCustomers) },
                { label: 'CAC', value: formatMoney(c.cac) },
                { label: 'CTR', value: formatPercent(c.ctr) },
            ];
        case 'LOY':
            return [
                { label: 'Orders', value: formatNumber(c.orders) },
                { label: 'CPO', value: formatMoney(c.cpo) },
                { label: 'CTR', value: formatPercent(c.ctr) },
            ];
        default:
            if (c.newCustomers !== null && c.cost !== null) {
                return [
                    { label: 'NC', value: formatNumber(c.newCustomers) },
                    { label: 'CAC', value: formatMoney(c.cac) },
                    { label: 'CTR', value: formatPercent(c.ctr) },
                ];
            }
            return [
                { label: 'Costo', value: formatMoney(c.cost) },
                { label: 'CTR', value: formatPercent(c.ctr) },
                { label: 'CPM', value: formatMoney(c.cpm) },
            ];
    }
}

// Mapea un Creativo+Resultado de Eloquent (con decimal:2 llegando como
// string) al `card` que esperan statsParaCard/placeholderPorTipo/etc. Campos
// sin equivalente persistido todavía (status, tipoCreativo, catalogThumbs,
// serie, videoUrl, fatiga) llegan null/[] -- gaps reales, no inventados (ver
// notas en CreativeCard.vue/CreativeModal.vue). campaignName/copy* SÍ
// persisten (2026-08-04, ver ImportadorDatos::procesarPlataforma) --
// creativo.copy es un objeto único {titulo, texto} (nunca variantes
// múltiples reales: Meta trae un solo creative activo por ad, TikTok
// ad_text es un campo singular -- para los ads "Smart+ automatizados" de
// TikTok, texto llega null porque la API no expone ahí el texto que
// rota/genera automáticamente).
export function numOrNull(v) {
    return v === null || v === undefined ? null : Number(v);
}

export function cardDesdeCreativo(creativo) {
    const r = creativo.resultados?.[0] ?? null;
    return {
        etapaFunnel: creativo.funnel,
        impressions: numOrNull(r?.impressions),
        clicks: numOrNull(r?.clicks),
        cpm: numOrNull(r?.cpm),
        ctr: numOrNull(r?.ctr),
        installs: numOrNull(r?.installs),
        cpi: numOrNull(r?.cpi),
        newCustomers: numOrNull(r?.nc),
        cac: numOrNull(r?.cac),
        orders: numOrNull(r?.orders),
        cpo: numOrNull(r?.cpo),
        cost: numOrNull(r?.cost),
        frequency: numOrNull(r?.frequency),
        adId: creativo.ad_id,
        plataforma: creativo.plataforma,
        status: creativo.status ?? null,
        tipoCreativo: creativo.tipo ?? null,
        catalogThumbs: creativo.catalogThumbs ?? [],
        imageUrl: creativo.imagen_url ?? null,
        videoUrl: creativo.videoUrl ?? null,
        adNameShort: creativo.nombre_comun,
        arte: creativo.nombre_comun,
        adIdsMostrables: creativo.adIdsMostrables ?? null,
        adIdMostrable: creativo.adIdMostrable ?? null,
        campaignName: creativo.nombre_campania ?? null,
        serie: creativo.serie ?? null,
        fatiga: creativo.fatiga ?? null,
        copyBodies: creativo.copy?.texto ? [creativo.copy.texto] : [],
        copyTitles: creativo.copy?.titulo ? [creativo.copy.titulo] : [],
        copyDescriptions: [],
        tieneMeta: creativo.tieneMeta ?? null,
        tieneAppsFlyer: creativo.tieneAppsFlyer ?? null,
    };
}
