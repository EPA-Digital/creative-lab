<script setup>
import { computed } from 'vue';
import { formatMoneyExacto, formatNumeroExacto } from '@/motor';

// "Double check" de una importación (2026-10-05, ver
// App\Services\Ingesta\ConciliacionImportacion) -- por plataforma: qué trajo
// cada cuenta publicitaria, qué NC de AppsFlyer cayó en anuncios sin costo
// en ninguna cuenta (delata una cuenta faltante) y cuánto es DTC no paid
// (informativo, también entra al reparto). Se usa en "Cargar datos" (al
// terminar) y en Ajustes (historial).
const props = defineProps({
    conciliacion: { type: Object, required: true },
});

const PLATAFORMA_LABEL = { meta: 'Meta', tiktok: 'TikTok' };
const TIPO_LABEL = { tada: 'TaDa', brd: 'BRD' };

const plataformas = computed(() =>
    Object.entries(props.conciliacion || {}).map(([key, datos]) => ({ key, label: PLATAFORMA_LABEL[key] || key, ...datos })),
);

function n(v) {
    return formatNumeroExacto(Math.round(Number(v) || 0));
}

// Diferencia entre lo tecleado y lo repartido -- el redondeo por anuncio
// deja hasta ±1 por anuncio, así que se tolera un margen chico antes de
// marcarlo como descuadre.
function estadoTotal(tecleado, repartido, ads) {
    if (tecleado === null || tecleado === undefined) return { clase: 'pendiente', texto: 'Sin total tecleado' };
    const diff = Math.abs(Number(tecleado) - Number(repartido));
    const tolerancia = Math.max(2, ads);
    return diff <= tolerancia
        ? { clase: 'ok', texto: 'Cuadra' }
        : { clase: 'error', texto: `Diferencia de ${n(diff)}` };
}

function adsTotales(p) {
    return (p.cuentas || []).reduce((s, c) => s + c.ads, 0) + (p.sin_cuenta?.ads || 0);
}
</script>

<template>
    <div class="conciliacion">
        <section v-for="p in plataformas" :key="p.key" class="conciliacion-plataforma">
            <h3 class="conciliacion-titulo">{{ p.label }}</h3>

            <p v-if="p.omitida" class="conciliacion-omitida">
                No se importó: {{ n(p.anuncios) }} anuncio(s) de {{ p.label }} en el archivo, pero el país no tiene ninguna
                cuenta publicitaria activa de {{ p.label }}. Un superadmin la agrega en Ajustes → Cuentas publicitarias y se
                vuelve a importar.
            </p>
            <template v-else>

            <div class="modal-kpis conciliacion-kpis">
                <div class="modal-kpi">
                    <span class="modal-kpi-label">NC tecleado → repartido</span>
                    <span class="modal-kpi-value">
                        {{ p.totales.nc_tecleado === null ? '—' : n(p.totales.nc_tecleado) }} → {{ n(p.totales.nc_repartido) }}
                    </span>
                    <span class="estado" :class="estadoTotal(p.totales.nc_tecleado, p.totales.nc_repartido, adsTotales(p)).clase">
                        {{ estadoTotal(p.totales.nc_tecleado, p.totales.nc_repartido, adsTotales(p)).texto }}
                    </span>
                </div>
                <div class="modal-kpi">
                    <span class="modal-kpi-label">Órdenes tecleadas → repartidas</span>
                    <span class="modal-kpi-value">
                        {{ p.totales.orders_tecleado === null ? '—' : n(p.totales.orders_tecleado) }} → {{ n(p.totales.orders_repartido) }}
                    </span>
                    <span class="estado" :class="estadoTotal(p.totales.orders_tecleado, p.totales.orders_repartido, adsTotales(p)).clase">
                        {{ estadoTotal(p.totales.orders_tecleado, p.totales.orders_repartido, adsTotales(p)).texto }}
                    </span>
                </div>
                <div class="modal-kpi" :class="{ accent: p.sin_cuenta.af_nc > 0 }">
                    <span class="modal-kpi-label">NC de AppsFlyer sin cuenta</span>
                    <span class="modal-kpi-value">{{ n(p.sin_cuenta.af_nc) }}</span>
                    <span class="modal-kpi-sub">{{ n(p.sin_cuenta.ads) }} anuncios sin costo en ninguna cuenta</span>
                </div>
            </div>

            <table class="conciliacion-tabla">
                <thead>
                    <tr>
                        <th>Cuenta</th>
                        <th>Tipo</th>
                        <th>Reparto</th>
                        <th class="num">Anuncios</th>
                        <th class="num">Costo</th>
                        <th class="num">NC AppsFlyer</th>
                        <th class="num">NC asignado</th>
                        <th class="num">Órdenes asignadas</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in p.cuentas" :key="c.id">
                        <td>
                            {{ c.nombre || '(sin nombre)' }}
                            <span class="mono conciliacion-id">{{ c.cuenta_id }}</span>
                        </td>
                        <td><span class="pill mono">{{ TIPO_LABEL[c.tipo] || c.tipo }}</span></td>
                        <td>
                            <span class="estado" :class="c.cuenta_en_venta_real ? 'ok' : 'pendiente'">
                                {{ c.cuenta_en_venta_real ? 'Entra' : 'No entra' }}
                            </span>
                        </td>
                        <td class="num mono" :class="{ 'conciliacion-alerta': c.ads === 0 }">{{ n(c.ads) }}</td>
                        <td class="num mono">{{ formatMoneyExacto(c.costo) }}</td>
                        <td class="num mono">{{ n(c.af_nc) }}</td>
                        <td class="num mono">{{ n(c.nc) }}</td>
                        <td class="num mono">{{ n(c.orders) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="p.cuentas.some((c) => c.ads === 0)" class="date-hint conciliacion-nota">
                Una cuenta activa con 0 anuncios suele ser un ID mal cargado o una cuenta sin gasto en el mes.
            </p>

            <div v-if="p.sin_cuenta.campanias.length" class="conciliacion-grupo">
                <h4>Campañas con conversiones de AppsFlyer pero sin costo en ninguna cuenta</h4>
                <p class="date-hint">Si una campaña tiene NC acá, probablemente su cuenta publicitaria no está cargada.</p>
                <table class="conciliacion-tabla">
                    <thead>
                        <tr><th>Campaña</th><th class="num">Anuncios</th><th class="num">NC AppsFlyer</th><th class="num">Órdenes AppsFlyer</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in p.sin_cuenta.campanias" :key="c.campania">
                            <td class="mono conciliacion-campania">{{ c.campania }}</td>
                            <td class="num mono">{{ n(c.ads) }}</td>
                            <td class="num mono">{{ n(c.af_nc) }}</td>
                            <td class="num mono">{{ n(c.af_orders) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="p.dtc_no_paid.campanias.length" class="conciliacion-grupo">
                <h4>DTC no paid (MLM / NON)</h4>
                <p class="date-hint">Informativo: estos anuncios también entran al reparto de venta real, como todos los canales.</p>
                <table class="conciliacion-tabla">
                    <thead>
                        <tr><th>Campaña</th><th class="num">Anuncios</th><th class="num">NC AppsFlyer</th><th class="num">Órdenes AppsFlyer</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in p.dtc_no_paid.campanias" :key="c.campania">
                            <td class="mono conciliacion-campania">{{ c.campania }}</td>
                            <td class="num mono">{{ n(c.ads) }}</td>
                            <td class="num mono">{{ n(c.af_nc) }}</td>
                            <td class="num mono">{{ n(c.af_orders) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="p.sin_actividad_descartados" class="date-hint conciliacion-nota">
                {{ n(p.sin_actividad_descartados) }} anuncio(s) descartado(s) por no tener ninguna actividad en el mes.
            </p>
            </template>
        </section>

        <p v-if="!plataformas.length" class="empty-note">Esta importación no tiene conciliación.</p>
    </div>
</template>

<style scoped>
.conciliacion {
    display: flex;
    flex-direction: column;
    gap: 28px;
}
.conciliacion-omitida {
    color: var(--coral);
    font-size: 0.85rem;
    margin: 0;
    padding: 12px 14px;
    border: 1px solid color-mix(in srgb, var(--coral) 40%, transparent);
    border-radius: 10px;
}
.conciliacion-titulo {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-weight: 600;
    font-size: 16px;
    margin: 0 0 12px;
}
.conciliacion-kpis {
    margin-bottom: 16px;
}
.conciliacion-kpis .estado {
    align-self: flex-start;
    margin-top: 4px;
}
.conciliacion-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.conciliacion-tabla th {
    text-align: left;
    padding: 8px 10px;
    font-size: 0.68rem;
    font-weight: 400;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    border-bottom: 1px solid var(--border);
}
.conciliacion-tabla td {
    padding: 9px 10px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}
.conciliacion-tabla .num {
    text-align: right;
    white-space: nowrap;
}
.conciliacion-id {
    display: block;
    font-size: 0.7rem;
    color: var(--text-faint);
}
.conciliacion-campania {
    font-size: 0.72rem;
    color: var(--text-muted);
    word-break: break-all;
}
.conciliacion-alerta {
    color: var(--coral);
}
.conciliacion-grupo {
    margin-top: 20px;
}
.conciliacion-grupo h4 {
    font-size: 0.85rem;
    font-weight: 600;
    margin: 0 0 4px;
}
.conciliacion-nota {
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
.estado.error {
    background: color-mix(in srgb, var(--coral) 18%, transparent);
    color: var(--coral);
}
.estado.pendiente {
    background: color-mix(in srgb, var(--amber) 18%, transparent);
    color: var(--amber);
}
</style>
