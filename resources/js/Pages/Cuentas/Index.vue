<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import { MEDIO_CODIGO } from '@/cuentas';
import LogoCuenta from '@/Components/Cuenta/LogoCuenta.vue';
import '../../../css/cuentas.css';

// Lista de cuentas (diseño "Cuentas — Dashboard de creativos", 2026-11-03):
// agrupadas por cliente, con filtros por tipo de servicio, venta real y
// medio. Filtrar es solo en el cliente -- son pocas cuentas.
const props = defineProps({
    pais: { type: String, required: true },
    cuentas: { type: Array, required: true },
    catalogo: { type: Object, required: true },
});

const servicio = ref('Todos');
const venta = ref('Todas');
const medio = ref('');
const medioAbierto = ref(false);
const busqueda = ref('');

const medios = computed(() => Object.fromEntries(props.catalogo.medios.map((m) => [m.clave, m])));
const banderas = computed(() => Object.fromEntries(props.catalogo.paises.map((p) => [p.codigo, p])));

const kpis = computed(() => {
    const usados = new Set(props.cuentas.flatMap((c) => c.medios));
    return {
        total: props.cuentas.length,
        activas: props.cuentas.filter((c) => c.activa).length,
        clientes: new Set(props.cuentas.map((c) => c.cliente)).size,
        venta: props.cuentas.filter((c) => c.venta_real !== 'no').length,
        medios: usados.size,
        conectados: [...usados].filter((m) => medios.value[m]?.conectado).length,
    };
});

const filtradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.cuentas.filter((c) =>
        (servicio.value === 'Todos' || c.servicios.includes(servicio.value))
        && (venta.value === 'Todas'
            || (venta.value === 'Manual' && c.venta_real === 'manual')
            || (venta.value === 'Automática' && c.venta_real === 'auto')
            || (venta.value === 'Sin venta real' && c.venta_real === 'no'))
        && (!medio.value || c.medios.includes(medio.value))
        && (!q || c.nombre.toLowerCase().includes(q) || (c.cliente || '').toLowerCase().includes(q)),
    );
});

const grupos = computed(() => {
    const porCliente = new Map();
    for (const c of filtradas.value) {
        if (!porCliente.has(c.cliente)) porCliente.set(c.cliente, []);
        porCliente.get(c.cliente).push(c);
    }
    return [...porCliente.entries()]
        .sort(([a], [b]) => a.localeCompare(b, 'es'))
        .map(([cliente, items]) => ({ cliente, items }));
});

function limpiar() {
    servicio.value = 'Todos';
    venta.value = 'Todas';
    medio.value = '';
    busqueda.value = '';
}

function elegirMedio(clave) {
    medio.value = clave;
    medioAbierto.value = false;
}

function cerrarMenu(e) {
    if (!e.target.closest('.cu-fdd-wrap')) medioAbierto.value = false;
}
onMounted(() => document.addEventListener('click', cerrarMenu));
onBeforeUnmount(() => document.removeEventListener('click', cerrarMenu));
</script>

<template>
    <Head title="Cuentas" />

    <DashboardLayout :pais="pais" vista-activa="cuentas">
        <div class="cu">
            <div class="cu-page">
                <header class="cu-head">
                    <div>
                        <div class="cu-eyebrow">Administración</div>
                        <h1 class="cu-title">Cuentas</h1>
                        <p class="cu-subtitle">Da de alta y edita las cuentas cliente que analiza el equipo, agrupadas por cliente.</p>
                    </div>
                    <Link class="cu-btn cu-btn-primary" href="/cuentas/nueva">
                        <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        Nueva cuenta
                    </Link>
                </header>

                <section v-if="!cuentas.length" class="cu-table-box" aria-label="Sin cuentas">
                    <div class="cu-empty">
                        <div class="cu-empty-ic"><svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2" /><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 13h18" /></svg></div>
                        <h2 class="cu-section-title" style="font-size: 18px">Todavía no tienes cuentas.</h2>
                        <p class="cu-hint" style="max-width: 420px">Crea la primera cuenta para empezar a analizar sus creativos. Puedes agruparla bajo un cliente y conectar sus medios después.</p>
                    </div>
                </section>

                <template v-else>
                    <section class="cu-kpis" aria-label="Resumen">
                        <div class="cu-kpi"><span class="cu-kpi-label">Cuentas</span><span class="cu-kpi-value">{{ kpis.total }}<small>{{ kpis.activas }} activas</small></span></div>
                        <div class="cu-kpi"><span class="cu-kpi-label">Clientes</span><span class="cu-kpi-value">{{ kpis.clientes }}</span></div>
                        <div class="cu-kpi"><span class="cu-kpi-label">Con venta real</span><span class="cu-kpi-value">{{ kpis.venta }}<small>de {{ kpis.total }}</small></span></div>
                        <div class="cu-kpi"><span class="cu-kpi-label">Medios en uso</span><span class="cu-kpi-value">{{ kpis.medios }}<small>{{ kpis.conectados }} conectados</small></span></div>
                    </section>

                    <div class="cu-filters">
                        <div class="cu-filter">
                            <span id="f-svc" class="cu-filter-label">Tipo de servicio</span>
                            <div class="cu-pills" role="group" aria-labelledby="f-svc">
                                <button
                                    v-for="o in ['Todos', ...catalogo.servicios]"
                                    :key="o"
                                    type="button"
                                    class="cu-pill"
                                    :class="{ on: servicio === o }"
                                    :aria-pressed="servicio === o"
                                    @click="servicio = o"
                                >{{ o }}</button>
                            </div>
                        </div>
                        <div class="cu-filter">
                            <span id="f-venta" class="cu-filter-label">Venta real</span>
                            <div class="cu-pills" role="group" aria-labelledby="f-venta">
                                <button
                                    v-for="o in ['Todas', 'Manual', 'Automática', 'Sin venta real']"
                                    :key="o"
                                    type="button"
                                    class="cu-pill"
                                    :class="{ on: venta === o }"
                                    :aria-pressed="venta === o"
                                    @click="venta = o"
                                >{{ o }}</button>
                            </div>
                        </div>
                        <div class="cu-filter">
                            <span id="f-medio" class="cu-filter-label">Medio</span>
                            <div class="cu-fdd-wrap">
                                <button
                                    type="button"
                                    class="cu-fdd"
                                    :class="{ open: medioAbierto }"
                                    aria-haspopup="listbox"
                                    :aria-expanded="medioAbierto"
                                    aria-labelledby="f-medio"
                                    @click="medioAbierto = !medioAbierto"
                                >
                                    <span>{{ medio ? medios[medio].nombre : 'Todos los medios' }}</span>
                                    <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true" style="width: 16px; height: 16px"><path d="M6 9l6 6 6-6" /></svg>
                                </button>
                                <div v-if="medioAbierto" class="cu-menu" role="listbox" aria-label="Medio">
                                    <button type="button" role="option" class="cu-opt" :class="{ on: !medio }" :aria-selected="!medio" @click="elegirMedio('')">
                                        <span>Todos los medios</span><span class="cu-opt-meta" />
                                    </button>
                                    <button
                                        v-for="m in catalogo.medios"
                                        :key="m.clave"
                                        type="button"
                                        role="option"
                                        class="cu-opt"
                                        :class="{ on: medio === m.clave }"
                                        :aria-selected="medio === m.clave"
                                        @click="elegirMedio(m.clave)"
                                    >
                                        <span>{{ m.nombre }}</span><span class="cu-opt-meta">{{ m.conectado ? 'Conectado' : 'Próximamente' }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <label class="cu-search">
                            <span class="sr">Buscar cuenta o cliente</span>
                            <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true" style="width: 16px; height: 16px"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
                            <input v-model="busqueda" class="cu-input" type="search" placeholder="Buscar cuenta o cliente">
                        </label>
                    </div>

                    <section class="cu-table-box" aria-label="Cuentas por cliente">
                        <div class="cu-table">
                            <div class="cu-cols cu-headrow">
                                <span>Cuenta</span><span>Tipo de servicio</span><span>Medios</span><span>Venta real</span><span>Países</span><span>Estado</span><span class="sr">Acciones</span>
                            </div>

                            <div v-if="!grupos.length" class="cu-empty" style="border-top: 1px solid var(--border)">
                                <div class="cu-empty-ic"><svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg></div>
                                <h2 class="cu-section-title">Ninguna cuenta coincide con estos filtros.</h2>
                                <p class="cu-hint">Prueba con otro tipo de servicio o medio, o limpia los filtros para ver todas.</p>
                                <button type="button" class="cu-btn cu-btn-sm" style="margin-top: 6px" @click="limpiar">Limpiar filtros</button>
                            </div>

                            <template v-for="g in grupos" :key="g.cliente">
                                <div class="cu-grp-row">
                                    <span class="cu-grp-name">{{ g.cliente }}</span>
                                    <span class="mono cu-faint" style="font-size: 10.5px; letter-spacing: .06em">{{ g.items.length === 1 ? '1 CUENTA' : `${g.items.length} CUENTAS` }}</span>
                                </div>
                                <div v-for="c in g.items" :key="c.id" class="cu-cols cu-row" :class="{ inactive: !c.activa }">
                                    <div style="display: flex; align-items: center; gap: 12px; min-width: 0">
                                        <LogoCuenta :cuenta="c" :tamano="40" :radio="10" />
                                        <div style="min-width: 0">
                                            <div class="cu-acc-name">{{ c.nombre }}</div>
                                            <div class="cu-acc-slug">{{ c.slug }}</div>
                                        </div>
                                    </div>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px">
                                        <span v-for="s in c.servicios" :key="s" class="cu-tag">{{ s }}</span>
                                    </div>
                                    <div class="cu-medios">
                                        <span v-for="m in c.medios" :key="m" class="cu-medio" :title="medios[m]?.nombre">
                                            <span aria-hidden="true">{{ MEDIO_CODIGO[m] }}</span><span class="sr">{{ medios[m]?.nombre }}</span>
                                        </span>
                                    </div>
                                    <div>
                                        <span v-if="c.venta_real === 'manual'" class="cu-badge cu-badge-manual">Manual</span>
                                        <span v-else-if="c.venta_real === 'auto'" class="cu-badge cu-badge-auto">Automática</span>
                                    </div>
                                    <div class="cu-flags">
                                        <span
                                            v-for="p in c.paises"
                                            :key="p"
                                            class="cu-flag"
                                            :style="{ background: banderas[p]?.bandera }"
                                            role="img"
                                            :aria-label="banderas[p]?.nombre"
                                            :title="banderas[p]?.nombre"
                                        />
                                    </div>
                                    <div style="display: flex; flex-direction: column; align-items: flex-start">
                                        <span class="cu-status"><span class="cu-dot" :style="{ background: c.activa ? 'var(--mint)' : 'var(--coral)' }" />{{ c.activa ? 'Activa' : 'Inactiva' }}</span>
                                        <span v-if="c.sin_token_appsflyer" class="cu-badge cu-badge-off" style="margin-top: 6px" title="AppsFlyer está encendido pero la cuenta no tiene token">Sin token AppsFlyer</span>
                                    </div>
                                    <div style="text-align: right">
                                        <Link class="cu-btn cu-btn-sm" :href="`/cuentas/${c.slug}/editar`" :aria-label="`Editar ${c.nombre}`">
                                            <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true" style="width: 15px; height: 15px"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4z" /><path d="M13.5 6.5l4 4" /></svg>
                                            Editar
                                        </Link>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </DashboardLayout>
</template>
