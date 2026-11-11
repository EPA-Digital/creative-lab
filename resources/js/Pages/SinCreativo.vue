<script setup>
import { urlPais } from '@/rutas';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import InfoTip from '@/Components/InfoTip.vue';

// Cola "Sin creativo" (2026-11-05): anuncios que el catálogo de la cuenta no
// pudo asignar a un creativo -- casi siempre Spark Ads de TikTok, cuyo
// nombre es el texto del post o el archivo del video. Agrupados por post (o
// texto idéntico). Asignar agrega una regla al catálogo de la cuenta, así
// los anuncios futuros de ese post lo heredan (ver SinCreativoController).
const props = defineProps({
    pais: { type: String, required: true },
    disponible: { type: Boolean, default: false },
    esTada: { type: Boolean, default: false },
    grupos: { type: Array, default: () => [] },
    creativosExistentes: { type: Array, default: () => [] },
});

const filas = ref(props.grupos.map((g) => ({ ...g, input: '', guardando: false, error: '' })));
const busqueda = ref('');
const aviso = ref('');
const filtradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return filas.value.filter((f) => !q || f.texto.toLowerCase().includes(q) || (f.campania || '').toLowerCase().includes(q));
});
const gastoTotal = computed(() => filas.value.reduce((t, f) => t + f.gasto, 0));
const dinero = (n) => `$${Math.round(n).toLocaleString('es-MX')}`;
const PLATAFORMA = { meta: 'Meta', tiktok: 'TikTok' };

async function asignar(fila) {
    if (!fila.input.trim()) {
        fila.error = 'Escribe el nombre del creativo.';
        return;
    }
    fila.guardando = true;
    fila.error = '';
    try {
        const { data } = await axios.post(`${urlPais(props.pais)}/ajustes/sin-creativo`, {
            post_id: fila.post_id, texto: fila.post_id ? null : fila.texto, nombre: fila.input.trim(),
        });
        aviso.value = `“${fila.input.trim()}” quedó en el catálogo: ${data.actualizados} anuncio(s) actualizados y los nuevos de ese ${fila.post_id ? 'post' : 'texto'} lo heredarán.`;
        filas.value = filas.value.filter((f) => f.clave !== fila.clave);
        router.reload({ only: ['creativosExistentes'] });
    } catch (e) {
        fila.error = e.response?.data?.errors?.nombre?.[0] || e.response?.data?.message || 'No se pudo asignar.';
    } finally {
        fila.guardando = false;
    }
}
</script>

<template>
    <Head title="Sin creativo" />

    <DashboardLayout :pais="pais" vista-activa="ajustes">
        <div class="page">
            <div class="page-inner">
                <header class="header">
                    <div>
                        <p class="eyebrow mono">Ajustes</p>
                        <h1 class="title title-con-info">
                            Anuncios sin creativo
                            <InfoTip etiqueta="Sobre los anuncios sin creativo">
                                Los que el catálogo de la cuenta no reconoce, casi siempre Spark Ads (su nombre es el texto del post). Asigna el creativo una vez por post: se guarda en el catálogo y los anuncios nuevos de ese post lo heredan.
                            </InfoTip>
                        </h1>
                    </div>
                    <Link :href="`${urlPais(pais)}/ajustes`" class="sc-volver">‹ Ajustes</Link>
                </header>

                <section v-if="!disponible" class="import-panel">
                    <p class="empty-note">
                        {{ esTada
                            ? 'TaDa usa su clasificación propia; sus creativos se corrigen en Gestionar nombres.'
                            : 'Esta cuenta todavía no tiene su clasificación configurada. Hazlo en Cuentas → editar → 07 · Clasificación.' }}
                    </p>
                </section>

                <section v-else class="import-panel">
                    <div class="sc-toolbar">
                        <input v-model="busqueda" type="text" class="sc-buscar" placeholder="Buscar por texto del post o campaña…" aria-label="Buscar">
                        <span class="mono sc-resumen">{{ filas.length }} grupo(s) · {{ dinero(gastoTotal) }} de gasto sin creativo</span>
                    </div>
                    <p v-if="aviso" class="sc-aviso" role="status">{{ aviso }}</p>

                    <p v-if="!filas.length" class="empty-note">Todos los anuncios tienen creativo. 🎉</p>
                    <p v-else-if="!filtradas.length" class="empty-note">Nada coincide con “{{ busqueda }}”.</p>

                    <datalist id="sc-creativos">
                        <option v-for="c in creativosExistentes" :key="c" :value="c" />
                    </datalist>

                    <table v-if="filtradas.length" class="sc-tabla">
                        <thead>
                            <tr><th>Post / anuncio</th><th>Anuncios</th><th>Gasto</th><th>Creativo</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in filtradas" :key="f.clave">
                                <td class="sc-texto">
                                    <span class="sc-chip mono">{{ PLATAFORMA[f.plataforma] || f.plataforma }}</span>
                                    <span v-if="f.post_id" class="sc-chip mono" :title="`Post de TikTok ${f.post_id}`">Post</span>
                                    <span>{{ f.texto || '(sin nombre)' }}</span>
                                    <span v-if="f.campania" class="sc-campania mono">{{ f.campania }}</span>
                                </td>
                                <td class="mono">{{ f.anuncios }}</td>
                                <td class="mono">{{ dinero(f.gasto) }}</td>
                                <td>
                                    <div class="sc-asignar">
                                        <input
                                            v-model="f.input"
                                            type="text"
                                            list="sc-creativos"
                                            maxlength="120"
                                            placeholder="Ej. Outfit Match"
                                            :aria-label="`Creativo para ${f.texto}`"
                                            @keydown.enter.prevent="asignar(f)"
                                        >
                                        <button type="button" class="btn-primary sc-btn" :disabled="f.guardando" @click="asignar(f)">
                                            {{ f.guardando ? 'Guardando…' : 'Asignar' }}
                                        </button>
                                    </div>
                                    <span v-if="f.error" class="sc-error">{{ f.error }}</span>
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
.title-con-info {
    display: flex;
    align-items: center;
    gap: 8px;
}
.page { padding: 32px 32px 64px; }
.page-inner { max-width: 1200px; margin: 0 auto; }
.header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 24px; }
.eyebrow { margin: 0; font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--amber); }
.title { margin: 6px 0 0; font-family: 'Space Grotesk', sans-serif; font-size: 28px; font-weight: 700; }
.subtitle { margin: 6px 0 0; max-width: 760px; font-size: 14px; color: var(--text-muted); }
.sc-volver { flex: none; padding: 8px 14px; border: 1px solid var(--border); border-radius: 8px; color: var(--text); text-decoration: none; font-size: 13px; }
.sc-volver:hover { border-color: var(--amber); }
.sc-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.sc-buscar { width: min(420px, 100%); height: 38px; padding: 0 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; font-size: 13.5px; }
.sc-buscar:focus { outline: 2px solid var(--amber); outline-offset: 1px; }
.sc-resumen { font-size: 12px; color: var(--text-muted); }
.sc-aviso { margin: 0 0 14px; padding: 10px 14px; border-radius: 10px; background: color-mix(in srgb, var(--mint) 12%, transparent); color: var(--mint); font-size: 13px; }
.empty-note { margin: 24px 0; text-align: center; color: var(--text-muted); }
.sc-tabla { width: 100%; border-collapse: collapse; }
.sc-tabla th { padding: 10px; border-bottom: 1px solid var(--border); text-align: left; font-size: 0.68rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text-faint); }
.sc-tabla td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: top; font-size: 13.5px; }
.sc-texto { max-width: 560px; }
.sc-texto > span:not(.sc-chip):not(.sc-campania) { overflow-wrap: anywhere; }
.sc-chip { display: inline-block; margin-right: 6px; padding: 1px 6px; border-radius: 4px; background: var(--surface-2); color: var(--text-muted); font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
.sc-campania { display: block; margin-top: 4px; font-size: 11px; color: var(--text-faint); overflow-wrap: anywhere; }
.sc-asignar { display: flex; gap: 8px; }
.sc-asignar input { flex: 1; min-width: 160px; height: 34px; padding: 0 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; font-size: 13px; }
.sc-asignar input:focus { outline: 2px solid var(--amber); outline-offset: 1px; }
.sc-btn { height: 34px; padding: 0 14px; }
.sc-btn:disabled { opacity: .5; cursor: not-allowed; }
.sc-error { display: block; margin-top: 4px; font-size: 12px; color: var(--coral); }
</style>
