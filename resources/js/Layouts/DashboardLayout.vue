<script setup>
import { ref, onMounted, onBeforeUnmount, computed, nextTick } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Aviso2fa from '@/Components/Aviso2fa.vue';
import { urlPais } from '@/rutas';
import { iniciales } from '@/cuentas';
import LogoCuenta from '@/Components/Cuenta/LogoCuenta.vue';
import AvatarUsuario from '@/Components/Perfil/AvatarUsuario.vue';
import PerfilModal from '@/Components/Perfil/PerfilModal.vue';

// Nav (diseño 2026-11-05, pedido explícito): logo de EPA, selector de cuenta
// desplegable (buscador con ⌘K, cuentas con sus países, "Nueva cuenta" y
// "Ver todas"), los países de la cuenta, Análisis, Datos y Administración, y
// el usuario con tema y salir al pie. Se puede contraer a solo íconos.
//
// 'resumen' apunta hoy a la misma ruta que 'creativos' -- resumen.html (home
// de país con KPIs/semáforo) todavía no se portó a Laravel: mejor una
// navegación funcional a la única página real que un link muerto.
const props = defineProps({
    pais: { type: String, required: true },
    vistaActiva: { type: String, default: null },
});

// esEpa -- rol !== 'cliente' (ver User::esEpa()). Un 'cliente' es solo
// lectura: solo ve Creativos. Todo esto es UX; la guardia real vive en el
// servidor (->middleware('can:epa'), Gates de /usuarios).
const usuario = computed(() => usePage().props.auth?.user ?? null);
const esEpa = computed(() => usuario.value && usuario.value.rol !== 'cliente');
const puedeVerUsuarios = computed(() => usuario.value && ['gerente', 'director', 'superadmin'].includes(usuario.value.rol));

// Ruta de cada vista dentro de /{cuenta}/pais/{pais}; cambiar de país
// conserva la vista en la que está.
const RUTA_VISTA = { resumen: '/analisis', creativos: '/analisis', inteligencia: '/inteligencia', importar: '/importar', ajustes: '/ajustes' };

const SECCION_ANALISIS = computed(() => (esEpa.value
    ? [
        { vista: 'resumen', label: 'Resumen', icono: 'house' },
        { vista: 'creativos', label: 'Creativos', icono: 'play' },
        { vista: 'inteligencia', label: 'Inteligencia', icono: 'bulb' },
        { vista: 'insights', label: 'Insights', icono: 'star', proximamente: true },
    ]
    : [{ vista: 'creativos', label: 'Creativos', icono: 'play' }]));

const cuentaActual = computed(() => usePage().props.cuentaActual ?? null);
const misCuentas = computed(() => usePage().props.misCuentas ?? []);
const paisesCuenta = computed(() => misCuentas.value.find((c) => c.slug === cuentaActual.value?.slug)?.paises ?? []);
const paisActual = computed(() => paisesCuenta.value.find((p) => p.id === props.pais));
const nombrePais = computed(() => paisActual.value?.nombre ?? (props.pais ? props.pais.charAt(0).toUpperCase() + props.pais.slice(1) : ''));

const hrefVista = (vista, pais = props.pais) => urlPais(pais, RUTA_VISTA[vista] ?? '/analisis');
// Otra cuenta: su primer país, en Creativos (sin países: el selector de inicio).
const hrefCuenta = (c) => (c.paises.length ? urlPais(c.paises[0].id, '/analisis', c.slug) : `/?cuenta=${c.slug}`);
// En Cuentas/Usuarios (fuera de un país) el cambio de país va a Creativos.
const vistaParaPais = computed(() => (RUTA_VISTA[props.vistaActiva] ? props.vistaActiva : 'creativos'));

// --- Selector de cuenta -------------------------------------------------------
const selectorAbierto = ref(false);
const busqueda = ref('');
const buscador = ref(null);
const cuentasFiltradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return misCuentas.value.filter((c) => !q || c.nombre.toLowerCase().includes(q) || (c.cliente || '').toLowerCase().includes(q));
});
async function abrirSelector() {
    selectorAbierto.value = true;
    busqueda.value = '';
    await nextTick();
    buscador.value?.focus();
}
function cerrarSelector() {
    selectorAbierto.value = false;
}
function irACuenta(c) {
    cerrarSelector();
    router.visit(hrefCuenta(c));
}
function alClicFuera(e) {
    if (selectorAbierto.value && !e.target.closest('.selector-cuenta')) cerrarSelector();
    if (menuUsuario.value && !e.target.closest('.usuario-zona')) menuUsuario.value = false;
}
// ⌘K / Ctrl+K abre el selector desde cualquier pantalla; Esc lo cierra.
function alTeclear(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        if (contraido.value) alternarPanel();
        selectorAbierto.value ? cerrarSelector() : abrirSelector();
    } else if (e.key === 'Escape' && (selectorAbierto.value || menuUsuario.value)) {
        cerrarSelector();
        menuUsuario.value = false;
    }
}
const codigosPaises = (c) => c.paises.map((p) => p.codigo).join(' · ');

// --- Usuario: tarjeta del pie con menú (perfil, tema, salir) ---------------
const menuUsuario = ref(false);
const perfilAbierto = ref(false);
function abrirPerfil() {
    menuUsuario.value = false;
    perfilAbierto.value = true;
}

function cerrarSesion() {
    router.post('/logout');
}

const ICONOS = {
    usuario: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    chevrons: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5M7 9l5-5 5 5"/></svg>',
    check: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>',
    buscar: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
    luna: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
    sol: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    panel: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>',
    mas: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    ajustes: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>',
    gear: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    house: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-9"/></svg>',
    play: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8.5"/><path d="M10 8.7v6.6l6-3.3z" fill="currentColor" stroke="none"/></svg>',
    bulb: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.45 1 1.15 1 1.9V16h5v-.2c0-.75.4-1.45 1-1.9A6 6 0 0 0 12 3z"/></svg>',
    star: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.9z"/></svg>',
    upload: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M20 15v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-4"/></svg>',
    cuentas: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 13h18"/></svg>',
    usuarios: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    salir: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
};

// Tema: sessionStorage (no localStorage), clave 'tada-theme', atributo
// data-theme en <html> (no una clase).
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

// Panel contraído (solo íconos) -- preferencia de este navegador.
const NAV_KEY = 'tada-nav-contraido';
const contraido = ref(false);
function alternarPanel() {
    contraido.value = !contraido.value;
    try {
        localStorage.setItem(NAV_KEY, contraido.value ? '1' : '0');
    } catch { /* localStorage no disponible */ }
}

onMounted(() => {
    document.addEventListener('click', alClicFuera);
    document.addEventListener('keydown', alTeclear);
    aplicarTemaGuardado();
    try {
        contraido.value = localStorage.getItem(NAV_KEY) === '1';
    } catch { /* localStorage no disponible */ }
});
onBeforeUnmount(() => {
    document.removeEventListener('click', alClicFuera);
    document.removeEventListener('keydown', alTeclear);
});
</script>

<template>
    <div class="app-shell">
        <aside class="panel" :class="{ contraido }">
            <div class="panel-top">
                <a href="/" class="logo-epa" aria-label="Inicio" title="Inicio">
                    <img src="/images/epa-logo-crop.png" alt="EPA" />
                </a>
                <button type="button" class="icono-btn" :aria-label="contraido ? 'Expandir menú' : 'Contraer menú'" :title="contraido ? 'Expandir menú' : 'Contraer menú'" @click="alternarPanel">
                    <span v-html="ICONOS.panel" />
                </button>
            </div>

            <!-- Selector de cuenta -->
            <div class="selector-cuenta">
                <button
                    type="button"
                    class="selector-btn"
                    :class="{ abierto: selectorAbierto }"
                    aria-haspopup="listbox"
                    :aria-expanded="selectorAbierto"
                    :title="contraido ? cuentaActual?.nombre : 'Cambiar de cuenta (⌘K)'"
                    @click="selectorAbierto ? cerrarSelector() : abrirSelector()"
                >
                    <LogoCuenta :cuenta="cuentaActual" :tamano="36" :radio="9" />
                    <span class="selector-texto">
                        <span class="selector-nombre">{{ cuentaActual?.nombre }}</span>
                        <span class="selector-sub">{{ nombrePais }}</span>
                    </span>
                    <span class="selector-chevrons" v-html="ICONOS.chevrons" />
                </button>

                <div v-if="selectorAbierto" class="selector-pop" role="dialog" aria-label="Cambiar de cuenta">
                    <label class="selector-buscar">
                        <span v-html="ICONOS.buscar" />
                        <input
                            ref="buscador"
                            v-model="busqueda"
                            type="text"
                            placeholder="Buscar cuenta o cliente"
                            aria-label="Buscar cuenta o cliente"
                            @keydown.enter.prevent="cuentasFiltradas[0] && irACuenta(cuentasFiltradas[0])"
                        >
                        <kbd class="mono">⌘K</kbd>
                    </label>
                    <span class="selector-titulo mono">Cuentas</span>
                    <div class="selector-lista" role="listbox">
                        <button
                            v-for="c in cuentasFiltradas"
                            :key="c.slug"
                            type="button"
                            role="option"
                            class="selector-opcion"
                            :class="{ actual: c.slug === cuentaActual?.slug }"
                            :aria-selected="c.slug === cuentaActual?.slug"
                            @click="irACuenta(c)"
                        >
                            <LogoCuenta :cuenta="c" :tamano="32" :radio="8" />
                            <span class="selector-texto">
                                <span class="selector-nombre">{{ c.nombre }}</span>
                                <span class="selector-sub">{{ c.cliente }}</span>
                            </span>
                            <span class="selector-paises mono">{{ codigosPaises(c) }}</span>
                            <span class="selector-check" :style="{ visibility: c.slug === cuentaActual?.slug ? 'visible' : 'hidden' }" v-html="ICONOS.check" />
                        </button>
                        <p v-if="!cuentasFiltradas.length" class="selector-vacio">Ninguna cuenta coincide con “{{ busqueda }}”.</p>
                    </div>
                    <div v-if="esEpa" class="selector-pie">
                        <Link href="/cuentas/nueva" class="selector-nueva" @click="cerrarSelector">
                            <span v-html="ICONOS.mas" />Nueva cuenta
                        </Link>
                        <Link href="/cuentas" class="selector-todas" @click="cerrarSelector">Ver todas</Link>
                    </div>
                </div>
            </div>

            <!-- Solo esta parte hace scroll: el panel no, para que el selector
                 de cuenta pueda salirse de él sin recortarse. -->
            <div class="panel-scroll">
            <div v-if="paisesCuenta.length > 1" class="panel-paises">
                <Link
                    v-for="p in paisesCuenta"
                    :key="p.id"
                    :href="hrefVista(vistaParaPais, p.id)"
                    class="pais-chip mono"
                    :class="{ activo: p.id === pais }"
                    :title="p.nombre"
                >{{ p.codigo }}</Link>
            </div>

            <div class="panel-seccion">
                <span class="panel-seccion-titulo mono">Análisis</span>
                <template v-for="item in SECCION_ANALISIS" :key="item.vista">
                    <span v-if="item.proximamente" class="nav-item deshabilitado" :title="`${item.label} · Próximamente`" aria-disabled="true">
                        <span class="nav-icono" v-html="ICONOS[item.icono]" />
                        <span class="nav-label">{{ item.label }}</span>
                        <span class="nav-tag mono">Próximamente</span>
                    </span>
                    <Link v-else :href="hrefVista(item.vista)" class="nav-item" :class="{ activo: vistaActiva === item.vista }" :title="item.label">
                        <span class="nav-icono" v-html="ICONOS[item.icono]" />
                        <span class="nav-label">{{ item.label }}</span>
                    </Link>
                </template>
            </div>

            <div v-if="esEpa" class="panel-seccion">
                <span class="panel-seccion-titulo mono">Datos</span>
                <Link :href="hrefVista('importar')" class="nav-item" :class="{ activo: vistaActiva === 'importar' }" title="Cargar datos">
                    <span class="nav-icono" v-html="ICONOS.upload" />
                    <span class="nav-label">Cargar datos</span>
                </Link>
            </div>

            <div v-if="esEpa" class="panel-seccion panel-admin">
                <span class="panel-seccion-titulo mono">Administración</span>
                <Link href="/cuentas" class="nav-item" :class="{ activo: vistaActiva === 'cuentas' }" title="Cuentas">
                    <span class="nav-icono" v-html="ICONOS.cuentas" />
                    <span class="nav-label">Cuentas</span>
                    <span class="nav-contador mono">{{ misCuentas.length }}</span>
                </Link>
                <Link v-if="puedeVerUsuarios" href="/usuarios" class="nav-item" :class="{ activo: vistaActiva === 'usuarios' }" title="Usuarios">
                    <span class="nav-icono" v-html="ICONOS.usuarios" />
                    <span class="nav-label">Usuarios</span>
                </Link>
                <Link :href="hrefVista('ajustes')" class="nav-item" :class="{ activo: vistaActiva === 'ajustes' }" title="Ajustes">
                    <span class="nav-icono" v-html="ICONOS.ajustes" />
                    <span class="nav-label">Ajustes</span>
                </Link>
            </div>

            </div>

            <div class="usuario-zona">
                <div v-if="menuUsuario" class="usuario-menu" role="menu">
                    <button type="button" role="menuitem" class="usuario-menu-item" @click="abrirPerfil">
                        <span v-html="ICONOS.usuario" />Editar perfil
                    </button>
                    <button type="button" role="menuitem" class="usuario-menu-item" @click="alternarTema">
                        <span v-html="modoClaro ? ICONOS.luna : ICONOS.sol" />{{ modoClaro ? 'Tema oscuro' : 'Tema claro' }}
                    </button>
                    <button type="button" role="menuitem" class="usuario-menu-item peligro" @click="cerrarSesion">
                        <span v-html="ICONOS.salir" />Cerrar sesión
                    </button>
                </div>
                <button
                    type="button"
                    class="usuario-btn"
                    :class="{ abierto: menuUsuario }"
                    aria-haspopup="menu"
                    :aria-expanded="menuUsuario"
                    :title="contraido ? usuario?.name : 'Tu perfil'"
                    @click="menuUsuario = !menuUsuario"
                >
                    <AvatarUsuario :usuario="usuario" :tamano="36" />
                    <span class="usuario-texto">
                        <span class="usuario-nombre">{{ usuario?.name }}</span>
                        <span class="usuario-puesto" :class="{ vacio: !usuario?.puesto }">{{ usuario?.puesto || 'Agrega tu puesto' }}</span>
                    </span>
                    <span class="selector-chevrons" v-html="ICONOS.chevrons" />
                </button>
            </div>
        </aside>

        <PerfilModal v-if="usuario" :show="perfilAbierto" :usuario="usuario" @close="perfilAbierto = false" />

        <div class="app-content">
            <Aviso2fa />
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

/* Panel fijo a la altura de la ventana. height:100vh solo rendea corto por
   el `zoom` de <body> (ver app.css): dividir por --zoom-factor lo compensa.
   Sticky necesita un alto fijo menor al alto scrolleable. */
.panel {
    position: sticky;
    top: 0;
    height: calc(100vh / var(--zoom-factor));
    flex: 0 0 auto;
    width: 280px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 18px 16px;
    background: var(--surface);
    border-right: 1px solid var(--border);
    z-index: 20;
    transition: width 0.15s ease;
}
.panel-scroll {
    display: flex;
    flex-direction: column;
    gap: 18px;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    margin: 0 -6px;
    padding: 0 6px;
}
.panel-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.logo-epa {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #ffffff;
    padding: 7px;
    transition: transform 0.15s ease;
}
.logo-epa img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}
.logo-epa:hover {
    transform: translateY(-1px);
}
.logo-epa:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 3px;
}

/* --- Selector de cuenta --------------------------------------------------- */
.selector-cuenta {
    position: relative;
}
.selector-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 9px 10px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-2);
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s ease;
}
.selector-btn:hover,
.selector-btn.abierto {
    border-color: var(--amber);
}
.selector-btn.abierto {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--amber) 25%, transparent);
}
.selector-btn:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.selector-texto {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}
.selector-nombre {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 15px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.selector-sub {
    font-size: 12px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.selector-chevrons {
    display: flex;
    color: var(--text-muted);
}
.selector-pop {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    z-index: 50;
    width: max(100%, 300px);
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--surface);
    box-shadow: var(--shadow);
}
.selector-buscar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 10px;
    height: 38px;
    border: 1px solid var(--amber);
    border-radius: 10px;
    background: var(--bg);
    color: var(--text-muted);
}
.selector-buscar input,
.selector-buscar input:focus {
    flex: 1;
    min-width: 0;
    padding: 0;
    border: 0;
    box-shadow: none;
    appearance: none;
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 13px;
    outline: none;
}
.selector-buscar kbd {
    font-size: 10.5px;
    color: var(--text-faint);
}
.selector-titulo {
    display: block;
    padding: 12px 6px 6px;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.selector-lista {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 320px;
    overflow-y: auto;
}
.selector-opcion {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px;
    border: 0;
    border-radius: 10px;
    background: none;
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: background 0.15s ease;
}
.selector-opcion:hover,
.selector-opcion:focus-visible,
.selector-opcion.actual {
    background: var(--surface-2);
    outline: none;
}
.selector-opcion .selector-nombre {
    font-size: 14px;
}
.selector-paises {
    font-size: 10.5px;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    white-space: nowrap;
}
.selector-check {
    display: flex;
    color: var(--accent-text);
}
.selector-vacio {
    margin: 0;
    padding: 10px 8px;
    font-size: 12.5px;
    color: var(--text-muted);
}
.selector-pie {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 8px;
    padding: 10px 6px 2px;
    border-top: 1px solid var(--border);
    font-size: 13px;
}
.selector-nueva {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--accent-text);
    font-weight: 600;
    text-decoration: none;
}
.selector-nueva :deep(svg) {
    width: 15px;
    height: 15px;
}
.selector-todas {
    color: var(--text-muted);
    text-decoration: none;
}
.selector-nueva:hover,
.selector-todas:hover {
    color: var(--text);
}

/* --- Botones de ícono ----------------------------------------------------- */
.icono-btn {
    display: grid;
    place-items: center;
    flex: none;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease;
}
.icono-btn.redondo {
    border-radius: 999px;
}
.icono-btn.sin-borde {
    border-color: transparent;
}
.icono-btn:hover {
    color: var(--text);
    border-color: var(--amber);
}
.icono-btn:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}

/* --- Países y navegación -------------------------------------------------- */
.panel-paises {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 0 2px;
}
.pais-chip {
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-decoration: none;
    transition: color 0.15s ease, border-color 0.15s ease;
}
.pais-chip:hover {
    color: var(--text);
    border-color: var(--amber);
}
.pais-chip.activo {
    color: var(--accent-text);
    border-color: var(--amber);
    background: color-mix(in srgb, var(--amber) 12%, transparent);
}
.panel-seccion {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.panel-seccion-titulo {
    padding: 0 10px 6px;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-faint);
}
.panel-admin {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 9px 10px;
    border-radius: 10px;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    transition: color 0.15s ease, background 0.15s ease;
}
.nav-item:hover:not(.deshabilitado) {
    color: var(--text);
    background: var(--surface-2);
}
.nav-item.activo {
    color: var(--accent-text);
    background: color-mix(in srgb, var(--amber) 14%, transparent);
    font-weight: 600;
}
.nav-item:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.nav-item.deshabilitado {
    opacity: 0.5;
    cursor: not-allowed;
}
.nav-icono {
    display: flex;
    flex: none;
}
.nav-label {
    flex: 1;
    white-space: nowrap;
}
.nav-tag {
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--surface-2);
    color: var(--text-muted);
    font-size: 9.5px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.nav-contador {
    font-size: 12px;
    color: var(--accent-text);
}

.usuario-zona {
    position: relative;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}
.usuario-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px;
    border: 1px solid transparent;
    border-radius: 12px;
    background: none;
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.usuario-btn:hover,
.usuario-btn.abierto {
    background: var(--surface-2);
}
.usuario-btn.abierto {
    border-color: var(--border);
}
.usuario-btn:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.usuario-texto {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}
.usuario-nombre {
    font-size: 14px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.usuario-puesto {
    font-size: 12px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* Invitación a completarlo, no un dato. */
.usuario-puesto.vacio {
    color: var(--text-faint);
}
.usuario-menu {
    position: absolute;
    bottom: calc(100% + 6px);
    left: 0;
    z-index: 50;
    width: max(100%, 220px);
    padding: 6px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: var(--shadow);
}
.usuario-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 9px 10px;
    border: 0;
    border-radius: 8px;
    background: none;
    color: var(--text);
    font: inherit;
    font-size: 14px;
    text-align: left;
    cursor: pointer;
}
.usuario-menu-item :deep(svg) {
    color: var(--text-muted);
}
.usuario-menu-item:hover,
.usuario-menu-item:focus-visible {
    background: var(--surface-2);
    outline: none;
}
.usuario-menu-item.peligro {
    color: var(--coral);
}
.usuario-menu-item.peligro :deep(svg) {
    color: var(--coral);
}

/* --- Contraído: solo íconos ----------------------------------------------- */
.panel.contraido {
    width: 76px;
    padding: 18px 12px;
    align-items: center;
}
.panel.contraido .panel-top {
    flex-direction: column;
    gap: 10px;
}
.panel.contraido .selector-btn {
    padding: 4px;
    border: 0;
    background: none;
}
.panel.contraido .selector-texto,
.panel.contraido .selector-chevrons,
.panel.contraido .panel-seccion-titulo,
.panel.contraido .nav-label,
.panel.contraido .nav-tag,
.panel.contraido .nav-contador,
.panel.contraido .usuario-texto,
.panel.contraido .usuario-btn .selector-chevrons {
    display: none;
}
.panel.contraido .usuario-btn {
    justify-content: center;
    padding: 4px;
}
.panel.contraido .panel-paises {
    flex-direction: column;
    padding: 0;
}
.panel.contraido .panel-seccion,
.panel.contraido .panel-scroll {
    align-items: center;
}

.app-content {
    flex: 1;
    min-width: 0;
}
</style>
