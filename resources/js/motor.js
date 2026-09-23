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
// creativos CONS caen a 'CONS' en estas tablas -- var(--sky), "Consideración"
// -- pedido explícito del negocio (2026-08-12): el sufijo "(BI)" no debe
// mostrarse, CON y CONS comparten el mismo label visible.
export const FUNNEL_ORDER = ['AWA', 'CON', 'CONS', 'CNV', 'LOY', 'Sin clasificar'];

export const FUNNEL_LABELS = {
    AWA: 'Awareness',
    CON: 'Consideración',
    CONS: 'Consideración',
    CNV: 'Conversión',
    LOY: 'Loyalty',
    'Sin clasificar': 'Sin clasificar',
};

// Remapeado 2026-08-21 -- pedido explícito del negocio (boceto de modal):
// Conversión = rojo, Consideración = verde, Loyalty = azul, Awareness =
// amarillo. mint/coral/sky ya existían con esos colores exactos, solo se
// reasignan a las claves de funnel correctas; --yellow es nuevo, dedicado
// (ver app.css).
export const FUNNEL_COLOR_VAR = {
    AWA: 'var(--yellow)',
    CON: 'var(--mint)',
    CONS: 'var(--mint)',
    CNV: 'var(--coral)',
    LOY: 'var(--sky)',
    'Sin clasificar': 'var(--text-faint)',
};

// TIPO_CUENTA_OPCIONES real (motor.js) -- compartido entre el filtro de
// AnalisisCreativo.vue y el preview de solo lectura de ImportarDatos.vue.
export const TIPO_CUENTA_OPCIONES = [
    { key: 'DTC', label: 'TaDa (DTC)' },
    { key: 'BRD', label: 'BI (Marca)' },
    { key: 'SIN_CLASIFICAR', label: 'Sin clasificar' },
];

// TIPO_CREATIVO_LABELS -- valores reales de creativo.formato
// (VIDEO/CATALOGO/IMAGEN, ver ClasificadorNombres::FORMATO_CODES en el
// backend) a Title Case, para no mostrarlos en mayúscula suelta en medio
// de una grilla que el resto muestra en oración normal.
export const TIPO_CREATIVO_LABELS = {
    VIDEO: 'Video',
    CATALOGO: 'Catálogo',
    IMAGEN: 'Imagen',
};

// Humaniza el Campaign name crudo de AppsFlyer (ej.
// "ECU_DTC_TAD_AON_AON_TAD_AWA_EPA_FB-AWARENESS-BROAD-V2") a algo legible
// (ej. "Meta Awareness Broad V2") -- pedido explícito (2026-08-21): el
// nombre técnico completo, con los segmentos de país/tipo de
// cuenta/agencia, no aporta nada al negocio, lo que importa ahí es
// plataforma + objetivo de campaña en texto plano. Reutiliza el MISMO
// patrón de bloque que ClasificadorNombres::bloqueRegex() en PHP (busca
// "FB-"/"TKT-" precedido de inicio de cadena, "_" o "-", acepta "_" o "-"
// como separador interno) -- toma todo lo que sigue al prefijo de
// plataforma y lo pasa a Title Case con espacios en vez de guiones. Si no
// hay bloque reconocible, se muestra el nombre crudo tal cual (nunca se
// inventa una humanización de algo que no calza el patrón esperado).
const BLOQUE_CAMPANIA_RE = {
    meta: /(?:^|[_-])FB[_-]([A-Za-z0-9_-]+)/i,
    tiktok: /(?:^|[_-])TKT[_-]([A-Za-z0-9_-]+)/i,
};
const PLATAFORMA_LABEL = { meta: 'Meta', tiktok: 'TikTok' };
function tituloDesdeGuiones(texto) {
    return texto
        .split(/[_-]/)
        .filter(Boolean)
        .map((t) => t.charAt(0).toUpperCase() + t.slice(1).toLowerCase())
        .join(' ');
}
export function humanizarCampania(nombreCampania) {
    const raw = String(nombreCampania || '');
    for (const [plataforma, re] of Object.entries(BLOQUE_CAMPANIA_RE)) {
        const m = raw.match(re);
        if (m) return `${PLATAFORMA_LABEL[plataforma]} ${tituloDesdeGuiones(m[1])}`;
    }
    return raw || null;
}

// nombrePrincipalYTecnico -- un solo criterio de "qué título mostrar" para
// todas las cards/el modal (2026-08-27, ver plan): con nombreAmigable
// asignado, ESE es el principal y el nombre técnico baja a subtexto; sin
// nombreAmigable, se comporta exactamente igual que antes -- el `tecnico`
// que ya calculaba cada componente (CreativeCard usa adNameShort||adId,
// SeleccionCard/CreativeModal usan arte||adNameShort||adId -- fallbacks
// distintos a propósito, no se tocan acá) como único texto, sin subtexto
// separado -- nunca se duplica el mismo string arriba y abajo. Recibe el
// `tecnico` ya resuelto por el caller en vez de recalcularlo, para no
// pisar esas diferencias existentes.
export function nombrePrincipalYTecnico(nombreAmigable, tecnico) {
    return nombreAmigable ? { principal: nombreAmigable, tecnico } : { principal: tecnico, tecnico: null };
}

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
        case 'CONS':
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
            // etapaFunnel null/no reconocida ("Sin clasificar") -- nunca se
            // disfraza de Conversions solo porque newCustomers/cost tengan
            // valor (ese default viejo mostraba NC/CAC de una etapa que no
            // es la real del creativo, el bug que motivó este cambio
            // 2026-09-17). '—' explícito en las 3 columnas, mismo criterio
            // que el resto del archivo para "no hay dato confiable".
            return [
                { label: 'Sin clasificar', value: '—' },
                { label: 'CTR', value: formatPercent(c.ctr) },
                { label: 'Costo', value: c.tieneMeta === false ? '—' : formatMoney(c.cost) },
            ];
    }
}

// kpisPrincipales -- grid de 3 KPIs del modal de detalle (2026-08-21,
// boceto de modal). A diferencia de statsParaCard (para la card chica),
// SIEMPRE arranca con Costo -- lado del gasto, universal a cualquier
// funnel -- seguido del par específico de la etapa (mismo mapeo por
// funnel ya usado en las cards), con `accent:true` en la métrica de
// eficiencia (3ra columna, resaltada en el boceto). `sub` es el texto de
// contexto bajo cada valor -- null si falta el dato para calcularlo
// (nunca se inventa un porcentaje con un denominador null/0).
function pctSubtexto(num, den) {
    if (num === null || !den) return null;
    return formatPercent((num / den) * 100);
}
export function kpisPrincipales(c) {
    // tieneMeta === false: este mes no tuvo match de costo en la API (Meta/
    // TikTok) -- resultados.cost/impressions llegan en 0 desde el backend a
    // propósito (columnas NOT NULL, ver migración 2026_08_04), tiene_meta es
    // la señal real para no confundir ese 0 con gasto real (2026-09-17,
    // antes tieneMeta nunca llegaba al card y esto mostraba "$0.00").
    const sinCosto = c.tieneMeta === false;
    const costo = {
        label: 'Costo',
        value: sinCosto ? '—' : formatMoneyExacto(c.cost),
        sub: (!sinCosto && c.impressions !== null) ? `CPM ${formatMoneyExacto(c.cpm)} · ${formatNumeroExacto(c.impressions)} impresiones` : null,
    };
    switch (c.etapaFunnel) {
        case 'AWA': {
            const ctrTxt = pctSubtexto(c.clicks && c.impressions ? c.clicks : null, c.impressions);
            return [
                costo,
                { label: 'Impresiones', value: formatNumeroExacto(c.impressions), sub: ctrTxt ? `${formatNumeroExacto(c.clicks)} clicks (${ctrTxt} CTR)` : null },
                { label: 'CPM', value: formatMoneyExacto(c.cpm), accent: true },
            ];
        }
        case 'CON':
        case 'CONS': {
            const pct = pctSubtexto(c.installs, c.clicks);
            return [
                costo,
                { label: 'Instalaciones', value: formatNumeroExacto(c.installs), sub: pct ? `${pct} de los clicks instaló` : null },
                { label: 'CPI', value: formatMoneyExacto(c.cpi), accent: true },
            ];
        }
        case 'CNV': {
            const pct = pctSubtexto(c.newCustomers, c.orders);
            return [
                costo,
                { label: 'Nuevos clientes', value: formatNumeroExacto(c.newCustomers), sub: pct ? `${pct} de las ${formatNumeroExacto(c.orders)} órdenes` : null },
                { label: 'CAC', value: formatMoneyExacto(c.cac), accent: true },
            ];
        }
        case 'LOY': {
            const pct = pctSubtexto(c.orders, c.installs);
            return [
                costo,
                { label: 'Órdenes', value: formatNumeroExacto(c.orders), sub: pct ? `${pct} de las instalaciones ordenó` : null },
                { label: 'CPO', value: formatMoneyExacto(c.cpo), accent: true },
            ];
        }
        default:
            return [costo, { label: 'CTR', value: formatPercent(c.ctr) }, { label: 'CPM', value: formatMoneyExacto(c.cpm), accent: true }];
    }
}

// DIRECCION_METRICA -- true si "más" es mejor (volumen/tasas de avance),
// false si "menos" es mejor (costo por resultado). Define el signo de
// sobre/bajo la media en compararConPromedio.
export const DIRECCION_METRICA_MAYOR_ES_MEJOR = {
    impressions: true, clicks: true, installs: true, orders: true, newCustomers: true, ctr: true,
    cpm: false, cpi: false, cpo: false, cac: false,
    // cost (2026-08-28, comparación de Inteligencia): mismo criterio que
    // CPM/CPI/CPO/CAC -- gastar menos por el mismo resultado es lo bueno,
    // nunca "más gasto = gana".
    cost: false,
};

// compararConPromedio -- 2026-08-21, pedido explícito: compara un valor
// contra el promedio de su grupo (mismo país+plataforma+funnel+mes, ver
// promediosParaCreativo en AnalisisCreativo.vue). ±10% se considera "en
// la media" (ruido normal de muestra chica); fuera de eso, sobre/bajo con
// el sentido correcto según DIRECCION_METRICA_MAYOR_ES_MEJOR. `estado`
// describe la dirección LITERAL del número (sobre/bajo el promedio) --
// `tagLabel`/`tagVariant` describen si eso es BUENO o MALO, nunca el
// número crudo: un CPI 30% más bajo que el promedio es bueno (más barato),
// mostrar "Bajo la media" ahí con un punto verde leía como contradictorio
// (probado en vivo, 2026-08-21) -- por eso el tag dice "Mejor/Peor que la
// media", siempre alineado con el color, sin importar si para esa métrica
// "más" o "menos" es lo bueno. Nunca compara si falta valor o promedio --
// null explícito, no un 0%/100% inventado.
export function compararConPromedio(valor, promedio, campo) {
    if (valor === null || valor === undefined || !promedio) return null;
    const pct = ((valor - promedio) / promedio) * 100;
    const mayorEsMejor = DIRECCION_METRICA_MAYOR_ES_MEJOR[campo] ?? true;

    let estado = 'en';
    if (pct > 10) estado = 'sobre';
    else if (pct < -10) estado = 'bajo';

    const tagVariant = estado === 'en' ? 'neutral' : ((estado === 'sobre') === mayorEsMejor ? 'positive' : 'negative');
    const tagLabel = estado === 'en' ? 'En la media' : (tagVariant === 'positive' ? 'Mejor que la media' : 'Peor que la media');

    return { estado, pct: Math.round(Math.abs(pct)), tagLabel, tagVariant };
}

// etapasFunnel -- las 5 filas de la vista "Etapas" (cascada Impresiones →
// Clicks → Instalaciones → Órdenes → Nuevos clientes), cada una con su
// categoría fija y su métrica de eficiencia comparada contra el promedio
// del grupo. `promedios` llega ya calculado desde AnalisisCreativo.vue
// (promediosParaCreativo) -- esta función no promedia nada, solo arma y
// compara.
// categoria: 2026-08-26, relabeled 'Resultado' -> 'Negocio' en Órdenes/
// Nuevos clientes -- alinea con CATEGORIAS_SCORE (el score de rendimiento
// promedia CPO+CAC bajo una sola categoría "Negocio"), evita que la vista
// Etapas muestre una 5ta categoría que el score no usa.
const ETAPAS_DEFINICION = [
    { key: 'impressions', label: 'Impresiones', categoria: 'Entrega', metricaCampo: 'cpm', metricaLabel: 'CPM' },
    { key: 'clicks', label: 'Clicks', categoria: 'Interés', metricaCampo: 'ctr', metricaLabel: 'CTR' },
    { key: 'installs', label: 'Instalaciones', categoria: 'Adquisición', metricaCampo: 'cpi', metricaLabel: 'CPI' },
    { key: 'orders', label: 'Órdenes', categoria: 'Negocio', metricaCampo: 'cpo', metricaLabel: 'CPO' },
    { key: 'newCustomers', label: 'Nuevos clientes', categoria: 'Negocio', metricaCampo: 'cac', metricaLabel: 'CAC' },
];
// notaEtapa -- línea de contexto bajo cada tarjeta de Etapas (2026-08-26,
// rediseño), describe la conversión hacia la SIGUIENTE etapa. Nunca inventa
// -- si falta el denominador (0 o null), no hay nota para esa fila.
// 'newCustomers' es la etapa terminal, sin siguiente paso que describir.
function notaEtapa(key, c) {
    if (key === 'impressions') {
        if (!c.clicks || !c.impressions) return null;
        return `CTR ${formatPercent(c.ctr)} — 1 click cada ${Math.round(c.impressions / c.clicks)} impresiones`;
    }
    if (key === 'clicks') {
        const pct = pctSubtexto(c.installs, c.clicks);
        return pct ? `${pct} de los clicks termina en instalación` : null;
    }
    if (key === 'installs') {
        return c.orders !== null ? `${formatNumeroExacto(c.orders)} órdenes atribuidas (incluye usuarios ya instalados)` : null;
    }
    if (key === 'orders') {
        const pct = pctSubtexto(c.newCustomers, c.orders);
        return pct ? `${formatNumeroExacto(c.newCustomers)} de esas órdenes son de clientes nuevos (${pct})` : null;
    }
    return null;
}
export function etapasFunnel(c, promedios) {
    return ETAPAS_DEFINICION.map((e) => {
        const valorMetrica = c[e.metricaCampo];
        const subScore = subScoreMetrica(valorMetrica, promedios?.[e.metricaCampo], e.metricaCampo);
        let comparacion = compararConPromedio(valorMetrica, promedios?.[e.metricaCampo], e.metricaCampo);
        // Fallback de conteo crudo (2026-08-28, pedido explícito) -- CPI/
        // CPO/CAC quedan indefinidos cuando el conteo (installs/orders/nc)
        // es 0 (no se puede dividir costo entre 0), así que compararConPromedio
        // no tiene nada que comparar y esta fila queda "Sin base". Pero
        // Meta (y desde agosto TikTok) SÍ atribuyen esos conteos en
        // Awareness -- un 0 real cuando el grupo tuvo resultado real es
        // comparable de verdad. Solo aplica cuando el conteo es
        // EXPLÍCITAMENTE 0 (no null/sin dato) y hay promedio de grupo para
        // ese conteo; nunca pisa una comparación que ya existe.
        if (comparacion === null && valorMetrica === null && c[e.key] === 0 && (promedios?.[e.key] ?? null) !== null) {
            comparacion = compararConPromedio(c[e.key], promedios[e.key], e.key);
        }
        return {
            key: e.key,
            label: e.label,
            categoria: e.categoria,
            valor: formatNumeroExacto(c[e.key]),
            metricaLabel: e.metricaLabel,
            metricaValor: e.metricaCampo === 'ctr' ? formatPercent(valorMetrica) : formatMoneyExacto(valorMetrica),
            comparacion,
            progresoPct: subScore === null ? null : Math.round((subScore / 25) * 100),
            nota: notaEtapa(e.key, c),
        };
    });
}

// METRICAS_RELEVANTES_POR_FUNNEL -- qué métricas de eficiencia son la señal
// correcta para juzgar CADA etapa (2026-08-26, pedido explícito: Lectura y
// Score deben mirar las métricas de SU etapa, no una lista fija de
// ctr/cpi/cpo/cac aplicada a cualquier funnel -- un AWA puro casi nunca
// tiene installs/orders todavía, así que cpi/cpo/cac ahí son
// estructuralmente null, no "malas": antes eso hacía que generarLectura
// se quedara con <2 métricas comparables y no mostrara nada, o comparara
// algo que no es la señal real de esa etapa). Única fuente de verdad --
// generarLectura Y calcularScoreCreativo/explicacionScore leen de acá,
// para que las dos piezas nunca queden desalineadas sobre qué es
// "relevante" para un AWA vs un LOY.
export const METRICAS_RELEVANTES_POR_FUNNEL = {
    AWA: ['cpm', 'ctr'],
    CON: ['cpi', 'ctr'],
    CONS: ['cpi', 'ctr'],
    CNV: ['cac', 'cpo', 'ctr'],
    LOY: ['cpo', 'ctr'],
};
// Fallback para funnel null/no clasificado -- mismo comportamiento que
// tenía generarLectura antes de este cambio, no se rompe nada para
// creativos sin `funnel` persistido.
const METRICAS_LECTURA_FALLBACK = ['ctr', 'cpi', 'cpo', 'cac'];

// generarLectura -- motor de reglas simple (2026-08-21, pedido explícito:
// "construirlo ahora, con reglas simples"). Evalúa las métricas relevantes
// de la etapa de funnel del creativo (ver METRICAS_RELEVANTES_POR_FUNNEL)
// contra el promedio del grupo, identifica el peor (cuello de botella) y
// las fortalezas, y compone un párrafo corto con los números reales + una
// palanca sugerida fija por métrica. Nunca inventa -- si no hay al menos
// 2 métricas comparables (valor Y promedio disponibles), devuelve null y
// el bloque simplemente no se dibuja (mismo criterio que fatiga/
// sparkline).
const LABEL_METRICA_LECTURA = { ctr: 'CTR', cpi: 'CPI', cpo: 'CPO', cac: 'CAC', cpm: 'CPM' };
const PALANCA_POR_METRICA = {
    ctr: 'probar un gancho o creativo nuevo en los primeros segundos',
    cpi: 'revisar la segmentación o la audiencia',
    cpo: 'revisar la landing o el checkout',
    cac: 'revisar la oferta o el creativo antes de escalar presupuesto',
    cpm: 'revisar la segmentación o la puja de la campaña (subasta cara para este público)',
};
function formatearValorLectura(campo, valor) {
    return campo === 'ctr' ? formatPercent(valor) : formatMoneyExacto(valor);
}
export function generarLectura(c, promedios) {
    const campos = METRICAS_RELEVANTES_POR_FUNNEL[c.etapaFunnel] || METRICAS_LECTURA_FALLBACK;
    const metricas = campos
        .map((campo) => ({ campo, valor: c[campo], comp: compararConPromedio(c[campo], promedios?.[campo], campo) }))
        .filter((m) => m.comp !== null);
    if (metricas.length < 2) return null;

    const malas = metricas.filter((m) => m.comp.tagVariant === 'negative').sort((a, b) => b.comp.pct - a.comp.pct);
    const buenas = metricas.filter((m) => m.comp.tagVariant === 'positive').sort((a, b) => b.comp.pct - a.comp.pct);

    if (malas.length === 0) {
        if (buenas.length === 0) return null;
        const lista = buenas.map((m) => LABEL_METRICA_LECTURA[m.campo]).join(', ');
        return `Este anuncio rinde en línea o mejor que el promedio de su grupo en ${lista} -- sin un cuello de botella claro todavía.`;
    }

    const cuello = malas[0];
    const fortalezaTexto = buenas.length
        ? `Lo que pasa fuera del ${LABEL_METRICA_LECTURA[cuello.campo]} rinde bien: ${buenas.map((m) => `${LABEL_METRICA_LECTURA[m.campo]} ${formatearValorLectura(m.campo, m.valor)}`).join(', ')}. `
        : '';
    return `${fortalezaTexto}El cuello de botella está en el ${LABEL_METRICA_LECTURA[cuello.campo]} (${formatearValorLectura(cuello.campo, cuello.valor)}, ${cuello.comp.pct}% peor que el promedio de su grupo). Vale ${PALANCA_POR_METRICA[cuello.campo]}.`;
}

// calcularScoreDesglose -- "Score de rendimiento" (2026-08-26, rediseño vía
// Claude Design; simplificado 2026-08-28, pedido explícito del negocio).
//
// Antes evaluaba 4 categorías FIJAS (Entrega/Interés/Adquisición/Negocio)
// sin importar la etapa -- un Awareness terminaba con solo 2 categorías
// con dato real (Entrega+Interés) porque Adquisición/Negocio le quedaban
// estructuralmente null, pero igual se llamaban "categorías" como si
// aplicaran. Ahora evalúa exactamente las métricas relevantes de LA ETAPA
// de este creativo (METRICAS_RELEVANTES_POR_FUNNEL, la misma lista que ya
// usa generarLectura -- una sola fuente de verdad de "qué importa en cada
// etapa", nunca dos listas que se puedan desalinear). Un Awareness ahora
// solo ve CPM+CTR; un Conversión ve CAC+CPO+CTR -- nunca una categoría
// fantasma sin sentido para esa etapa.
//
// Cada métrica se ancla en 12.5/25 ("en la media") y sube/baja según
// compararConPromedio, tope de 60 puntos porcentuales para que un outlier
// de grupo chico no sature la categoría -- mismo criterio que el modelo
// anterior.
//
// nivel/etiqueta (pedido explícito 2026-08-28): "lo dejaremos más fácil,
// solo dirá Malo, Regular, Bueno, y será del 1-5, 5 es bueno". El score
// 0-100 de siempre se sigue calculando (es la base de la comparación
// entre creativos en ComparacionCreativos), pero la cara visible pasa a
// ser nivel (1-5, redondeado linealmente desde el score) + etiqueta
// (1-2 Malo, 3 Regular, 4-5 Bueno -- el corte estándar de escalas de 5
// puntos, nunca a la mitad de un nivel).
//
// explicacion: reusa generarLectura tal cual -- es LITERALMENTE la
// respuesta a "por qué" (identifica el cuello de botella real aunque el
// resto rinda bien, ej. "trae más NC pero con un CAC muy alto" -- el
// ejemplo que dio el negocio), no hace falta una segunda función que
// diga lo mismo con otras palabras.
const NIVEL_ETIQUETA = { 1: 'Malo', 2: 'Malo', 3: 'Regular', 4: 'Bueno', 5: 'Bueno' };
const TOPE_DESVIACION_PCT = 60;
function subScoreMetrica(valor, promedio, campo) {
    const comp = compararConPromedio(valor, promedio, campo);
    if (!comp) return null;
    const signo = comp.tagVariant === 'negative' ? -1 : comp.tagVariant === 'positive' ? 1 : 0;
    return 12.5 + ((signo * Math.min(comp.pct, TOPE_DESVIACION_PCT)) / TOPE_DESVIACION_PCT) * 12.5;
}
// Nunca inventa: una métrica sin dato comparable (valor Y promedio
// disponibles) queda con puntos:null -- la UI la pinta "Sin datos", nunca
// un 12.5 falso. El score final solo se oculta (return null) si menos de 2
// de las métricas relevantes de esa etapa tienen datos -- mismo piso que
// generarLectura (de hecho, el mismo umbral hace que "hay score" y "hay
// explicación" casi siempre coincidan).
export function calcularScoreDesglose(c, promedios) {
    const campos = METRICAS_RELEVANTES_POR_FUNNEL[c.etapaFunnel] || METRICAS_LECTURA_FALLBACK;
    const categorias = campos.map((campo) => {
        const puntos = subScoreMetrica(c[campo], promedios?.[campo], campo);
        return { clave: campo, label: LABEL_METRICA_LECTURA[campo] || campo.toUpperCase(), puntos: puntos === null ? null : Math.round(puntos), max: 25 };
    });

    const conDatos = categorias.filter((cat) => cat.puntos !== null);
    if (conDatos.length < 2) return null;

    const total = conDatos.reduce((acc, cat) => acc + cat.puntos, 0);
    const maxPosible = conDatos.length * 25;
    const score = Math.round((total / maxPosible) * 100);
    const nivel = Math.max(1, Math.min(5, Math.round((score / 100) * 4) + 1));
    return { score, nivel, etiqueta: NIVEL_ETIQUETA[nivel], categorias, explicacion: generarLectura(c, promedios) };
}

// Mapea un Creativo+Resultado de Eloquent (con decimal:2 llegando como
// string) al `card` que esperan statsParaCard/placeholderPorTipo/etc. Campos
// sin equivalente persistido todavía (status, catalogThumbs, serie,
// videoUrl, fatiga) llegan null/[] -- gaps reales, no inventados (ver notas
// en CreativeCard.vue/CreativeModal.vue). campaignName/copy* SÍ persisten
// (2026-08-04, ver ImportadorDatos::procesarPlataforma) -- creativo.copy es
// un objeto único {titulo, texto} (nunca variantes múltiples reales: Meta
// trae un solo creative activo por ad, TikTok ad_text es un campo singular
// -- para los ads "Smart+ automatizados" de TikTok, texto llega null porque
// la API no expone ahí el texto que rota/genera automáticamente).
//
// tipoCreativo lee creativo.formato (2026-08-21, corregido -- antes leía
// creativo.tipo, una columna real en la tabla pero que NINGÚN paso del
// pipeline de import llena jamás, así que siempre daba null: "Tipo
// creativo" en el modal mostraba "Sin dato en el export" con TODOS los
// creativos, y como CreativeCard.vue también usa este campo para decidir
// el badge "▶ Video"/el mosaico de CATALOGO/el glyph de placeholder
// correcto, esas tres cosas tampoco se dibujaban nunca aunque el dato real
// (formato: VIDEO/CATALOGO/IMAGEN, viene de ClasificadorNombres::
// buscarFormato() y SÍ se persiste) estaba ahí sin usarse.
export function numOrNull(v) {
    return v === null || v === undefined ? null : Number(v);
}

// resultadoDeMes/valorCampoEnMes/CAMPO_A_RESULTADO -- 2026-08-27, subidos
// desde AnalisisCreativo.vue (donde vivían sin exportar, asumiendo
// siempre resultados[0] porque ese controller ya manda un solo mes
// filtrado) para compartirlos con Inteligencia -- esa página manda el
// HISTORIAL COMPLETO de resultados por creativo (para el gráfico de
// tendencia mensual), así que no puede asumir la posición 0 y necesita
// buscar la fila de un mes específico explícito.
export const CAMPO_A_RESULTADO = {
    newCustomers: 'nc', orders: 'orders', impressions: 'impressions',
    cpo: 'cpo', cac: 'cac', cpi: 'cpi', ctr: 'ctr', installs: 'installs', cpm: 'cpm',
};
export function resultadoDeMes(creativo, mes) {
    if (!mes) return creativo.resultados?.[0] ?? null;
    return creativo.resultados?.find((r) => r.mes === mes) ?? null;
}
export function valorCampoEnMes(creativo, campo, mes) {
    const r = resultadoDeMes(creativo, mes);
    const v = r?.[CAMPO_A_RESULTADO[campo] ?? campo];
    return v === null || v === undefined ? null : Number(v);
}

// promediosDeGrupo -- 2026-08-21, pedido explícito del negocio (extraído
// de AnalisisCreativo.vue el 2026-08-27 para compartirlo con Inteligencia,
// mismo cuerpo): promedia CPM/CTR/CPI/CPO/CAC de todos los demás
// creativos del mismo país + plataforma + funnel + mes -- país ya
// implícito (viene ya filtrado desde el backend), mismo criterio de
// "comparar peras con peras" que usa el resto del motor. Ignora nulls al
// promediar (un creativo sin CTR no cuenta ni suma ni resta al promedio
// de CTR del grupo).
//
// installs/orders/newCustomers agregados 2026-08-28 (pedido explícito):
// Meta SÍ atribuye instalaciones/NC/órdenes en Awareness (y desde agosto
// también TikTok), así que un creativo AWA con 0 instalaciones cuando el
// grupo tuvo instalaciones reales es una señal comparable de verdad, no
// "no aplica" -- ver el fallback en etapasFunnel más abajo, que compara el
// CONTEO crudo cuando la métrica de eficiencia (cpi/cpo/cac) queda
// indefinida por dividir sobre 0. Un peer con 0 instalaciones SÍ cuenta en
// este promedio (0 no es null) -- mismo criterio que el resto de esta
// función.
export function promediosDeGrupo(creativo, todos, mes) {
    if (!creativo) return null;
    const grupo = todos.filter(
        (c) => c.id !== creativo.id && c.plataforma === creativo.plataforma && c.funnel === creativo.funnel,
    );
    const promedio = (campo) => {
        const valores = grupo.map((c) => valorCampoEnMes(c, campo, mes)).filter((v) => v !== null);
        return valores.length ? valores.reduce((a, b) => a + b, 0) / valores.length : null;
    };
    return {
        cpm: promedio('cpm'), ctr: promedio('ctr'), cpi: promedio('cpi'), cpo: promedio('cpo'), cac: promedio('cac'),
        installs: promedio('installs'), orders: promedio('orders'), newCustomers: promedio('newCustomers'),
    };
}

// mejorDeGrupo -- 2026-08-27, para Inteligencia (comparación head-to-head):
// dado un array de `card` (ya mapeados vía cardDesdeCreativo) y un campo,
// devuelve el card con el mejor valor -- usa
// DIRECCION_METRICA_MAYOR_ES_MEJOR para saber si gana el mayor o el menor,
// mismo criterio que compararConPromedio. Nunca "gana" un card con valor
// null/undefined; devuelve null si ninguno tiene el campo comparable.
export function mejorDeGrupo(cards, campo) {
    const conDato = cards.filter((c) => c[campo] !== null && c[campo] !== undefined);
    if (!conDato.length) return null;
    const mayorEsMejor = DIRECCION_METRICA_MAYOR_ES_MEJOR[campo] ?? true;
    return conDato.reduce((mejor, c) => {
        if (!mejor) return c;
        return (mayorEsMejor ? c[campo] > mejor[campo] : c[campo] < mejor[campo]) ? c : mejor;
    }, null);
}

// serieMensual -- historial mensual REAL de un campo para un creativo
// (Inteligencia: gráfico de tendencia). `creativo` debe traer `resultados`
// SIN acotar a un mes (a diferencia del resto del dashboard). Ordena por
// mes ascendente; nunca inventa un punto para un mes sin resultado (los
// omite, no los rellena con 0 ni interpola).
export function serieMensual(creativo, campo) {
    const filas = [...(creativo.resultados ?? [])].sort((a, b) => (a.mes < b.mes ? -1 : a.mes > b.mes ? 1 : 0));
    return filas
        .map((r) => ({ mes: r.mes, valor: valorCampoEnMes(creativo, campo, r.mes) }))
        .filter((p) => p.valor !== null);
}

// derivarMetricaDiaria -- resultados_diarios NO guarda cpi/cpo/cac/ctr/cpm
// (solo los crudos: cost/impressions/clicks/installs/nc/orders), a
// diferencia de `resultados` (mensual) que sí los tiene precalculados --
// mismas fórmulas EXACTAS que
// ImportadorDatosDiario::recalcularResultadoMensual (PHP) para no
// desalinear el número diario del mensual que ya se ve en el resto del
// dashboard. nc_real/orders_real ganan sobre el crudo cuando existen,
// igual criterio que esa función.
function derivarMetricaDiaria(fila, campo) {
    const cost = Number(fila.cost);
    const nc = fila.nc_real ?? fila.nc;
    const orders = fila.orders_real ?? fila.orders;
    switch (campo) {
        case 'impressions': return numOrNull(fila.impressions);
        case 'clicks': return numOrNull(fila.clicks);
        case 'installs': return numOrNull(fila.installs);
        case 'orders': return numOrNull(orders);
        case 'newCustomers': return numOrNull(nc);
        case 'cpm': return fila.impressions > 0 ? (cost / fila.impressions) * 1000 : null;
        case 'ctr': return fila.impressions > 0 ? (fila.clicks / fila.impressions) * 100 : null;
        case 'cpi': return fila.installs > 0 ? cost / fila.installs : null;
        case 'cac': return nc > 0 ? cost / nc : null;
        case 'cpo': return orders > 0 ? cost / orders : null;
        default: return null;
    }
}

// siguienteDiaISO -- aritmética de fecha en UTC puro (nunca Date local) para
// no desfasar un día según la zona horaria del navegador.
function siguienteDiaISO(clave) {
    const [a, m, d] = clave.split('-').map(Number);
    const fecha = new Date(Date.UTC(a, m - 1, d));
    fecha.setUTCDate(fecha.getUTCDate() + 1);
    return `${fecha.getUTCFullYear()}-${String(fecha.getUTCMonth() + 1).padStart(2, '0')}-${String(fecha.getUTCDate()).padStart(2, '0')}`;
}
// FILA_VACIA -- días sin fila en resultados_diarios (el import los omite a
// propósito, ver ImportadorDatosDiario::esSinActividad) representan "0 de
// todo ese día", no "no se sabe". derivarMetricaDiaria sobre esta fila
// devuelve 0 para conteos/montos (installs, cost, etc.) y null para
// métricas derivadas (cpm/cpi/cac/cpo -- dividir por 0 instalaciones sigue
// siendo indefinido, nunca "$0 de CPI").
const FILA_VACIA = { cost: 0, impressions: 0, clicks: 0, installs: 0, nc: 0, orders: 0, nc_real: null, orders_real: null };

// serieDiaria -- 2026-08-28, pedido explícito: tendencia DIARIA (no solo
// mensual) en la comparación de Inteligencia, ahora que Panamá tiene
// `resultados_diarios` (ver plan del selector de fecha). `creativo` debe
// traer `resultadosDiarios` YA acotado al mes elegido (InteligenciaController
// lo filtra server-side, no vienen meses de más). Países sin pipeline
// diario (Ecuador/México) simplemente no tienen esta relación cargada --
// vuelve un array vacío, nunca inventa un punto.
//
// Relleno de huecos (2026-08-28, pedido explícito: "la línea se corta en
// vez de mostrar 0... necesito que muestre 0 explícito, línea continua día
// a día"). Un día SIN fila real en resultados_diarios se rellena con
// FILA_VACIA -- pero SOLO entre el primer y el último día que sí tienen
// fila real para este creativo (nunca antes/después, eso sería inventar
// actividad fuera del período en que el ad realmente corrió). Dentro de
// ese rango, conteos/montos quedan en 0 explícito (línea continua);
// métricas derivadas siguen null en esos huecos (gap real, dividir por 0
// instalaciones no es "$0 de CPI" -- ver derivarMetricaDiaria).
export function serieDiaria(creativo, campo) {
    const filas = creativo.resultados_diarios ?? [];
    if (!filas.length) return [];
    const porFecha = new Map(filas.map((f) => [String(f.fecha).slice(0, 10), f]));
    const claves = [...porFecha.keys()].sort();
    const primera = claves[0];
    const ultima = claves[claves.length - 1];

    const puntos = [];
    for (let clave = primera; clave <= ultima; clave = siguienteDiaISO(clave)) {
        const valor = derivarMetricaDiaria(porFecha.get(clave) ?? FILA_VACIA, campo);
        if (valor !== null) puntos.push({ fecha: clave, valor });
    }
    return puntos;
}

export function cardDesdeCreativo(creativo, mes = null) {
    const r = mes ? resultadoDeMes(creativo, mes) : (creativo.resultados?.[0] ?? null);
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
        tipoCreativo: creativo.formato ?? null,
        catalogThumbs: creativo.catalogThumbs ?? [],
        imageUrl: creativo.imagen_url ?? null,
        videoUrl: creativo.videoUrl ?? null,
        // adNameShort SIEMPRE viene de nombre_completo (el nombre real del
        // ad, nunca vacío) -- arte es SOLO el nombre corto parseado
        // (nombre_comun, puede fallar). Antes los dos leían nombre_comun,
        // así que el fallback `arte || adNameShort || adId` del modal caía
        // directo al Ad ID numérico cuando el parseo de arte fallaba, aunque
        // el nombre real del ad sí estaba disponible (2026-08-12).
        adNameShort: creativo.nombre_completo,
        arte: creativo.nombre_comun,
        // nombreAmigable (2026-08-27, ver plan del rediseño) -- override
        // opcional de correcciones_nombres, nunca reemplaza `arte`/
        // `adNameShort` (el nombre técnico sigue viajando siempre, se
        // muestra como subtexto donde haya nombreAmigable). null si el
        // creativo nunca fue renombrado.
        nombreAmigable: creativo.nombre_amigable ?? null,
        adIdsMostrables: creativo.adIdsMostrables ?? null,
        adIdMostrable: creativo.adIdMostrable ?? null,
        campaignName: creativo.nombre_campania ?? null,
        serie: creativo.serie ?? null,
        fatiga: creativo.fatiga ?? null,
        // esGrupoArte/miembros (2026-09-17, consolidación por arte+etapa,
        // ver AnalisisCreativoController::cardAJson) -- esGrupoArte es true
        // para CUALQUIER creativo con arte+funnel válidos (incluso un solo
        // ad_id), no solo cuando hay 2+ campañas fundidas: usar
        // `miembros.length > 1` para decidir si vale la pena mostrar un
        // desglose, no `esGrupoArte` a secas.
        esGrupoArte: creativo.esGrupoArte ?? false,
        miembros: creativo.miembros ?? null,
        // copyBodies YA viene armado (deduplicado/etiquetado por campaña)
        // cuando el backend consolidó por arte -- ver
        // VentaRealYAgrupacion::consolidarCopyBodiesPorArte(). Si no viene
        // (creativo sin consolidar todavía, otras páginas), se arma como
        // siempre desde el copy singular.
        copyBodies: creativo.copyBodies ?? (creativo.copy?.texto ? [creativo.copy.texto] : []),
        copyTitles: creativo.copy?.titulo ? [creativo.copy.titulo] : [],
        copyDescriptions: [],
        // `r` es el resultado del mes ya resuelto arriba -- `creativo.tieneMeta`
        // nunca existió en ningún payload (ni backend ni otro componente lo
        // seteaba), así que esto SIEMPRE daba null y faltaMeta en
        // CreativeModal.vue nunca se activaba (2026-09-17, ver plan).
        tieneMeta: r?.tiene_meta ?? null,
        tieneAppsFlyer: creativo.tieneAppsFlyer ?? null,
        // rangosActividad (2026-08-28, pedido explícito) -- UN rango
        // (primer día con actividad real -> último día), calculado en
        // AnalisisCreativoController (ver rangosActividadPorCreativo) a
        // partir de resultados_diarios. Vacío para países sin datos diarios
        // (Ecuador/México) o si el creativo nunca tuvo actividad real --
        // nunca se inventa un rango.
        rangosActividad: creativo.rangos_actividad ?? [],
    };
}

// formatearRangoFecha/rangoActividadEnMes -- 2026-08-28, pedido explícito:
// "el tiempo que estuvieron activos" en la card. APROXIMADO (a partir de
// entrega real en resultados_diarios), no el registro exacto de Meta --
// ver docblock de rangosActividadPorCreativo en el controller. Nunca se
// muestra sin dejar claro que es una aproximación (el caller agrega la
// etiqueta "aproximado").
const MESES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
export function formatearRangoFecha(rango) {
    const [aIni, mIni, dIni] = rango.inicio.split('-').map(Number);
    const [aFin, mFin, dFin] = rango.fin.split('-').map(Number);
    if (rango.inicio === rango.fin) return `${dIni} ${MESES_CORTO[mIni - 1]}`;
    const mismoMes = aIni === aFin && mIni === mFin;
    const inicioTxt = mismoMes ? `${dIni}` : `${dIni} ${MESES_CORTO[mIni - 1]}`;
    const finTxt = aIni === aFin ? `${dFin} ${MESES_CORTO[mFin - 1]}` : `${dFin} ${MESES_CORTO[mFin - 1]} ${aFin}`;
    return `${inicioTxt}–${finTxt}`;
}
// rangoActividadEnMes -- para la card (acotada a un mes a la vez): el
// único rango de card.rangosActividad si se solapa con ese mes, o null si
// no (ej. país sin resultados_diarios, o el creativo no tuvo actividad ese
// mes). Simplificado 2026-08-28 (pedido explícito) -- ya no hay múltiples
// tramos que fusionar/detectar como "pausas", rangosActividad siempre trae
// como mucho un elemento (ver rangosActividadPorCreativo en el
// controller).
export function rangoActividadEnMes(card, mes) {
    if (!card.rangosActividad?.length || !mes) return null;
    const primerDiaMes = `${mes}-01`;
    const ultimoDiaMes = `${mes}-31`; // comparación lexicográfica de strings YYYY-MM-DD, alcanza para el corte
    const rango = card.rangosActividad[0];
    return rango.inicio <= ultimoDiaMes && rango.fin >= primerDiaMes ? rango : null;
}

// diasEnRango -- cantidad de días calendario INCLUSIVA entre inicio y fin
// (YYYY-MM-DD). Date.UTC puro, mismo criterio anti-desfase que
// siguienteDiaISO más arriba.
export function diasEnRango(rango) {
    if (!rango) return null;
    const [aIni, mIni, dIni] = rango.inicio.split('-').map(Number);
    const [aFin, mFin, dFin] = rango.fin.split('-').map(Number);
    const ini = Date.UTC(aIni, mIni - 1, dIni);
    const fin = Date.UTC(aFin, mFin - 1, dFin);
    return Math.round((fin - ini) / 86400000) + 1;
}

// parsearRangoDeclarado -- 2026-08-28, pedido explícito: comparar "días
// declarados" (el rango de fechas que el propio nombre del arte anuncia,
// ej. "SP-01JUL-31JUL-RGB-ATLAS", "20MAR-20ABR", "01-18FEB") contra los
// días REALES de actividad (rangosActividad). Heurística de texto sobre
// una convención de nombres que no es 100% uniforme -- si no matchea
// ninguno de los dos patrones conocidos, vuelve null y el caller
// simplemente no muestra esta comparación puntual, nunca inventa un rango
// declarado. `anioReferencia` es el año del rango REAL de ese creativo (o
// el año en curso si no hay uno) -- el nombre del arte nunca trae año.
const MESES_ABBR_ES = { ENE: 1, FEB: 2, MAR: 3, ABR: 4, MAY: 5, JUN: 6, JUL: 7, AGO: 8, SEP: 9, OCT: 10, NOV: 11, DIC: 12 };
function construirRangoDeclarado(dIni, mIni, dFin, mFin, anio) {
    // Cruce de año (ej. "22DIC-4ENE") -- `anio` viene del año REAL de
    // rango.inicio (evaluarComparabilidad), que en un cruce cae del lado
    // ENE/fin, no del lado DIC/inicio (la actividad real empieza en enero).
    // Por eso el lado que cruza es `inicio` (año anterior), nunca `fin`.
    const anioIni = mFin < mIni ? anio - 1 : anio;
    const pad = (n) => String(n).padStart(2, '0');
    return {
        inicio: `${anioIni}-${pad(mIni)}-${pad(dIni)}`,
        fin: `${anio}-${pad(mFin)}-${pad(dFin)}`,
    };
}
export function parsearRangoDeclarado(nombre, anioReferencia) {
    if (!nombre) return null;
    const anio = anioReferencia || new Date().getUTCFullYear();
    const texto = nombre.toUpperCase();
    // DDMMM-DDMMM, meses explícitos en ambos lados: "01JUL-31JUL", "20MAR-20ABR"
    let m = texto.match(/\b(\d{1,2})([A-Z]{3})-(\d{1,2})([A-Z]{3})\b/);
    if (m) {
        const [, dIni, mIniTxt, dFin, mFinTxt] = m;
        const mIni = MESES_ABBR_ES[mIniTxt];
        const mFin = MESES_ABBR_ES[mFinTxt];
        if (!mIni || !mFin) return null;
        return construirRangoDeclarado(Number(dIni), mIni, Number(dFin), mFin, anio);
    }
    // DD-DDMMM, mismo mes implícito en ambos lados: "01-31JUL", "01-18FEB"
    m = texto.match(/\b(\d{1,2})-(\d{1,2})([A-Z]{3})\b/);
    if (m) {
        const [, dIni, dFin, mTxt] = m;
        const mes = MESES_ABBR_ES[mTxt];
        if (!mes) return null;
        return construirRangoDeclarado(Number(dIni), mes, Number(dFin), mes, anio);
    }
    return null;
}

// evaluarComparabilidad -- 2026-08-28, pedido explícito ("necesito que se
// pueda distinguir cuáles son realmente comparables... basándose en sus
// días activos reales, no en el rango de fechas de la taxonomía"). Recibe
// las `card` (ya mapeadas vía cardDesdeCreativo) de los 2-4 creativos
// elegidos en Inteligencia y devuelve:
// - `info`: por creativo, su rango/días reales, su rango/días declarados
//   (si el nombre matchea) y si le faltó actividad respecto a lo
//   declarado.
// - `avisos`: mensajes puntuales por creativo (sin datos reales, o corrió
//   menos días de los declarados).
// - `pares`: para cada par, si sus rangos reales se solapan en el
//   calendario (comparable) o no, con el motivo en texto listo para UI.
// Nunca compara contra Meta exacto -- todo basado en la aproximación de
// rangosActividad (resultados_diarios), igual que el resto de esta
// funcionalidad.
export function evaluarComparabilidad(cards) {
    const info = cards.map((card) => {
        const rango = card.rangosActividad?.[0] ?? null;
        const diasReales = rango ? diasEnRango(rango) : null;
        const anioRef = rango ? Number(rango.inicio.slice(0, 4)) : null;
        const nombre = card.arte || card.adNameShort || '';
        const declarado = parsearRangoDeclarado(nombre, anioRef);
        const diasDeclarados = declarado ? diasEnRango(declarado) : null;
        return { card, rango, diasReales, declarado, diasDeclarados };
    });

    const nombreCorto = (card) => card.nombreAmigable || card.arte || card.adNameShort || card.adId;

    const avisos = [];
    info.forEach((it) => {
        if (!it.rango) {
            avisos.push({
                tipo: 'sin-datos',
                card: it.card,
                mensaje: `${nombreCorto(it.card)}: sin datos diarios de actividad real -- no se puede confirmar cuánto corrió.`,
            });
        } else if (it.declarado && it.diasReales < it.diasDeclarados) {
            avisos.push({
                tipo: 'incompleto',
                card: it.card,
                mensaje: `${nombreCorto(it.card)}: corrió ${it.diasReales} de ${it.diasDeclarados} días declarados (real ${formatearRangoFecha(it.rango)}, declarado ${formatearRangoFecha(it.declarado)}).`,
            });
        }
    });

    const pares = [];
    for (let i = 0; i < info.length; i++) {
        for (let j = i + 1; j < info.length; j++) {
            const a = info[i];
            const b = info[j];
            if (!a.rango || !b.rango) {
                pares.push({
                    a: a.card,
                    b: b.card,
                    comparable: null,
                    motivo: `${nombreCorto(a.card)} y ${nombreCorto(b.card)}: al menos uno no tiene datos diarios de actividad real, no se puede confirmar si corrieron en simultáneo.`,
                });
                continue;
            }
            const solapan = a.rango.inicio <= b.rango.fin && b.rango.inicio <= a.rango.fin;
            pares.push({
                a: a.card,
                b: b.card,
                comparable: solapan,
                motivo: solapan
                    ? `${nombreCorto(a.card)} y ${nombreCorto(b.card)} corrieron en simultáneo (${formatearRangoFecha(a.rango)} y ${formatearRangoFecha(b.rango)}).`
                    : `${nombreCorto(a.card)} (${formatearRangoFecha(a.rango)}) y ${nombreCorto(b.card)} (${formatearRangoFecha(b.rango)}) no corrieron en simultáneo -- no son comparables.`,
            });
        }
    }

    const todosComparables = pares.length > 0 && pares.every((p) => p.comparable === true);

    return { info, avisos, pares, todosComparables };
}
