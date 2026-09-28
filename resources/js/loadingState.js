import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

// Estado global de navegación de Inertia (2026-09-24, pedido explícito:
// "al inicio tarda mucho en cargar"). router.on('start'/'finish') son
// eventos del router en sí, no de un componente de página -- por eso
// vive en un módulo aparte y se monta UNA sola vez en app.js, en vez de
// en el onMounted de una página que se destruye/recrea en cada
// navegación (perdería el listener justo cuando más se necesita: la
// transición ENTRE páginas, ej. Landing -> País).
export const cargando = ref(false);
export const rutaDestino = ref('');

let iniciado = false;
let temporizadorMostrar = null;

export function iniciarSeguimientoCarga() {
    if (iniciado) return;
    iniciado = true;

    router.on('start', (event) => {
        const url = event.detail.visit.url;
        rutaDestino.value = typeof url === 'string' ? url : url?.pathname || '';

        // Delay antes de mostrar (pedido explícito 2026-09-25: "si se
        // tarda mas de un segundo") -- una navegación rápida (incluido el
        // salto directo país único -> análisis, ver LandingController)
        // nunca debe hacer parpadear el overlay.
        temporizadorMostrar = setTimeout(() => {
            cargando.value = true;
        }, 1000);
    });

    router.on('finish', () => {
        clearTimeout(temporizadorMostrar);
        cargando.value = false;
    });
}
