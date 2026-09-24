<script setup>
import { ref, onMounted, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';

// Puerto de NAV_PAIS_ITEMS + renderNavPais (motor.js:1905-1933) -- mismos 4
// items, mismos íconos SVG reales, mismo criterio "Próximamente" (sin href,
// nunca navega). Transformado de rail horizontal-top a vertical-izquierdo
// (pedido explícito) -- la lista de items/íconos NO cambió, solo la
// disposición espacial y el estado .activo (borde izquierdo en vez de
// border-bottom).
//
// 'resumen' apunta hoy a la misma ruta que 'creativos' -- resumen.html (home
// de país con KPIs/semáforo) todavía no se portó a Laravel, así que esto es
// un gap real temporal, no un error: mejor una navegación funcional a la
// única página real que existe que un link muerto o un "Próximamente" falso
// (en el sistema real, Resumen SÍ es una página real).
const props = defineProps({
    pais: { type: String, required: true },
    vistaActiva: { type: String, default: null },
});

// esEpa -- rol !== 'cliente' (ver User::esEpa() en el backend, mismo
// criterio). Un 'cliente' es solo lectura: nunca ve Cargar datos ni
// Ajustes en el rail, aunque intentara entrar por URL directa el backend
// igual lo rechaza (->middleware('can:epa'), ver routes/web.php) -- esto
// es solo UX, la guardia real vive en el servidor.
const usuario = computed(() => usePage().props.auth?.user ?? null);
const esEpa = computed(() => usuario.value && usuario.value.rol !== 'cliente');

// Ver /usuarios e invitar clientes -- gerente/director/superadmin (pedido
// explícito 2026-09-24: junior/senior ya no pueden ni ver la lista ni
// invitar, ver Gate 'ver-usuarios'). Solo UX -- la guardia real vive en
// el servidor, esto evita mostrar un link que va a dar 403.
const puedeVerUsuarios = computed(() => usuario.value && ['gerente', 'director', 'superadmin'].includes(usuario.value.rol));

// 'cliente' (pedido explícito 2026-09-23) -- solo ve Creativos, nada más.
// Resumen/Inteligencia/Insights/Cargar datos/Ajustes desaparecen del rail
// aunque backend ya las dejaba pasar en solo-lectura (analisis-creativo/
// inteligencia); un cliente no necesita ver más superficie que la data de
// creativos en sí. La guardia real sigue siendo el backend (->middleware
// ('can:epa') para Cargar datos/Ajustes) -- esto es solo UX.
const NAV_ITEMS = computed(() => {
    if (! esEpa.value) {
        return [{ vista: 'creativos', label: 'Creativos', icono: 'play', href: (id) => `/pais/${id}/analisis` }];
    }

    return [
        { vista: 'resumen', label: 'Resumen', icono: 'house', href: (id) => `/pais/${id}/analisis` },
        { vista: 'creativos', label: 'Creativos', icono: 'play', href: (id) => `/pais/${id}/analisis` },
        { vista: 'inteligencia', label: 'Inteligencia', icono: 'bulb', href: (id) => `/pais/${id}/inteligencia` },
        { vista: 'insights', label: 'Insights', icono: 'star', href: null },
        { vista: 'importar', label: 'Cargar datos', icono: 'upload', href: (id) => `/pais/${id}/importar` },
        { vista: 'ajustes', label: 'Ajustes', icono: 'gear', href: (id) => `/pais/${id}/ajustes` },
    ];
});

function cerrarSesion() {
    router.post('/logout');
}

const ICONOS = {
    gear: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    house: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-9"/></svg>',
    play: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8.5"/><path d="M10 8.7v6.6l6-3.3z" fill="currentColor" stroke="none"/></svg>',
    bulb: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.45 1 1.15 1 1.9V16h5v-.2c0-.75.4-1.45 1-1.9A6 6 0 0 0 12 3z"/></svg>',
    star: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.9z"/></svg>',
    upload: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M20 15v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-4"/></svg>',
    usuarios: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    salir: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
};

// aplicarTemaGuardado/alternarTema reales -- sessionStorage (no localStorage),
// clave 'tada-theme', atributo data-theme en <html> (no una clase).
const THEME_KEY = 'tada-theme';
const modoClaro = ref(false);
function aplicarTemaGuardado() {
    let theme = 'dark';
    try {
        theme = sessionStorage.getItem(THEME_KEY) || 'dark';
    } catch { /* sessionStorage no disponible */ }
    document.documentElement.setAttribute('data-theme', theme);
    modoClaro.value = theme === 'light';
}
function alternarTema() {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    try {
        sessionStorage.setItem(THEME_KEY, next);
    } catch { /* sessionStorage no disponible */ }
    aplicarTemaGuardado();
}
onMounted(aplicarTemaGuardado);
</script>

<template>
    <div class="app-shell">
        <aside class="rail">
            <a href="/" class="rail-logo" aria-label="Volver a selección de país" title="Volver a selección de país">
                <img src="/images/logo-white-bg.png" alt="TaDa" />
            </a>
            <span class="rail-pais mono">{{ pais?.toUpperCase() }}</span>

            <nav class="rail-nav">
                <template v-for="item in NAV_ITEMS" :key="item.vista">
                    <Link
                        v-if="item.href"
                        :href="item.href(pais)"
                        class="rail-nav-item"
                        :class="{ activo: item.vista === vistaActiva }"
                    >
                        <span class="rail-nav-icon" v-html="ICONOS[item.icono]" />
                        <span class="rail-nav-label">{{ item.label }}</span>
                    </Link>
                    <span v-else class="rail-nav-item deshabilitado" title="Próximamente" aria-disabled="true">
                        <span class="rail-nav-icon" v-html="ICONOS[item.icono]" />
                        <span class="rail-nav-label">{{ item.label }}</span>
                        <span class="proximamente-tag">Próximamente</span>
                    </span>
                </template>
            </nav>

            <Link
                v-if="puedeVerUsuarios"
                href="/usuarios"
                class="rail-nav-item rail-nav-item-secundario"
                title="Usuarios"
            >
                <span class="rail-nav-icon" v-html="ICONOS.usuarios" />
                <span class="rail-nav-label">Usuarios</span>
            </Link>

            <button
                type="button"
                class="rail-nav-item rail-nav-item-secundario"
                title="Cerrar sesión"
                @click="cerrarSesion"
            >
                <span class="rail-nav-icon" v-html="ICONOS.salir" />
                <span class="rail-nav-label">Salir</span>
            </button>

            <button type="button" class="theme-toggle rail-theme-toggle" aria-label="Cambiar tema" @click="alternarTema">
                <span class="theme-toggle-knob"></span>
            </button>
        </aside>

        <div class="app-content">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.app-shell {
    display: flex;
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
}
.rail {
    flex: 0 0 auto;
    width: 96px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    padding: 20px 0 24px;
    background: var(--surface);
    border-right: 1px solid var(--border);
    position: sticky;
    top: 0;
    /* height:100vh solo (sin dividir por --zoom-factor) rendeariza corto
       -- vh se mide contra el viewport REAL, pero `zoom` en <body> (ver
       app.css) reduce visualmente esa altura otra vez, dejando un hueco
       abajo. Dividir por --zoom-factor compensa exactamente esa segunda
       reducción. (align-self:stretch NO sirve acá -- si el rail queda tan
       alto como TODO el contenido de la página, sticky no tiene margen
       para "pegarse": necesita un alto fijo menor al alto total scrolleable.) */
    height: calc(100vh / var(--zoom-factor));
}
.rail-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid var(--border);
    padding: 5px;
    transition: transform 0.15s ease;
}
.rail-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}
.rail-logo:hover {
    transform: translateY(-1px);
}
.rail-pais {
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    color: var(--text-muted);
}
.rail-nav {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
    width: 100%;
    margin-top: 8px;
}
.rail-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 10px 4px;
    border-left: 2px solid transparent;
    color: var(--text-muted);
    text-decoration: none;
    background: none;
    font-family: 'Inter', sans-serif;
    font-size: 0.68rem;
    font-weight: 600;
    text-align: center;
    transition: color 0.15s ease, border-color 0.15s ease;
}
.rail-nav-item-secundario {
    /* .rail-nav-item ya resetea background/border-left -- esto solo cubre
       lo que un <button> nativo trae de más (border en los otros 3 lados,
       cursor, ancho) para que Usuarios/Salir se vean igual que los <Link>
       de arriba. */
    border-top: none;
    border-right: none;
    border-bottom: none;
    width: 100%;
    cursor: pointer;
    margin-top: 8px;
}
.rail-nav-icon {
    display: flex;
}
.rail-nav-item:hover:not(.deshabilitado) {
    color: var(--text);
}
.rail-nav-item.activo {
    color: var(--amber);
    border-left-color: var(--amber);
}
.rail-nav-item.deshabilitado {
    cursor: not-allowed;
    opacity: 0.5;
}
.rail-nav-item .proximamente-tag {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.55rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    font-weight: 500;
}
.rail-theme-toggle {
    margin-top: auto;
}
.app-content {
    flex: 1;
    min-width: 0;
}
</style>
