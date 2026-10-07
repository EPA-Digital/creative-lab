import axios from 'axios';
import { notificar } from '@/avisos';

// Seguimiento de imágenes/videos que se guardan en background (2026-10-07,
// ver TareaMediosController): "te avisamos cuando todo esté listo". Las
// tareas en curso viven en sessionStorage, así que el aviso llega aunque se
// cambie de página o se recargue; el polling corre a nivel de módulo
// (iniciado una vez en app.js), no dentro de una página.
const CLAVE = 'tada-tareas-medios';
const INTERVALO_MS = 15000;
let temporizador = null;

function leer() {
    try {
        return JSON.parse(sessionStorage.getItem(CLAVE) || '[]');
    } catch {
        return [];
    }
}

function escribir(tareas) {
    try {
        sessionStorage.setItem(CLAVE, JSON.stringify(tareas));
    } catch {
        /* sessionStorage no disponible: el aviso solo llega en esta página */
    }
}

export function etiquetaMes(mes) {
    const [anio, m] = (mes || '').split('-').map(Number);
    if (!anio || !m) return mes || '';
    return new Date(anio, m - 1, 1).toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
}

function n(v) {
    return Number(v || 0).toLocaleString('es-MX');
}

async function revisar() {
    const tareas = leer();
    if (!tareas.length) {
        detener();
        return;
    }

    const siguen = [];
    for (const t of tareas) {
        try {
            const { data } = await axios.get(`/pais/${t.pais}/medios/tareas/${t.id}`);
            if (data.estado === 'listo') {
                const fallidos = data.fallidos ? ` ${n(data.fallidos)} no se pudieron recuperar (el anuncio ya no existe en la plataforma o no tiene imagen).` : '';
                notificar({
                    tipo: data.fallidos && !data.listos ? 'error' : 'exito',
                    titulo: `Listo: imágenes y videos de ${etiquetaMes(data.mes)}`,
                    texto: `Se guardaron ${n(data.listos)} de ${n(data.total)}.${fallidos}`,
                    duracion: 0,
                });
            } else if (data.estado === 'error') {
                notificar({
                    tipo: 'error',
                    titulo: `No se pudieron guardar las imágenes de ${etiquetaMes(data.mes)}`,
                    texto: data.error_mensaje || 'Ocurrió un error inesperado.',
                    duracion: 0,
                });
            } else {
                siguen.push(t);
            }
        } catch (err) {
            // 404: la tarea ya no existe o no es de este país -- se deja de
            // seguir. Cualquier otro error (red), se reintenta en el próximo ciclo.
            if (err.response?.status !== 404 && err.response?.status !== 403) siguen.push(t);
        }
    }
    escribir(siguen);
    if (!siguen.length) detener();
}

function detener() {
    if (temporizador) {
        clearInterval(temporizador);
        temporizador = null;
    }
}

function arrancar() {
    if (!temporizador && leer().length) {
        temporizador = setInterval(revisar, INTERVALO_MS);
    }
}

/**
 * Empieza a seguir una tarea; avisa con una notificación cuando termina.
 */
export function seguirTareaMedios({ pais, id }) {
    if (!pais || !id) return;
    const tareas = leer().filter((t) => t.id !== id);
    escribir([...tareas, { pais, id }]);
    arrancar();
}

/**
 * Retoma las tareas pendientes al cargar la app (ver app.js).
 */
export function iniciarSeguimientoMedios() {
    arrancar();
}
