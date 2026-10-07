<script setup>
import { alerta, notificaciones, cerrarAlerta, cerrarNotificacion } from '@/avisos';

// Alertas y notificaciones globales (2026-10-07, estilo SweetAlert) -- ver
// avisos.js. Montado una sola vez en app.js, fuera del árbol de Inertia.
const ICONO = { exito: '✓', error: '!', info: 'i' };
</script>

<template>
    <Transition name="aviso-fade">
        <div v-if="alerta" class="aviso-overlay" @click.self="cerrarAlerta">
            <div class="aviso-card" role="alertdialog" aria-modal="true" aria-labelledby="avisoTitulo" aria-describedby="avisoTexto">
                <span class="aviso-icono" :class="alerta.tipo" aria-hidden="true">{{ ICONO[alerta.tipo] }}</span>
                <h2 id="avisoTitulo" class="aviso-titulo">{{ alerta.titulo }}</h2>
                <p v-if="alerta.texto" id="avisoTexto" class="aviso-texto">{{ alerta.texto }}</p>
                <button type="button" class="btn-primary aviso-boton" @click="cerrarAlerta">{{ alerta.boton }}</button>
            </div>
        </div>
    </Transition>

    <div class="notificaciones" aria-live="polite">
        <TransitionGroup name="notif">
            <div v-for="nota in notificaciones" :key="nota.id" class="notificacion" :class="nota.tipo" role="status">
                <span class="notificacion-icono" :class="nota.tipo" aria-hidden="true">{{ ICONO[nota.tipo] }}</span>
                <div class="notificacion-cuerpo">
                    <p class="notificacion-titulo">{{ nota.titulo }}</p>
                    <p v-if="nota.texto" class="notificacion-texto">{{ nota.texto }}</p>
                </div>
                <button type="button" class="notificacion-cerrar" aria-label="Cerrar aviso" @click="cerrarNotificacion(nota.id)">✕</button>
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
    background: color-mix(in srgb, var(--bg, #161826) 80%, transparent);
    backdrop-filter: blur(2px);
}
.aviso-card {
    width: 100%;
    max-width: 420px;
    padding: 30px 28px 24px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-align: center;
    animation: aviso-entrar 0.25s ease-out;
}
.aviso-icono {
    width: 64px;
    height: 64px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    font-weight: 700;
    border: 3px solid currentColor;
    animation: aviso-pop 0.35s ease-out;
}
.aviso-icono.exito { color: var(--mint); background: color-mix(in srgb, var(--mint) 12%, transparent); }
.aviso-icono.error { color: var(--coral); background: color-mix(in srgb, var(--coral) 12%, transparent); }
.aviso-icono.info { color: var(--amber); background: color-mix(in srgb, var(--amber) 12%, transparent); }
.aviso-titulo {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 1.2rem;
    font-weight: 600;
    margin: 6px 0 0;
}
.aviso-texto {
    margin: 0;
    font-size: 0.88rem;
    line-height: 1.5;
    color: var(--text-muted);
}
.aviso-boton {
    margin-top: 10px;
    min-width: 140px;
}

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
.notificacion-icono {
    flex: 0 0 auto;
    width: 26px;
    height: 26px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 700;
    border: 2px solid currentColor;
}
.notificacion-icono.exito { color: var(--mint); }
.notificacion-icono.error { color: var(--coral); }
.notificacion-icono.info { color: var(--amber); }
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
    background: none;
    border: none;
    color: var(--text-faint);
    font-size: 0.8rem;
    padding: 2px 4px;
    cursor: pointer;
}
.notificacion-cerrar:hover {
    color: var(--text);
}

@keyframes aviso-entrar {
    from { opacity: 0; transform: translateY(10px) scale(0.97); }
    to { opacity: 1; transform: none; }
}
@keyframes aviso-pop {
    0% { transform: scale(0.6); opacity: 0; }
    70% { transform: scale(1.08); opacity: 1; }
    100% { transform: scale(1); }
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
    .aviso-icono {
        animation: none;
    }
}
</style>
