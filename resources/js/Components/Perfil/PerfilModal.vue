<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import AvatarUsuario from '@/Components/Perfil/AvatarUsuario.vue';
import { alerta, confirmar, notificar } from '@/avisos';

// "Editar perfil" (diseño 2026-11-05): cómo te ve el equipo en el
// dashboard -- foto o iniciales sobre tu color, nombre y puesto. La foto
// se sube al elegirla; nombre, puesto y color al Guardar.
const props = defineProps({
    show: { type: Boolean, default: false },
    usuario: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const COLORES = ['#9184d9', '#59e0bd', '#4fb6ff', '#d8a94a', '#ff8f84'];
const NOMBRES_COLOR = { '#9184d9': 'Morado', '#59e0bd': 'Menta', '#4fb6ff': 'Azul', '#d8a94a': 'Ámbar', '#ff8f84': 'Coral' };

const form = reactive({ name: '', puesto: '', avatar_color: COLORES[0] });
const foto = ref(null);
const guardando = ref(false);
const subiendo = ref(false);
const errores = ref({});
const campoNombre = ref(null);

const vistaPrevia = computed(() => ({ name: form.name, avatar_color: form.avatar_color, foto: foto.value }));

watch(() => props.show, async (abierto) => {
    if (!abierto) return;
    form.name = props.usuario.name ?? '';
    form.puesto = props.usuario.puesto ?? '';
    form.avatar_color = props.usuario.avatar_color || COLORES[0];
    foto.value = props.usuario.foto ?? null;
    errores.value = {};
    document.addEventListener('keydown', alTeclear);
    await nextTick();
    campoNombre.value?.focus();
}, { immediate: true });

function cerrar() {
    document.removeEventListener('keydown', alTeclear);
    emit('close');
}
function alTeclear(e) {
    // Con una confirmación abierta, Esc la cierra a ella, no al modal.
    if (e.key === 'Escape' && !alerta.value) cerrar();
}
onBeforeUnmount(() => document.removeEventListener('keydown', alTeclear));

const primerError = (e, porDefecto) => e.response?.data?.errors
    ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
    : { general: e.response?.data?.message || porDefecto };

async function elegirFoto(e) {
    const archivo = e.target.files?.[0];
    e.target.value = '';
    if (!archivo) return;
    errores.value = {};
    subiendo.value = true;
    try {
        const datos = new FormData();
        datos.append('foto', archivo);
        const { data } = await axios.post('/perfil/foto', datos);
        foto.value = data.usuario.foto;
        router.reload({ only: ['auth'] });
    } catch (err) {
        errores.value = primerError(err, 'No se pudo subir la foto.');
    } finally {
        subiendo.value = false;
    }
}

async function quitarFoto() {
    errores.value = {};
    const ok = await confirmar({
        tipo: 'advertencia',
        titulo: '¿Quitar tu foto?',
        texto: 'El equipo verá tus iniciales sobre tu color. Puedes subir otra cuando quieras.',
        confirmar: 'Sí, quitar',
        accion: () => axios.delete('/perfil/foto'),
    });
    if (!ok) return;
    foto.value = null;
    router.reload({ only: ['auth'] });
}

async function guardar() {
    if (!form.name.trim()) {
        errores.value = { name: 'Escribe tu nombre.' };
        return;
    }
    guardando.value = true;
    errores.value = {};
    try {
        await axios.patch('/perfil', { ...form });
        router.reload({ only: ['auth'] });
        notificar({ tipo: 'exito', titulo: 'Perfil actualizado', duracion: 4000 });
        cerrar();
    } catch (err) {
        errores.value = primerError(err, 'No se pudo guardar tu perfil.');
    } finally {
        guardando.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="perfil-overlay" @mousedown.self="cerrar">
            <form class="perfil-modal" role="dialog" aria-modal="true" aria-labelledby="perfil-titulo" @submit.prevent="guardar">
                <header class="perfil-head">
                    <div>
                        <h2 id="perfil-titulo">Editar perfil</h2>
                        <p>Así te verá el equipo en el dashboard.</p>
                    </div>
                    <button type="button" class="perfil-cerrar" aria-label="Cerrar" @click="cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </header>

                <div class="perfil-foto">
                    <AvatarUsuario :usuario="vistaPrevia" :tamano="72" />
                    <div class="perfil-foto-acciones">
                        <div class="perfil-foto-botones">
                            <label for="perfil-foto-input" class="perfil-btn-sec" :class="{ ocupado: subiendo }">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4" /><path d="m7 9 5-5 5 5" /><path d="M20 15v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-4" /></svg>
                                {{ subiendo ? 'Subiendo…' : (foto ? 'Cambiar foto' : 'Subir foto') }}
                            </label>
                            <input id="perfil-foto-input" class="file-select-input" type="file" accept="image/png,image/jpeg,image/webp" :disabled="subiendo" @change="elegirFoto">
                            <button v-if="foto" type="button" class="perfil-link" @click="quitarFoto">Quitar</button>
                        </div>
                        <span class="perfil-hint">PNG o JPG, de preferencia cuadrada.</span>
                        <span v-if="errores.foto" class="perfil-error">{{ errores.foto }}</span>
                    </div>
                </div>

                <div class="perfil-campo">
                    <span id="perfil-color-l" class="perfil-label mono">Color del avatar</span>
                    <div class="perfil-colores" role="radiogroup" aria-labelledby="perfil-color-l">
                        <button
                            v-for="col in COLORES"
                            :key="col"
                            type="button"
                            role="radio"
                            class="perfil-color"
                            :class="{ activo: form.avatar_color === col }"
                            :style="{ background: col }"
                            :aria-checked="form.avatar_color === col"
                            :aria-label="NOMBRES_COLOR[col]"
                            @click="form.avatar_color = col"
                        />
                    </div>
                    <span v-if="foto" class="perfil-hint">Con foto, el color se usa si la foto no carga.</span>
                </div>

                <label class="perfil-campo">
                    <span class="perfil-label mono">Nombre</span>
                    <input ref="campoNombre" v-model="form.name" class="perfil-input" :class="{ err: errores.name }" type="text" maxlength="120" autocomplete="name">
                    <span v-if="errores.name" class="perfil-error">{{ errores.name }}</span>
                </label>

                <label class="perfil-campo">
                    <span class="perfil-label mono">Puesto</span>
                    <input v-model="form.puesto" class="perfil-input" :class="{ err: errores.puesto }" type="text" maxlength="80" placeholder="Ej. Analista de performance" autocomplete="organization-title">
                    <span v-if="errores.puesto" class="perfil-error">{{ errores.puesto }}</span>
                </label>

                <p v-if="errores.general" class="perfil-error">{{ errores.general }}</p>

                <footer class="perfil-pie">
                    <button type="button" class="perfil-btn-sec" @click="cerrar">Cancelar</button>
                    <button type="submit" class="perfil-btn" :disabled="guardando">{{ guardando ? 'Guardando…' : 'Guardar' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>

<style scoped>
.perfil-overlay {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    padding: 16px;
    background: var(--overlay);
}
.perfil-modal {
    width: min(440px, 100%);
    max-height: calc(100vh / var(--zoom-factor) - 32px);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 22px 24px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow);
}
.perfil-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.perfil-head h2 {
    margin: 0;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 18px;
    font-weight: 600;
}
.perfil-head p {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--text-muted);
}
.perfil-cerrar {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 8px;
    background: none;
    color: var(--text-muted);
    cursor: pointer;
}
.perfil-cerrar:hover {
    color: var(--text);
    background: var(--surface-2);
}
.perfil-foto {
    display: flex;
    align-items: center;
    gap: 18px;
}
.perfil-foto-acciones {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.perfil-foto-botones {
    display: flex;
    align-items: center;
    gap: 12px;
}
.perfil-campo {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.perfil-label {
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.perfil-colores {
    display: flex;
    gap: 10px;
}
.perfil-color {
    width: 30px;
    height: 30px;
    border: 0;
    border-radius: 999px;
    cursor: pointer;
    transition: transform 0.15s ease;
}
.perfil-color:hover {
    transform: scale(1.08);
}
.perfil-color.activo {
    box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text);
}
.perfil-input {
    height: 40px;
    padding: 0 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg);
    color: var(--text);
    font: inherit;
    font-size: 14px;
}
.perfil-input::placeholder {
    color: var(--text-faint);
}
.perfil-input:focus {
    outline: 2px solid var(--amber);
    outline-offset: 1px;
    border-color: var(--amber);
}
.perfil-input.err {
    border-color: var(--coral);
}
.perfil-hint {
    font-size: 12.5px;
    color: var(--text-muted);
}
.perfil-error {
    margin: 0;
    font-size: 12.5px;
    color: var(--coral);
}
.perfil-pie {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
.perfil-btn,
.perfil-btn-sec {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 40px;
    padding: 0 16px;
    border-radius: 10px;
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: border-color 0.15s ease, opacity 0.15s ease;
}
.perfil-btn {
    border: 0;
    background: var(--amber);
    color: var(--amber-ink);
}
.perfil-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.perfil-btn-sec {
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
}
.perfil-btn-sec:hover {
    border-color: var(--amber);
}
.perfil-btn-sec.ocupado {
    opacity: 0.6;
    pointer-events: none;
}
.perfil-link {
    border: 0;
    padding: 0;
    background: none;
    color: var(--text-muted);
    font: inherit;
    font-size: 13px;
    text-decoration: underline;
    text-underline-offset: 2px;
    cursor: pointer;
}
.perfil-link:hover {
    color: var(--text);
}
.perfil-btn:focus-visible,
.perfil-btn-sec:focus-visible,
.perfil-color:focus-visible,
.perfil-cerrar:focus-visible,
.perfil-link:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
</style>
