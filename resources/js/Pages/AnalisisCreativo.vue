<script setup>
import { alerta } from '@/avisos';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import { useFiltroTipoCampania } from '@/tiposCampania';
import PodiumTop3 from '@/Components/Creativo/PodiumTop3.vue';
import CreativeCarousel from '@/Components/Creativo/CreativeCarousel.vue';
import CreativeCard from '@/Components/Creativo/CreativeCard.vue';
import CreativeTable from '@/Components/Creativo/CreativeTable.vue';
import CreativeBars from '@/Components/Creativo/CreativeBars.vue';
import VistaSwitch from '@/Components/Creativo/VistaSwitch.vue';
import CreativeModal from '@/Components/Creativo/CreativeModal.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import InfoTip from '@/Components/InfoTip.vue';
import { fechaHora } from '@/fechas';
import { formatNumeroExacto, formatMoneyExacto, FUNNEL_ORDER, opcionesTipoCuenta, etiquetaFiltroTipoCuenta, promediosDeGrupo, etiquetaFunnel } from '@/motor';

const props = defineProps({
    pais: String,
    plataforma: String,
    creativos: Array,
    mes: String,
    mesesDisponibles: Array,
    // Selector Inicio/Fin (spec Adenda B) -- solo tiene sentido donde haya
    // `resultados_diarios` (hoy, Panamá). tieneDatosDiarios decide si se
    // muestra el control; desde/hasta ya vienen aplicados por el backend
    // cuando el usuario los usó (null si la página está en modo "Mes").
    tieneDatosDiarios: { type: Boolean, default: false },
    desde: { type: String, default: null },
    hasta: { type: String, default: null },
    rangoDiarioMin: { type: String, default: null },
    rangoDiarioMax: { type: String, default: null },
    ultimaActualizacion: { type: String, default: null },
});

// A diferencia de Plataforma/Tipo de cuenta/Formato (que filtran EN CLIENTE
// sobre los creativos ya cargados), Mes recarga el servidor -- desde
// 2026-08-12 el controller acota `resultados` a un único mes por consulta
// (con 8 meses de backfill, traer todos a la vez ya no tiene sentido: un
// creativo tiene un resultado por mes, no uno solo).
const MESES_LABEL = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
function formatMesLabel(mesIso) {
    const [anio, mes] = mesIso.split('-').map(Number);
    return `${MESES_LABEL[mes - 1]} ${anio}`;
}
const mesOptions = computed(() => (props.mesesDisponibles || []).map((m) => ({ key: m, label: formatMesLabel(m) })));
function cambiarMes(nuevoMes) {
    router.get(window.location.pathname, { mes: nuevoMes }, { preserveState: true, preserveScroll: true, replace: true });
}

// Selector Inicio/Fin (spec Adenda B) -- reemplaza "Mes" por un rango
// arbitrario, sourced de resultados_diarios en vez de resultados (mes).
// Un solo estado de tiempo para TODA la página (podio, tabla, carrusel,
// barras reflejan el mismo rango a la vez) -- ver
// AnalisisCreativoController::index, no hay dos relojes independientes.
const usandoRango = computed(() => !!(props.desde && props.hasta));
const rangoDesde = ref(props.desde || props.rangoDiarioMax || '');
const rangoHasta = ref(props.hasta || props.rangoDiarioMax || '');
watch(() => [props.desde, props.hasta], ([d, h]) => {
    if (d && h) {
        rangoDesde.value = d;
        rangoHasta.value = h;
    }
});
function irARango(desde, hasta) {
    if (!desde || !hasta) return;
    router.get(window.location.pathname, { desde, hasta }, { preserveState: true, preserveScroll: true, replace: true });
}
function aplicarRango() {
    irARango(rangoDesde.value, rangoHasta.value);
}
function volverAMes() {
    router.get(window.location.pathname, { mes: props.mes }, { preserveState: true, preserveScroll: true, replace: true });
}

// Por mes / Por día (2026-11-11). Los atajos se cuentan desde el último día
// con datos, no desde hoy (AppsFlyer va unos días atrás).
function restarDias(iso, dias) {
    const [a, m, d] = iso.split('-').map(Number);
    return new Date(Date.UTC(a, m - 1, d - dias)).toISOString().slice(0, 10);
}
function verPorDia() {
    if (!props.tieneDatosDiarios || usandoRango.value) return;
    const fin = props.rangoDiarioMax;
    irARango(restarDias(fin, 6), fin);
}
const ATAJOS_DIA = [
    { key: 'dia', label: 'Último día', rango: (fin) => [fin, fin] },
    { key: 'semana', label: 'Últimos 7 días', rango: (fin) => [restarDias(fin, 6), fin] },
    { key: 'mes', label: 'Todo el mes', rango: (fin) => [`${fin.slice(0, 7)}-01`, fin] },
];
function atajoActivo(atajo) {
    const [d, h] = atajo.rango(props.rangoDiarioMax || '');
    return usandoRango.value && props.desde === d && props.hasta === h;
}
function aplicarAtajo(atajo) {
    const [d, h] = atajo.rango(props.rangoDiarioMax);
    irARango(d, h);
}
const ultimaActualizacionTexto = computed(() => (props.ultimaActualizacion ? fechaHora(props.ultimaActualizacion) : ''));

// Puerto literal de METRICAS_RANKING + calcularRanking + ETIQUETA_CORTA_POR_
// CAMPO + formatearValorMetrica + estrellaActual (motor.js:1654-1689,
// creativos.html:246-283) -- misma lógica de piso de volumen (nunca rankea
// un ratio con base insuficiente) y mismo criterio de "estrella" para podio
// y carrusel.
// Piso general de volumen POR ETAPA DE FUNNEL -- pedido explícito
// (2026-08-04): cada etapa se mide con su propia métrica de volumen, no
// todas con instalaciones (un AWA puede tener millones de impresiones y
// pocas o ninguna instalación -- no es su objetivo, así que el piso viejo
// lo excluía casi siempre sin importar su alcance real). CNV/LOY quedan en
// 0 (sin piso real todavía, ningún número confirmado) a propósito -- 0
// sigue exigiendo que el campo no sea null, solo no excluye por volumen
// bajo hasta que el negocio confirme un umbral. impresiones de AWA usa el
// mismo número que ya usa CTR (único precedente de piso por impresiones,
// ver METRICAS_RANKING.ctr.umbralDefault) -- confirmar si el negocio quiere
// otro número específico.
const UMBRAL_VOLUMEN_POR_FUNNEL = {
    AWA: { campo: 'impressions', umbral: 1000 },
    CONS: { campo: 'installs', umbral: 15 },
    CNV: { campo: 'newCustomers', umbral: 0 },
    LOY: { campo: 'orders', umbral: 0 },
};
// Fallback para funnel null/no clasificado -- no cubierto por el mapeo de
// arriba, se mantiene el criterio anterior (installs) para no dejar esos
// casos sin ningún piso.
const UMBRAL_INSTALLS_RANKING = 15;

const METRICAS_RANKING = {
    nc: { label: 'Más nuevos clientes', campo: 'newCustomers', tipo: 'conteo', orden: 'desc' },
    orders: { label: 'Más órdenes', campo: 'orders', tipo: 'conteo', orden: 'desc' },
    impresiones: { label: 'Más impresiones', campo: 'impressions', tipo: 'conteo', orden: 'desc' },
    // installs (agregado 2026-08-21) -- CAMPO_A_RESULTADO ya lo mapeaba desde
    // antes, pero faltaba como opción propia de ranking. Necesario para que
    // el KPI de la card en la pestaña Consideración pueda ser "Installs" (su
    // métrica natural, ver METRICA_POR_FUNNEL_DEFAULT) y no quede sin ningún
    // campo de conteo al que sincronizarse.
    installs: { label: 'Más instalaciones', campo: 'installs', tipo: 'conteo', orden: 'desc' },
    cpo: { label: 'Mejor CPO', campo: 'cpo', tipo: 'ratio', orden: 'asc', campoVolumen: 'orders', labelVolumen: 'órdenes', umbralDefault: 10 },
    cac: { label: 'Mejor CAC', campo: 'cac', tipo: 'ratio', orden: 'asc', campoVolumen: 'newCustomers', labelVolumen: 'nuevos clientes', umbralDefault: 10 },
    cpi: { label: 'Mejor CPI', campo: 'cpi', tipo: 'ratio', orden: 'asc', campoVolumen: 'installs', labelVolumen: 'instalaciones', umbralDefault: 30 },
    ctr: { label: 'Mejor CTR', campo: 'ctr', tipo: 'ratio', orden: 'desc', campoVolumen: 'impressions', labelVolumen: 'impresiones', umbralDefault: 1000 },
};
// FiltroDropdown espera { key, label }[], METRICAS_RANKING es un objeto
// keyed -- se deriva una sola vez, no se duplica la lista de métricas.
const METRICAS_RANKING_OPCIONES = Object.entries(METRICAS_RANKING).map(([key, m]) => ({ key, label: m.label }));

const ETIQUETA_CORTA_POR_CAMPO = {
    newCustomers: 'NC', orders: 'Orders', impressions: 'Impresiones', installs: 'Installs',
    cpo: 'CPO', cac: 'CAC', cpi: 'CPI', ctr: 'CTR',
};

function formatearValorMetrica(campo, valor) {
    if (valor === null || valor === undefined) return '—';
    if (campo === 'ctr') return valor.toFixed(2) + '%';
    if (campo === 'cac' || campo === 'cpo' || campo === 'cpi') return formatMoneyExacto(valor);
    return formatNumeroExacto(valor);
}

// Mapea el campo de METRICAS_RANKING/statsParaCard al valor real en nuestro
// esquema (resultados.nc, resultados.orders, etc.) -- los decimal:2 de
// Eloquent llegan como string, de ahí el Number().
const CAMPO_A_RESULTADO = {
    newCustomers: 'nc', orders: 'orders', impressions: 'impressions',
    cpo: 'cpo', cac: 'cac', cpi: 'cpi', ctr: 'ctr', installs: 'installs', cpm: 'cpm',
};
function valorCampo(creativo, campo) {
    const r = creativo.resultados?.[0];
    const v = r?.[CAMPO_A_RESULTADO[campo]];
    return v === null || v === undefined ? null : Number(v);
}

// sinActividadReal (2026-09-22, pedido explícito) -- mismo criterio que
// ImportadorDatos::esSinActividad en el backend: costo, impresiones,
// clicks E installs los 4 en 0 (o null) significa que no hay ninguna señal
// real para este creativo en el período visto, casi siempre un `Resultado`
// huérfano de una importación de prueba vieja que quedó sin actualizar (ver
// limpieza de 2026-09-22). Nunca excluye un creativo con AL MENOS un
// número real, por chico que sea -- eso sí sería un creativo legítimo del
// mes, no basura.
function sinActividadReal(creativo) {
    const r = creativo.resultados?.[0];
    return !((Number(r?.cost) || 0) > 0
        || (Number(r?.impressions) || 0) > 0
        || (Number(r?.clicks) || 0) > 0
        || (Number(r?.installs) || 0) > 0);
}

// Puerto literal de PLATAFORMA_OPCIONES + renderPlataformaToggle
// (creativos.html:210-225) -- Meta y TikTok usan SEGMENTOS de funnel
// distintos para el mismo tipo de campaña (ej. BI: Meta usa CNV, TikTok usa
// CON -> CONS), así que mezclarlas sin poder separar por plataforma
// confunde el ranking. Igual que en el real: el conteo de este toggle
// SIEMPRE es sobre el set completo sin filtrar (nunca se recorta por tipo
// de cuenta), plataformaSeleccionada se aplica ANTES que tipoCuenta.
const PLATAFORMA_OPCIONES = [
    { key: 'TODOS', label: 'Todos' },
    { key: 'meta', label: 'Meta' },
    { key: 'tiktok', label: 'TikTok' },
];
const plataformaSeleccionada = ref('TODOS');
const plataformaCounts = computed(() => {
    const counts = { TODOS: props.creativos.length, meta: 0, tiktok: 0 };
    for (const c of props.creativos) counts[c.plataforma] = (counts[c.plataforma] || 0) + 1;
    return counts;
});
// Tipo de campaña propio de la cuenta (ver tiposCampania.js) -- se aplica
// antes que la plataforma; sus conteos son sobre el set completo.
const filtroTipoCampania = useFiltroTipoCampania(computed(() => props.creativos));
const tipoCampaniaSeleccionado = filtroTipoCampania.seleccionado;
const creativosPorPlataforma = computed(() =>
    plataformaSeleccionada.value === 'TODOS'
        ? filtroTipoCampania.filtrados.value
        : filtroTipoCampania.filtrados.value.filter((c) => c.plataforma === plataformaSeleccionada.value),
);

// Puerto literal de tipoCuentaDeCard + filtrarPorTipoCuenta +
// renderTipoCuentaToggle (motor.js) -- DTC/BRD es una dimensión ORTOGONAL
// al funnel (Awareness/Consideración/Conversión/Loyalty), nunca se suman en
// el mismo cálculo. tipoCuentaDeCard cae a 'SIN_CLASIFICAR' solo si el
// campo viniera null -- hoy nunca pasa, porque ImportarCsvAppsFlyer ya
// persiste 'DTC' por defecto cuando no hay segmento explícito (nunca 'BRD'
// por defecto, mismo criterio que ClasificadorNombres). Opciones/labels
// viven en TipoCuentaToggle.vue (compartido con ImportarDatos.vue). Sus
// conteos SÍ dependen de la plataforma elegida (mismo criterio que
// renderTipoCuentaToggle(..., plataformaSeleccionada === 'TODOS' ? todas : filtradas, ...)).
function tipoCuentaDeCard(creativo) {
    return creativo.tipo_cuenta || 'SIN_CLASIFICAR';
}
const tipoCuentaSeleccionado = ref('DTC');
const tipoCuentaCounts = computed(() => {
    const counts = { DTC: 0, BRD: 0, SIN_CLASIFICAR: 0 };
    for (const c of creativosPorPlataforma.value) counts[tipoCuentaDeCard(c)]++;
    return counts;
});
// tieneMeta real (motor.js: !!costos, persistido en resultados.tiene_meta) --
// false significa que el creativo viene SOLO de AppsFlyer, sin cruce real
// contra el export de costos de la API de Meta/TikTok Ads (sin costo/CTR/
// CPM reales). Se oculta acá (no se borra de la BD) porque no representa
// paid real todavía -- podría ser tráfico en tránsito sin cruce completo en
// esta corrida. Se aplica JUNTO con plataforma y tipo de cuenta, nunca en
// su lugar.
function tieneMeta(creativo) {
    return creativo.resultados?.[0]?.tiene_meta === true;
}
// 'Sin clasificar' es POR DEFINICIÓN tiene_meta=false (ver
// EnriquecedorCostosMeta::detectarSinActividadReciente /
// EnriquecedorCostosTiktok::detectarEliminados -- solo se evalúan ahí los
// ads que YA vinieron sin match de costo) -- exigirle tieneMeta encima
// siempre da 0 resultados, así que para ese valor puntual el filtro de
// tieneMeta se omite (no tendría sentido pedir "verdadero" de algo que por
// diseño nunca lo es).
const creativosPorTipoCuenta = computed(() =>
    creativosPorPlataforma.value.filter((c) =>
        tipoCuentaDeCard(c) === tipoCuentaSeleccionado.value
        && (tipoCuentaSeleccionado.value === 'SIN_CLASIFICAR' || tieneMeta(c))),
);

// Formato (Imagen/Video/Catálogo) -- pedido explícito (2026-08-04): sale del
// token VID/MP/CARO/SP/IMG en el Ad name CRUDO (creativos.formato,
// ClasificadorNombres::buscarFormato), nunca del nombre corto que muestra
// la card/modal. 'SIN_CLASIFICAR' cuando el nombre no trae ninguno de esos
// tokens -- real, no todos los nombres los traen. Mismo criterio que
// tipoCuenta: cuenta sobre el nivel anterior (tipoCuenta), se aplica junto,
// nunca en su lugar.
const FORMATO_OPCIONES = [
    { key: 'TODOS', label: 'Todos' },
    { key: 'IMAGEN', label: 'Imagen' },
    { key: 'VIDEO', label: 'Video' },
    { key: 'CATALOGO', label: 'Catálogo' },
    { key: 'SIN_CLASIFICAR', label: 'Sin clasificar' },
];
function formatoDeCard(creativo) {
    return creativo.formato || 'SIN_CLASIFICAR';
}
const formatoSeleccionado = ref('TODOS');
const formatoCounts = computed(() => {
    const counts = { TODOS: creativosPorTipoCuenta.value.length, IMAGEN: 0, VIDEO: 0, CATALOGO: 0, SIN_CLASIFICAR: 0 };
    for (const c of creativosPorTipoCuenta.value) counts[formatoDeCard(c)]++;
    return counts;
});
const creativosPorFormato = computed(() =>
    formatoSeleccionado.value === 'TODOS'
        ? creativosPorTipoCuenta.value
        : creativosPorTipoCuenta.value.filter((c) => formatoDeCard(c) === formatoSeleccionado.value),
);

const metricaKey = ref('nc');
const metricaActual = computed(() => METRICAS_RANKING[metricaKey.value]);
const funnelSeleccionado = ref('TODOS');

// Al entrar a una pestaña de funnel específica, "Rankear por" se sincroniza
// a la métrica natural de esa etapa -- pedido explícito (2026-08-21): antes
// "Rankear por" se quedaba en lo último elegido manualmente (default "Más
// nuevos clientes"), así que entrar a Awareness o Consideración seguía
// mostrando NC en la card aunque casi nunca aplica ahí. El usuario sigue
// pudiendo cambiar "Rankear por" a mano DESPUÉS de entrar a la pestaña (esto
// solo fija un default sensato al cambiar de pestaña, no lo bloquea). Al
// volver a "TODOS" se deja la selección tal cual quedó -- ahí sí tiene
// sentido rankear por una sola métrica elegida a propósito entre funnels
// mezclados.
const METRICA_POR_FUNNEL_DEFAULT = { AWA: 'impresiones', CON: 'installs', CONS: 'installs', CNV: 'nc', LOY: 'orders' };
watch(funnelSeleccionado, (nuevo) => {
    if (METRICA_POR_FUNNEL_DEFAULT[nuevo]) {
        metricaKey.value = METRICA_POR_FUNNEL_DEFAULT[nuevo];
    }
});

const CAMPO_VOLUMEN_LABEL = {
    impressions: 'impresiones', installs: 'installs', newCustomers: 'nuevos clientes', orders: 'órdenes',
};
// Texto del piso general de volumen para la nota debajo de los chips -- con
// un funnel específico seleccionado, muestra SU piso exacto; con "TODOS"
// (funnels mezclados) no hay un solo número que describir, cada card se
// juzga con el de su propia etapa (ver calcularRanking).
const pisoFunnelTexto = computed(() => {
    if (funnelSeleccionado.value === 'TODOS') {
        return 'el piso de su propia etapa (impresiones en Awareness, installs en Consideración, nuevos clientes en Conversión, órdenes en Loyalty)';
    }
    const piso = UMBRAL_VOLUMEN_POR_FUNNEL[funnelSeleccionado.value];
    return piso
        ? `${piso.umbral} ${CAMPO_VOLUMEN_LABEL[piso.campo]}`
        : `${UMBRAL_INSTALLS_RANKING} installs`;
});
// El piso propio del ratio (metricaActual.umbralDefault) solo aplica de
// verdad cuando el funnel seleccionado es justo el que usa ese mismo campo
// como piso natural (CAC/CNV, CPO/LOY, CPI/CONS) -- con "TODOS" siempre se
// menciona (aplica a ALGUNAS cards de la mezcla), con otro funnel específico
// no aplica y mencionarlo sería engañoso (ver calcularRanking).
const pisoRatioAplica = computed(() => {
    if (funnelSeleccionado.value === 'TODOS') return true;
    const piso = UMBRAL_VOLUMEN_POR_FUNNEL[funnelSeleccionado.value];
    return !piso || piso.campo === metricaActual.value.campoVolumen;
});

function calcularRanking(cards, key) {
    const metrica = METRICAS_RANKING[key];
    if (!metrica) return { elegibles: [], excluidosPorVolumen: 0 };

    let candidatos = cards.filter((c) => valorCampo(c, metrica.campo) !== null && !sinActividadReal(c));
    let excluidosPorVolumen = 0;

    // Piso general de volumen -- por CARD, no por selección de funnel (con
    // "TODOS" los funnels mezclados en un mismo ranking, cada card se juzga
    // con el piso que corresponde a SU etapa, nunca con el de otra).
    const antesPiso = candidatos.length;
    candidatos = candidatos.filter((c) => {
        const piso = UMBRAL_VOLUMEN_POR_FUNNEL[c.funnel];
        return piso
            ? (valorCampo(c, piso.campo) || 0) >= piso.umbral
            : (valorCampo(c, 'installs') || 0) >= UMBRAL_INSTALLS_RANKING;
    });
    excluidosPorVolumen += antesPiso - candidatos.length;

    if (metrica.tipo === 'ratio') {
        const antes = candidatos.length;
        candidatos = candidatos.filter((c) => {
            // El piso propio del ratio (ej. CAC exige newCustomers >= 10)
            // solo tiene sentido cuando ES el mismo campo que el piso
            // general de la etapa de esa card (CAC natural de CNV, CPO de
            // LOY, CPI de CONS) -- mismo bug que el piso general: exigirle
            // 10 nuevos clientes a un Awareness (que casi nunca junta eso,
            // no es su objetivo) dejaba el Top 3 casi vacío aunque el ad
            // tuviera millones de impresiones reales. Si la etapa de la
            // card usa OTRO campo como piso natural, el piso general ya
            // alcanza -- no se le suma esta exigencia extra.
            const pisoCard = UMBRAL_VOLUMEN_POR_FUNNEL[c.funnel];
            if (pisoCard && pisoCard.campo !== metrica.campoVolumen) return true;
            return (valorCampo(c, metrica.campoVolumen) || 0) >= metrica.umbralDefault;
        });
        excluidosPorVolumen = antes - candidatos.length;
    }

    candidatos = [...candidatos].sort((a, b) => {
        const va = valorCampo(a, metrica.campo);
        const vb = valorCampo(b, metrica.campo);
        return metrica.orden === 'asc' ? va - vb : vb - va;
    });

    return { elegibles: candidatos, excluidosPorVolumen };
}

function estrellaActual(creativo) {
    const m = metricaActual.value;
    return {
        label: ETIQUETA_CORTA_POR_CAMPO[m.campo] || m.campo,
        value: formatearValorMetrica(m.campo, valorCampo(creativo, m.campo)),
    };
}

const creativosFiltrados = computed(() =>
    funnelSeleccionado.value === 'TODOS'
        ? creativosPorFormato.value
        : creativosPorFormato.value.filter((c) => c.funnel === funnelSeleccionado.value),
);

const funnelsPresentes = computed(() => {
    const presentes = [...new Set(creativosPorFormato.value.map((c) => c.funnel).filter(Boolean))];
    return [
        ...FUNNEL_ORDER.filter((f) => presentes.includes(f)),
        ...presentes.filter((f) => !FUNNEL_ORDER.includes(f)),
    ];
});

const ranking = computed(() => calcularRanking(creativosFiltrados.value, metricaKey.value));

// N (3/5) -- selector compartido por el podio único (TODOS) y los bloques
// por etapa.
const N_OPCIONES = [3, 5];
const nPodio = ref(3);

// Podio único (funnelSeleccionado === 'TODOS') -- vuelve al comportamiento
// original (2026-09-22, pedido explícito): un solo ranking mezclando
// funnels, rankeado por "Rankear por" (sí aplica acá, a propósito -- con
// funnels mezclados no hay una sola métrica "natural"), con flechas para
// alternar Mejores/A mejorar en vez de mostrarlos simultáneos. El bloque
// por etapa (abajo) es solo para cuando se elige UNA etapa puntual.
const vistaTop3 = ref('mejores');
function alternarVistaTop3() {
    vistaTop3.value = vistaTop3.value === 'mejores' ? 'peores' : 'mejores';
}
const topUnico = computed(() =>
    vistaTop3.value === 'mejores'
        ? ranking.value.elegibles.slice(0, nPodio.value)
        : [...ranking.value.elegibles].reverse().slice(0, nPodio.value),
);

// Podio por etapa (spec Adenda B) -- un bloque por etapa, con TOP-N y
// WORST-N simultáneos (verde/rojo). La métrica de volumen NO es elegible
// acá (a propósito: "el costo no ordena el podio") -- se fija a
// METRICA_POR_FUNNEL_DEFAULT de esa etapa. Solo corre para la etapa elegida
// por chip (2026-09-22: antes corría para TODAS sin importar el chip
// elegido) -- con "TODOS" no hay ningún bloque acá, se usa el podio único
// de arriba.
const podiosPorEtapa = computed(() => {
    if (funnelSeleccionado.value === 'TODOS') return [];
    return funnelsPresentes.value
        .filter((etapa) => etapa === funnelSeleccionado.value)
        .map((etapa) => {
            const cardsEtapa = creativosPorFormato.value.filter((c) => c.funnel === etapa);
            const metricaKeyEtapa = METRICA_POR_FUNNEL_DEFAULT[etapa] || 'nc';
            const metricaEtapa = METRICAS_RANKING[metricaKeyEtapa];
            const { elegibles, excluidosPorVolumen } = calcularRanking(cardsEtapa, metricaKeyEtapa);
            function estrellaEtapa(creativo) {
                return {
                    label: ETIQUETA_CORTA_POR_CAMPO[metricaEtapa.campo] || metricaEtapa.campo,
                    value: formatearValorMetrica(metricaEtapa.campo, valorCampo(creativo, metricaEtapa.campo)),
                };
            }
            return {
                etapa,
                label: etiquetaFunnel(etapa) || etapa,
                excluidosPorVolumen,
                estrellaEtapa,
                top: elegibles.slice(0, nPodio.value),
                worst: [...elegibles].reverse().slice(0, nPodio.value),
            };
        });
});

// Vista general: mismo criterio que creativos.html -- TODAS las cards del
// filtro actual, sin piso de volumen (el piso es para no ensuciar el Top 3,
// no para esconder inventario); las que no tienen valor para la métrica van
// al final sin pretender un orden que no existe. sinActividadReal SÍ se
// excluye acá (2026-09-22) -- un creativo con costo/impresiones/clicks/
// installs los 4 en 0 no es "inventario sin métrica todavía", es basura de
// una importación vieja (ver limpieza 2026-09-22), nunca un caso real.
const todasOrdenadas = computed(() => {
    const conActividad = creativosFiltrados.value.filter((c) => !sinActividadReal(c));
    const generalSinPiso = conActividad.filter(
        (c) => valorCampo(c, metricaActual.value.campo) !== null,
    );
    const idsEnGeneral = new Set(generalSinPiso.map((c) => c.id));
    const sinMetrica = conActividad.filter((c) => !idsEnGeneral.has(c.id));
    return [...generalSinPiso.sort((a, b) => {
        const va = valorCampo(a, metricaActual.value.campo);
        const vb = valorCampo(b, metricaActual.value.campo);
        return metricaActual.value.orden === 'asc' ? va - vb : vb - va;
    }), ...sinMetrica];
});

// Buscador (2026-11-13, pedido explícito): filtra sobre los mismos
// creativos de la vista (respeta filtros y etapa). Mientras hay texto se
// ocultan el Top y el carrusel: los resultados se ven en cuadrícula.
// Sin distinguir mayúsculas ni acentos.
const busqueda = ref('');
const sinAcentos = (t) => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const buscando = computed(() => busqueda.value.trim() !== '');
const resultadosBusqueda = computed(() => {
    const q = sinAcentos(busqueda.value.trim());
    if (!q) return todasOrdenadas.value;
    return todasOrdenadas.value.filter((c) =>
        [c.nombre_amigable, c.nombre_comun, c.nombre_completo, c.nombre_campania, c.ad_id, ...(c.adIdsMostrables || [])]
            .some((v) => sinAcentos(v).includes(q)),
    );
});
const creativosVista = computed(() => (buscando.value ? resultadosBusqueda.value : todasOrdenadas.value));

// Tipos de gráfica para "Todos los creativos" (2026-08-27, ver plan del
// rediseño) -- Cards/Tabla/Barras sobre el MISMO dataset (todasOrdenadas),
// solo cambia el componente que lo pinta.
const vistaActiva = ref('cards');

// Click en card -> modal (wireCardClicks real, ver CreativeCard.vue) --
// guarda el creativo completo, no solo lo que ya mostraba la card.
const creativoAbierto = ref(null);
function abrirDetalle(creativo) {
    creativoAbierto.value = creativo;
}
function cerrarModal() {
    creativoAbierto.value = null;
}

// promediosParaCreativo -- 2026-08-21, pedido explícito del negocio;
// extraído a motor.js (promediosDeGrupo) el 2026-08-27 para compartirlo
// con Inteligencia -- ver el comentario de esa función para el criterio
// completo. País/mes ya están implícitos acá (la página completa está
// acotada a uno).
function promediosParaCreativo(creativo) {
    return promediosDeGrupo(creativo, props.creativos, props.mes);
}
const promediosDelAbierto = computed(() => promediosParaCreativo(creativoAbierto.value));
function onKeydownGlobal(e) {
    if (e.key === 'Escape' && !alerta.value) cerrarModal();
}
onMounted(() => document.addEventListener('keydown', onKeydownGlobal));
onUnmounted(() => document.removeEventListener('keydown', onKeydownGlobal));
</script>

<template>
    <Head :title="`Análisis creativo — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="creativos">
    <div class="page">
        <div class="page-inner">
            <header class="header">
                <div>
                    <p class="eyebrow mono">{{ pais }}{{ plataforma ? ` · ${plataforma}` : '' }}</p>
                    <h1 class="title">Análisis creativo</h1>
                    <p class="subtitle">
                        {{ creativos.length }} creativo(s) cargados
                        <template v-if="ultimaActualizacionTexto">
                            · <span class="ultima-actualizacion">Última actualización: {{ ultimaActualizacionTexto }} <span class="mono">(hora CDMX)</span></span>
                        </template>
                    </p>
                </div>
            </header>

            <div class="resumen-filtros">
                <div class="periodo">
                    <span class="periodo-label mono">
                        Periodo
                        <InfoTip etiqueta="Sobre los datos por periodo">
                            Los datos por mes, semana y día pueden variar: AppsFlyer sigue atribuyendo conversiones varios
                            días después, y la venta real por día es un reparto proporcional del total del periodo importado.
                        </InfoTip>
                    </span>
                    <div class="periodo-toggle" role="group" aria-label="Periodo">
                        <button type="button" :class="{ activo: !usandoRango }" @click="usandoRango && volverAMes()">Por mes</button>
                        <button
                            type="button"
                            :class="{ activo: usandoRango }"
                            :disabled="!tieneDatosDiarios"
                            :title="tieneDatosDiarios ? '' : 'Este país todavía no tiene datos por día: vuelve a importar el mes para cargarlos.'"
                            @click="verPorDia"
                        >
                            Por día
                        </button>
                    </div>
                </div>
                <FiltroDropdown v-if="!usandoRango" label="Mes" :modelValue="mes" :options="mesOptions" @update:modelValue="cambiarMes" />
                <div v-else class="periodo">
                    <span class="periodo-label mono">Días</span>
                    <div class="rango-control">
                        <input v-model="rangoDesde" type="date" aria-label="Desde" :min="rangoDiarioMin" :max="rangoDiarioMax" @change="aplicarRango" />
                        <span class="rango-flecha">→</span>
                        <input v-model="rangoHasta" type="date" aria-label="Hasta" :min="rangoDiarioMin" :max="rangoDiarioMax" @change="aplicarRango" />
                        <button v-for="a in ATAJOS_DIA" :key="a.key" type="button" class="chip" :class="{ activo: atajoActivo(a) }" @click="aplicarAtajo(a)">{{ a.label }}</button>
                    </div>
                </div>
                <FiltroDropdown v-if="filtroTipoCampania.visible.value" label="Tipo de campaña" v-model="tipoCampaniaSeleccionado" :options="filtroTipoCampania.opciones.value" :counts="filtroTipoCampania.counts.value" />
                <FiltroDropdown label="Plataforma" v-model="plataformaSeleccionada" :options="PLATAFORMA_OPCIONES" :counts="plataformaCounts" />
                <FiltroDropdown :label="etiquetaFiltroTipoCuenta()" v-model="tipoCuentaSeleccionado" :options="opcionesTipoCuenta(pais)" :counts="tipoCuentaCounts" />
                <FiltroDropdown label="Formato" v-model="formatoSeleccionado" :options="FORMATO_OPCIONES" :counts="formatoCounts" />
                <!-- Pista propia de la nomenclatura de TaDa (BI, CNV/CON). -->
                <span v-if="usePage().props.cuentaActual?.slug === 'tada'" class="filtros-info">
                    <InfoTip etiqueta="Sobre Meta y TikTok en el ranking">
                        Acá sí se mezclan Meta y TikTok en el mismo ranking: útil justamente porque el mismo tipo de
                        campaña (ej. BI) usa segmentos de funnel distintos por plataforma (Meta: CNV, TikTok: CON → CONS).
                    </InfoTip>
                </span>
            </div>

            <div class="ranking-controls">
                <FiltroDropdown label="Rankear por" v-model="metricaKey" :options="METRICAS_RANKING_OPCIONES" />
                <div class="chip-group">
                    <button
                        type="button"
                        class="chip"
                        :class="{ activo: funnelSeleccionado === 'TODOS' }"
                        @click="funnelSeleccionado = 'TODOS'"
                    >
                        Todos
                    </button>
                    <button
                        v-for="f in funnelsPresentes"
                        :key="f"
                        type="button"
                        class="chip"
                        :class="{ activo: funnelSeleccionado === f }"
                        @click="funnelSeleccionado = f"
                    >
                        {{ etiquetaFunnel(f) || f }}
                    </button>
                </div>
                <div class="buscador-creativos">
                    <label for="buscadorCreativos" class="periodo-label mono">Buscar</label>
                    <div class="buscador-campo">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input id="buscadorCreativos" v-model="busqueda" type="search" placeholder="Nombre, Ad ID, campaña…" @keydown.esc.stop="busqueda = ''" />
                        <button v-if="buscando" type="button" class="buscador-limpiar" aria-label="Limpiar búsqueda" @click="busqueda = ''">×</button>
                    </div>
                </div>
                <span v-if="ranking.excluidosPorVolumen > 0 && !buscando" class="umbral-nota">
                    <InfoTip etiqueta="Mínimo de volumen aplicado" lado="izquierda">
                        Mínimo aplicado: {{ pisoFunnelTexto }}
                        <template v-if="metricaActual.tipo === 'ratio' && pisoRatioAplica">
                            · {{ metricaActual.umbralDefault }} {{ metricaActual.labelVolumen }} (para {{ metricaActual.label.toLowerCase() }})
                        </template>
                        · {{ ranking.excluidosPorVolumen }} anuncio(s) excluido(s) del Top 3 por bajo volumen.
                    </InfoTip>
                </span>
            </div>

            <section v-if="!buscando" class="section">
                <div class="section-header">
                    <h2 class="section-title">Análisis creativo{{ funnelSeleccionado === 'TODOS' ? '' : ` — ${etiquetaFunnel(funnelSeleccionado) || funnelSeleccionado}` }}</h2>
                    <div class="chip-group">
                        <button
                            v-for="n in N_OPCIONES"
                            :key="n"
                            type="button"
                            class="chip"
                            :class="{ activo: nPodio === n }"
                            @click="nPodio = n"
                        >
                            Top {{ n }}
                        </button>
                    </div>
                </div>

                <!-- TODOS: podio único (funnels mezclados, rankeado por "Rankear por"),
                     flechas para alternar Mejores/A mejorar -- comportamiento original. -->
                <template v-if="funnelSeleccionado === 'TODOS'">
                    <div class="top3-header">
                        <h3 class="etapa-titulo">{{ vistaTop3 === 'mejores' ? 'Mejores' : 'A mejorar' }}</h3>
                    </div>
                    <div class="top3-wrap">
                        <button
                            type="button"
                            class="top3-flecha top3-flecha-izq"
                            :title="vistaTop3 === 'mejores' ? 'Ver A mejorar' : 'Ver Mejores'"
                            @click="alternarVistaTop3"
                        >‹</button>
                        <div class="podio">
                            <PodiumTop3 v-if="topUnico.length" :top3="topUnico" :estrella-override="estrellaActual" :mes="mes" @abrir="abrirDetalle" />
                            <p v-else class="empty-note">Ningún anuncio cumple el umbral actual para esta métrica.</p>
                        </div>
                        <button
                            type="button"
                            class="top3-flecha top3-flecha-der"
                            :title="vistaTop3 === 'mejores' ? 'Ver A mejorar' : 'Ver Mejores'"
                            @click="alternarVistaTop3"
                        >›</button>
                    </div>
                </template>

                <!-- Etapa específica: mismo patrón de flechas que TODOS (2026-09-22,
                     pedido explícito -- antes mostraba Mejores + A mejorar
                     simultáneos, sin carrusel; ahora es consistente con TODOS). -->
                <template v-else>
                    <div v-for="p in podiosPorEtapa" :key="p.etapa" class="etapa-bloque">
                        <p v-if="p.excluidosPorVolumen > 0" class="umbral-nota">
                            {{ p.excluidosPorVolumen }} anuncio(s) excluido(s) por bajo volumen.
                        </p>
                        <div class="top3-header">
                            <h3 class="etapa-titulo">{{ vistaTop3 === 'mejores' ? 'Mejores' : 'A mejorar' }}</h3>
                        </div>
                        <div class="top3-wrap">
                            <button
                                type="button"
                                class="top3-flecha top3-flecha-izq"
                                :title="vistaTop3 === 'mejores' ? 'Ver A mejorar' : 'Ver Mejores'"
                                @click="alternarVistaTop3"
                            >‹</button>
                            <div class="podio">
                                <PodiumTop3
                                    v-if="(vistaTop3 === 'mejores' ? p.top : p.worst).length"
                                    :top3="vistaTop3 === 'mejores' ? p.top : p.worst"
                                    :estrella-override="p.estrellaEtapa"
                                    :mes="mes"
                                    @abrir="abrirDetalle"
                                />
                                <p v-else class="empty-note">Ningún anuncio cumple el umbral actual.</p>
                            </div>
                            <button
                                type="button"
                                class="top3-flecha top3-flecha-der"
                                :title="vistaTop3 === 'mejores' ? 'Ver A mejorar' : 'Ver Mejores'"
                                @click="alternarVistaTop3"
                            >›</button>
                        </div>
                    </div>
                </template>
            </section>

            <section class="section">
                <div class="section-header">
                    <div class="section-header-titulo">
                        <h2 class="section-title">{{ buscando ? 'Resultados de búsqueda' : 'Todos los creativos' }}</h2>
                        <span class="section-count mono">{{ creativosVista.length }} anuncio{{ creativosVista.length === 1 ? '' : 's' }}</span>
                    </div>
                    <VistaSwitch v-model="vistaActiva" />
                </div>
                <p v-if="buscando && !creativosVista.length" class="empty-note">Ningún creativo coincide con “{{ busqueda.trim() }}” con los filtros actuales.</p>
                <template v-else-if="vistaActiva === 'cards'">
                    <div v-if="buscando" class="busqueda-grid">
                        <CreativeCard
                            v-for="(c, i) in creativosVista"
                            :key="c.id"
                            :creativo="c"
                            :rank="i + 1"
                            :estrella-override="estrellaActual(c)"
                            :mes="mes"
                            @abrir="abrirDetalle"
                        />
                    </div>
                    <CreativeCarousel v-else :creativos="todasOrdenadas" :estrella-override="estrellaActual" :mes="mes" @abrir="abrirDetalle" />
                </template>
                <CreativeTable v-else-if="vistaActiva === 'tabla'" :creativos="creativosVista" :estrella-override="estrellaActual" @abrir="abrirDetalle" />
                <CreativeBars
                    v-else
                    :creativos="creativosVista"
                    :estrella-override="estrellaActual"
                    :valor-numerico="(c) => valorCampo(c, metricaActual.campo)"
                    @abrir="abrirDetalle"
                />
            </section>
        </div>
    </div>

    <CreativeModal
        v-if="creativoAbierto"
        :creativo="creativoAbierto"
        :pais="pais"
        :promedios="promediosDelAbierto"
        :mes="mes"
        @cerrar="cerrarModal"
    />
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
    margin-bottom: 28px;
}
.eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--accent-text);
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
}
.ranking-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 14px 16px;
    margin-bottom: 32px;
}
.ranking-field {
    display: flex;
    align-items: center;
    gap: 10px;
}
.ranking-field label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.ranking-field select {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
}
.chip-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.chip {
    padding: 6px 13px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text-muted);
    cursor: pointer;
}
.chip:hover {
    border-color: var(--amber);
    color: var(--text);
}
.chip.activo {
    background: var(--amber);
    border-color: var(--amber);
    color: var(--amber-ink);
}
.umbral-nota {
    display: inline-flex;
    align-items: center;
    align-self: center;
}
.section {
    margin-bottom: 40px;
}
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.section-header-titulo {
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.section-header-titulo .section-title {
    margin: 0;
}
.section-title {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 600;
    font-size: 16px;
    margin: 0 0 8px;
}
.section-count {
    font-size: 11px;
    color: var(--text-muted);
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
    width: 100%;
}
.etapa-bloque {
    margin-bottom: 32px;
}
.top3-header {
    margin-bottom: 8px;
}
.top3-wrap {
    position: relative;
}
.top3-flecha {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 5;
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border-radius: 999px;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 1.2rem;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color 0.15s ease, color 0.15s ease;
}
.top3-flecha-izq {
    left: -8px;
}
.top3-flecha-der {
    right: -8px;
}
.top3-flecha:hover {
    border-color: var(--amber);
    color: var(--accent-text);
}
.etapa-titulo {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 600;
    font-size: 14px;
    text-align: center;
    margin: 0 0 4px;
}
.rango-control {
    display: flex;
    align-items: center;
    gap: 8px;
}
.periodo {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.periodo-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
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
.periodo-toggle button:hover:not(:disabled) {
    color: var(--text);
}
.periodo-toggle button.activo {
    background: var(--amber);
    color: var(--amber-ink);
    font-weight: 600;
}
.periodo-toggle button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.periodo-toggle button:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.rango-control {
    flex-wrap: wrap;
}
.rango-flecha {
    color: var(--text-faint);
}
.periodo-label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.filtros-info {
    align-self: flex-end;
    padding-bottom: 10px;
}
.ultima-actualizacion .mono {
    font-size: 0.85em;
    color: var(--text-faint);
}
.rango-control input[type='date'] {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
}
.buscador-creativos {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1 1 240px;
    max-width: 360px;
    margin-left: auto;
}
.buscador-campo {
    position: relative;
    display: flex;
    align-items: center;
    color: var(--text-faint);
}
.buscador-campo svg {
    position: absolute;
    left: 12px;
    pointer-events: none;
}
.buscador-campo input {
    width: 100%;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 34px 10px 36px;
    font-size: 0.85rem;
    transition: border-color 0.15s ease;
}
.buscador-campo input::-webkit-search-cancel-button {
    display: none;
}
.buscador-campo input::placeholder {
    color: var(--text-faint);
}
.buscador-campo input:focus {
    outline: none;
    border-color: var(--amber);
}
.buscador-limpiar {
    position: absolute;
    right: 8px;
    width: 22px;
    height: 22px;
    border: none;
    border-radius: 999px;
    background: var(--surface-2);
    color: var(--text-muted);
    cursor: pointer;
    line-height: 1;
}
.buscador-limpiar:hover {
    color: var(--text);
}
.busqueda-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}
.resumen-filtros {
    align-items: flex-start;
    /* Los globos de InfoTip quedan por encima del panel de ranking. */
    position: relative;
    z-index: 5;
}
</style>
