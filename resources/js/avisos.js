import { ref } from 'vue';

// Avisos globales (2026-10-07, pedido explícito: "como las alertas de
// SweetAlert") -- una alerta central con botón y notificaciones en la
// esquina. Estado a nivel de módulo, igual que loadingState.js: lo pinta
// Avisos.vue, montado una sola vez en app.js fuera del árbol de Inertia, así
// sobrevive a la navegación entre páginas.
export const alerta = ref(null); // { tipo, titulo, texto, boton }
export const notificaciones = ref([]); // [{ id, tipo, titulo, texto }]

let siguienteId = 1;

/**
 * Alerta central (se cierra con el botón). tipo: exito | error | info.
 */
export function mostrarAlerta({ tipo = 'exito', titulo, texto = '', boton = 'Entendido' }) {
    alerta.value = { tipo, titulo, texto, boton };
}

export function cerrarAlerta() {
    alerta.value = null;
}

/**
 * Notificación en la esquina; se cierra sola después de `duracion` ms
 * (0 = solo a mano).
 */
export function notificar({ tipo = 'info', titulo, texto = '', duracion = 8000 }) {
    const id = siguienteId++;
    notificaciones.value = [...notificaciones.value, { id, tipo, titulo, texto }];
    if (duracion > 0) {
        setTimeout(() => cerrarNotificacion(id), duracion);
    }
}

export function cerrarNotificacion(id) {
    notificaciones.value = notificaciones.value.filter((n) => n.id !== id);
}
