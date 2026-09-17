<script setup>
import { ref, computed, watch } from 'vue';
import { formatMoneyExacto, formatPercent, formatNumeroExacto } from '@/motor';

// TendenciaChart -- 2026-08-28, pedido explícito: el bloque de tendencia
// (antes fijo en CPM/CPI según la etapa, sin elegir nada) pasa a ser un
// mini-panel con selector de MÉTRICA + selector de TIPO DE GRÁFICA (línea/
// barras), ejes con valores reales (antes la línea "se veía cortada" sin
// ninguna referencia de escala) y tooltip al pasar el cursor (crosshair +
// lectura de cada serie en ese punto, ver dataviz skill: interaction.md).
//
// "Pastel" quedó afuera a propósito: un pie compara partes de un TOTAL en
// un punto fijo, no una serie de días/meses -- meterlo acá mostraría algo
// que no es lo que el dato es (ver dataviz skill: choosing-a-form.md, "la
// forma la elige el trabajo del dato, no la preferencia"). Si más adelante
// se quiere "qué % del gasto total se llevó cada creativo este mes", ESO sí
// es un pie/donut real -- pero es un bloque nuevo aparte, no un modo de
// este.
//
// `opciones` ya viene con los puntos normalizados a {clave, valor} (clave =
// mes o fecha según corresponda) -- este componente no sabe ni le importa
// cuál de los dos es, solo cómo formatearla (`ejeTipo`).
const props = defineProps({
    titulo: { type: String, required: true },
    // [{ campo, label, series: [{it, puntos: [{clave, valor}]}], eje: string[] }]
    opciones: { type: Array, required: true },
    ejeTipo: { type: String, default: 'mes' }, // 'mes' | 'dia'
});

const CAMPOS_CONTEO = new Set(['impressions', 'clicks', 'installs', 'orders', 'newCustomers']);
function formatearValor(campo, valor) {
    if (valor === null || valor === undefined) return '—';
    if (campo === 'ctr') return formatPercent(valor);
    if (CAMPOS_CONTEO.has(campo)) return formatNumeroExacto(valor);
    return formatMoneyExacto(valor);
}
function formatearEje(clave) {
    if (props.ejeTipo === 'dia') return clave.slice(8, 10).replace(/^0/, '');
    // 'YYYY-MM' -> 'Mmm' corto, sin depender de Date() por huso horario.
    const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return MESES[Number(clave.slice(5, 7)) - 1] ?? clave;
}

const campoActivo = ref(props.opciones[0]?.campo ?? null);
// Si cambian las opciones disponibles (ej. se agregó/quitó un creativo de
// la comparación) y la métrica elegida ya no está, cae a la primera
// disponible -- nunca se queda mostrando un bloque vacío en silencio.
watch(
    () => props.opciones,
    (nuevas) => {
        if (!nuevas.some((o) => o.campo === campoActivo.value)) {
            campoActivo.value = nuevas[0]?.campo ?? null;
        }
    },
);
const bloque = computed(() => props.opciones.find((o) => o.campo === campoActivo.value) ?? null);

// Barras con más de 12 posiciones en el eje (ej. los ~30 días de un mes)
// quedan ilegibles -- cada barra se reduce a un par de píxeles. Con
// "trend over time" la forma correcta es línea (dataviz skill), así que
// barras ni se ofrece pasado ese umbral en vez de dejar elegir algo que
// se ve mal.
const permiteBarras = computed(() => (bloque.value?.eje.length ?? 0) <= 12);
const tipoGrafica = ref('linea');
watch(permiteBarras, (permite) => {
    if (!permite) tipoGrafica.value = 'linea';
});

const ANCHO = 800;
const ALTO = 220;
const PAD_Y = 16;
const PAD_X = 8;

const escalaY = computed(() => {
    const valores = (bloque.value?.series ?? []).flatMap((s) => s.puntos.map((p) => p.valor).filter((v) => v !== null));
    if (!valores.length) return { min: 0, max: 1 };
    const min = Math.min(0, ...valores); // el 0 siempre entra en escala -- nunca exagera una variación chica
    const max = Math.max(...valores);
    return min === max ? { min: min - 1, max: max + 1 } : { min, max };
});
// yTicks -- 4 valores reales (max, 2/3, 1/3, min), formateados con el
// mismo formateador que el resto de la métrica. No son "números lindos"
// redondeados (ver marks-and-anatomy.md) -- con series cortas (4-30
// puntos) y rangos chicos, redondear a miles/centenas puede colapsar los
// 4 ticks al mismo valor; 4 puntos reales evenly-spaced es más honesto acá.
const yTicks = computed(() => {
    const { min, max } = escalaY.value;
    return [max, min + ((max - min) * 2) / 3, min + (max - min) / 3, min];
});
function coordX(clave) {
    const eje = bloque.value?.eje ?? [];
    const i = eje.indexOf(clave);
    return eje.length > 1 ? PAD_X + (i / (eje.length - 1)) * (ANCHO - 2 * PAD_X) : ANCHO / 2;
}
function coordY(valor) {
    const { min, max } = escalaY.value;
    if (max === min) return ALTO / 2;
    return PAD_Y + (ALTO - 2 * PAD_Y) * (1 - (valor - min) / (max - min));
}
// segmentosSerie -- corta la línea en tramos contiguos según el eje
// compartido -- si a un creativo le falta un punto en medio, nunca se
// dibuja una recta "inventando" ese tramo (mismo criterio que antes).
function segmentosSerie(puntos) {
    const eje = bloque.value?.eje ?? [];
    const segmentos = [];
    let actual = [];
    let prevIndex = null;
    for (const p of puntos) {
        if (p.valor === null) continue;
        const idx = eje.indexOf(p.clave);
        if (prevIndex !== null && idx !== prevIndex + 1 && actual.length) {
            segmentos.push(actual);
            actual = [];
        }
        actual.push({ x: coordX(p.clave), y: coordY(p.valor) });
        prevIndex = idx;
    }
    if (actual.length) segmentos.push(actual);
    return segmentos.map((seg) => seg.map((pt) => `${pt.x},${pt.y}`).join(' '));
}

// Barras agrupadas: un slot por posición del eje, una barra por serie
// dentro del slot -- <=24px de grosor (mark spec), separadas por un gap de
// 2px (surface gap).
const GAP_BARRA = 2;
const barras = computed(() => {
    if (!bloque.value || tipoGrafica.value !== 'barra') return [];
    const eje = bloque.value.eje;
    const nSeries = bloque.value.series.length;
    const anchoSlot = (ANCHO - 2 * PAD_X) / eje.length;
    const anchoBarra = Math.min(24, (anchoSlot - GAP_BARRA * (nSeries + 1)) / nSeries);
    const base = coordY(Math.max(0, escalaY.value.min));
    const out = [];
    eje.forEach((clave, i) => {
        const slotX = PAD_X + i * anchoSlot;
        bloque.value.series.forEach((s, si) => {
            const punto = s.puntos.find((p) => p.clave === clave);
            if (!punto || punto.valor === null) return; // nunca inventa una barra en 0 para un día sin dato
            const x = slotX + GAP_BARRA + si * (anchoBarra + GAP_BARRA);
            const y = coordY(punto.valor);
            out.push({
                x,
                y: Math.min(y, base),
                width: Math.max(1, anchoBarra),
                height: Math.abs(base - y),
                color: s.it.color,
                key: `${clave}-${s.it.creativo.id}`,
            });
        });
    });
    return out;
});

// Hover: crosshair que engancha a la posición del eje más cercana al
// cursor (ver interaction.md -- "el lector apunta a una fecha, nunca a una
// línea de 2px").
const hoverX = ref(null);
function onMove(e) {
    const svg = e.currentTarget;
    const rect = svg.getBoundingClientRect();
    hoverX.value = ((e.clientX - rect.left) / rect.width) * ANCHO;
}
function onLeave() {
    hoverX.value = null;
}
const hoverIdx = computed(() => {
    const eje = bloque.value?.eje ?? [];
    if (hoverX.value === null || eje.length < 1) return null;
    if (eje.length === 1) return 0;
    const idxFloat = ((hoverX.value - PAD_X) / (ANCHO - 2 * PAD_X)) * (eje.length - 1);
    return Math.max(0, Math.min(eje.length - 1, Math.round(idxFloat)));
});
const hoverClave = computed(() => (hoverIdx.value !== null ? bloque.value.eje[hoverIdx.value] : null));
const hoverFilas = computed(() => {
    if (hoverClave.value === null || !bloque.value) return [];
    return bloque.value.series.map((s) => ({
        it: s.it,
        valor: s.puntos.find((p) => p.clave === hoverClave.value)?.valor ?? null,
    }));
});
const hoverPxX = computed(() => (hoverIdx.value !== null ? coordX(bloque.value.eje[hoverIdx.value]) : 0));
// Posición del tooltip en % del ancho del panel (el SVG es responsive,
// viewBox 0-800 -- convertir a % lo mantiene alineado a cualquier tamaño real).
const hoverPct = computed(() => (hoverPxX.value / ANCHO) * 100);
const tooltipAlineacion = computed(() => (hoverPct.value > 70 ? 'derecha' : hoverPct.value < 15 ? 'izquierda' : 'centro'));
</script>

<template>
    <div v-if="bloque" class="tendencia-panel">
        <div class="tendencia-panel-cabecera">
            <span class="tendencia-panel-titulo">{{ titulo }}</span>
            <div class="tendencia-panel-controles">
                <div class="tendencia-selector-metrica">
                    <button
                        v-for="o in opciones"
                        :key="o.campo"
                        type="button"
                        class="tendencia-chip"
                        :class="{ activo: o.campo === campoActivo }"
                        @click="campoActivo = o.campo"
                    >{{ o.label }}</button>
                </div>
                <div class="tendencia-selector-tipo">
                    <button type="button" class="tendencia-tipo-btn" :class="{ activo: tipoGrafica === 'linea' }" title="Gráfica de líneas" @click="tipoGrafica = 'linea'">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3,17 9,10 14,14 21,5" /></svg>
                    </button>
                    <button
                        type="button"
                        class="tendencia-tipo-btn"
                        :class="{ activo: tipoGrafica === 'barra', deshabilitado: !permiteBarras }"
                        :disabled="!permiteBarras"
                        :title="permiteBarras ? 'Gráfica de barras' : 'Barras no disponible con más de 12 puntos -- se vería ilegible'"
                        @click="permiteBarras && (tipoGrafica = 'barra')"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="4" height="10" /><rect x="10" y="5" width="4" height="15" /><rect x="16" y="13" width="4" height="7" /></svg>
                    </button>
                </div>
            </div>
        </div>

        <div v-if="bloque.series.length >= 2" class="tendencia-leyenda">
            <span v-for="s in bloque.series" :key="s.it.creativo.id" class="tendencia-leyenda-item" :style="{ color: s.it.color }">
                <span class="tendencia-leyenda-dot" :style="{ background: s.it.color }"></span>{{ s.it.card.nombreAmigable || s.it.card.arte || s.it.card.adNameShort }}
            </span>
        </div>

        <div class="tendencia-grafica-wrap">
            <svg
                width="100%" :height="ALTO" :viewBox="`0 0 ${ANCHO} ${ALTO}`" preserveAspectRatio="none"
                class="tendencia-svg"
                @pointermove="onMove"
                @pointerleave="onLeave"
            >
                <!-- Gridlines + valores de eje (antes no había ninguna referencia de escala) -->
                <g v-for="(t, i) in yTicks" :key="i">
                    <line :x1="PAD_X" :x2="ANCHO - PAD_X" :y1="coordY(t)" :y2="coordY(t)" class="tendencia-gridline" />
                    <text :x="ANCHO - PAD_X" :y="coordY(t) - 4" class="tendencia-tick-label" text-anchor="end">{{ formatearValor(bloque.campo, t) }}</text>
                </g>

                <!-- Barras -->
                <rect v-for="b in barras" :key="b.key" :x="b.x" :y="b.y" :width="b.width" :height="Math.max(b.height, 1)" :fill="b.color" rx="3" class="tendencia-barra" />

                <!-- Líneas -->
                <template v-if="tipoGrafica === 'linea'">
                    <template v-for="s in bloque.series" :key="s.it.creativo.id">
                        <polyline
                            v-for="(seg, i) in segmentosSerie(s.puntos)"
                            :key="i"
                            :points="seg"
                            fill="none"
                            :stroke="s.it.color"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </template>
                </template>

                <!-- Crosshair -->
                <line v-if="hoverIdx !== null" :x1="hoverPxX" :x2="hoverPxX" :y1="PAD_Y" :y2="ALTO - PAD_Y" class="tendencia-crosshair" />
                <circle
                    v-for="f in (hoverIdx !== null ? hoverFilas : [])"
                    :key="f.it.creativo.id"
                    v-show="f.valor !== null"
                    :cx="hoverPxX"
                    :cy="f.valor !== null ? coordY(f.valor) : 0"
                    r="4"
                    :fill="f.it.color"
                    class="tendencia-punto-hover"
                />
            </svg>

            <div
                v-if="hoverIdx !== null"
                class="tendencia-tooltip"
                :class="`tendencia-tooltip--${tooltipAlineacion}`"
                :style="{ left: hoverPct + '%' }"
            >
                <span class="tendencia-tooltip-fecha">{{ ejeTipo === 'dia' ? `Día ${formatearEje(hoverClave)}` : formatearEje(hoverClave) }}</span>
                <div v-for="f in hoverFilas" :key="f.it.creativo.id" class="tendencia-tooltip-fila">
                    <span class="tendencia-tooltip-key" :style="{ background: f.it.color }"></span>
                    <span class="tendencia-tooltip-nombre">{{ f.it.card.nombreAmigable || f.it.card.arte || f.it.card.adNameShort }}</span>
                    <span class="mono tendencia-tooltip-valor">{{ formatearValor(bloque.campo, f.valor) }}</span>
                </div>
            </div>
        </div>

        <div class="tendencia-panel-pie">
            <span>{{ ejeTipo === 'dia' ? `Día ${formatearEje(bloque.eje[0])}` : formatearEje(bloque.eje[0]) }}</span>
            <span>{{ ejeTipo === 'dia' ? `Día ${formatearEje(bloque.eje[bloque.eje.length - 1])}` : formatearEje(bloque.eje[bloque.eje.length - 1]) }}</span>
        </div>
    </div>
</template>
