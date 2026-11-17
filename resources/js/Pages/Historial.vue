<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';

// Historial de cambios (2026-11-17, pedido explícito): quién cambió qué y
// cuándo en el país -- Daily, Control de gasto, cargas de datos y capturas.
// Sale de la auditoría (HistorialCambios.php), del más reciente al más viejo.
const props = defineProps({
    pais: { type: String, required: true },
    area: { type: String, default: null },
    usuario: { type: Number, default: null },
    areas: { type: Array, default: () => [] },
    eventos: { type: Array, default: () => [] },
    hayMas: { type: Boolean, default: false },
    usuarios: { type: Array, default: () => [] },
});

const AREA_OPCIONES = computed(() => [{ key: 'todas', label: 'Todas' }, ...props.areas]);
const USUARIO_OPCIONES = computed(() => [{ key: 0, label: 'Todos' }, ...props.usuarios.map((u) => ({ key: u.id, label: u.nombre }))]);
const filtrar = (cambio) =>
    router.get(location.pathname, { area: props.area ?? undefined, usuario: props.usuario ?? undefined, ...cambio }, { preserveScroll: true });

// Más eventos: se agregan abajo sin perder los que ya están.
const lista = ref([...props.eventos]);
const cargando = ref(false);
watch(() => props.eventos, (n) => { lista.value = cargando.value ? [...lista.value, ...n] : [...n]; cargando.value = false; });
function cargarMas() {
    cargando.value = true;
    router.get(location.pathname, { area: props.area ?? undefined, usuario: props.usuario ?? undefined, antes: lista.value.at(-1)?.id }, { preserveScroll: true, preserveState: true, only: ['eventos', 'hayMas'] });
}

const busqueda = ref('');
const visibles = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return q ? lista.value.filter((e) => `${e.texto} ${e.quien}`.toLowerCase().includes(q)) : lista.value;
});

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const dia = (iso) => {
    const d = new Date(iso);
    const hoy = new Date();
    const ayer = new Date(Date.now() - 86400000);
    const igual = (a, b) => a.toDateString() === b.toDateString();
    if (igual(d, hoy)) return 'Hoy';
    if (igual(d, ayer)) return 'Ayer';
    return `${DIAS[d.getDay()]} ${d.getDate()} ${MESES[d.getMonth()]} ${d.getFullYear()}`;
};
const hora = (iso) => {
    const d = new Date(iso);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
/** Agrupado por día, en hora local. */
const porDia = computed(() => {
    const grupos = [];
    for (const e of visibles.value) {
        const k = dia(e.en);
        if (!grupos.length || grupos.at(-1).dia !== k) grupos.push({ dia: k, eventos: [] });
        grupos.at(-1).eventos.push(e);
    }
    return grupos;
});
const nombreArea = (id) => props.areas.find((a) => a.key === id)?.label ?? id;
const abierto = ref(null);
</script>

<template>
    <Head :title="`Historial de cambios — ${pais}`" />

    <DashboardLayout :pais="pais" vista-activa="historial">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <p class="eyebrow mono">{{ pais }}</p>
                    <h1 class="title">Historial de cambios</h1>
                    <p class="subtitle">Quién cambió qué y cuándo: Daily, Control de gasto, cargas de datos y capturas manuales. Lo más reciente primero, en tu hora local.</p>
                </header>

                <div class="resumen-filtros">
                    <FiltroDropdown label="Área" :model-value="area ?? 'todas'" :options="AREA_OPCIONES" @update:model-value="filtrar({ area: $event === 'todas' ? undefined : $event })" />
                    <FiltroDropdown label="Quién" :model-value="usuario ?? 0" :options="USUARIO_OPCIONES" @update:model-value="filtrar({ usuario: $event || undefined })" />
                    <label class="buscador">
                        <span class="periodo-label mono">Buscar</span>
                        <input v-model="busqueda" type="search" autocomplete="off" placeholder="Campaña, persona, mes…" />
                    </label>
                </div>

                <p v-if="!visibles.length" class="empty-note">Todavía no hay cambios registrados con estos filtros.</p>

                <section v-for="g in porDia" :key="g.dia" class="dia">
                    <h2 class="dia-titulo mono">{{ g.dia }}</h2>
                    <ol class="eventos">
                        <li v-for="e in g.eventos" :key="e.id" class="evento">
                            <span class="hora mono">{{ hora(e.en) }}</span>
                            <span class="area mono" :class="e.area">{{ nombreArea(e.area) }}</span>
                            <div class="cuerpo">
                                <p><b>{{ e.quien }}</b> · {{ e.texto }}</p>
                                <button v-if="Object.keys(e.detalle).length" type="button" class="ver" @click="abierto = abierto === e.id ? null : e.id">
                                    {{ abierto === e.id ? 'Ocultar detalle' : 'Ver detalle' }}
                                </button>
                                <dl v-if="abierto === e.id" class="detalle mono">
                                    <template v-for="(v, k) in e.detalle" :key="k">
                                        <dt>{{ k }}</dt>
                                        <dd>{{ typeof v === 'object' ? JSON.stringify(v) : v }}</dd>
                                    </template>
                                </dl>
                            </div>
                        </li>
                    </ol>
                </section>

                <div v-if="hayMas" class="mas">
                    <button type="button" class="btn-primary" :disabled="cargando" @click="cargarMas">{{ cargando ? 'Cargando…' : 'Ver cambios anteriores' }}</button>
                </div>
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
    max-width: 1000px;
    margin: 0 auto;
    padding: 32px 32px 64px;
}
.header {
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
}
.periodo-label {
    font-size: 10px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.buscador {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1 1 240px;
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
.dia {
    margin-bottom: 22px;
}
.dia-titulo {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-faint);
    margin: 0 0 8px;
}
.eventos {
    list-style: none;
    margin: 0;
    padding: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
}
.evento {
    display: grid;
    grid-template-columns: 48px 130px minmax(0, 1fr);
    gap: 12px;
    align-items: start;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}
.evento:last-child {
    border-bottom: none;
}
.hora {
    font-size: 0.74rem;
    color: var(--text-faint);
    padding-top: 2px;
}
.area {
    justify-self: start;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border-radius: 999px;
    padding: 3px 9px;
    background: var(--surface-2);
    color: var(--text-muted);
    white-space: nowrap;
}
.area.daily {
    background: color-mix(in srgb, var(--serie-6) 20%, transparent);
    color: var(--serie-6);
}
.area.control {
    background: color-mix(in srgb, var(--yellow) 18%, transparent);
    color: var(--yellow);
}
.area.datos {
    background: color-mix(in srgb, var(--sky) 18%, transparent);
    color: var(--sky);
}
.area.fuentes {
    background: color-mix(in srgb, var(--mint) 18%, transparent);
    color: var(--mint);
}
.cuerpo p {
    margin: 0;
    font-size: 0.85rem;
    line-height: 1.45;
    word-break: break-word;
}
.ver {
    background: none;
    border: none;
    color: var(--amber);
    font-size: 0.72rem;
    padding: 0;
    margin-top: 4px;
    cursor: pointer;
}
.detalle {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    gap: 2px 12px;
    margin: 8px 0 0;
    font-size: 0.7rem;
    color: var(--text-muted);
}
.detalle dt {
    color: var(--text-faint);
}
.detalle dd {
    margin: 0;
    word-break: break-all;
}
.mas {
    display: flex;
    justify-content: center;
    margin-top: 12px;
}
@media (max-width: 640px) {
    .page-inner {
        padding: 20px 16px 48px;
    }
    .evento {
        grid-template-columns: 44px minmax(0, 1fr);
    }
    .area {
        grid-column: 2;
    }
    .cuerpo {
        grid-column: 2;
    }
}
</style>
