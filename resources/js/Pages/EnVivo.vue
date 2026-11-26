<script setup>
// En vivo (2026-11-26, pedido explícito): campaña → conjunto / ad group →
// anuncio con lo que trae la API de Meta y TikTok cada hora. La columna "Δ"
// es cuánto se movió desde la actualización anterior. La página se recarga
// sola cada 5 minutos para tomar la corrida nueva (EnVivoController).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import AvisoCostosApi from '@/Components/AvisoCostosApi.vue';
import { mensajeDeError, notificar } from '@/avisos';

const props = defineProps({
    pais: { type: String, required: true },
    rango: { type: String, default: 'hoy' },
    desde: { type: String, required: true },
    hasta: { type: String, required: true },
    campanias: { type: Array, default: () => [] },
    conDelta: { type: Boolean, default: false },
    actualizadoDatos: { type: String, default: null },
});

const RANGOS = [
    { key: 'hoy', label: 'Hoy' },
    { key: 'ayer', label: 'Ayer' },
    { key: '7d', label: '7 días' },
    { key: 'mes', label: 'Mes' },
];
const PLATAFORMAS = [
    { key: 'todas', label: 'Todas' },
    { key: 'meta', label: 'Meta' },
    { key: 'tiktok', label: 'TikTok' },
];
const plataforma = ref('todas');
const busqueda = ref('');
const abiertas = ref(new Set());
const actualizando = ref(false);

const cambiarRango = (r) => router.get(location.pathname, { rango: r }, { preserveScroll: true, preserveState: true });

// --- Sumas: m = [costo, impresiones, clicks]; d = lo mismo desde la actualización anterior.
const suma = (lista) =>
    lista.reduce(
        (acc, x) => ({ m: acc.m.map((v, i) => v + x.m[i]), d: acc.d.map((v, i) => v + x.d[i]) }),
        { m: [0, 0, 0], d: [0, 0, 0] },
    );
const arbol = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.campanias
        .filter((c) => plataforma.value === 'todas' || c.plataforma === plataforma.value)
        .map((c) => {
            const conjuntos = c.conjuntos
                .map((g) => {
                    const coincide = !q || c.nombre.toLowerCase().includes(q) || g.nombre.toLowerCase().includes(q);
                    const anuncios = g.anuncios.filter((a) => coincide || a.nombre.toLowerCase().includes(q)).sort((a, b) => b.m[0] - a.m[0]);
                    return { ...g, anuncios, ...suma(anuncios) };
                })
                .filter((g) => g.anuncios.length)
                .sort((a, b) => b.m[0] - a.m[0]);
            return { ...c, conjuntos, ...suma(conjuntos) };
        })
        .filter((c) => c.conjuntos.length)
        .sort((a, b) => b.m[0] - a.m[0]);
});
const total = computed(() => suma(arbol.value));

const alternar = (k) => {
    const s = new Set(abiertas.value);
    s.has(k) ? s.delete(k) : s.add(k);
    abiertas.value = s;
};
const todoAbierto = computed(() => arbol.value.length > 0 && arbol.value.every((c) => abiertas.value.has(`c:${c.plataforma}:${c.id}`)));
function abrirTodo() {
    if (todoAbierto.value) {
        abiertas.value = new Set();
        return;
    }
    const s = new Set();
    arbol.value.forEach((c) => {
        s.add(`c:${c.plataforma}:${c.id}`);
        c.conjuntos.forEach((g) => s.add(`g:${c.plataforma}:${c.id}:${g.id}`));
    });
    abiertas.value = s;
}

// --- Formatos -------------------------------------------------------------------
const usd = (v) => '$' + v.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ent = (v) => Math.round(v).toLocaleString('es-MX');
const pct = (v) => (v == null ? '—' : v.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%');
const ctr = (m) => (m[1] > 0 ? (m[2] / m[1]) * 100 : null);
const cpm = (m) => (m[1] > 0 ? usd((m[0] / m[1]) * 1000) : '—');
const cpc = (m) => (m[2] > 0 ? usd(m[0] / m[2]) : '—');
const delta = (v, fmt) => (v > 0.004 ? `+${fmt(v)}` : v < -0.004 ? `−${fmt(-v)}` : '');
const NOMBRE_PLATAFORMA = { meta: 'Meta', tiktok: 'TikTok' };

// --- Actualizar --------------------------------------------------------------------
async function actualizarAhora() {
    actualizando.value = true;
    try {
        const { data } = await axios.post(`${location.pathname}/actualizar`);
        if (data.errores?.length) {
            notificar({ tipo: 'error', titulo: 'Actualizado con errores', texto: data.errores.join(' ') });
        } else {
            notificar({ tipo: 'exito', titulo: 'Actualizado', texto: `Se trajeron ${data.cuentas.length} cuenta(s) de Meta y TikTok.` });
        }
        router.reload({ preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo actualizar', texto: mensajeDeError(err) });
    } finally {
        actualizando.value = false;
    }
}
let recarga = null;
onMounted(() => (recarga = setInterval(() => router.reload({ preserveScroll: true, preserveState: true }), 5 * 60000)));
onBeforeUnmount(() => clearInterval(recarga));
</script>

<template>
    <Head title="En vivo" />
    <DashboardLayout :pais="pais" vista-activa="en-vivo">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">En vivo</h1>
                        <p class="subtitle">
                            Campaña, conjunto y anuncio como los trae la API de Meta y TikTok. Costo en USD; en Meta los clicks son al enlace.
                            <template v-if="conDelta">"Δ" es lo que cambió desde la actualización anterior.</template>
                        </p>
                        <AvisoCostosApi />
                    </div>
                    <button type="button" class="btn-primary" :disabled="actualizando" @click="actualizarAhora">
                        {{ actualizando ? 'Actualizando…' : 'Actualizar ahora' }}
                    </button>
                </header>

                <div class="filtros">
                    <div class="periodo-toggle" role="group" aria-label="Periodo">
                        <button v-for="r in RANGOS" :key="r.key" type="button" :class="{ activo: rango === r.key }" @click="cambiarRango(r.key)">{{ r.label }}</button>
                    </div>
                    <div class="periodo-toggle" role="group" aria-label="Plataforma">
                        <button v-for="p in PLATAFORMAS" :key="p.key" type="button" :class="{ activo: plataforma === p.key }" @click="plataforma = p.key">{{ p.label }}</button>
                    </div>
                    <input v-model="busqueda" type="search" class="buscar" placeholder="Buscar campaña, conjunto o anuncio" />
                    <button type="button" class="modal-copy-btn" :disabled="!arbol.length" @click="abrirTodo">{{ todoAbierto ? 'Cerrar todo' : 'Abrir todo' }}</button>
                </div>

                <div class="kpis">
                    <div class="kpi">
                        <span class="kpi-label">Costo</span><span class="kpi-num">{{ usd(total.m[0]) }}</span>
                        <span v-if="conDelta && delta(total.d[0], usd)" class="kpi-delta mono">{{ delta(total.d[0], usd) }}</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Impresiones</span><span class="kpi-num">{{ ent(total.m[1]) }}</span>
                        <span v-if="conDelta && delta(total.d[1], ent)" class="kpi-delta mono">{{ delta(total.d[1], ent) }}</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Clicks</span><span class="kpi-num">{{ ent(total.m[2]) }}</span>
                        <span v-if="conDelta && delta(total.d[2], ent)" class="kpi-delta mono">{{ delta(total.d[2], ent) }}</span>
                    </div>
                    <div class="kpi"><span class="kpi-label">CTR</span><span class="kpi-num">{{ pct(ctr(total.m)) }}</span></div>
                    <div class="kpi"><span class="kpi-label">CPM</span><span class="kpi-num">{{ cpm(total.m) }}</span></div>
                    <div class="kpi"><span class="kpi-label">CPC</span><span class="kpi-num">{{ cpc(total.m) }}</span></div>
                </div>

                <section class="import-panel panel">
                    <p v-if="!campanias.length" class="empty-note">
                        No hay datos de la API para este periodo. Si es la primera vez, toca "Actualizar ahora" o espera a la próxima actualización.
                    </p>
                    <p v-else-if="!arbol.length" class="empty-note">Ninguna campaña coincide con la búsqueda.</p>
                    <div v-else class="tabla-wrap">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th class="col-nombre">Campaña / conjunto / anuncio</th>
                                    <th>Costo</th>
                                    <th v-if="conDelta" class="col-delta">Δ</th>
                                    <th>Impresiones</th>
                                    <th v-if="conDelta" class="col-delta">Δ</th>
                                    <th>Clicks</th>
                                    <th v-if="conDelta" class="col-delta">Δ</th>
                                    <th>CTR</th>
                                    <th>CPM</th>
                                    <th>CPC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="c in arbol" :key="`${c.plataforma}:${c.id}`">
                                    <tr class="fila-campania" @click="alternar(`c:${c.plataforma}:${c.id}`)">
                                        <td class="col-nombre">
                                            <span class="flecha" :class="{ abierta: abiertas.has(`c:${c.plataforma}:${c.id}`) }">›</span>
                                            <span class="medio mono" :class="c.plataforma">{{ NOMBRE_PLATAFORMA[c.plataforma] }}</span>
                                            <span class="nombre" :title="c.nombre">{{ c.nombre }}</span>
                                        </td>
                                        <td class="num">{{ usd(c.m[0]) }}</td>
                                        <td v-if="conDelta" class="num col-delta mono">{{ delta(c.d[0], usd) }}</td>
                                        <td class="num">{{ ent(c.m[1]) }}</td>
                                        <td v-if="conDelta" class="num col-delta mono">{{ delta(c.d[1], ent) }}</td>
                                        <td class="num">{{ ent(c.m[2]) }}</td>
                                        <td v-if="conDelta" class="num col-delta mono">{{ delta(c.d[2], ent) }}</td>
                                        <td class="num">{{ pct(ctr(c.m)) }}</td>
                                        <td class="num">{{ cpm(c.m) }}</td>
                                        <td class="num">{{ cpc(c.m) }}</td>
                                    </tr>
                                    <template v-if="abiertas.has(`c:${c.plataforma}:${c.id}`)">
                                        <template v-for="g in c.conjuntos" :key="`${c.plataforma}:${c.id}:${g.id}`">
                                            <tr class="fila-conjunto" @click="alternar(`g:${c.plataforma}:${c.id}:${g.id}`)">
                                                <td class="col-nombre">
                                                    <span class="flecha" :class="{ abierta: abiertas.has(`g:${c.plataforma}:${c.id}:${g.id}`) }">›</span>
                                                    <span class="nombre" :title="g.nombre">{{ g.nombre }}</span>
                                                    <span class="cuenta mono">{{ g.anuncios.length }} anuncio{{ g.anuncios.length === 1 ? '' : 's' }}</span>
                                                </td>
                                                <td class="num">{{ usd(g.m[0]) }}</td>
                                                <td v-if="conDelta" class="num col-delta mono">{{ delta(g.d[0], usd) }}</td>
                                                <td class="num">{{ ent(g.m[1]) }}</td>
                                                <td v-if="conDelta" class="num col-delta mono">{{ delta(g.d[1], ent) }}</td>
                                                <td class="num">{{ ent(g.m[2]) }}</td>
                                                <td v-if="conDelta" class="num col-delta mono">{{ delta(g.d[2], ent) }}</td>
                                                <td class="num">{{ pct(ctr(g.m)) }}</td>
                                                <td class="num">{{ cpm(g.m) }}</td>
                                                <td class="num">{{ cpc(g.m) }}</td>
                                            </tr>
                                            <template v-if="abiertas.has(`g:${c.plataforma}:${c.id}:${g.id}`)">
                                                <tr v-for="a in g.anuncios" :key="a.id" class="fila-anuncio">
                                                    <td class="col-nombre">
                                                        <span class="nombre" :title="a.nombre">{{ a.nombre }}</span>
                                                    </td>
                                                    <td class="num">{{ usd(a.m[0]) }}</td>
                                                    <td v-if="conDelta" class="num col-delta mono">{{ delta(a.d[0], usd) }}</td>
                                                    <td class="num">{{ ent(a.m[1]) }}</td>
                                                    <td v-if="conDelta" class="num col-delta mono">{{ delta(a.d[1], ent) }}</td>
                                                    <td class="num">{{ ent(a.m[2]) }}</td>
                                                    <td v-if="conDelta" class="num col-delta mono">{{ delta(a.d[2], ent) }}</td>
                                                    <td class="num">{{ pct(ctr(a.m)) }}</td>
                                                    <td class="num">{{ cpm(a.m) }}</td>
                                                    <td class="num">{{ cpc(a.m) }}</td>
                                                </tr>
                                            </template>
                                        </template>
                                    </template>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </DashboardLayout>
</template>

<style scoped>
.page {
    min-height: calc(100vh / var(--zoom-factor));
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
    margin-bottom: 24px;
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
    max-width: 720px;
}
.btn-primary:disabled,
.modal-copy-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Filtros */
.filtros {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
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
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 0.78rem;
    padding: 5px 10px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.periodo-toggle button.activo {
    background: var(--amber);
    color: var(--amber-ink);
    font-weight: 600;
}
.periodo-toggle button:focus-visible,
.buscar:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.buscar {
    flex: 1;
    min-width: 220px;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 7px 10px;
    font-size: 0.82rem;
}

/* KPIs */
.kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 18px;
}
.kpi {
    background: var(--surface);
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.kpi-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
}
.kpi-num {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.5rem;
    font-weight: 600;
}
.kpi-delta {
    font-size: 0.72rem;
    color: var(--mint);
}

/* Tabla en árbol */
.tabla-wrap {
    overflow-x: auto;
}
.tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}
.tabla th {
    text-align: right;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-faint);
    padding: 8px 10px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.tabla th.col-nombre {
    text-align: left;
}
.tabla td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.tabla td.col-nombre {
    min-width: 320px;
    max-width: 520px;
}
.col-nombre > span {
    vertical-align: middle;
    margin-right: 8px;
}
.nombre {
    display: inline-block;
    max-width: 420px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.col-delta {
    color: var(--mint);
    font-size: 0.72rem;
}
.fila-campania,
.fila-conjunto {
    cursor: pointer;
}
.fila-campania:hover td,
.fila-conjunto:hover td {
    background: var(--surface-2);
}
.fila-campania td {
    font-weight: 600;
}
.fila-conjunto .col-nombre {
    padding-left: 34px;
    color: var(--text);
}
.fila-anuncio .col-nombre {
    padding-left: 64px;
    color: var(--text-muted);
}
.flecha {
    display: inline-block;
    width: 12px;
    color: var(--text-faint);
    transition: transform 0.15s ease;
}
.flecha.abierta {
    transform: rotate(90deg);
}
.medio {
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 2px 7px;
    border-radius: 999px;
    color: var(--sky);
    background: color-mix(in srgb, var(--sky) 20%, transparent);
}
.medio.tiktok {
    color: var(--violet);
    background: color-mix(in srgb, var(--violet) 20%, transparent);
}
.cuenta {
    font-size: 0.68rem;
    color: var(--text-faint);
    font-weight: 400;
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
    margin: 0;
}
</style>
