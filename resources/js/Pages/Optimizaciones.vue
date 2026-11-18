<script setup>
import { ref, computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import InfoTip from '@/Components/InfoTip.vue';
import { confirmar, mensajeDeError, notificar } from '@/avisos';

// Optimizaciones (2026-11-18, pedido explícito): la bitácora de lo que el
// equipo hace en las plataformas -- creación, pautas, ajustes de dinero o de
// anuncios, apagados. Antes vivía en optimizaciones.2026 / ✅OPTIS de cada
// Daily Cuentas; se captura aquí y lo de la hoja se importa.
const props = defineProps({
    pais: { type: String, required: true },
    mes: { type: String, default: null },
    meses: { type: Array, default: () => [] },
    optimizaciones: { type: Array, default: () => [] },
    medios: { type: Array, default: () => [] },
    tipos: { type: Array, default: () => [] },
    campaniasSugeridas: { type: Array, default: () => [] },
    yo: { type: String, default: '' },
});

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const etiquetaMes = (m) => (m === 'todo' ? 'Todo' : `${MESES[+m.slice(5, 7) - 1].replace(/^./, (c) => c.toUpperCase())} ${m.slice(0, 4)}`);
const MES_OPCIONES = computed(() => [...props.meses.map((m) => ({ key: m, label: etiquetaMes(m) })), { key: 'todo', label: 'Todo' }]);
const cambiarMes = (mes) => router.get(location.pathname, { mes }, { preserveScroll: true });

const tipoDe = (k) => props.tipos.find((t) => t.key === k) ?? { label: k, grupo: 'otro' };
const nombreMedio = (id) => props.medios.find((m) => m.id === id)?.nombre ?? (id ? id.replace(/^./, (c) => c.toUpperCase()) : 'Sin medio');
const colorMedio = (id) => props.medios.find((m) => m.id === id)?.color ?? null;
const GRUPOS = [
    { key: 'crear', label: 'Creación' },
    { key: 'pautar', label: 'Pautas' },
    { key: 'ajustar', label: 'Ajustes' },
    { key: 'apagar', label: 'Apagados' },
];

// --- Filtros -----------------------------------------------------------------------
const filtroMedio = ref('todos');
const filtroTipo = ref('todos');
const filtroResp = ref('todos');
const filtroGrupo = ref(null);
const busqueda = ref('');
const MEDIO_OPCIONES = computed(() => [{ key: 'todos', label: 'Todos' }, ...[...new Set(props.optimizaciones.map((o) => o.medio ?? ''))].map((m) => ({ key: m, label: m ? nombreMedio(m) : 'Sin medio' }))]);
const TIPO_OPCIONES = computed(() => [{ key: 'todos', label: 'Todos' }, ...props.tipos.filter((t) => props.optimizaciones.some((o) => o.tipo === t.key)).map((t) => ({ key: t.key, label: t.label }))]);
const RESP_OPCIONES = computed(() => [{ key: 'todos', label: 'Todos' }, ...[...new Set(props.optimizaciones.map((o) => o.responsable ?? '—'))].sort().map((r) => ({ key: r, label: r }))]);
const visibles = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.optimizaciones.filter(
        (o) =>
            (filtroMedio.value === 'todos' || (o.medio ?? '') === filtroMedio.value) &&
            (filtroTipo.value === 'todos' || o.tipo === filtroTipo.value) &&
            (filtroResp.value === 'todos' || (o.responsable ?? '—') === filtroResp.value) &&
            (!filtroGrupo.value || tipoDe(o.tipo).grupo === filtroGrupo.value) &&
            (!q || `${o.descripcion ?? ''} ${o.comentarios ?? ''} ${o.campanias.join(' ')} ${o.responsable ?? ''}`.toLowerCase().includes(q)),
    );
});
const hayFiltros = computed(() => filtroMedio.value !== 'todos' || filtroTipo.value !== 'todos' || filtroResp.value !== 'todos' || filtroGrupo.value || busqueda.value);
function limpiarFiltros() {
    filtroMedio.value = 'todos';
    filtroTipo.value = 'todos';
    filtroResp.value = 'todos';
    filtroGrupo.value = null;
    busqueda.value = '';
}

// --- Resumen -----------------------------------------------------------------------
const porGrupo = computed(() => GRUPOS.map((g) => ({ ...g, n: visibles.value.filter((o) => tipoDe(o.tipo).grupo === g.key).length })));
const porTipo = computed(() => {
    const c = {};
    for (const o of visibles.value) c[o.tipo] = (c[o.tipo] ?? 0) + 1;
    const max = Math.max(1, ...Object.values(c));
    return Object.entries(c).sort((a, b) => b[1] - a[1]).map(([k, n]) => ({ k, label: tipoDe(k).label, grupo: tipoDe(k).grupo, n, w: `${(n / max) * 100}%` }));
});
const porResponsable = computed(() => {
    const c = {};
    for (const o of visibles.value) c[o.responsable ?? '—'] = (c[o.responsable ?? '—'] ?? 0) + 1;
    return Object.entries(c).sort((a, b) => b[1] - a[1]).slice(0, 6);
});

// --- Línea de tiempo por día ---------------------------------------------------------
const PASO = 120;
const limite = ref(PASO);
const porDia = computed(() => {
    const grupos = [];
    for (const o of visibles.value.slice(0, limite.value)) {
        if (!grupos.length || grupos.at(-1).fecha !== o.fecha) grupos.push({ fecha: o.fecha, items: [] });
        grupos.at(-1).items.push(o);
    }
    return grupos;
});
const diaLargo = (f) => {
    const d = new Date(`${f}T00:00:00Z`);
    return `${DIAS[d.getUTCDay()]} ${d.getUTCDate()} ${MES_CORTO[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
};
const abiertas = ref(new Set());
const alternarCampanias = (id) => {
    const s = new Set(abiertas.value);
    s.has(id) ? s.delete(id) : s.add(id);
    abiertas.value = s;
};

// --- Capturar / editar ---------------------------------------------------------------
const hoy = new Date();
const hoyIso = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;
const modal = ref(false);
const editando = ref(null);
const form = reactive({ fecha: hoyIso, medio: '', campanias: [], tipo: 'ajuste_dinero', descripcion: '', comentarios: '', responsable: '' });
const nuevaCampania = ref('');
const guardando = ref(false);
const errorForm = ref('');
const sugeridas = computed(() => {
    const q = nuevaCampania.value.trim().toLowerCase();
    return props.campaniasSugeridas
        .filter((c) => (!form.medio || c.medio === form.medio) && !form.campanias.includes(c.campania) && (!q || c.campania.toLowerCase().includes(q)))
        .slice(0, 8);
});
function abrir(o = null) {
    editando.value = o;
    Object.assign(form, o
        ? { fecha: o.fecha, medio: o.medio ?? '', campanias: [...o.campanias], tipo: o.tipo, descripcion: o.descripcion ?? '', comentarios: o.comentarios ?? '', responsable: o.responsable ?? '' }
        : { fecha: hoyIso, medio: props.medios[0]?.id ?? '', campanias: [], tipo: 'ajuste_dinero', descripcion: '', comentarios: '', responsable: props.yo });
    nuevaCampania.value = '';
    errorForm.value = '';
    modal.value = true;
}
function agregarCampania(nombre = nuevaCampania.value) {
    const n = nombre.trim();
    if (n && !form.campanias.includes(n)) form.campanias.push(n);
    nuevaCampania.value = '';
}
async function guardar() {
    if (nuevaCampania.value.trim()) agregarCampania();
    guardando.value = true;
    errorForm.value = '';
    try {
        const datos = { ...form, medio: form.medio || null, comentarios: form.comentarios || null, responsable: form.responsable || null };
        if (editando.value) {
            await axios.patch(`${location.pathname}/${editando.value.id}`, datos);
            notificar({ tipo: 'exito', titulo: 'Optimización actualizada' });
            modal.value = false;
            router.reload({ preserveScroll: true });
        } else {
            const { data } = await axios.post(location.pathname, datos);
            notificar({ tipo: 'exito', titulo: 'Optimización registrada', texto: `${tipoDe(form.tipo).label} · ${diaLargo(form.fecha)}` });
            modal.value = false;
            if (props.mes !== 'todo' && data.mes !== props.mes) cambiarMes(data.mes);
            else router.reload({ preserveScroll: true });
        }
    } catch (err) {
        errorForm.value = mensajeDeError(err);
    } finally {
        guardando.value = false;
    }
}
function borrar(o) {
    confirmar({
        tipo: 'peligro',
        titulo: 'Borrar optimización',
        texto: `${tipoDe(o.tipo).label} del ${diaLargo(o.fecha)}. Queda registrado en el Historial de cambios.`,
        confirmar: 'Sí, borrar',
        accion: async () => {
            await axios.delete(`${location.pathname}/${o.id}`);
            router.reload({ preserveScroll: true });
        },
        exito: 'Optimización borrada',
    });
}

// --- Sincronizar con Meta y TikTok (lo mismo que corre cada mañana) -----------------
const sincronizando = ref(false);
async function sincronizar() {
    sincronizando.value = true;
    try {
        const { data } = await axios.post(`${location.pathname}/sincronizar`);
        notificar({
            tipo: data.errores.length ? 'error' : 'exito',
            titulo: `${data.meta + data.tiktok} optimizaciones nuevas`,
            texto: `${data.meta} de Meta y ${data.tiktok} de TikTok.${data.errores.length ? ` Problemas: ${data.errores.join(' · ')}` : ''}`,
        });
        router.reload({ preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo sincronizar', texto: mensajeDeError(err) });
    } finally {
        sincronizando.value = false;
    }
}
const ORIGEN = { hoja: ['hoja', 'Importada de la hoja'], meta: ['Meta auto', 'Del historial de actividad de Meta'], tiktok: ['TikTok auto', 'Cambio detectado en TikTok'] };

// --- Importar de la hoja ---------------------------------------------------------------
const archivo = ref(null);
const subiendo = ref(false);
async function importar(e) {
    const f = e.target.files?.[0];
    if (!f) return;
    subiendo.value = true;
    try {
        const datos = new FormData();
        datos.append('archivo', f);
        const { data } = await axios.post(`${location.pathname}/importar`, datos);
        notificar({
            tipo: 'exito',
            titulo: `${data.filas} optimizaciones importadas`,
            texto: `Del ${data.desde} al ${data.hasta}.${data.sinFecha ? ` ${data.sinFecha} renglones sin fecha no se importaron.` : ''}`,
        });
        router.get(location.pathname, { mes: 'todo' }, { preserveScroll: true });
    } catch (err) {
        notificar({ tipo: 'error', titulo: 'No se pudo importar', texto: mensajeDeError(err) });
    } finally {
        subiendo.value = false;
        if (archivo.value) archivo.value.value = '';
    }
}
</script>

<template>
    <Head :title="`Optimizaciones — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="optimizaciones">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Optimizaciones</h1>
                        <p class="subtitle">Todo lo que se hace en las plataformas: creación, pautas, ajustes de dinero o de anuncios y apagados. Se ven también en el histórico de Control de gasto.</p>
                    </div>
                    <div class="acciones">
                        <label class="modal-copy-btn subir" :class="{ deshabilitado: subiendo }">
                            {{ subiendo ? 'Importando…' : 'Importar de la hoja' }}
                            <input ref="archivo" type="file" accept=".csv,text/csv" :disabled="subiendo" @change="importar" />
                        </label>
                        <InfoTip etiqueta="Qué archivo subir" lado="izquierda">
                            En el Daily Cuentas del país, pestaña optimizaciones.2026 (Perú: ✅OPTIS) → Archivo → Descargar → CSV. Volver a subir reemplaza lo
                            importado de esas fechas; lo capturado aquí y lo que llega solo de Meta / TikTok no se toca. Sirve para el historial: Meta solo
                            guarda ~1 semana de actividad.
                        </InfoTip>
                        <button type="button" class="modal-copy-btn" :disabled="sincronizando" title="Trae los cambios de Meta y TikTok (también corre solo cada mañana)" @click="sincronizar">
                            {{ sincronizando ? 'Sincronizando…' : 'Sincronizar Meta y TikTok' }}
                        </button>
                        <button type="button" class="btn-primary" @click="abrir()">Nueva optimización</button>
                    </div>
                </header>

                <div class="resumen-filtros">
                    <FiltroDropdown label="Mes" :model-value="mes" :options="MES_OPCIONES" @update:model-value="cambiarMes" />
                    <FiltroDropdown label="Medio" v-model="filtroMedio" :options="MEDIO_OPCIONES" />
                    <FiltroDropdown label="Tipo" v-model="filtroTipo" :options="TIPO_OPCIONES" />
                    <FiltroDropdown label="Responsable" v-model="filtroResp" :options="RESP_OPCIONES" />
                    <label class="buscador">
                        <span class="periodo-label mono">Buscar</span>
                        <input v-model="busqueda" type="search" autocomplete="off" placeholder="Campaña, descripción…" />
                    </label>
                </div>

                <p v-if="!optimizaciones.length" class="empty-note">
                    No hay optimizaciones en {{ etiquetaMes(mes).toLowerCase() }}. Registra una o importa la pestaña de la hoja.
                </p>

                <template v-else>
                    <!-- Resumen -->
                    <section class="resumen">
                        <button type="button" class="tarjeta total" :class="{ activa: !filtroGrupo }" @click="filtroGrupo = null">
                            <span class="kpi-label">Total</span>
                            <b class="num">{{ visibles.length }}</b>
                            <span class="sub">de {{ optimizaciones.length }} en {{ etiquetaMes(mes).toLowerCase() }}</span>
                        </button>
                        <button v-for="g in porGrupo" :key="g.key" type="button" class="tarjeta" :class="[`g-${g.key}`, { activa: filtroGrupo === g.key }]" @click="filtroGrupo = filtroGrupo === g.key ? null : g.key">
                            <span class="kpi-label">{{ g.label }}</span>
                            <b class="num">{{ g.n }}</b>
                        </button>
                        <div class="tarjeta lista">
                            <span class="kpi-label">Por tipo</span>
                            <div v-for="t in porTipo.slice(0, 5)" :key="t.k" class="barra-tipo">
                                <span>{{ t.label }}</span>
                                <span class="pista"><i :class="`g-${t.grupo}`" :style="{ width: t.w }" /></span>
                                <b class="mono">{{ t.n }}</b>
                            </div>
                        </div>
                        <div class="tarjeta lista">
                            <span class="kpi-label">Quién</span>
                            <div v-for="[r, n] in porResponsable" :key="r" class="fila-resp">
                                <span>{{ r }}</span><b class="mono">{{ n }}</b>
                            </div>
                        </div>
                    </section>

                    <p v-if="hayFiltros" class="filtrado mono">
                        Mostrando {{ visibles.length }} de {{ optimizaciones.length }} · <button type="button" class="link" @click="limpiarFiltros">quitar filtros</button>
                    </p>

                    <!-- Línea de tiempo -->
                    <section v-for="d in porDia" :key="d.fecha" class="dia">
                        <h2 class="dia-titulo mono">{{ diaLargo(d.fecha) }} <span>· {{ d.items.length }}</span></h2>
                        <ol class="items">
                            <li v-for="o in d.items" :key="o.id" class="item">
                                <div class="item-cabeza">
                                    <span class="tipo mono" :class="`g-${tipoDe(o.tipo).grupo}`">{{ tipoDe(o.tipo).label }}</span>
                                    <span class="medio"><i v-if="colorMedio(o.medio)" class="punto" :style="{ background: `var(--${colorMedio(o.medio)})` }" />{{ nombreMedio(o.medio) }}</span>
                                    <span class="resp">{{ o.responsable ?? '—' }}</span>
                                    <span v-if="ORIGEN[o.origen]" class="origen mono" :class="o.origen" :title="ORIGEN[o.origen][1]">{{ ORIGEN[o.origen][0] }}</span>
                                    <span class="item-acciones">
                                        <button type="button" class="link" @click="abrir(o)">Editar</button>
                                        <button type="button" class="link borrar" @click="borrar(o)">Borrar</button>
                                    </span>
                                </div>
                                <p class="descripcion">{{ o.descripcion }}</p>
                                <p v-if="o.comentarios" class="comentarios">{{ o.comentarios }}</p>
                                <div v-if="o.campanias.length" class="campanias">
                                    <span v-for="c in abiertas.has(o.id) ? o.campanias : o.campanias.slice(0, 2)" :key="c" class="campania mono" :title="c">{{ c }}</span>
                                    <button v-if="o.campanias.length > 2" type="button" class="link" @click="alternarCampanias(o.id)">
                                        {{ abiertas.has(o.id) ? 'ver menos' : `+${o.campanias.length - 2} campañas` }}
                                    </button>
                                </div>
                            </li>
                        </ol>
                    </section>
                    <div v-if="visibles.length > limite" class="mas">
                        <button type="button" class="btn-primary" @click="limite += PASO">Ver más ({{ visibles.length - limite }})</button>
                    </div>
                    <p v-if="!visibles.length" class="empty-note">Nada con estos filtros.</p>
                </template>
            </div>
        </div>

        <!-- Capturar / editar -->
        <div v-if="modal" class="modal-overlay" @click.self="modal = false" @keydown.esc="modal = false">
            <div class="modal modal-opti" role="dialog" aria-modal="true" :aria-label="editando ? 'Editar optimización' : 'Nueva optimización'">
                <button type="button" class="modal-close" aria-label="Cerrar" @click="modal = false">✕</button>
                <h2>{{ editando ? 'Editar optimización' : 'Nueva optimización' }}</h2>
                <form class="form" @submit.prevent="guardar">
                    <label>
                        <span class="periodo-label mono">Fecha</span>
                        <input v-model="form.fecha" type="date" required />
                    </label>
                    <label>
                        <span class="periodo-label mono">Medio</span>
                        <select v-model="form.medio">
                            <option value="">Sin medio / varios</option>
                            <option v-for="m in medios" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                        </select>
                    </label>
                    <label class="ancho">
                        <span class="periodo-label mono">Tipo</span>
                        <select v-model="form.tipo" required>
                            <optgroup v-for="g in [...GRUPOS, { key: 'otro', label: 'Otro' }]" :key="g.key" :label="g.label">
                                <option v-for="t in tipos.filter((t) => t.grupo === g.key)" :key="t.key" :value="t.key">{{ t.label }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <div class="ancho">
                        <span class="periodo-label mono">Campañas ({{ form.campanias.length }})</span>
                        <div v-if="form.campanias.length" class="elegidas">
                            <span v-for="c in form.campanias" :key="c" class="campania mono">
                                {{ c }}
                                <button type="button" :aria-label="`Quitar ${c}`" @click="form.campanias = form.campanias.filter((x) => x !== c)">×</button>
                            </span>
                        </div>
                        <input v-model="nuevaCampania" type="text" placeholder="Busca o pega el nombre y presiona Enter" @keydown.enter.prevent="agregarCampania()" />
                        <div v-if="sugeridas.length && nuevaCampania" class="sugeridas">
                            <button v-for="s in sugeridas" :key="s.campania" type="button" class="mono" @click="agregarCampania(s.campania)">{{ s.campania }}</button>
                        </div>
                    </div>
                    <label class="ancho">
                        <span class="periodo-label mono">Qué se hizo</span>
                        <textarea v-model="form.descripcion" rows="3" required placeholder="Ej. Se restan $200 al ppto de Purchase Advantage y se pasan a Conv. RMK" />
                    </label>
                    <label class="ancho">
                        <span class="periodo-label mono">Comentarios / documento (opcional)</span>
                        <input v-model="form.comentarios" type="text" placeholder="Link a la ODT, nota…" />
                    </label>
                    <label>
                        <span class="periodo-label mono">Responsable</span>
                        <input v-model="form.responsable" type="text" />
                    </label>
                    <p v-if="errorForm" class="error ancho">{{ errorForm }}</p>
                    <div class="botones ancho">
                        <button type="button" class="modal-copy-btn" @click="modal = false">Cancelar</button>
                        <button type="submit" class="btn-primary" :disabled="guardando">{{ guardando ? 'Guardando…' : 'Guardar' }}</button>
                    </div>
                </form>
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
    max-width: 1200px;
    margin: 0 auto;
    padding: 32px 32px 64px;
}
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
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
    max-width: 640px;
}
.acciones {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.subir {
    position: relative;
    cursor: pointer;
}
.subir input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}
.subir.deshabilitado {
    opacity: 0.5;
}
.periodo-label,
.kpi-label {
    font-size: 0.64rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.buscador {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1 1 220px;
}
.buscador input {
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 0.85rem;
}
.buscador input:focus {
    outline: none;
    border-color: var(--amber);
}
.empty-note {
    color: var(--text-muted);
    text-align: center;
    padding: 24px 0;
}
.link {
    background: none;
    border: none;
    color: var(--amber);
    font: inherit;
    font-size: 0.74rem;
    cursor: pointer;
    padding: 0;
}
.link.borrar {
    color: var(--coral);
}
.num {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
}

/* Colores por grupo */
.g-crear {
    --g: var(--mint);
}
.g-pautar {
    --g: var(--sky);
}
.g-ajustar {
    --g: var(--yellow);
}
.g-apagar {
    --g: var(--coral);
}
.g-otro {
    --g: var(--text-faint);
}

/* Resumen */
.resumen {
    display: grid;
    grid-template-columns: repeat(5, minmax(110px, 0.6fr)) minmax(220px, 1.6fr) minmax(160px, 1fr);
    gap: 10px;
    margin-bottom: 14px;
}
.tarjeta {
    background: var(--surface);
    border: 1px solid var(--border);
    border-top: 2px solid var(--g, var(--border));
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    color: var(--text);
    font: inherit;
    text-align: left;
    min-width: 0;
}
button.tarjeta {
    cursor: pointer;
}
button.tarjeta:hover,
.tarjeta.activa {
    border-color: var(--amber);
}
.tarjeta b {
    font-size: 1.6rem;
    font-weight: 600;
    line-height: 1.1;
}
.tarjeta .sub {
    font-size: 0.68rem;
    color: var(--text-faint);
}
.tarjeta.lista {
    gap: 6px;
}
.barra-tipo {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(40px, 1fr) 28px;
    gap: 8px;
    align-items: center;
    font-size: 0.72rem;
}
.barra-tipo span:first-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.barra-tipo b,
.fila-resp b {
    text-align: right;
    font-size: 0.7rem;
}
.pista {
    height: 6px;
    border-radius: 999px;
    background: var(--surface-2);
    overflow: hidden;
}
.pista i {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--g);
}
.fila-resp {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 0.74rem;
}
.filtrado {
    font-size: 0.7rem;
    color: var(--text-faint);
    margin: 0 0 12px;
}

/* Línea de tiempo */
.dia {
    margin-bottom: 18px;
}
.dia-titulo {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-faint);
    margin: 0 0 8px;
}
.dia-titulo span {
    color: var(--text-faint);
    opacity: 0.7;
}
.items {
    list-style: none;
    margin: 0;
    padding: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
}
.item {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    border-left: 3px solid var(--g, transparent);
}
.item:last-child {
    border-bottom: none;
}
.item-cabeza {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 0.78rem;
}
.tipo {
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border-radius: 999px;
    padding: 2px 9px;
    background: color-mix(in srgb, var(--g) 18%, transparent);
    color: var(--g);
}
.medio {
    display: inline-flex;
    align-items: center;
    color: var(--text-muted);
}
.punto {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    margin-right: 6px;
    display: inline-block;
}
.resp {
    color: var(--text-muted);
}
.origen {
    font-size: 0.6rem;
    color: var(--text-faint);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 0 6px;
}
.origen.meta {
    color: var(--serie-1);
    border-color: color-mix(in srgb, var(--serie-1) 40%, transparent);
}
.origen.tiktok {
    color: var(--serie-6);
    border-color: color-mix(in srgb, var(--serie-6) 40%, transparent);
}
.item-acciones {
    margin-left: auto;
    display: inline-flex;
    gap: 12px;
}
.descripcion {
    margin: 6px 0 0;
    font-size: 0.86rem;
    line-height: 1.45;
    white-space: pre-line;
}
.comentarios {
    margin: 4px 0 0;
    font-size: 0.74rem;
    color: var(--text-muted);
    white-space: pre-line;
    word-break: break-word;
}
.campanias,
.elegidas {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
    align-items: center;
}
.campania {
    font-size: 0.64rem;
    background: var(--surface-2);
    border-radius: 6px;
    padding: 2px 8px;
    color: var(--text-muted);
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.elegidas .campania button {
    background: none;
    border: none;
    color: var(--text-faint);
    cursor: pointer;
    margin-left: 4px;
}
.mas {
    display: flex;
    justify-content: center;
}

/* Modal */
.modal.modal-opti {
    display: block;
    max-width: 680px;
    padding: 24px;
    color: var(--text);
    overflow: auto;
}
.modal-opti h2 {
    margin: 0 0 14px;
}
.form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.form label,
.form > div {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.form .ancho {
    grid-column: 1 / -1;
}
.form input,
.form select,
.form textarea {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
    font: inherit;
    font-size: 0.85rem;
    color-scheme: dark light;
}
.form input:focus,
.form select:focus,
.form textarea:focus {
    outline: none;
    border-color: var(--amber);
}
.sugeridas {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border);
    border-radius: 8px;
    max-height: 200px;
    overflow: auto;
}
.sugeridas button {
    background: none;
    border: none;
    border-bottom: 1px solid var(--border);
    color: var(--text-muted);
    text-align: left;
    padding: 7px 10px;
    font-size: 0.68rem;
    cursor: pointer;
}
.sugeridas button:hover {
    background: var(--surface-2);
    color: var(--text);
}
.error {
    color: var(--coral);
    font-size: 0.8rem;
    margin: 0;
}
.botones {
    display: flex !important;
    flex-direction: row !important;
    justify-content: flex-end;
    gap: 10px;
}

@media (max-width: 1100px) {
    .resumen {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .tarjeta.lista {
        grid-column: span 3;
    }
}
@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
    .header {
        flex-direction: column;
    }
    .form {
        grid-template-columns: 1fr;
    }
}
</style>
