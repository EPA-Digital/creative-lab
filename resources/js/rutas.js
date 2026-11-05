import { usePage } from '@inertiajs/vue3';

// URLs del dashboard por cuenta y país (multi-cuenta, 2026-11-04):
// /{cuenta}/pais/{pais}/... Sin cuenta explícita usa la actual de la página
// (prop compartido `cuentaActual`, ver HandleInertiaRequests).
export function cuentaActualSlug() {
    return usePage().props.cuentaActual?.slug ?? 'tada';
}

export function urlPais(pais, resto = '', cuenta = null) {
    return `/${cuenta ?? cuentaActualSlug()}/pais/${pais}${resto}`;
}
