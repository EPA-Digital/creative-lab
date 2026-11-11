import axios from 'axios';

// Presencia (2026-11-11): mientras alguien USA el dashboard (mueve el mouse,
// teclea, hace scroll o toca), la pestaña avisa una vez por minuto. Así el
// círculo de Usuarios distingue "en línea" de "dejó la pestaña abierta".
// No avisa en segundo plano ni sin uso reciente: una sesión olvidada sigue
// venciéndose sola (SESSION_LIFETIME).
const CADA_MS = 60 * 1000;
const USO_RECIENTE_MS = 5 * 60 * 1000;

let iniciado = false;
let ultimoUso = Date.now();
let ultimoAviso = 0;

function marcarUso() {
    ultimoUso = Date.now();
    // Si vuelve después de un rato, avisa ya (no hasta el siguiente minuto).
    if (Date.now() - ultimoAviso > CADA_MS) avisar();
}

function avisar() {
    if (document.visibilityState !== 'visible') return;
    if (Date.now() - ultimoUso > USO_RECIENTE_MS) return;
    ultimoAviso = Date.now();
    axios.post('/perfil/actividad').catch(() => {});
}

/** Lo arranca DashboardLayout (solo con sesión); una sola vez por pestaña. */
export function iniciarPresencia() {
    if (iniciado) return;
    iniciado = true;
    for (const evento of ['mousemove', 'keydown', 'scroll', 'pointerdown', 'touchstart']) {
        window.addEventListener(evento, marcarUso, { passive: true, capture: true });
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') marcarUso();
    });
    setInterval(avisar, CADA_MS);
    avisar();
}
