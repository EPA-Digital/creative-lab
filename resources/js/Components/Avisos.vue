<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { alerta, notificaciones, aceptarAlerta, cerrarAlerta, cerrarNotificacion } from '@/avisos';

// Alertas, confirmaciones y notificaciones globales (estilo SweetAlert) --
// ver avisos.js. Montado una sola vez en app.js, fuera del árbol de Inertia.
const botonPrincipal = ref(null);
const botonCancelar = ref(null);
const campoEscribir = ref(null);

// Al abrir: el foco va al campo de texto si hay que escribir algo; en
// acciones destructivas, a "Cancelar" (un Enter distraído no borra nada).
watch(
    () => alerta.value,
    async (a) => {
        if (!a) return;
        await nextTick();
        if (a.escribir) campoEscribir.value?.focus();
        else if (a.tipo === 'peligro') botonCancelar.value?.focus();
        else botonPrincipal.value?.focus();
    },
);

function alTeclear(e) {
    if (!alerta.value) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        cerrarAlerta();
    }
}
onMounted(() => window.addEventListener('keydown', alTeclear));
onBeforeUnmount(() => window.removeEventListener('keydown', alTeclear));

function puedeAceptar(a) {
    return !a.procesando && (!a.escribir || a.tecleado.trim() === a.escribir);
}
</script>

<template>
    <Transition name="aviso-fade">
        <div v-if="alerta" class="aviso-overlay" @click.self="cerrarAlerta">
            <div
                class="aviso-card"
                :class="`aviso-card--${alerta.tipo}`"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="avisoTitulo"
                aria-describedby="avisoTexto"
            >
                <!-- Íconos animados al estilo SweetAlert: el trazo se dibuja al abrir. -->
                <span class="aviso-icono" :class="alerta.tipo" aria-hidden="true">
                    <svg viewBox="0 0 80 80" width="80" height="80">
                        <circle class="aviso-anillo" cx="40" cy="40" r="36" />
                        <path v-if="alerta.tipo === 'exito'" class="aviso-trazo" d="M24 41 L35 52 L57 29" />
                        <g v-else-if="alerta.tipo === 'error'">
                            <path class="aviso-trazo" d="M28 28 L52 52" />
                            <path class="aviso-trazo aviso-trazo--2" d="M52 28 L28 52" />
                        </g>
                        <g v-else-if="alerta.tipo === 'pregunta'">
                            <path class="aviso-trazo" d="M31 31 C31 22 49 22 49 31 C49 38 40 38 40 46" />
                            <circle class="aviso-punto" cx="40" cy="56" r="2.6" />
                        </g>
                        <g v-else-if="alerta.tipo === 'info'">
                            <circle class="aviso-punto" cx="40" cy="25" r="2.6" />
                            <path class="aviso-trazo" d="M40 35 L40 56" />
                        </g>
                        <g v-else>
                            <path class="aviso-trazo" d="M40 22 L40 46" />
                            <circle class="aviso-punto" cx="40" cy="56" r="2.6" />
                        </g>
                    </svg>
                </span>

                <h2 id="avisoTitulo" class="aviso-titulo">{{ alerta.titulo }}</h2>
                <p v-if="alerta.texto" id="avisoTexto" class="aviso-texto">{{ alerta.texto }}</p>
                <ul v-if="alerta.detalle?.length" class="aviso-detalle">
                    <li v-for="(linea, i) in alerta.detalle" :key="i">{{ linea }}</li>
                </ul>

                <form v-if="alerta.modo === 'confirmar' && alerta.escribir" class="aviso-escribir" @submit.prevent="aceptarAlerta">
                    <label for="avisoEscribir">
                        Escribe <span class="mono aviso-clave">{{ alerta.escribir }}</span> para confirmar
                    </label>
                    <input
                        id="avisoEscribir"
                        ref="campoEscribir"
                        v-model="alerta.tecleado"
                        class="aviso-input mono"
                        autocomplete="off"
                        spellcheck="false"
                        :disabled="alerta.procesando"
                    />
                </form>

                <p v-if="alerta.error" class="aviso-error" role="alert">{{ alerta.error }}</p>

                <div class="aviso-botones">
                    <template v-if="alerta.modo === 'confirmar'">
                        <button ref="botonCancelar" type="button" class="aviso-btn aviso-btn--sec" :disabled="alerta.procesando" @click="cerrarAlerta">
                            {{ alerta.cancelar }}
                        </button>
                        <button
                            ref="botonPrincipal"
                            type="button"
                            class="aviso-btn"
                            :class="alerta.tipo === 'peligro' ? 'aviso-btn--peligro' : 'aviso-btn--principal'"
                            :disabled="!puedeAceptar(alerta)"
                            @click="aceptarAlerta"
                        >
                            <span v-if="alerta.procesando" class="aviso-spinner" aria-hidden="true" />
                            {{ alerta.procesando ? 'Procesando…' : alerta.botonConfirmar }}
                        </button>
                    </template>
                    <button v-else ref="botonPrincipal" type="button" class="aviso-btn aviso-btn--principal" @click="aceptarAlerta">
                        {{ alerta.boton }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <div class="notificaciones" aria-live="polite">
        <TransitionGroup name="notif">
            <div v-for="nota in notificaciones" :key="nota.id" class="notificacion" :class="nota.tipo" role="status">
                <span class="notificacion-icono" :class="nota.tipo" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                        <path v-if="nota.tipo === 'exito'" d="M5 12.5l4.5 4.5L19 7.5" />
                        <path v-else-if="nota.tipo === 'error'" d="M7 7l10 10M17 7L7 17" />
                        <path v-else-if="nota.tipo === 'info'" d="M12 11v7M12 6.5h.01" />
                        <path v-else d="M12 6v8M12 18h.01" />
                    </svg>
                </span>
                <div class="notificacion-cuerpo">
                    <p class="notificacion-titulo">{{ nota.titulo }}</p>
                    <p v-if="nota.texto" class="notificacion-texto">{{ nota.texto }}</p>
                </div>
                <button type="button" class="notificacion-cerrar" aria-label="Cerrar aviso" @click="cerrarNotificacion(nota.id)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17" /></svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.aviso-overlay {
    position: fixed;
    inset: 0;
    z-index: 9500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: color-mix(in srgb, var(--bg) 78%, transparent);
    backdrop-filter: blur(3px);
}
.aviso-card {
    width: 100%;
    max-width: 440px;
    padding: 32px 28px 24px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-align: center;
    animation: aviso-entrar 0.3s cubic-bezier(0.2, 0.9, 0.3, 1.2);
}

/* --- Ícono --- */
.aviso-icono {
    --c: var(--amber);
    color: var(--c);
    display: flex;
    margin-bottom: 4px;
}
.aviso-icono.exito { --c: var(--mint); }
.aviso-icono.error,
.aviso-icono.peligro { --c: var(--coral); }
.aviso-icono.advertencia { --c: var(--yellow); }
.aviso-icono.pregunta { --c: var(--sky); }
.aviso-icono svg {
    fill: none;
    stroke: currentColor;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.aviso-anillo {
    stroke-width: 3;
    fill: color-mix(in srgb, var(--c) 12%, transparent);
    stroke-dasharray: 227;
    stroke-dashoffset: 227;
    transform: rotate(-90deg);
    transform-origin: center;
    animation: aviso-dibujar 0.45s ease-out forwards;
}
.aviso-trazo {
    stroke-width: 5;
    stroke-dasharray: 60;
    stroke-dashoffset: 60;
    animation: aviso-dibujar 0.3s 0.35s ease-out forwards;
}
.aviso-trazo--2 {
    animation-delay: 0.5s;
}
.aviso-punto {
    fill: currentColor;
    stroke: none;
    opacity: 0;
    animation: aviso-aparecer 0.2s 0.6s ease-out forwards;
}
.aviso-icono.advertencia svg,
.aviso-icono.peligro svg {
    animation: aviso-latido 0.6s 0.7s ease-in-out;
}

/* --- Texto --- */
.aviso-titulo {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
    color: var(--text);
}
.aviso-texto {
    margin: 0;
    font-size: 0.88rem;
    line-height: 1.5;
    color: var(--text-muted);
}
.aviso-detalle {
    margin: 4px 0 0;
    padding: 12px 14px 12px 30px;
    width: 100%;
    text-align: left;
    font-size: 0.8rem;
    line-height: 1.5;
    color: var(--text-muted);
    background: var(--surface-2);
    border-radius: 10px;
}
.aviso-detalle li + li {
    margin-top: 4px;
}
.aviso-escribir {
    width: 100%;
    margin-top: 6px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    text-align: left;
    font-size: 0.8rem;
    color: var(--text-muted);
}
.aviso-clave {
    color: var(--coral);
    font-weight: 600;
}
.aviso-input {
    width: 100%;
    padding: 9px 12px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 0.85rem;
}
.aviso-input:focus {
    outline: none;
    border-color: var(--amber);
}
.aviso-error {
    margin: 4px 0 0;
    width: 100%;
    padding: 9px 12px;
    font-size: 0.8rem;
    line-height: 1.45;
    color: var(--coral);
    background: color-mix(in srgb, var(--coral) 12%, transparent);
    border-radius: 8px;
}

/* --- Botones --- */
.aviso-botones {
    margin-top: 14px;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
}
.aviso-btn {
    min-width: 130px;
    padding: 10px 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 10px;
    border: 1px solid transparent;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, transform 0.15s ease, opacity 0.15s ease;
}
.aviso-btn:hover:not(:disabled) {
    transform: translateY(-1px);
}
.aviso-btn:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.aviso-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.aviso-btn--principal {
    background: var(--amber);
    color: var(--amber-ink);
}
.aviso-btn--principal:hover:not(:disabled) {
    background: color-mix(in srgb, var(--amber) 85%, var(--text));
}
.aviso-btn--peligro {
    background: var(--coral);
    color: #fff;
}
.aviso-btn--peligro:hover:not(:disabled) {
    background: color-mix(in srgb, var(--coral) 85%, var(--bg));
}
.aviso-btn--peligro:focus-visible {
    outline-color: var(--coral);
}
.aviso-btn--sec {
    background: transparent;
    border-color: var(--border);
    color: var(--text-muted);
}
.aviso-btn--sec:hover:not(:disabled) {
    border-color: var(--amber);
    color: var(--text);
}
.aviso-spinner {
    width: 14px;
    height: 14px;
    border-radius: 999px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    animation: aviso-girar 0.7s linear infinite;
}

/* --- Notificaciones --- */
.notificaciones {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 9400;
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: min(380px, calc(100vw - 40px));
}
.notificacion {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 14px 14px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: var(--shadow);
}
.notificacion.exito { border-color: color-mix(in srgb, var(--mint) 50%, var(--border)); }
.notificacion.error { border-color: color-mix(in srgb, var(--coral) 50%, var(--border)); }
.notificacion.advertencia { border-color: color-mix(in srgb, var(--yellow) 50%, var(--border)); }
.notificacion-icono {
    flex: 0 0 auto;
    width: 26px;
    height: 26px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid currentColor;
    color: var(--accent-text);
}
.notificacion-icono.exito { color: var(--mint); }
.notificacion-icono.error { color: var(--coral); }
.notificacion-icono.advertencia { color: var(--yellow); }
.notificacion-cuerpo {
    flex: 1;
    min-width: 0;
}
.notificacion-titulo {
    margin: 2px 0 0;
    font-size: 0.86rem;
    font-weight: 600;
}
.notificacion-texto {
    margin: 4px 0 0;
    font-size: 0.8rem;
    line-height: 1.45;
    color: var(--text-muted);
}
.notificacion-cerrar {
    flex: 0 0 auto;
    display: inline-flex;
    background: none;
    border: none;
    color: var(--text-faint);
    padding: 2px;
    cursor: pointer;
}
.notificacion-cerrar:hover {
    color: var(--text);
}

@keyframes aviso-entrar {
    0% { opacity: 0; transform: scale(0.8); }
    60% { opacity: 1; transform: scale(1.03); }
    100% { transform: scale(1); }
}
@keyframes aviso-dibujar {
    to { stroke-dashoffset: 0; }
}
@keyframes aviso-aparecer {
    to { opacity: 1; }
}
@keyframes aviso-latido {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.08); }
}
@keyframes aviso-girar {
    to { transform: rotate(360deg); }
}
.aviso-fade-enter-active,
.aviso-fade-leave-active {
    transition: opacity 0.15s ease;
}
.aviso-fade-enter-from,
.aviso-fade-leave-to {
    opacity: 0;
}
.notif-enter-active,
.notif-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.notif-enter-from,
.notif-leave-to {
    opacity: 0;
    transform: translateX(16px);
}
@media (prefers-reduced-motion: reduce) {
    .aviso-card,
    .aviso-icono svg,
    .aviso-anillo,
    .aviso-trazo,
    .aviso-punto {
        animation: none;
        stroke-dashoffset: 0;
        opacity: 1;
    }
}
</style>
