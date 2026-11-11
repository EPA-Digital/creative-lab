import { ref } from 'vue';

// Avisos globales (2026-10-07, pedido explícito: "como las alertas de
// SweetAlert") -- una alerta central y notificaciones en la esquina. Estado a
// nivel de módulo, igual que loadingState.js: lo pinta Avisos.vue, montado una
// sola vez en app.js fuera del árbol de Inertia, así sobrevive a la navegación
// entre páginas.
//
// 2026-11-11: confirmaciones (`confirmar`) para todo lo que borra, desactiva o
// cambia algo importante -- reemplazan los "¿Borrar? Sí / No" en línea y los
// modales de confirmación propios de cada página.
//
// tipo (ícono y color): exito | error | info | advertencia | peligro | pregunta
export const alerta = ref(null);
export const notificaciones = ref([]); // [{ id, tipo, titulo, texto }]

let siguienteId = 1;
let resolverActual = null;

/**
 * Alerta central con un solo botón. Devuelve una promesa que se resuelve al
 * cerrarla.
 */
export function mostrarAlerta({ tipo = 'exito', titulo, texto = '', boton = 'Entendido' }) {
    terminar(false);
    return new Promise((resolve) => {
        resolverActual = resolve;
        alerta.value = { modo: 'alerta', tipo, titulo, texto, boton };
    });
}

/**
 * Pregunta antes de hacer algo. Resuelve `true` si confirman y `false` si
 * cancelan (botón, Esc o clic afuera).
 *
 * - detalle: lista corta de lo que va a pasar (opcional).
 * - escribir: texto que hay que teclear tal cual para habilitar el botón
 *   (borrados grandes, ej. "2026-10" o el nombre de la cuenta).
 * - accion: función async que se corre AL confirmar, con la alerta abierta
 *   en "Procesando…". Si lanza error, el mensaje se muestra dentro de la
 *   alerta y se puede reintentar o cancelar; si sale bien, se cierra y la
 *   promesa resuelve `true`.
 * - exito: si viene, al terminar se muestra como notificación en la esquina
 *   ({ titulo, texto } o solo el título).
 */
export function confirmar({
    tipo = 'advertencia',
    titulo,
    texto = '',
    detalle = [],
    confirmar: botonConfirmar = 'Sí, continuar',
    cancelar = 'Cancelar',
    escribir = null,
    accion = null,
    exito = null,
}) {
    terminar(false);
    return new Promise((resolve) => {
        resolverActual = resolve;
        alerta.value = {
            modo: 'confirmar',
            tipo,
            titulo,
            texto,
            detalle,
            botonConfirmar,
            cancelar,
            escribir,
            accion,
            exito,
            tecleado: '',
            procesando: false,
            error: '',
        };
    });
}

/** Lo llama Avisos.vue al pulsar el botón principal. */
export async function aceptarAlerta() {
    const a = alerta.value;
    if (!a || a.procesando) return;
    if (a.modo === 'confirmar' && a.escribir && a.tecleado.trim() !== a.escribir) return;
    if (a.modo === 'confirmar' && a.accion) {
        a.procesando = true;
        a.error = '';
        try {
            await a.accion();
        } catch (err) {
            a.procesando = false;
            a.error = mensajeDeError(err);
            return;
        }
    }
    const exito = a.exito;
    terminar(true);
    if (exito) {
        notificar({ tipo: 'exito', duracion: 5000, ...(typeof exito === 'string' ? { titulo: exito } : exito) });
    }
}

/** Cancelar / cerrar (botón, Esc o clic afuera). No cierra a media acción. */
export function cerrarAlerta() {
    if (alerta.value?.procesando) return;
    terminar(false);
}

function terminar(valor) {
    const resolver = resolverActual;
    resolverActual = null;
    alerta.value = null;
    resolver?.(valor);
}

/** El mensaje de una respuesta de Laravel (422 / 4xx con `message`) o uno genérico. */
export function mensajeDeError(err, porDefecto = 'Ocurrió un error inesperado. Intenta de nuevo.') {
    const data = err?.response?.data;
    if (data?.errors) {
        const primero = Object.values(data.errors)[0];
        if (Array.isArray(primero) && primero[0]) return primero[0];
    }
    // Errores de axios traen "Request failed with status code 500" en
    // err.message: ese no se muestra.
    if (err?.response) return data?.message || porDefecto;
    return err?.message || porDefecto;
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
