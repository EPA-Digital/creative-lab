<script setup>
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import TipoCuentaToggle from '@/Components/Creativo/TipoCuentaToggle.vue';

// Puerto del import-panel de meta.html/tiktok.html ("Cargar datos") -- el
// hueco real de la migración: antes no había dónde teclear ncTotalReal/
// ordersTotalReal ni ver el rango de fechas del CSV, todo dependía de
// correr `importar:csv` de memoria por consola. Este panel llama a los
// mismos endpoints que usa ImportadorDatos (la clase que también usa el
// comando de consola) -- nunca reimplementa el parseo/cruce/agrupación acá.
const props = defineProps({
    pais: { type: String, required: true },
});

const archivoInput = ref(null);
const nombreArchivo = ref('');
const token = ref(null);
const cargandoPreview = ref(false);
const previewError = ref('');
const preview = ref(null);

const desde = ref('');
const hasta = ref('');
// Meta y TikTok son cuentas/negocios distintos -- cada uno reparte SU
// PROPIO total tecleado entre sus propios ads, nunca un total compartido.
const ncTotalRealMeta = ref('');
const ordersTotalRealMeta = ref('');
const ncTotalRealTiktok = ref('');
const ordersTotalRealTiktok = ref('');

const importando = ref(false);
const importError = ref('');
const resumen = ref(null);

async function onArchivoSeleccionado(e) {
    const file = e.target.files[0];
    if (!file) return;

    nombreArchivo.value = file.name;
    token.value = null;
    preview.value = null;
    previewError.value = '';
    resumen.value = null;
    importError.value = '';
    desde.value = '';
    hasta.value = '';
    cargandoPreview.value = true;

    try {
        const formData = new FormData();
        formData.append('archivo', file);
        const { data } = await axios.post(`/pais/${props.pais}/importar/previsualizar`, formData);
        preview.value = data;
        token.value = data.token;
        desde.value = data.rangoMin || '';
        hasta.value = data.rangoMax || '';
    } catch (err) {
        previewError.value = err.response?.data?.message || 'No se pudo leer el CSV.';
    } finally {
        cargandoPreview.value = false;
    }
}

// "Desde no puede ser después de Hasta." -- mismo texto y misma condición
// que calcularCardsFinal (motor.js), no se recalcula nada si esto dispara.
const rangoError = computed(() => {
    if (desde.value && hasta.value && desde.value > hasta.value) {
        return 'Desde no puede ser después de Hasta.';
    }
    return '';
});

// esRangoParcial en vivo, mismo cálculo que ImportadorDatos::importar --
// solo para avisar ANTES de confirmar, el server la recalcula igual al
// importar (nunca confía en un valor mandado por el cliente).
const esRangoParcialPreview = computed(() => {
    if (!preview.value?.rangoMin || !preview.value?.rangoMax) return false;
    return (
        (desde.value || preview.value.rangoMin) !== preview.value.rangoMin ||
        (hasta.value || preview.value.rangoMax) !== preview.value.rangoMax
    );
});

async function importar() {
    if (rangoError.value || !token.value || importando.value) return;
    importando.value = true;
    importError.value = '';
    resumen.value = null;

    try {
        const { data } = await axios.post(`/pais/${props.pais}/importar`, {
            token: token.value,
            nombre_archivo: nombreArchivo.value,
            desde: desde.value,
            hasta: hasta.value,
            nc_total_real_meta: ncTotalRealMeta.value === '' ? null : ncTotalRealMeta.value,
            orders_total_real_meta: ordersTotalRealMeta.value === '' ? null : ordersTotalRealMeta.value,
            nc_total_real_tiktok: ncTotalRealTiktok.value === '' ? null : ncTotalRealTiktok.value,
            orders_total_real_tiktok: ordersTotalRealTiktok.value === '' ? null : ordersTotalRealTiktok.value,
        });
        resumen.value = data;
        token.value = null; // el archivo temporal ya se borró en el server
        if (archivoInput.value) archivoInput.value.value = '';
    } catch (err) {
        importError.value = err.response?.data?.error || err.response?.data?.message || 'No se pudo importar.';
    } finally {
        importando.value = false;
    }
}
</script>

<template>
    <Head :title="`Cargar datos — ${pais}`" />

    <DashboardLayout :pais="pais">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">{{ pais }}</p>
                        <h1 class="title">Cargar datos</h1>
                    </div>
                </header>

                <section class="import-panel">
                    <h2>Cargar datos</h2>
                    <p class="hint">
                        Sube el CSV único de AppsFlyer (Meta + TikTok juntos -- se separan solos por el bloque
                        FB-/TKT- del nombre). Cost/Impressions/Clicks se traen solos desde la API de Meta/TikTok Ads
                        para el rango elegido.
                    </p>

                    <div class="date-range-row">
                        <div class="date-field">
                            <label for="desdeInput">Desde</label>
                            <input id="desdeInput" v-model="desde" type="date" :disabled="!preview" />
                        </div>
                        <div class="date-field">
                            <label for="hastaInput">Hasta</label>
                            <input id="hastaInput" v-model="hasta" type="date" :disabled="!preview" />
                        </div>
                        <span class="date-hint">
                            Se autocompleta con el rango de fechas del CSV. Solo filtra instalaciones/nuevos
                            clientes/órdenes/venta real (AppsFlyer) — costo, CTR y CPM de la API reflejan todo el CSV
                            subido, sin fecha propia.
                        </span>
                        <span class="date-range-error">{{ rangoError }}</span>
                        <span class="date-range-nota">
                            {{ esRangoParcialPreview ? 'Rango parcial: NC/CAC/Orders/CPO/CPI quedarán null -- no se pueden prorratear a un sub-rango.' : '' }}
                        </span>
                    </div>

                    <div class="tipo-cuenta-row">
                        <span class="tipo-cuenta-label">Tipo de cuenta</span>
                        <TipoCuentaToggle :counts="preview?.tipoCuentaCounts || {}" readonly />
                        <span class="date-hint">DTC (paid propio de TaDa) y BRD (integración de marca) son negocios distintos — nunca se suman juntos.</span>
                    </div>

                    <div class="import-grid">
                        <div class="import-col">
                            <label for="csvInput">CSV de AppsFlyer</label>
                            <span class="cols-hint">
                                Campaign · Campaign ID · Ad · Ad ID · Install Day · Installs (sum) · Activity - Event
                                Counter - First Order (sum) · Activity - Event Counter - Order Submitted (sum).
                            </span>
                            <label for="csvInput" class="file-select-btn">
                                {{ nombreArchivo ? 'Seleccionar otro archivo' : 'Seleccionar archivo' }}
                            </label>
                            <input
                                id="csvInput"
                                ref="archivoInput"
                                type="file"
                                accept=".csv"
                                class="file-select-input"
                                @change="onArchivoSeleccionado"
                            />
                            <span v-if="nombreArchivo" class="cols-hint archivo-cargado">📄 {{ nombreArchivo }}</span>
                            <span v-if="cargandoPreview" class="cols-hint">Leyendo CSV…</span>
                            <span v-else-if="previewError" class="cols-hint" style="color: var(--coral)">{{ previewError }}</span>
                            <span v-else-if="preview" class="cols-hint">
                                {{ preview.totalAdIds }} Ad ID únicos · {{ preview.meta }} Meta, {{ preview.tiktok }} TikTok, {{ preview.excluidos }} excluido(s)
                                <template v-if="preview.problemas > 0">· {{ preview.problemas }} problema(s)</template>
                            </span>
                            <span v-else class="cols-hint">Sin archivos seleccionados.</span>
                        </div>
                        <div class="import-col">
                            <label for="ncTotalRealMetaInput">Venta real — Meta</label>
                            <span class="cols-hint">
                                NC y Orders REALES del negocio para este rango (no los de AppsFlyer) -- se reparten
                                entre los ads de Meta según su participación en AppsFlyer. CAC/CPO salen de acá,
                                nunca del crudo de AppsFlyer.
                            </span>
                            <input id="ncTotalRealMetaInput" v-model="ncTotalRealMeta" type="number" placeholder="NC total real (Meta)" min="0" />
                            <input id="ordersTotalRealMetaInput" v-model="ordersTotalRealMeta" type="number" placeholder="Orders total real (Meta)" min="0" style="margin-top: 8px" />
                        </div>
                        <div class="import-col">
                            <label for="ncTotalRealTiktokInput">Venta real — TikTok</label>
                            <span class="cols-hint">
                                Mismo criterio, para los ads de TikTok -- Meta y TikTok son cuentas distintas, cada
                                una reparte SU PROPIO total, nunca uno compartido entre las dos.
                            </span>
                            <input id="ncTotalRealTiktokInput" v-model="ncTotalRealTiktok" type="number" placeholder="NC total real (TikTok)" min="0" />
                            <input id="ordersTotalRealTiktokInput" v-model="ordersTotalRealTiktok" type="number" placeholder="Orders total real (TikTok)" min="0" style="margin-top: 8px" />
                        </div>
                    </div>

                    <div class="import-actions">
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="!token || !!rangoError || importando"
                            @click="importar"
                        >
                            {{ importando ? 'Importando…' : 'Limpiar y cargar' }}
                        </button>
                        <span class="import-status">
                            <template v-if="importError">{{ importError }}</template>
                            <template v-else-if="!preview">Aún no se ha cargado nada.</template>
                            <template v-else-if="!resumen">Listo para importar.</template>
                            <template v-else>Importación completa.</template>
                        </span>
                    </div>

                    <div v-if="resumen" class="summary-pills">
                        <span class="pill"><span class="dot" style="background: var(--mint)" /> {{ resumen.creativosTocados }} creativo(s)</span>
                        <span class="pill"><span class="dot" style="background: var(--mint)" /> {{ resumen.resultadosTocados }} resultado(s)</span>
                        <span class="pill"><span class="dot" style="background: var(--amber)" /> {{ resumen.tieneMetaTrue }} con match de costo (tiene_meta)</span>
                        <span v-if="resumen.esRangoParcial" class="pill"><span class="dot" style="background: var(--coral)" /> Rango parcial</span>
                        <span v-if="resumen.problemas > 0" class="pill"><span class="dot" style="background: var(--coral)" /> {{ resumen.problemas }} problema(s)</span>
                        <span v-if="resumen.excluidos > 0" class="pill"><span class="dot" style="background: var(--text-faint)" /> {{ resumen.excluidos }} excluido(s)</span>
                    </div>
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
    max-width: 1000px;
    margin: 0 auto;
    padding: 32px 32px 64px;
}
.header {
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
    margin: 0;
}
</style>
