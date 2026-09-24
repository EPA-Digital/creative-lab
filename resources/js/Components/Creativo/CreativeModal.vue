<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    cardDesdeCreativo, placeholderPorTipo, humanizarCampania,
    kpisPrincipales, etapasFunnel, generarLectura,
    calcularScoreDesglose, nombrePrincipalYTecnico, formatNumeroExacto, formatMoneyExacto, formatearRangoFecha,
    FUNNEL_LABELS, FUNNEL_COLOR_VAR, TIPO_CUENTA_OPCIONES, TIPO_CREATIVO_LABELS,
} from '@/motor';

// Rediseño 2026-08-21 (boceto del usuario, bocetos-modals.html 4A/4B) --
// dos vistas dentro del mismo modal ("Resumen"/"Etapas"), colores de
// funnel nuevos (ver FUNNEL_COLOR_VAR), promedios de grupo pasados desde
// AnalisisCreativo.vue (promediosParaCreativo) para las comparaciones de
// la vista Etapas y el bloque Lectura. A diferencia de la card (que
// compacta a K/M), acá todo se muestra exacto (formatNumeroExacto/
// formatMoneyExacto, vía kpisPrincipales/etapasFunnel) para poder auditar
// el dato.
const props = defineProps({
    creativo: { type: Object, required: true },
    pais: { type: String, default: null },
    // {cpm,ctr,cpi,cpo,cac} promedio del grupo (mismo país+plataforma+
    // funnel+mes) -- null si no hay otros creativos comparables todavía.
    promedios: { type: Object, default: null },
    // Mes elegido en AnalisisCreativo.vue (formato 'YYYY-MM') -- requerido
    // por la evaluación IA (2026-08-26): la caché de evaluarPorMetricas/
    // evaluarPorArte se guarda por (creativo_id, mes, modo), así que el
    // backend necesita saber qué mes se está viendo.
    mes: { type: String, default: null },
});

defineEmits(['cerrar']);

// "Evaluar por métricas"/"Evaluar por arte" son EPA-only en el backend
// (Gate 'epa', ver EvaluacionCreativoController/routes/web.php) -- un
// 'cliente' que los probara solo vería un 403, mejor no mostrárselos
// (pedido explícito 2026-09-24). Mismo criterio que DashboardLayout.vue.
const esEpa = computed(() => usePage().props.auth?.user?.rol !== 'cliente');

const card = computed(() => cardDesdeCreativo(props.creativo));
const esTikTok = computed(() => card.value.plataforma === 'tiktok');
const ph = computed(() => placeholderPorTipo(card.value.tipoCreativo));
const stageColor = computed(() => FUNNEL_COLOR_VAR[card.value.etapaFunnel] || 'var(--text-faint)');

// "País · tipo de cuenta" del header -- pais llega como slug simple
// (mexico/ecuador), no hay tabla de nombres bonitos en el proyecto todavía,
// alcanza con capitalizar. tipo_cuenta vive en el creativo crudo (no en
// `card`, cardDesdeCreativo no lo mapea porque hasta ahora nada lo
// necesitaba del lado del modal).
const paisLabel = computed(() => (props.pais ? props.pais.charAt(0).toUpperCase() + props.pais.slice(1) : ''));
const tipoCuentaLabel = computed(() => {
    const key = props.creativo.tipo_cuenta || 'SIN_CLASIFICAR';
    return TIPO_CUENTA_OPCIONES.find((o) => o.key === key)?.label || 'Sin clasificar';
});

// Toggle simple Resumen/Etapas -- se probaron flechitas grandes en los
// bordes del modal para pasar de vista (2026-08-21) pero el usuario pidió
// sacarlas ("se ve fea"), vuelve a quedar solo el par de botones directo.
const VISTAS = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'etapas', label: 'Etapas' },
];
const vista = ref('resumen');

// TikTok: un arte agrupado trae hasta 2 ad_id reales (uno por campaña) -- se
// listan los dos, nunca se colapsa a uno solo en silencio. Meta/TikTok sin
// match de API caen a card.adId tal cual.
const adIdMostrado = computed(() => {
    const c = card.value;
    if (Array.isArray(c.adIdsMostrables) && c.adIdsMostrables.length) {
        return c.adIdsMostrables.length > 1
            ? `${c.adIdsMostrables.join(', ')} (${c.adIdsMostrables.length} anuncios)`
            : c.adIdsMostrables[0];
    }
    if (c.adIdMostrable) return c.adIdMostrable;
    return c.adId;
});

const copiado = ref(false);
async function copiarAdId() {
    try {
        await navigator.clipboard.writeText(String(adIdMostrado.value));
        copiado.value = true;
        setTimeout(() => { copiado.value = false; }, 1500);
    } catch {
        // Portapapeles no disponible (permiso/contexto no seguro) -- no
        // rompe el modal, el Ad ID sigue visible para copiar a mano.
    }
}

// Taxonomía legible (2026-08-27, ver plan) -- lápiz inline junto al
// título, mismo patrón de axios+JSON que evaluar() más abajo (nunca
// Inertia form/redirect acá, es un dato chico). nombreAmigableLocal
// arranca en card.value.nombreAmigable pero vive aparte para poder
// actualizar el modal en caliente sin esperar un reload completo de la
// página -- el watch de creativo.id lo resetea al abrir otro creativo.
const nombreAmigableLocal = ref(card.value.nombreAmigable);
const editandoNombre = ref(false);
const nombreInput = ref('');
const guardandoNombre = ref(false);
const errorNombre = ref('');
const nombre = computed(() => nombrePrincipalYTecnico(nombreAmigableLocal.value, card.value.arte || card.value.adNameShort || card.value.adId));
function abrirEdicionNombre() {
    nombreInput.value = nombreAmigableLocal.value || '';
    errorNombre.value = '';
    editandoNombre.value = true;
}
function cancelarEdicionNombre() {
    editandoNombre.value = false;
}
async function guardarNombre() {
    const valor = nombreInput.value.trim();
    if (!valor || guardandoNombre.value) return;
    guardandoNombre.value = true;
    errorNombre.value = '';
    try {
        const { data } = await axios.post(`/pais/${props.pais}/creativos/${props.creativo.id}/nombre`, {
            nombre_corregido: valor,
        });
        nombreAmigableLocal.value = data.nombre_corregido;
        editandoNombre.value = false;
    } catch (e) {
        errorNombre.value = e.response?.data?.message || 'No se pudo guardar el nombre.';
    } finally {
        guardandoNombre.value = false;
    }
}

const kpis = computed(() => kpisPrincipales(card.value));
const etapas = computed(() => etapasFunnel(card.value, props.promedios));
const lectura = computed(() => generarLectura(card.value, props.promedios));
const campaniaHumanizada = computed(() => humanizarCampania(card.value.campaignName));

// funnelPasos -- 2026-08-28, pedido explícito: waterfall visual del embudo
// completo (Impresiones→Clicks→Instalaciones→Órdenes→Nuevos clientes)
// arriba de las tarjetas de Etapas, para ver el drop-off de un vistazo en
// vez de leer 5 números sueltos. Usa los campos CRUDOS de `card` (no los
// de `etapas`, que ya vienen formateados a string) para poder calcular el
// ancho proporcional de cada barra. Un paso sin dato (null) se OMITE, no
// se muestra como 0 -- mismo criterio de "nunca inventar" que el resto del
// motor; con menos de 2 pasos reales no hay embudo que dibujar.
const FUNNEL_PASOS_DEF = [
    { key: 'impressions', label: 'Impresiones' },
    { key: 'clicks', label: 'Clicks' },
    { key: 'installs', label: 'Instalaciones' },
    { key: 'orders', label: 'Órdenes' },
    { key: 'newCustomers', label: 'Nuevos clientes' },
];
const funnelPasos = computed(() => {
    const c = card.value;
    const pasos = FUNNEL_PASOS_DEF
        .map((p) => ({ ...p, valor: c[p.key] }))
        .filter((p) => p.valor !== null);
    if (pasos.length < 2) return [];
    const base = pasos[0].valor;
    return pasos.map((p, i) => ({
        ...p,
        valorFmt: formatNumeroExacto(p.valor),
        // Mínimo visual de 4% -- una caída real a un valor chico (ej. 2
        // instalaciones sobre 50,000 impresiones) sigue siendo una barra
        // clickeable/legible, nunca una línea invisible de 0.1px.
        pct: base > 0 ? Math.max(4, Math.round((p.valor / base) * 100)) : 0,
        pctDelPrevio: i > 0 && pasos[i - 1].valor > 0 ? Math.round((p.valor / pasos[i - 1].valor) * 100) : null,
    }));
});

// Score de rendimiento -- anillo + desglose (2026-08-26, rediseño vía
// Claude Design: "Detalle de anuncio.dc.html"). Ver calcularScoreDesglose
// en motor.js para el criterio completo (4 categorías de 25 pts, nunca
// inventa un número si faltan datos). CIRCUNFERENCIA/dashoffset replican
// las matemáticas exactas del SVG del diseño importado (r=22).
const scoreDesglose = computed(() => calcularScoreDesglose(card.value, props.promedios));
const CIRCUNFERENCIA = 2 * Math.PI * 22;
// Rediseño 2026-09-24 (pedido explícito: el anillo chico con la
// explicación escondida detrás de un hover "no se ve muy bien") -- pasa
// a un panel horizontal siempre visible (ver .modal-score-rendimiento),
// con su propio anillo más grande (r=30, no reusa CIRCUNFERENCIA de
// arriba porque ese sigue siendo el de "Score de arte", que no cambió).
const CIRCUNFERENCIA_RENDIMIENTO = 2 * Math.PI * 30;
// Instalaciones vs. la media del grupo -- solo tiene sentido mostrarlo
// cuando CPI (costo por instalación) es una de las métricas relevantes
// de la etapa de este creativo (ver METRICAS_RELEVANTES_POR_FUNNEL en
// motor.js): ahí es donde "¿está peor por costo o por volumen?" es una
// pregunta real. Para Awareness/Conversión/Loyalty, instalaciones no es
// la métrica de volumen que importa, mostrarla ahí confundiría más de
// lo que aclara -- se deja pendiente para cuando se trabaje ese caso por
// nivel de funnel (pedido explícito).
const volumenComparado = computed(() => {
    if (!scoreDesglose.value?.categorias?.some((cat) => cat.clave === 'cpi')) return false;
    const instalaciones = card.value.installs;
    const media = props.promedios?.installs;
    return instalaciones !== null && instalaciones !== undefined && media !== null && media !== undefined;
});
function colorPorPuntaje(pct) {
    // pct: fracción 0-1 del máximo de esa categoría/score
    if (pct === null) return 'var(--text-faint)';
    if (pct >= 0.65) return 'var(--mint)';
    if (pct <= 0.35) return 'var(--coral)';
    return 'var(--yellow)';
}
// colorPorEtiqueta -- 2026-08-28: el color del anillo de rendimiento ahora
// sigue la MISMA etiqueta que se muestra (Malo/Regular/Bueno), nunca un
// umbral de score aparte que podría desalinearse del texto en un caso
// límite -- una sola fuente de verdad (nivel/etiqueta, ver
// calcularScoreDesglose) para lo que el número dice y lo que el color dice.
function colorPorEtiqueta(etiqueta) {
    if (etiqueta === 'Bueno') return 'var(--mint)';
    if (etiqueta === 'Malo') return 'var(--coral)';
    if (etiqueta === 'Regular') return 'var(--yellow)';
    return 'var(--text-faint)';
}
const scoreColor = computed(() => (scoreDesglose.value ? colorPorEtiqueta(scoreDesglose.value.etiqueta) : 'var(--text-faint)'));
const scoreDashoffset = computed(() =>
    scoreDesglose.value ? CIRCUNFERENCIA_RENDIMIENTO * (1 - scoreDesglose.value.score / 100) : CIRCUNFERENCIA_RENDIMIENTO,
);
function catColor(cat) {
    return colorPorPuntaje(cat.puntos === null ? null : cat.puntos / cat.max);
}

function dotColor(comparacion) {
    if (!comparacion) return 'var(--text-faint)';
    if (comparacion.tagVariant === 'positive') return 'var(--mint)';
    if (comparacion.tagVariant === 'negative') return 'var(--coral)';
    return 'var(--yellow)';
}

// Copy: creativo.copy es un objeto único {titulo, texto} persistido desde
// la API (Meta: creative.body/title; TikTok: ad_text, vacío para los ads
// Smart+ automatizados -- ver motor.js/cardDesdeCreativo).
const copyBodies = computed(() => card.value.copyBodies || []);
const copyTitles = computed(() => card.value.copyTitles || []);
const hayCopy = computed(() => copyBodies.value.length || copyTitles.value.length);
const multiVariante = computed(() => copyBodies.value.length > 1);
const rotacionLabel = computed(() => (esTikTok.value ? 'TikTok Smart+ las rota' : 'Meta las rota dinámicamente'));

// tieneMeta/tieneAppsFlyer: el esquema Laravel no distingue todavía de qué
// export vino cada match (siempre null) -- se guarda contra `=== false`
// explícito en vez de `!card.tieneMeta`, para no mostrar "sin contraparte"
// como si lo supiéramos cuando en realidad no tenemos ese dato.
const faltaMeta = computed(() => card.value.tieneMeta === false);
const faltaAppsFlyer = computed(() => card.value.tieneAppsFlyer === false);

// Desglose por campaña (2026-09-17, consolidación por arte+etapa, spec
// Adenda A: "al abrir una fila, desglose por campaña individual mostrando
// el TOTAL del arte junto a sus partes"). esGrupoArte es true para
// CUALQUIER arte clasificado, incluso con un solo ad_id (ver
// VentaRealYAgrupacion::agruparPorArteYFunnel) -- el desglose solo aporta
// algo cuando hay 2+ miembros, mostrarlo con 1 solo sería un duplicado
// exacto del total de arriba.
const miembrosDesglose = computed(() => (card.value.miembros?.length > 1 ? card.value.miembros : []));
// Volumen del desglose usa el MISMO campo que la etapa del arte (no siempre
// newCustomers) -- un miembro de Awareness no tiene newCustomers, mostrar
// eso ahí daría "0" siempre en vez del dato real (impresiones).
const CAMPO_VOLUMEN_POR_FUNNEL_MODAL = { AWA: 'impressions', CON: 'installs', CONS: 'installs', CNV: 'newCustomers', LOY: 'orders' };
const labelVolumenMiembros = computed(() => {
    const campo = CAMPO_VOLUMEN_POR_FUNNEL_MODAL[card.value.etapaFunnel];
    return { impressions: 'Impresiones', installs: 'Installs', newCustomers: 'NC', orders: 'Órdenes' }[campo] || 'Volumen';
});
function volumenMiembro(m) {
    const campo = CAMPO_VOLUMEN_POR_FUNNEL_MODAL[card.value.etapaFunnel] || 'installs';
    return m[campo] ?? null;
}

// Evaluación IA on-demand (2026-08-26, ver plan) -- botones "Evaluar por
// métricas"/"Evaluar por arte". El backend (EvaluacionCreativoController)
// ya revisa caché antes de llamar a Anthropic, así que acá no hace falta
// ninguna lógica de caché propia: un click repetido para el mismo
// creativo+mes+modo es igual de barato que el primero. Requiere
// ANTHROPIC_API_KEY configurada en el backend -- sin eso, errorEvaluacion
// muestra el mensaje real del backend en vez de fallar en silencio.
const cargandoMetricas = ref(false);
const cargandoArte = ref(false);
const evaluacionMetricas = ref(null);
const evaluacionArte = ref(null);
const errorEvaluacion = ref(null);
// Score de arte -- 2026-08-26, unificado con "Evaluar por arte" (pedido
// explícito: un solo click, un solo llamado a Claude, llena a la vez el
// anillo del header Y el panel de texto). Estructura análoga a
// scoreDesglose (categorías Color/Composición/Texto en pantalla/Gancho,
// cada una sobre 25 pts) pero viene YA calculada por el backend (juicio de
// Claude, no una fórmula local) -- ver EvaluadorCreativoService::promptArte.
// Sigue null hasta el primer click exitoso; no se auto-consulta la caché al
// abrir el modal a propósito (evita una llamada de red extra por creativo
// abierto solo para chequear si ya existe evaluación).
const scoreArte = ref(null);
const desgloseArte = ref(null);
const CATEGORIAS_ARTE_LABEL = { color: 'Color', composicion: 'Composición', texto: 'Texto en pantalla', gancho: 'Gancho visual' };
const categoriasArte = computed(() => {
    if (!desgloseArte.value) return [];
    return Object.entries(desgloseArte.value).map(([clave, puntos]) => ({ clave, label: CATEGORIAS_ARTE_LABEL[clave] || clave, puntos, max: 25 }));
});
const scoreArteTipAbierto = ref(false);
const scoreArteColor = computed(() => colorPorPuntaje(scoreArte.value === null ? null : scoreArte.value / 100));
const scoreArteDashoffset = computed(() =>
    scoreArte.value === null ? CIRCUNFERENCIA : CIRCUNFERENCIA * (1 - scoreArte.value / 100),
);

async function evaluar(modo) {
    const cargando = modo === 'metricas' ? cargandoMetricas : cargandoArte;
    cargando.value = true;
    errorEvaluacion.value = null;
    try {
        const { data } = await axios.post(`/pais/${props.pais}/creativos/${props.creativo.id}/evaluar`, {
            modo,
            mes: props.mes,
        });
        if (modo === 'metricas') {
            evaluacionMetricas.value = data.resultado;
        } else {
            evaluacionArte.value = data.resultado;
            scoreArte.value = data.score ?? null;
            desgloseArte.value = data.desglose ?? null;
        }
    } catch (e) {
        errorEvaluacion.value = e.response?.data?.message || 'No se pudo evaluar este creativo.';
    } finally {
        cargando.value = false;
    }
}

// Al cambiar de creativo abierto (sin cerrar el modal) se limpia el estado
// de la evaluación anterior -- de otro modo se vería por un instante el
// texto IA del creativo previo antes de que el usuario vuelva a pedirlo.
watch(() => props.creativo?.id, () => {
    evaluacionMetricas.value = null;
    evaluacionArte.value = null;
    errorEvaluacion.value = null;
    scoreArte.value = null;
    desgloseArte.value = null;
    nombreAmigableLocal.value = card.value.nombreAmigable;
    editandoNombre.value = false;
});
</script>

<template>
    <div class="modal-overlay" @click="$event.target === $event.currentTarget && $emit('cerrar')">
        <div class="modal modal-v2">
            <button class="modal-close" type="button" aria-label="Cerrar" @click="$emit('cerrar')">✕</button>

            <div class="modal-image-wrap">
                <template v-if="card.tipoCreativo === 'VIDEO' && card.videoUrl">
                    <div class="no-image" :style="card.imageUrl ? { display: 'none' } : {}">
                        <span class="glyph">{{ ph.glyph }}</span>{{ ph.texto }}
                    </div>
                    <img v-if="card.imageUrl" :src="card.imageUrl" alt="" />
                    <a class="video-play-overlay" :href="card.videoUrl" target="_blank" rel="noopener noreferrer">
                        <span class="video-play-circle">▶</span>
                        <span class="video-play-label">Ver video en Facebook</span>
                    </a>
                </template>
                <template v-else>
                    <div class="no-image" :style="card.imageUrl ? { display: 'none' } : {}">
                        <span class="glyph">{{ ph.glyph }}</span>{{ ph.texto }}
                    </div>
                    <img v-if="card.imageUrl" :src="card.imageUrl" alt="" />
                </template>
            </div>

            <div class="modal-body">
                <div class="modal-header">
                    <div class="modal-header-info">
                        <span class="modal-eyebrow">{{ paisLabel }}{{ paisLabel ? ' · ' : '' }}{{ tipoCuentaLabel }}</span>
                        <div class="modal-title-row">
                            <template v-if="!editandoNombre">
                                <h2>{{ nombre.principal }}</h2>
                                <button v-if="esEpa" type="button" class="modal-nombre-editar" title="Renombrar" @click="abrirEdicionNombre">✎</button>
                            </template>
                            <div v-else class="modal-nombre-edicion">
                                <input
                                    v-model="nombreInput"
                                    type="text"
                                    class="modal-nombre-input"
                                    placeholder="Nombre amigable, ej. Burger Show"
                                    :disabled="guardandoNombre"
                                    @keyup.enter="guardarNombre"
                                    @keyup.escape="cancelarEdicionNombre"
                                />
                                <button type="button" class="modal-copy-btn" :disabled="guardandoNombre || !nombreInput.trim()" @click="guardarNombre">
                                    {{ guardandoNombre ? 'Guardando…' : 'Guardar' }}
                                </button>
                                <button type="button" class="modal-copy-btn" :disabled="guardandoNombre" @click="cancelarEdicionNombre">Cancelar</button>
                            </div>
                            <span class="modal-funnel-tag" :style="{ '--stage-color': stageColor }">
                                {{ (FUNNEL_LABELS[card.etapaFunnel] || 'Sin clasificar').toUpperCase() }}
                            </span>
                        </div>
                        <span v-if="nombre.tecnico" class="modal-nombre-tecnico mono">{{ nombre.tecnico }}</span>
                        <p v-if="errorNombre" class="modal-copy-note" style="margin: 0;">{{ errorNombre }}</p>
                    </div>

                    <div class="modal-scores">
                        <div v-if="scoreArte !== null" class="modal-score-ring-block">
                            <span class="modal-score-ring-caption">Score de arte</span>
                            <div
                                class="modal-score-ring-wrap"
                                tabindex="0"
                                @mouseenter="scoreArteTipAbierto = true"
                                @mouseleave="scoreArteTipAbierto = false"
                                @focus="scoreArteTipAbierto = true"
                                @blur="scoreArteTipAbierto = false"
                            >
                                <svg width="52" height="52" viewBox="0 0 52 52" class="modal-score-ring">
                                    <circle cx="26" cy="26" r="22" class="modal-score-ring-track" />
                                    <circle
                                        cx="26" cy="26" r="22" class="modal-score-ring-fill"
                                        :style="{ '--score-color': scoreArteColor, strokeDasharray: CIRCUNFERENCIA, strokeDashoffset: scoreArteDashoffset }"
                                    />
                                </svg>
                                <span class="modal-score-ring-value mono">{{ scoreArte }}</span>
                                <span class="modal-score-ring-help" aria-hidden="true">?</span>
                                <div v-if="scoreArteTipAbierto" class="modal-score-tooltip" role="tooltip">
                                    <div class="modal-score-tooltip-head">
                                        <span>Score de arte</span>
                                        <span class="mono">{{ scoreArte }} / 100</span>
                                    </div>
                                    <p>Lectura visual generada por IA a partir del creativo (colores, composición, texto en pantalla, gancho).</p>
                                    <div class="modal-score-tooltip-grid">
                                        <template v-for="cat in categoriasArte" :key="cat.clave">
                                            <span>{{ cat.label }}</span>
                                            <span class="modal-score-tooltip-bar"><span :style="{ width: (cat.puntos / cat.max) * 100 + '%', background: catColor(cat) }" /></span>
                                            <span class="mono">{{ cat.puntos }}/{{ cat.max }}</span>
                                        </template>
                                    </div>
                                    <p class="modal-score-tooltip-foot">Generado por Claude a partir de la imagen del creativo.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-content">
                    <div v-if="scoreDesglose" class="modal-score-rendimiento" :style="{ '--score-color': scoreColor }">
                        <div class="modal-score-rendimiento-ring">
                            <svg width="72" height="72" viewBox="0 0 72 72" class="modal-score-ring">
                                <circle cx="36" cy="36" r="30" class="modal-score-ring-track" />
                                <circle
                                    cx="36" cy="36" r="30" class="modal-score-ring-fill"
                                    :style="{ strokeDasharray: CIRCUNFERENCIA_RENDIMIENTO, strokeDashoffset: scoreDashoffset }"
                                />
                            </svg>
                            <span class="modal-score-rendimiento-value mono">{{ scoreDesglose.nivel }}<span class="modal-score-ring-value-max">/5</span></span>
                        </div>
                        <div class="modal-score-rendimiento-texto">
                            <div class="modal-score-rendimiento-head">
                                <span class="modal-score-rendimiento-caption">Score de rendimiento</span>
                                <span class="modal-score-rendimiento-etiqueta mono" :style="{ color: scoreColor }">{{ scoreDesglose.etiqueta }}</span>
                            </div>
                            <p v-if="scoreDesglose.explicacion" class="modal-score-rendimiento-explicacion">{{ scoreDesglose.explicacion }}</p>
                            <p v-else class="modal-score-rendimiento-explicacion sin-dato">
                                Compara {{ FUNNEL_LABELS[card.etapaFunnel] || 'este creativo' }} contra la media de su grupo ({{ paisLabel }} · {{ tipoCuentaLabel }}) -- todavía sin un cuello de botella claro.
                            </p>
                            <p v-if="volumenComparado" class="modal-score-rendimiento-volumen mono">
                                {{ formatNumeroExacto(card.installs) }} instalaciones · media del grupo: {{ formatNumeroExacto(promedios.installs) }}
                            </p>
                        </div>
                    </div>

                    <div class="modal-kpis">
                        <div v-for="k in kpis" :key="k.label" class="modal-kpi" :class="{ accent: k.accent }">
                            <span class="modal-kpi-label">{{ k.label }}</span>
                            <span class="modal-kpi-value mono">{{ k.value }}</span>
                            <span v-if="k.sub" class="modal-kpi-sub">{{ k.sub }}</span>
                        </div>
                    </div>

                    <div class="modal-view-dots">
                        <button
                            v-for="v in VISTAS"
                            :key="v.key"
                            type="button"
                            class="modal-view-dot"
                            :class="{ active: v.key === vista }"
                            @click="vista = v.key"
                        >{{ v.label }}</button>
                    </div>

                    <template v-if="vista === 'resumen'">
                        <div class="modal-anuncio">
                            <div class="modal-anuncio-grid">
                                <span class="k">Nombre común</span>
                                <span class="v">{{ campaniaHumanizada || 'Sin campaña asociada' }}</span>

                                <span class="k">Campaña</span>
                                <span class="v">{{ card.campaignName || 'Sin campaña asociada' }}</span>

                                <span class="k">Ad ID</span>
                                <span class="v modal-adid">
                                    <span class="mono">{{ adIdMostrado }}</span>
                                    <button type="button" class="modal-copy-btn" @click="copiarAdId">{{ copiado ? 'Copiado' : 'Copiar' }}</button>
                                </span>

                                <span class="k">Tipo creativo</span>
                                <span class="v">{{ TIPO_CREATIVO_LABELS[card.tipoCreativo] || card.tipoCreativo || 'Sin dato en el export' }}</span>

                                <template v-if="card.rangosActividad?.length">
                                    <span class="k">Fechas activas</span>
                                    <span class="v modal-vigencia">
                                        <span class="modal-vigencia-tramo">{{ formatearRangoFecha(card.rangosActividad[0]) }}</span>
                                        <span class="modal-vigencia-nota">Aproximado: primer día con impresiones o costo real hasta el último -- no es el registro exacto de encendido/apagado de Meta.</span>
                                    </span>
                                </template>

                                <span class="k">Copy</span>
                                <span v-if="!hayCopy" class="v modal-copy-empty">Sin dato en el export</span>
                                <div v-else class="v">
                                    <p v-for="(b, i) in copyBodies" :key="'b' + i" class="copy-body">{{ b }}</p>
                                    <p v-for="(t, i) in copyTitles" :key="'t' + i" class="copy-title">{{ t }}</p>
                                    <span v-if="multiVariante" class="text-muted">{{ copyBodies.length }} variantes ({{ rotacionLabel }})</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="miembrosDesglose.length" class="modal-anuncio modal-miembros">
                            <span class="modal-miembros-titulo">Desglose por campaña ({{ miembrosDesglose.length }} anuncios de este arte)</span>
                            <table class="modal-miembros-tabla">
                                <thead>
                                    <tr>
                                        <th>Ad ID</th>
                                        <th>Campaña</th>
                                        <th>Costo</th>
                                        <th>{{ labelVolumenMiembros }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in miembrosDesglose" :key="m.adId">
                                        <td class="mono">{{ m.adId }}</td>
                                        <td>{{ m.campaignName || '—' }}</td>
                                        <td class="mono">{{ formatMoneyExacto(m.cost) }}</td>
                                        <td class="mono">{{ formatNumeroExacto(volumenMiembro(m)) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="lectura" class="modal-lectura">
                            <span class="modal-lectura-kicker">Lectura</span>
                            <p>{{ lectura }}</p>
                        </div>

                        <div v-if="esEpa" class="modal-ia-acciones">
                            <button type="button" :disabled="cargandoMetricas" @click="evaluar('metricas')">
                                {{ cargandoMetricas ? 'Evaluando…' : 'Evaluar por métricas' }}
                            </button>
                            <button
                                type="button"
                                :disabled="cargandoArte || !card.imageUrl"
                                :title="!card.imageUrl ? 'Sin imagen disponible para este creativo' : null"
                                @click="evaluar('arte')"
                            >{{ cargandoArte ? 'Evaluando…' : 'Evaluar por arte' }}</button>
                        </div>
                        <p v-if="errorEvaluacion" class="modal-copy-note">{{ errorEvaluacion }}</p>
                        <div v-if="evaluacionMetricas" class="modal-ia-resultado">
                            <span class="modal-ia-kicker">✦ Evaluación IA · Métricas</span>
                            <p>{{ evaluacionMetricas }}</p>
                        </div>
                        <div v-if="evaluacionArte" class="modal-ia-resultado">
                            <span class="modal-ia-kicker">✦ Evaluación IA · Arte</span>
                            <p>{{ evaluacionArte }}</p>
                        </div>
                    </template>

                    <template v-else>
                        <div class="modal-etapas">
                            <div v-if="funnelPasos.length >= 2" class="modal-funnel">
                                <div v-for="(p, i) in funnelPasos" :key="p.key" class="modal-funnel-paso">
                                    <div class="modal-funnel-barra-wrap">
                                        <div class="modal-funnel-barra" :style="{ width: p.pct + '%' }"></div>
                                    </div>
                                    <div class="modal-funnel-info">
                                        <span class="modal-funnel-label">{{ p.label }}</span>
                                        <span class="modal-funnel-valor mono">{{ p.valorFmt }}</span>
                                        <span v-if="p.pctDelPrevio !== null" class="modal-funnel-caida">{{ p.pctDelPrevio }}% del paso anterior</span>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-etapas-leyenda">
                                <span><span class="modal-etapa-dot" style="background: var(--mint)"></span>Mejor que la media</span>
                                <span><span class="modal-etapa-dot" style="background: var(--yellow)"></span>En la media</span>
                                <span><span class="modal-etapa-dot" style="background: var(--coral)"></span>Peor que la media</span>
                                <span v-if="etapas.some((e) => e.comparacion)"><span class="modal-etapa-media-marca modal-etapa-media-marca--leyenda"></span>Media del grupo</span>
                            </div>
                            <template v-for="e in etapas" :key="e.key">
                                <div class="modal-etapa-row">
                                    <div class="modal-etapa-row-datos">
                                        <div class="modal-etapa-info">
                                            <span class="modal-etapa-dot" :style="{ background: dotColor(e.comparacion) }"></span>
                                            <div class="modal-etapa-labels">
                                                <span class="modal-etapa-label">{{ e.label }}</span>
                                                <span class="modal-etapa-categoria">{{ e.categoria }}</span>
                                            </div>
                                        </div>
                                        <span class="modal-etapa-valor mono">{{ e.valor }}</span>
                                        <div class="modal-etapa-metrica">
                                            <span class="modal-etapa-metrica-label">{{ e.metricaLabel }}</span>
                                            <span class="mono">{{ e.metricaValor }}</span>
                                            <span v-if="e.metricaPromedio" class="modal-etapa-metrica-promedio mono">
                                                vs. {{ e.metricaPromedio }} de media<template v-if="e.comparacion"> ({{ e.comparacion.pct }}%)</template>
                                            </span>
                                        </div>
                                        <span
                                            v-if="e.comparacion"
                                            class="modal-etapa-tag"
                                            :class="'modal-etapa-tag--' + e.comparacion.tagVariant"
                                        >{{ e.comparacion.tagLabel }}</span>
                                        <span v-else class="modal-etapa-tag modal-etapa-tag--neutral text-muted">Sin base</span>
                                    </div>
                                    <span class="modal-etapa-progreso">
                                        <span :style="{ width: (e.progresoPct ?? 0) + '%', background: dotColor(e.comparacion) }" />
                                        <span v-if="e.comparacion" class="modal-etapa-media-marca" title="Media del grupo"></span>
                                    </span>
                                </div>
                                <span v-if="e.nota" class="modal-etapa-nota">↓ {{ e.nota }}</span>
                            </template>
                        </div>

                        <div class="modal-etapas-footer">
                            <span>ID {{ adIdMostrado }}</span>
                            <span>{{ campaniaHumanizada }}</span>
                        </div>
                    </template>
                </div>

                <p v-if="faltaMeta" class="modal-copy-note">
                    Este anuncio no tiene contraparte en el export de Meta Ads — solo se muestran los datos de AppsFlyer.
                </p>
                <p v-if="faltaAppsFlyer" class="modal-copy-note">
                    Este anuncio no tiene contraparte en el export de AppsFlyer — solo se muestran los datos de Meta Ads.
                </p>
            </div>
        </div>
    </div>
</template>
