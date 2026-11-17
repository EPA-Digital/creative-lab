<script setup>
// Comparar grupos (2026-11-17, pedido explícito): ej. los creativos de
// "corona" de agosto contra los de octubre. Cada grupo = búsqueda + mes;
// se comparan por etapa contra el benchmark de su mes (ver
// CompararGrupos.php). Cada grupo puede bajar su PDF con benchmark.
import { ref, computed, provide } from 'vue';
import CreativeCard from '@/Components/Creativo/CreativeCard.vue';
import CreativeModal from '@/Components/Creativo/CreativeModal.vue';
import axios from 'axios';
import { urlPais } from '@/rutas';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import { formatMoneyExacto, formatNumeroExacto, opcionesTipoCuenta, etiquetaFiltroTipoCuenta, etiquetaFunnel } from '@/motor';
import { ETIQUETA_KPI } from '@/benchmark';
import { descargarPdfComparacion } from '@/reportePdf';
import { mensajeDeError } from '@/avisos';

const props = defineProps({
    pais: { type: String, required: true },
    mesesDisponibles: { type: Array, default: () => [] },
    esEpa: { type: Boolean, default: false },
});

const MESES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MESES_LARGO = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const mesCorto = (m) => `${MESES_CORTO[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}`;
const mesOptions = computed(() => [...props.mesesDisponibles].reverse().map((m) => ({ key: m, label: `${MESES_LARGO[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}` })));

const MAX = 4;
const ultimo = props.mesesDisponibles.at(-1) ?? null;
const penultimo = props.mesesDisponibles.at(-2) ?? ultimo;
const grupos = ref([
    { q: '', mes: penultimo },
    { q: '', mes: ultimo },
]);
function agregar() {
    if (grupos.value.length < MAX) grupos.value.push({ q: grupos.value.at(-1)?.q ?? '', mes: ultimo });
}
function quitar(i) {
    if (grupos.value.length > 2) grupos.value.splice(i, 1);
}

const PLATAFORMAS = [
    { key: 'TODOS', label: 'Todas' },
    { key: 'meta', label: 'Meta' },
    { key: 'tiktok', label: 'TikTok' },
];
const plataforma = ref('TODOS');
const tipoCuenta = ref('DTC');

const listo = computed(() => grupos.value.every((g) => g.q.trim().length >= 2 && g.mes));
const cargando = ref(false);
const error = ref('');
const resultado = ref(null);

// Parámetros de la última comparación: el PDF sale de lo mismo que se ve.
const paramsComparados = ref(null);
const paramsActuales = () => ({
    grupos: grupos.value.map((g) => ({ q: g.q.trim(), mes: g.mes })),
    plataforma: plataforma.value === 'TODOS' ? undefined : plataforma.value,
    tipo_cuenta: tipoCuenta.value === 'TODOS' ? undefined : tipoCuenta.value,
});

async function comparar() {
    if (!listo.value || cargando.value) return;
    cargando.value = true;
    error.value = '';
    const params = paramsActuales();
    try {
        const { data } = await axios.get(`${urlPais(props.pais)}/inteligencia/grupos`, { params });
        resultado.value = data.grupos;
        paramsComparados.value = params;
        verTodos.value = {};
    } catch (e) {
        error.value = mensajeDeError(e, 'No se pudo comparar los grupos.');
    } finally {
        cargando.value = false;
    }
}

const nombreGrupo = (g) => `${g.q} · ${mesCorto(g.mes)}`;
const COLOR = (i) => `var(--serie-${i + 1})`;

// Etapas presentes en cualquiera de los grupos, en orden de funnel.
const ORDEN = ['AWA', 'CON', 'CONS', 'CNV', 'LOY', 'SIN'];
const etapas = computed(() => {
    if (!resultado.value) return [];
    const presentes = new Set(resultado.value.flatMap((g) => g.etapas.map((e) => e.funnel)));
    return ORDEN.filter((f) => presentes.has(f)).map((funnel) => {
        const porGrupo = resultado.value.map((g) => g.etapas.find((e) => e.funnel === funnel) ?? null);
        const campos = porGrupo.find(Boolean).kpis.map((k) => k.campo);
        const filas = campos.map((campo) => {
            const celdas = porGrupo.map((e) => e?.kpis.find((k) => k.campo === campo) ?? null);
            const valores = celdas.map((c) => c?.valor ?? null).filter((v) => v !== null);
            const mejor = valores.length > 1 ? (campo === 'ctr' ? Math.max(...valores) : Math.min(...valores)) : null;
            return { campo, celdas, mejor };
        });
        // Ganador de la etapa: el más barato en el KPI de costo de la etapa (el primero).
        const principal = filas[0];
        const ganadorIdx = principal.mejor === null ? -1 : principal.celdas.findIndex((c) => c?.valor === principal.mejor);
        return { funnel, porGrupo, filas, ganadorIdx };
    });
});

const fmt = (campo, v) => (v === null || v === undefined ? '—' : campo === 'ctr' ? `${Number(v).toFixed(2)}%` : formatMoneyExacto(v));
const ESTADO = { mejor: 'Mejor', media: 'En la media', peor: 'Peor' };

// PDF de la comparación: los grupos lado a lado y los creativos de cada uno.
const generando = ref(false);
async function pdf() {
    if (generando.value || !paramsComparados.value) return;
    generando.value = true;
    try {
        await descargarPdfComparacion(props.pais, paramsComparados.value);
    } catch (e) {
        error.value = e.message;
    } finally {
        generando.value = false;
    }
}

// Creativos de cada grupo, como en Creativos: card con su benchmark contra
// su mes (viene calculado del servidor) y el modal al hacer clic.
provide('benchmarkCreativo', (c) => c?.benchmark ?? null);
const VISIBLES = 8;
const verTodos = ref({});
const creativoAbierto = ref(null);
const mesAbierto = ref(null);
function abrir(c, mes) {
    creativoAbierto.value = c;
    mesAbierto.value = mes;
}
</script>

<template>
    <section class="cg">
        <div class="cg-grupos">
            <div v-for="(g, i) in grupos" :key="i" class="cg-grupo">
                <span class="cg-punto" :style="{ background: COLOR(i) }" aria-hidden="true" />
                <div class="cg-campo">
                    <label :for="`cg-q-${i}`" class="cg-label mono">Grupo {{ i + 1 }}: busca</label>
                    <div class="buscador-campo">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input :id="`cg-q-${i}`" v-model="g.q" type="search" autocomplete="off" placeholder="Nombre, campaña, Ad ID…" @keydown.enter="comparar" @keydown.esc.stop="g.q = ''" />
                        <button v-if="g.q" type="button" class="buscador-limpiar" aria-label="Limpiar búsqueda" @click="g.q = ''">×</button>
                    </div>
                </div>
                <FiltroDropdown label="Mes" v-model="g.mes" :options="mesOptions" />
                <button v-if="grupos.length > 2" type="button" class="cg-quitar" :aria-label="`Quitar grupo ${i + 1}`" title="Quitar grupo" @click="quitar(i)">×</button>
            </div>
            <button v-if="grupos.length < MAX" type="button" class="modal-copy-btn cg-agregar" @click="agregar">+ Agregar grupo</button>
        </div>

        <div class="cg-acciones">
            <FiltroDropdown label="Plataforma" v-model="plataforma" :options="PLATAFORMAS" />
            <FiltroDropdown :label="etiquetaFiltroTipoCuenta()" v-model="tipoCuenta" :options="[{ key: 'TODOS', label: 'Todos' }, ...opcionesTipoCuenta(pais)]" />
            <button type="button" class="btn-primary" :disabled="!listo || cargando" @click="comparar">
                {{ cargando ? 'Comparando…' : 'Comparar grupos' }}
            </button>
            <button
                v-if="esEpa && resultado"
                type="button"
                class="btn-primary cg-pdf"
                :disabled="generando"
                title="PDF con los grupos lado a lado por etapa y los creativos de cada grupo con imagen y su benchmark"
                @click="pdf"
            >
                {{ generando ? 'Generando PDF…' : 'PDF de la comparación' }}
            </button>
        </div>
        <span class="cg-hint">La búsqueda encuentra nombre, campaña, conjunto o Ad ID, sin importar mayúsculas ni acentos.</span>
        <p v-if="error" class="cg-error">{{ error }}</p>

        <template v-if="resultado">
            <div class="cg-resumen">
                <div v-for="(g, i) in resultado" :key="i" class="cg-tarjeta" :style="{ '--color-grupo': COLOR(i) }">
                    <div class="cg-tarjeta-head">
                        <span class="cg-punto" :style="{ background: COLOR(i) }" aria-hidden="true" />
                        <span class="cg-tarjeta-nombre">{{ nombreGrupo(g) }}</span>
                    </div>
                    <span class="cg-tarjeta-num">{{ formatMoneyExacto(g.totales.cost) }}</span>
                    <span class="cg-tarjeta-sub">{{ formatNumeroExacto(g.creativos) }} creativo{{ g.creativos === 1 ? '' : 's' }} · {{ formatNumeroExacto(g.totales.orders) }} órdenes · {{ formatNumeroExacto(g.totales.nc) }} NC</span>
                </div>
            </div>

            <p v-if="!etapas.length" class="empty-note">Ningún creativo coincide con esas búsquedas en esos meses.</p>

            <section v-for="e in etapas" :key="e.funnel" class="import-panel cg-etapa">
                <div class="cg-etapa-head">
                    <h2>{{ e.funnel === 'SIN' ? 'Sin etapa' : etiquetaFunnel(e.funnel) || e.funnel }}</h2>
                    <span v-if="e.ganadorIdx >= 0" class="cg-gana">
                        Gana <strong>{{ nombreGrupo(resultado[e.ganadorIdx]) }}</strong> en {{ ETIQUETA_KPI[e.filas[0].campo] }}
                    </span>
                </div>
                <div class="cg-tabla-wrap">
                    <table class="cg-tabla">
                        <thead>
                            <tr>
                                <th>KPI</th>
                                <th v-for="(g, i) in resultado" :key="i">
                                    <span class="cg-punto" :style="{ background: COLOR(i) }" aria-hidden="true" />
                                    {{ nombreGrupo(g) }}
                                    <span class="cg-th-sub">{{ e.porGrupo[i] ? `${e.porGrupo[i].creativos} creativo(s) · ${formatMoneyExacto(e.porGrupo[i].totales.cost)}` : 'sin creativos' }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in e.filas" :key="f.campo">
                                <td class="mono">{{ ETIQUETA_KPI[f.campo] }}</td>
                                <td v-for="(c, i) in f.celdas" :key="i" :class="{ ganador: f.mejor !== null && c?.valor === f.mejor }">
                                    <template v-if="c && c.valor !== null">
                                        <span class="cg-valor mono">{{ fmt(f.campo, c.valor) }}</span>
                                        <span v-if="f.mejor !== null && c.valor === f.mejor" class="cg-estrella" title="Mejor de los grupos">★</span>
                                        <span class="cg-bench">
                                            media del mes {{ fmt(f.campo, c.benchmark) }}
                                            <span v-if="c.estado" class="cg-estado" :class="c.estado">{{ ESTADO[c.estado] }}{{ c.estado !== 'media' ? ` ${c.pct}%` : '' }}</span>
                                        </span>
                                    </template>
                                    <span v-else class="cg-vacio">—</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="cg-top-label">Mejores creativos</td>
                                <td v-for="(eg, i) in e.porGrupo" :key="i">
                                    <ol v-if="eg && eg.top.length" class="cg-top">
                                        <li v-for="t in eg.top" :key="t.id" :title="t.nombre">
                                            <span class="cg-top-nombre">{{ t.nombre }}</span>
                                            <span class="mono">{{ fmt(t.campo, t.valor) }}</span>
                                        </li>
                                    </ol>
                                    <span v-else class="cg-vacio">Sin volumen suficiente</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <p class="cg-nota">
                KPIs sobre totales del grupo (costo total entre resultados totales). La media del mes es todo lo de esa etapa
                en ese mes, con los mismos filtros de plataforma y tipo de cuenta. "En la media" es ±10%.
            </p>

            <section v-for="(g, i) in resultado" :key="`lista-${i}`" class="cg-lista">
                <div class="cg-lista-head">
                    <span class="cg-punto" :style="{ background: COLOR(i) }" aria-hidden="true" />
                    <h2 class="cg-lista-titulo">{{ nombreGrupo(g) }}</h2>
                    <span class="cg-lista-cuenta mono">{{ g.lista.length }} creativo{{ g.lista.length === 1 ? '' : 's' }} · por inversión</span>
                </div>
                <p v-if="!g.lista.length" class="empty-note">Ningún creativo coincide con esta búsqueda en este mes.</p>
                <div v-else class="cg-grid">
                    <CreativeCard
                        v-for="(c, n) in verTodos[i] ? g.lista : g.lista.slice(0, VISIBLES)"
                        :key="c.id"
                        :creativo="c"
                        :rank="n + 1"
                        :mes="g.mes"
                        @abrir="abrir($event, g.mes)"
                    />
                </div>
                <button v-if="g.lista.length > VISIBLES" type="button" class="modal-copy-btn cg-ver-todos" @click="verTodos = { ...verTodos, [i]: !verTodos[i] }">
                    {{ verTodos[i] ? 'Ver menos' : `Ver los ${g.lista.length}` }}
                </button>
            </section>
        </template>

        <CreativeModal v-if="creativoAbierto" :creativo="creativoAbierto" :pais="pais" :mes="mesAbierto" @cerrar="creativoAbierto = null" />
    </section>
</template>

<style scoped>
.cg {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.cg-grupos {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.cg-grupo {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}
.cg-punto {
    display: inline-block;
    flex: none;
    width: 10px;
    height: 10px;
    border-radius: 999px;
    margin-bottom: 12px;
}
.cg-campo {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 240px;
}
.cg-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-faint);
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
    font: inherit;
    font-size: 0.85rem;
    transition: border-color 0.15s ease;
}
/* Sin el fondo claro del autocompletado del navegador. */
.buscador-campo input:-webkit-autofill {
    -webkit-box-shadow: 0 0 0 40px var(--bg) inset;
    -webkit-text-fill-color: var(--text);
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
.cg-lista {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 12px;
}
.cg-lista-head {
    display: flex;
    align-items: center;
    gap: 10px;
}
.cg-lista-head .cg-punto {
    margin: 0;
}
.cg-lista-titulo {
    margin: 0;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 16px;
    font-weight: 600;
}
.cg-lista-cuenta {
    font-size: 0.72rem;
    color: var(--text-faint);
}
.cg-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}
.cg-ver-todos {
    align-self: flex-start;
}
.cg-quitar {
    height: 38px;
    width: 38px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: none;
    color: var(--text-muted);
    font-size: 1.1rem;
    cursor: pointer;
}
.cg-quitar:hover {
    border-color: var(--coral);
    color: var(--coral);
}
.cg-agregar {
    align-self: flex-start;
}
.cg-acciones {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}
.cg-pdf {
    margin-left: auto;
}
.cg-hint {
    margin-top: -8px;
}
.cg-hint,
.cg-nota {
    font-size: 0.74rem;
    color: var(--text-faint);
}
.cg-error {
    margin: 0;
    color: var(--coral);
    font-size: 0.82rem;
}
.cg-resumen {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}
.cg-tarjeta {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-top: 3px solid var(--color-grupo);
    border-radius: 14px;
    background: var(--surface);
}
.cg-tarjeta-head {
    display: flex;
    align-items: center;
    gap: 8px;
}
.cg-tarjeta-head .cg-punto {
    margin: 0;
}
.cg-tarjeta-nombre {
    font-weight: 600;
    font-size: 0.9rem;
}
.cg-tarjeta-num {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 700;
}
.cg-tarjeta-sub {
    font-size: 0.74rem;
    color: var(--text-muted);
}
.cg-etapa-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.cg-etapa-head h2 {
    margin: 0;
}
.cg-gana {
    font-size: 0.82rem;
    color: var(--text-muted);
}
.cg-gana strong {
    color: var(--text);
}
.cg-tabla-wrap {
    overflow-x: auto;
    margin-top: 12px;
}
.cg-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
}
.cg-tabla th {
    padding: 0 12px 8px 0;
    text-align: left;
    vertical-align: bottom;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text);
    border-bottom: 1px solid var(--border);
}
.cg-tabla th:first-child {
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.cg-tabla th .cg-punto {
    margin: 0 4px 0 0;
}
.cg-th-sub {
    display: block;
    margin-top: 2px;
    font-weight: 400;
    color: var(--text-faint);
}
.cg-tabla td {
    padding: 10px 12px 10px 0;
    vertical-align: top;
    border-bottom: 1px solid var(--border);
}
.cg-tabla tr:last-child td {
    border-bottom: none;
}
.cg-valor {
    font-size: 1rem;
    font-weight: 600;
}
td.ganador .cg-valor {
    color: var(--mint);
}
.cg-estrella {
    margin-left: 4px;
    color: var(--mint);
}
.cg-bench {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 4px;
    font-size: 0.72rem;
    color: var(--text-faint);
}
.cg-estado {
    padding: 1px 7px;
    border-radius: 999px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.64rem;
    font-weight: 600;
    background: var(--surface-2);
    color: var(--text-muted);
}
.cg-estado.mejor {
    background: color-mix(in srgb, var(--mint) 18%, transparent);
    color: var(--mint);
}
.cg-estado.peor {
    background: color-mix(in srgb, var(--coral) 18%, transparent);
    color: var(--coral);
}
.cg-vacio,
.cg-top-label {
    color: var(--text-faint);
    font-size: 0.78rem;
}
.cg-top {
    margin: 0;
    padding-left: 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 0.76rem;
}
.cg-top li {
    max-width: 260px;
}
.cg-top-nombre {
    display: inline-block;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
    margin-right: 6px;
}
</style>
