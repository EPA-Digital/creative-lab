import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import CargandoOverlay from './Components/CargandoOverlay.vue';
import { iniciarSeguimientoCarga } from './loadingState';
import Avisos from './Components/Avisos.vue';
import { iniciarSeguimientoMedios } from './seguimientoMedios';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Overlay de carga (2026-09-24) -- mini-app Vue aparte, montada en su
// propio nodo al final de <body>, fuera del árbol de Inertia. Así
// sobrevive a que cada navegación destruya y vuelva a montar la página
// entera (incluido el layout) -- si viviera dentro de una página, el
// listener de router.on('start') se perdería justo en la transición que
// más importa mostrar (ej. Landing -> País).
iniciarSeguimientoCarga();
const overlayHost = document.createElement('div');
document.body.appendChild(overlayHost);
createApp(CargandoOverlay).mount(overlayHost);

// Avisos globales (2026-10-07, estilo SweetAlert) + seguimiento de
// imágenes/videos en background -- mismo criterio que el overlay: su propia
// mini-app fuera del árbol de Inertia, para que un aviso de "ya están las
// imágenes" llegue aunque se haya cambiado de página.
const avisosHost = document.createElement('div');
document.body.appendChild(avisosHost);
createApp(Avisos).mount(avisosHost);
iniciarSeguimientoMedios();

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
