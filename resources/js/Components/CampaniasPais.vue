<script setup>
import { computed, ref } from 'vue';
import FiltroDropdown from '@/Components/FiltroDropdown.vue';
import { formatMoneyExacto, formatNumeroExacto, FUNNEL_LABELS } from '@/motor';

// Campañas que usa el país (2026-10-06, pedido explícito) -- ver
// AjustesController::campaniasDelPais. Solo lectura: sirve para revisar que
// cuentas y nomenclaturas cuadren con lo que se está pautando.
const props = defineProps({
    campanias: { type: Array, required: true },
});

const busqueda = ref('');
const plataforma = ref('TODAS');
const tipo = ref('TODOS');
const PLATAFORMA_OPCIONES = [
    { key: 'TODAS', label: 'Todas' },
    { key: 'meta', label: 'Meta' },
    { key: 'tiktok', label: 'TikTok' },
];
const TIPO_OPCIONES = [
    { key: 'TODOS', label: 'Todos' },
    { key: 'DTC', label: 'DTC paid' },
    { key: 'DTC_NO_PAID', label: 'DTC no paid' },
    { key: 'BRD', label: 'BRD' },
];

function tipoDe(c) {
    if (c.tipoCuenta === 'BRD') return 'BRD';
    return c.paid ? 'DTC' : 'DTC_NO_PAID';
}

const filtradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return props.campanias.filter((c) =>
        (plataforma.value === 'TODAS' || c.plataforma === plataforma.value)
        && (tipo.value === 'TODOS' || tipoDe(c) === tipo.value)
        && (!q || c.campania.toLowerCase().includes(q) || (c.cuenta || '').toLowerCase().includes(q)));
});

function etiquetaMes(m) {
    if (!m) return '—';
    const [anio, mm] = m.split('-').map(Number);
    return new Date(anio, mm - 1, 1).toLocaleDateString('es-MX', { month: 'short', year: '2-digit' });
}
</script>

<template>
    <div>
        <div class="resumen-filtros campanias-filtros">
            <FiltroDropdown v-model="plataforma" label="Plataforma" :options="PLATAFORMA_OPCIONES" />
            <FiltroDropdown v-model="tipo" label="Tipo" :options="TIPO_OPCIONES" />
            <div class="campanias-buscador">
                <label for="campaniasBuscador" class="filtro-label">Buscar</label>
                <input id="campaniasBuscador" v-model="busqueda" type="text" placeholder="Nombre de campaña o cuenta…" />
            </div>
        </div>

        <p v-if="!campanias.length" class="empty-note">Este país todavía no tiene campañas importadas.</p>
        <p v-else-if="!filtradas.length" class="empty-note">Ninguna campaña coincide con los filtros.</p>
        <div v-else class="campanias-wrap">
            <table class="campanias-tabla">
                <thead>
                    <tr>
                        <th>Campaña</th>
                        <th>Plataforma</th>
                        <th>Cuenta</th>
                        <th>Etapa</th>
                        <th>Tipo</th>
                        <th class="num">Anuncios</th>
                        <th class="num">Gasto total</th>
                        <th>Meses</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in filtradas" :key="`${c.plataforma}-${c.campania}`">
                        <td class="mono campanias-nombre">{{ c.campania }}</td>
                        <td>{{ c.plataforma === 'meta' ? 'Meta' : 'TikTok' }}</td>
                        <td>{{ c.cuenta || '—' }}</td>
                        <td>{{ c.funnel ? FUNNEL_LABELS[c.funnel] || c.funnel : 'Sin clasificar' }}</td>
                        <td>
                            <span class="estado" :class="{ ok: tipoDe(c) === 'DTC', pendiente: tipoDe(c) === 'BRD', neutro: tipoDe(c) === 'DTC_NO_PAID' }">
                                {{ TIPO_OPCIONES.find((t) => t.key === tipoDe(c))?.label }}
                            </span>
                        </td>
                        <td class="num mono">{{ formatNumeroExacto(c.anuncios) }}</td>
                        <td class="num mono">{{ formatMoneyExacto(c.costo) }}</td>
                        <td class="mono campanias-meses">
                            {{ c.primerMes === c.ultimoMes ? etiquetaMes(c.primerMes) : `${etiquetaMes(c.primerMes)} – ${etiquetaMes(c.ultimoMes)}` }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="campanias.length" class="date-hint campanias-total">
            {{ formatNumeroExacto(filtradas.length) }} de {{ formatNumeroExacto(campanias.length) }} campaña(s)
        </p>
    </div>
</template>

<style scoped>
.campanias-filtros {
    align-items: flex-end;
}
.campanias-buscador {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1 1 240px;
}
.filtro-label {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.campanias-buscador input {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.85rem;
}
.campanias-buscador input:focus {
    outline: none;
    border-color: var(--amber);
}
.campanias-wrap {
    overflow: auto;
    max-height: 60vh;
    border: 1px solid var(--border);
    border-radius: 12px;
}
.campanias-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.campanias-tabla th,
.campanias-tabla td {
    padding: 9px 12px;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.campanias-tabla th {
    position: sticky;
    top: 0;
    background: var(--surface-2);
    font-size: 0.68rem;
    font-weight: 400;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.campanias-tabla .num {
    text-align: right;
}
.campanias-nombre {
    font-size: 0.74rem;
    color: var(--text-muted);
    white-space: normal;
    word-break: break-all;
    min-width: 280px;
}
.campanias-meses {
    font-size: 0.74rem;
    color: var(--text-muted);
}
.campanias-total {
    display: block;
    margin-top: 8px;
}
.estado {
    display: inline-flex;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    border-radius: 999px;
    padding: 3px 10px;
}
.estado.ok {
    background: color-mix(in srgb, var(--mint) 20%, transparent);
    color: var(--mint);
}
.estado.pendiente {
    background: color-mix(in srgb, var(--amber) 18%, transparent);
    color: var(--amber);
}
.estado.neutro {
    background: var(--surface-2);
    color: var(--text-muted);
}
</style>
