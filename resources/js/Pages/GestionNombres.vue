<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';

// Taxonomía legible (2026-08-27, ver plan del rediseño de Creativos/
// Inteligencia) -- panel de gestión completo, mismo endpoint de guardado
// (POST /pais/{pais}/creativos/{creativo}/nombre) que el lápiz inline de
// CreativeModal.vue. Trae TODOS los creativos del país (GestionNombres-
// Controller::index no acota a un mes -- es gestión de identidad, no de
// rendimiento).
const props = defineProps({
    pais: { type: String, required: true },
    creativos: { type: Array, required: true },
});

// Copia local editable -- cada fila trae su propio input/estado de guardado,
// mismo patrón que appsflyerApps en Ajustes/Index.vue (axios+JSON, sin
// Inertia form/redirect).
const filas = ref(props.creativos.map((c) => ({
    id: c.id,
    adId: c.ad_id,
    nombreComun: c.nombre_comun,
    nombreCompleto: c.nombre_completo,
    plataforma: c.plataforma,
    nombreAmigable: c.nombre_amigable,
    input: c.nombre_amigable || '',
    guardando: false,
    error: '',
})));

const busqueda = ref('');
const filasFiltradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    if (!q) return filas.value;
    return filas.value.filter((f) =>
        (f.nombreComun || '').toLowerCase().includes(q)
        || (f.nombreCompleto || '').toLowerCase().includes(q)
        || (f.nombreAmigable || '').toLowerCase().includes(q));
});

const totalRenombrados = computed(() => filas.value.filter((f) => f.nombreAmigable).length);
const progresoPct = computed(() => (filas.value.length ? Math.round((totalRenombrados.value / filas.value.length) * 100) : 0));

async function guardarFila(fila) {
    const valor = fila.input.trim();
    if (!valor || fila.guardando) return;
    fila.guardando = true;
    fila.error = '';
    try {
        const { data } = await axios.post(`/pais/${props.pais}/creativos/${fila.id}/nombre`, {
            nombre_corregido: valor,
        });
        fila.nombreAmigable = data.nombre_corregido;
        fila.input = data.nombre_corregido;
    } catch (e) {
        fila.error = e.response?.data?.message || 'No se pudo guardar.';
    } finally {
        fila.guardando = false;
    }
}
</script>

<template>
    <Head title="Gestionar nombres" />

    <DashboardLayout :pais="pais" vista-activa="ajustes">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">Ajustes</p>
                        <h1 class="title">Gestionar nombres de creativos</h1>
                        <p class="subtitle">
                            Mapea cada nombre técnico a un nombre amigable -- se aplica en Creativos e Inteligencia,
                            el nombre técnico original nunca se pierde (queda como referencia debajo).
                        </p>
                    </div>
                    <Link :href="`/pais/${pais}/ajustes`" class="cargar-datos-btn">‹ Ajustes</Link>
                </header>

                <section class="import-panel">
                    <div class="gestion-nombres-toolbar">
                        <input
                            v-model="busqueda"
                            type="text"
                            class="gestion-nombres-buscador"
                            placeholder="Buscar por nombre técnico o amigable…"
                        />
                        <div class="gestion-nombres-progreso">
                            <span class="mono">{{ totalRenombrados }} de {{ filas.length }} renombrados</span>
                            <div class="gestion-nombres-progreso-track">
                                <div class="gestion-nombres-progreso-fill" :style="{ width: progresoPct + '%' }" />
                            </div>
                            <span class="mono gestion-nombres-progreso-pct">{{ progresoPct }}%</span>
                        </div>
                    </div>

                    <div v-if="filasFiltradas.length === 0" class="cols-hint">Sin resultados para "{{ busqueda }}".</div>

                    <table v-else class="gestion-nombres-tabla">
                        <thead>
                            <tr>
                                <th>Nombre técnico</th>
                                <th>Nombre amigable</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="fila in filasFiltradas" :key="fila.id">
                                <td>
                                    <span class="mono gestion-nombres-tecnico">{{ fila.nombreComun || fila.nombreCompleto }}</span>
                                    <span class="gestion-nombres-plataforma pill">{{ fila.plataforma }}</span>
                                </td>
                                <td>
                                    <div class="gestion-nombres-input-row">
                                        <input
                                            v-model="fila.input"
                                            type="text"
                                            placeholder="Sin renombrar"
                                            :disabled="fila.guardando"
                                            @keyup.enter="guardarFila(fila)"
                                        />
                                        <button
                                            type="button"
                                            class="modal-copy-btn"
                                            :disabled="fila.guardando || !fila.input.trim() || fila.input.trim() === fila.nombreAmigable"
                                            @click="guardarFila(fila)"
                                        >
                                            {{ fila.guardando ? 'Guardando…' : 'Guardar' }}
                                        </button>
                                    </div>
                                    <span v-if="fila.error" class="gestion-nombres-error">{{ fila.error }}</span>
                                </td>
                                <td>
                                    <span class="gestion-nombres-estado" :class="fila.nombreAmigable ? 'ok' : 'pend'">
                                        {{ fila.nombreAmigable ? 'Renombrado' : 'Sin renombrar' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
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
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 8px;
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
.gestion-nombres-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}
.gestion-nombres-buscador {
    flex: 1 1 260px;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 0.85rem;
}
.gestion-nombres-progreso {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.78rem;
    color: var(--text-muted);
}
.gestion-nombres-progreso-track {
    width: 120px;
    height: 8px;
    border-radius: 999px;
    background: var(--surface-2);
    overflow: hidden;
}
.gestion-nombres-progreso-fill {
    height: 100%;
    background: var(--amber);
    border-radius: 999px;
}
.gestion-nombres-progreso-pct {
    color: var(--amber);
}
.gestion-nombres-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.gestion-nombres-tabla th {
    text-align: left;
    padding: 8px 10px;
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    border-bottom: 1px solid var(--border);
}
.gestion-nombres-tabla td {
    padding: 10px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}
.gestion-nombres-tecnico {
    display: block;
    font-size: 0.76rem;
    color: var(--text-muted);
    word-break: break-all;
    margin-bottom: 4px;
}
.gestion-nombres-plataforma {
    font-size: 0.62rem;
    text-transform: uppercase;
}
.gestion-nombres-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.gestion-nombres-input-row input {
    flex: 1;
    min-width: 180px;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 7px 10px;
    font-size: 0.85rem;
}
.gestion-nombres-error {
    display: block;
    margin-top: 4px;
    font-size: 0.72rem;
    color: var(--coral);
}
.gestion-nombres-estado {
    display: inline-flex;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    border-radius: 999px;
    padding: 3px 10px;
}
.gestion-nombres-estado.ok {
    background: color-mix(in srgb, var(--mint) 20%, transparent);
    color: var(--mint);
}
.gestion-nombres-estado.pend {
    background: color-mix(in srgb, var(--coral) 18%, transparent);
    color: var(--coral);
}
</style>
