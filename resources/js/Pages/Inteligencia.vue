<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import SeleccionCard from '@/Components/Inteligencia/SeleccionCard.vue';
import ComparacionCreativos from '@/Components/Inteligencia/ComparacionCreativos.vue';
import CreativeModal from '@/Components/Creativo/CreativeModal.vue';
import { cardDesdeCreativo, placeholderPorTipo, promediosDeGrupo, FUNNEL_LABELS, FUNNEL_ORDER, TIPO_CUENTA_OPCIONES, resultadoDeMes } from '@/motor';

// Inteligencia -- comparación head-to-head de 2-4 creativos (2026-08-27,
// ver plan). El controller manda el historial COMPLETO de `resultados`
// por creativo (a diferencia de analisis-creativo) para el gráfico de
// tendencia mensual de ComparacionCreativos.vue.
const props = defineProps({
    pais: String,
    plataforma: String,
    creativos: Array,
    mes: String,
    mesesDisponibles: Array,
});

// Mismo patrón de selector de mes que AnalisisCreativo.vue -- Mes recarga
// el servidor porque cambia qué mes cuenta como "actual" para el
// score/las barras del head-to-head (la tendencia mensual siempre usa el
// historial completo, sin importar qué mes esté seleccionado acá).
const MESES_LABEL = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
function formatMesLabel(mesIso) {
    const [anio, mes] = mesIso.split('-').map(Number);
    return `${MESES_LABEL[mes - 1]} ${anio}`;
}
const mesOptions = computed(() => (props.mesesDisponibles || []).map((m) => ({ key: m, label: formatMesLabel(m) })));
function cambiarMes(nuevoMes) {
    router.get(window.location.pathname, { mes: nuevoMes }, { preserveState: true, preserveScroll: true, replace: true });
}

// Filtros de la grilla (2026-08-27) -- mismo orden de cascada que
// AnalisisCreativo.vue (Tipo de cuenta antes que Funnel): Tipo de cuenta
// (BI/DTC) es el filtro MÁS ALTO, Funnel se calcula/cuenta sobre lo que ya
// pasó ese filtro. Ninguno de los dos impide seleccionar -- solo acotan
// qué se VE; lo ya elegido sigue elegido aunque quede fuera de la vista.

// Plataforma -- mismo patrón que AnalisisCreativo.vue (PLATAFORMA_OPCIONES,
// filtro EN CLIENTE sobre el set completo ya cargado, cascada ANTES de Tipo
// de cuenta). 2026-08-27, ver plan del rediseño -- Inteligencia no tenía
// este filtro todavía, Creativos sí.
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
const creativosPorPlataforma = computed(() =>
    plataformaSeleccionada.value === 'TODOS'
        ? props.creativos
        : props.creativos.filter((c) => c.plataforma === plataformaSeleccionada.value),
);

// Tipo de cuenta -- mismo patrón que AnalisisCreativo.vue
// (TIPO_CUENTA_OPCIONES, default 'DTC'): "Sin clasificar" pasa siempre
// (por definición nunca tiene cruce de costo real, exigírselo lo dejaría
// siempre en 0 resultados); DTC/BRD exigen tiene_meta=true ese mes --
// nunca comparar con un creativo que solo tiene actividad de AppsFlyer sin
// costo real cruzado. resultadoDeMes (no resultados[0]) porque acá cada
// creativo trae el historial completo, no un solo mes ya filtrado. Cuenta
// sobre creativosPorPlataforma (no props.creativos) -- misma cascada
// Plataforma -> Tipo de cuenta que AnalisisCreativo.vue.
function tipoCuentaDeCard(creativo) {
    return creativo.tipo_cuenta || 'SIN_CLASIFICAR';
}
function tieneMetaEnMes(creativo) {
    return resultadoDeMes(creativo, props.mes)?.tiene_meta === true;
}
const tipoCuentaSeleccionado = ref('DTC');
const tipoCuentaCounts = computed(() => {
    const counts = { DTC: 0, BRD: 0, SIN_CLASIFICAR: 0 };
    for (const c of creativosPorPlataforma.value) counts[tipoCuentaDeCard(c)]++;
    return counts;
});
const creativosPorTipoCuenta = computed(() => creativosPorPlataforma.value.filter((c) =>
    tipoCuentaDeCard(c) === tipoCuentaSeleccionado.value
    && (tipoCuentaSeleccionado.value === 'SIN_CLASIFICAR' || tieneMetaEnMes(c))));

// Funnel -- pedido explícito ("no ver demasiadooos creativos"), calculado
// sobre lo que ya pasó el filtro de tipo de cuenta.
const funnelFiltro = ref('TODOS');
const funnelsPresentes = computed(() => {
    const presentes = new Set(creativosPorTipoCuenta.value.map((c) => c.funnel).filter(Boolean));
    return FUNNEL_ORDER.filter((f) => presentes.has(f));
});
const creativosFiltrados = computed(() => (funnelFiltro.value === 'TODOS'
    ? creativosPorTipoCuenta.value
    : creativosPorTipoCuenta.value.filter((c) => c.funnel === funnelFiltro.value)));

// Carrito de selección -- pedido explícito del usuario: máximo 4, mínimo 2
// para competir. Restringido a UN solo funnel a la vez (mismo criterio de
// "nunca comparar peras con manzanas" que ya usa todo el dashboard vía
// promediosDeGrupo/UMBRAL_VOLUMEN_POR_FUNNEL) -- elegido el primero, el
// resto de funnels quedan deshabilitados hasta vaciar el carrito.
const MAX_SELECCION = 4;
const seleccionados = ref([]);
const funnelBloqueado = computed(() => {
    if (!seleccionados.value.length) return null;
    const primero = props.creativos.find((c) => c.id === seleccionados.value[0]);
    return primero?.funnel ?? null;
});
function estaSeleccionado(creativo) {
    return seleccionados.value.includes(creativo.id);
}
function estaDeshabilitado(creativo) {
    if (estaSeleccionado(creativo)) return false;
    if (seleccionados.value.length >= MAX_SELECCION) return true;
    return funnelBloqueado.value !== null && creativo.funnel !== funnelBloqueado.value;
}
function alternar(creativo) {
    if (estaSeleccionado(creativo)) {
        seleccionados.value = seleccionados.value.filter((id) => id !== creativo.id);
        return;
    }
    if (estaDeshabilitado(creativo)) return;
    seleccionados.value = [...seleccionados.value, creativo.id];
}
const puedeCompetir = computed(() => seleccionados.value.length >= 2);
const creativosElegidos = computed(() => props.creativos.filter((c) => seleccionados.value.includes(c.id)));

const comparando = ref(false);
function competir() {
    if (!puedeCompetir.value) return;
    comparando.value = true;
}
function volverASeleccion() {
    comparando.value = false;
}

// Miniaturas del carrito en la barra inferior -- mismo patrón de
// imagen/fallback que el resto del dashboard, nunca un ícono inventado.
function miniatura(creativo) {
    const card = cardDesdeCreativo(creativo, props.mes);
    return { imageUrl: card.imageUrl, glyph: placeholderPorTipo(card.tipoCreativo).glyph };
}

// "Ver detalle" desde la comparación (2026-08-27, ver plan del rediseño) --
// mismo CreativeModal.vue y mismo cálculo de promedios de grupo
// (promediosDeGrupo, motor.js) que ya usa AnalisisCreativo.vue
// (promediosParaCreativo) -- país/mes ya están implícitos en `todos`
// (props.creativos) porque el controller ya los acota.
const creativoAbierto = ref(null);
function abrirDetalle(creativo) {
    creativoAbierto.value = creativo;
}
function cerrarModal() {
    creativoAbierto.value = null;
}
const promediosDelAbierto = computed(() => promediosDeGrupo(creativoAbierto.value, props.creativos, props.mes));
</script>

<template>
    <Head :title="`Inteligencia — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="inteligencia">
    <div class="page">
        <div class="page-inner">
            <template v-if="!comparando">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Pon a competir tus creativos</h1>
                        <p class="subtitle">Elige 2 para un cara a cara directo, o hasta 4 para verlos comparados a la vez.</p>
                    </div>
                </header>

                <div class="resumen-filtros">
                    <FiltroDropdown label="Mes" :modelValue="mes" :options="mesOptions" @update:modelValue="cambiarMes" />
                    <FiltroDropdown label="Plataforma" v-model="plataformaSeleccionada" :options="PLATAFORMA_OPCIONES" :counts="plataformaCounts" />
                    <FiltroDropdown label="Tipo de cuenta" v-model="tipoCuentaSeleccionado" :options="TIPO_CUENTA_OPCIONES" :counts="tipoCuentaCounts" />
                    <p v-if="funnelBloqueado" class="date-hint">
                        Comparando dentro de {{ FUNNEL_LABELS[funnelBloqueado] || funnelBloqueado }} -- vacía el carrito para elegir de otra etapa.
                    </p>
                </div>

                <div class="chip-group">
                    <button type="button" class="chip" :class="{ activo: funnelFiltro === 'TODOS' }" @click="funnelFiltro = 'TODOS'">
                        TODOS ({{ creativosPorTipoCuenta.length }})
                    </button>
                    <button
                        v-for="f in funnelsPresentes"
                        :key="f"
                        type="button"
                        class="chip"
                        :class="{ activo: funnelFiltro === f }"
                        @click="funnelFiltro = f"
                    >
                        {{ FUNNEL_LABELS[f] || f }} ({{ creativosPorTipoCuenta.filter((c) => c.funnel === f).length }})
                    </button>
                </div>

                <section class="section seleccion-grid">
                    <SeleccionCard
                        v-for="creativo in creativosFiltrados"
                        :key="creativo.id"
                        :creativo="creativo"
                        :mes="mes"
                        :seleccionada="estaSeleccionado(creativo)"
                        :deshabilitada="estaDeshabilitado(creativo)"
                        @toggle="alternar"
                    />
                </section>

                <div class="seleccion-barra">
                    <div class="seleccion-barra-info">
                        <span class="mono seleccion-barra-contador">{{ seleccionados.length }}<span style="color: var(--text-faint)">/{{ MAX_SELECCION }}</span></span>
                        <span class="seleccion-barra-label">seleccionados</span>
                        <div class="seleccion-barra-miniaturas">
                            <span v-for="creativo in creativosElegidos" :key="creativo.id" class="seleccion-barra-mini">
                                <img v-if="miniatura(creativo).imageUrl" :src="miniatura(creativo).imageUrl" alt="" />
                                <span v-else>{{ miniatura(creativo).glyph }}</span>

                                <button
                                    type="button"
                                    class="seleccion-barra-mini-quitar"
                                    :aria-label="`Quitar ${creativo.nombre_comun || creativo.nombre_completo} de la selección`"
                                    :title="`Quitar de la selección`"
                                    @click.stop="alternar(creativo)"
                                >
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /><path d="M10 11v6" /><path d="M14 11v6" />
                                    </svg>
                                </button>

                                <span v-if="miniatura(creativo).imageUrl" class="seleccion-barra-preview">
                                    <img :src="miniatura(creativo).imageUrl" alt="" />
                                </span>
                            </span>
                            <span v-for="n in Math.max(0, 2 - seleccionados.length)" :key="'vacio' + n" class="seleccion-barra-mini seleccion-barra-mini--vacia">+</span>
                        </div>
                    </div>
                    <button type="button" class="seleccion-barra-boton" :disabled="!puedeCompetir" @click="competir">
                        {{ puedeCompetir ? `Competir (${seleccionados.length})` : 'Elige al menos 2' }}
                    </button>
                </div>
            </template>

            <ComparacionCreativos
                v-else
                :creativos="creativosElegidos"
                :todos="creativos"
                :mes="mes"
                @volver="volverASeleccion"
                @abrir="abrirDetalle"
            />
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
/* Mismo page-chrome que AnalisisCreativo.vue (.page/.page-inner/.header/
   .eyebrow/.title/.subtitle/.section son scoped por página en este
   proyecto -- no viven en app.css, cada página los repite). */
.page {
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
}
.page-inner {
    max-width: 1400px;
    margin: 0 auto;
    padding: 32px 32px 140px;
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
}
.section {
    margin-bottom: 40px;
}
.chip-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 24px;
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

/* Grilla de selección -- carrito de Inteligencia (2026-08-27). */
.seleccion-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 18px;
}

/* Barra inferior fija -- mismo tratamiento visual que el header del modal
   (blur + borde superior), nunca tapa contenido gracias al padding-bottom
   de .page-inner. */
.seleccion-barra {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 20;
    padding: 16px 32px;
    background: color-mix(in srgb, var(--surface) 92%, transparent);
    backdrop-filter: blur(8px);
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}
.seleccion-barra-info {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.seleccion-barra-contador {
    font-size: 0.95rem;
    font-weight: 600;
}
.seleccion-barra-label {
    font-size: 0.8rem;
    color: var(--text-muted);
}
.seleccion-barra-miniaturas {
    display: flex;
    align-items: center;
    gap: 6px;
}
.seleccion-barra-mini {
    position: relative;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: var(--surface-2);
    border: 1.5px solid var(--amber);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: visible;
    font-size: 0.7rem;
    color: var(--text-faint);
}
.seleccion-barra-mini img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 6.5px;
}
.seleccion-barra-mini--vacia {
    border-style: dashed;
    border-color: var(--border);
    font-size: 0.9rem;
}
/* Botecito de basura -- 2026-08-28, pedido explícito: solo aparece al
   pasar el cursor (nunca compite visualmente con la miniatura en reposo),
   quita ESE creativo de la selección reusando alternar() -- mismo criterio
   que desmarcarlo desde la grilla de arriba, no es una acción nueva. */
.seleccion-barra-mini-quitar {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--coral);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid var(--surface);
    opacity: 0;
    transform: scale(0.75);
    transition: opacity 0.12s ease, transform 0.12s ease;
    z-index: 2;
}
.seleccion-barra-mini:hover .seleccion-barra-mini-quitar {
    opacity: 1;
    transform: scale(1);
}
/* Preview grande -- 2026-08-28, pedido explícito: la miniatura de 34px no
   alcanza para ver el creativo antes de decidir sacarlo. Aparece arriba de
   la barra fija (bottom:100%) al pasar el cursor, mismo aspect-ratio 3/4
   que las cards reales para no distorsionar la imagen. */
.seleccion-barra-preview {
    position: absolute;
    bottom: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%) translateY(4px);
    width: 120px;
    aspect-ratio: 3 / 4;
    border-radius: 10px;
    overflow: hidden;
    background: var(--bg);
    border: 1.5px solid var(--border);
    box-shadow: var(--shadow);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.12s ease, transform 0.12s ease;
    z-index: 3;
}
.seleccion-barra-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.seleccion-barra-mini:hover .seleccion-barra-preview {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(0);
}
.seleccion-barra-boton {
    border: none;
    border-radius: 10px;
    padding: 12px 22px;
    font-family: 'Inter', sans-serif;
    font-size: 0.85rem;
    font-weight: 600;
    background: var(--amber);
    color: var(--amber-ink);
    cursor: pointer;
    white-space: nowrap;
}
.seleccion-barra-boton:disabled {
    background: var(--surface-2);
    color: var(--text-faint);
    cursor: not-allowed;
}
</style>
