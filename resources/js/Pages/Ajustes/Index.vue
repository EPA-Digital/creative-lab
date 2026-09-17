<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';

// Gestión de apps de AppsFlyer por país (tabla appsflyer_apps, 2026-08-12) --
// antes solo se podían cargar por seeder/tinker. Mismo patrón de axios+JSON
// (no Inertia form/redirect) que ya usa ImportarDatos.vue.
const props = defineProps({
    pais: { type: String, required: true },
    paises: { type: Array, required: true },
    appsflyerApps: { type: Array, required: true },
});

const appsflyerApps = ref(props.appsflyerApps);
const appsflyerAppsOrdenadas = computed(() =>
    [...appsflyerApps.value].sort((a, b) => {
        const nombrePais = (a.pais?.nombre || '').localeCompare(b.pais?.nombre || '');
        return nombrePais !== 0 ? nombrePais : a.plataforma.localeCompare(b.plataforma);
    }),
);

const paisId = ref(props.paises[0]?.id ?? null);
const plataforma = ref('ios');
const appId = ref('');
const nombre = ref('');
const guardando = ref(false);
const error = ref('');
const guardadoOk = ref(false);

async function guardar() {
    if (!paisId.value || !plataforma.value || !appId.value || guardando.value) return;
    guardando.value = true;
    error.value = '';
    guardadoOk.value = false;

    try {
        const { data } = await axios.post(`/pais/${props.pais}/ajustes/appsflyer-apps`, {
            pais_id: paisId.value,
            plataforma: plataforma.value,
            app_id: appId.value,
            nombre: nombre.value || null,
        });
        const idx = appsflyerApps.value.findIndex((a) => a.id === data.id);
        if (idx >= 0) appsflyerApps.value[idx] = data;
        else appsflyerApps.value.push(data);
        appId.value = '';
        nombre.value = '';
        guardadoOk.value = true;
    } catch (err) {
        error.value = err.response?.data?.message || 'No se pudo guardar.';
    } finally {
        guardando.value = false;
    }
}
</script>

<template>
    <Head title="Ajustes" />

    <DashboardLayout :pais="pais" vista-activa="ajustes">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">Ajustes</p>
                        <h1 class="title">Apps de AppsFlyer por país</h1>
                    </div>
                </header>

                <section class="import-panel">
                    <h2>Taxonomía de creativos</h2>
                    <p class="hint">
                        Nombres amigables por creativo (ej. "AON-BURGER-SHOW" → "Burger Show") -- se aplican en
                        Creativos e Inteligencia, el nombre técnico original nunca se pierde.
                    </p>
                    <Link :href="`/pais/${pais}/ajustes/nombres`" class="btn-primary" style="text-decoration: none; display: inline-block;">
                        Gestionar nombres
                    </Link>
                </section>

                <section class="import-panel">
                    <h2>Apps cargadas</h2>
                    <p class="hint">
                        Cada país puede tener hasta 2 apps (iOS + Android) -- se usan para traer AppsFlyer vía API en
                        "Cargar datos" y en el refresh diario agendado.
                    </p>

                    <div v-if="appsflyerAppsOrdenadas.length === 0" class="cols-hint">Sin apps cargadas todavía.</div>
                    <div v-for="app in appsflyerAppsOrdenadas" :key="app.id" class="fila-app">
                        <span class="fila-app-pais">{{ app.pais?.nombre ?? '—' }}</span>
                        <span class="fila-app-plataforma pill">{{ app.plataforma }}</span>
                        <span class="fila-app-nombre">{{ app.nombre || '(sin nombre)' }}</span>
                        <span class="fila-app-id mono">{{ app.app_id }}</span>
                    </div>

                    <h2 class="form-titulo">Agregar / actualizar</h2>
                    <p class="hint">
                        Si el país + plataforma ya existe, esto lo actualiza en vez de duplicarlo.
                    </p>

                    <div class="import-grid">
                        <div class="import-col">
                            <label for="paisSelect">País</label>
                            <select id="paisSelect" v-model.number="paisId">
                                <option v-for="p in paises" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                            </select>
                        </div>
                        <div class="import-col">
                            <label for="plataformaSelect">Plataforma</label>
                            <select id="plataformaSelect" v-model="plataforma">
                                <option value="ios">iOS</option>
                                <option value="android">Android</option>
                            </select>
                        </div>
                        <div class="import-col">
                            <label for="appIdInput">App ID</label>
                            <input id="appIdInput" v-model="appId" type="text" placeholder="ej. id1596944067 / com.empresa.app" />
                        </div>
                        <div class="import-col">
                            <label for="nombreInput">Nombre (para identificarla)</label>
                            <input id="nombreInput" v-model="nombre" type="text" placeholder="ej. México Android" />
                        </div>
                    </div>

                    <div class="import-actions">
                        <button type="button" class="btn-primary" :disabled="!paisId || !appId || guardando" @click="guardar">
                            {{ guardando ? 'Guardando…' : 'Guardar' }}
                        </button>
                        <span class="import-status">
                            <template v-if="error">{{ error }}</template>
                            <template v-else-if="guardadoOk">Guardado.</template>
                        </span>
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
.form-titulo {
    margin-top: 28px;
}
.fila-app {
    display: grid;
    grid-template-columns: 1fr auto 1.5fr 2fr;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
}
.fila-app-pais {
    font-weight: 600;
}
.fila-app-id {
    color: var(--text-faint);
}
.import-col select,
.import-col input[type='text'] {
    width: 100%;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.85rem;
}
</style>
