<script setup>
import { computed, ref, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Aviso2fa from '@/Components/Aviso2fa.vue';
import LogoCuenta from '@/Components/Cuenta/LogoCuenta.vue';
import { urlPais } from '@/rutas';

// Puerto de index.html + renderSelectorPais (motor.js:1833-1880) -- la
// jerarquía topbar/selector/footer y el comportamiento (click en un país
// habilitado navega a su análisis) son literales; el detalle visual del
// anillo animado de selección (reproducirAnimacionSeleccionPais, 32 líneas
// radiales) se omitió a propósito -- el usuario confirmó que el detalle
// visual de los círculos puede resolverse simple en Vue, no es una regla
// estricta como sí lo es la jerarquía de secciones.
//
// Multi-cuenta (2026-11-04): primero se elige la cuenta y después el país.
// Con una sola cuenta se salta directo a sus países.
const props = defineProps({
    cuentas: { type: Array, required: true },
    cuentaInicial: { type: String, default: null },
});

const elegida = ref(
    props.cuentas.find((c) => c.slug === props.cuentaInicial)
    ?? (props.cuentas.length === 1 ? props.cuentas[0] : null),
);
const paises = computed(() => elegida.value?.paises ?? []);

function elegirCuenta(cuenta) {
    const habilitados = cuenta.paises.filter((p) => p.habilitado);
    if (habilitados.length === 1 && cuenta.paises.length === 1) {
        router.visit(urlPais(habilitados[0].id, '/analisis', cuenta.slug));
        return;
    }
    elegida.value = cuenta;
}

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

function seleccionarPais(pais) {
    if (!pais.habilitado) return;
    router.visit(urlPais(pais.id, '/analisis', elegida.value.slug));
}
</script>

<template>
    <Head title="TaDa Creative Intelligence" />

    <header class="topbar">
        <div class="brand"><h1>TaDa Creative Intelligence</h1></div>
        <button type="button" class="theme-toggle" aria-label="Cambiar tema" @click="alternarTema">
            <span class="theme-toggle-knob"></span>
        </button>
    </header>

    <main>
        <Aviso2fa />
        <div v-if="!cuentas.length" class="selector-pais-view">
            <h2>Aún no tienes cuentas asignadas</h2>
            <div class="selector-pais-regla"></div>
            <p class="landing-vacio">Pide a un director que te asigne una cuenta y sus países desde Usuarios.</p>
        </div>

        <div v-else-if="!elegida" class="selector-pais-view">
            <h2>Selecciona una cuenta</h2>
            <div class="selector-pais-regla"></div>
            <div class="landing-cuentas">
                <button v-for="c in cuentas" :key="c.slug" type="button" class="landing-cuenta" @click="elegirCuenta(c)">
                    <LogoCuenta :cuenta="c" :tamano="44" :radio="12" />
                    <span class="landing-cuenta-texto">
                        <span class="landing-cuenta-nombre">{{ c.nombre }}</span>
                        <span class="landing-cuenta-meta mono">{{ c.cliente }} · {{ c.paises.length === 1 ? '1 PAÍS' : `${c.paises.length} PAÍSES` }}</span>
                    </span>
                </button>
            </div>
        </div>

        <div v-else class="selector-pais-view">
            <p v-if="cuentas.length > 1" class="landing-cuenta-elegida">
                <span class="mono">{{ elegida.nombre }}</span>
                <button type="button" class="landing-cambiar" @click="elegida = null">Cambiar cuenta</button>
            </p>
            <h2>Selecciona un país</h2>
            <div class="selector-pais-regla"></div>

            <div class="pais-constelacion">
                <div
                    v-for="pais in paises"
                    :key="pais.id"
                    class="pais-circulo-wrap"
                    :class="pais.habilitado ? 'activo' : 'apagado'"
                >
                    <button
                        type="button"
                        class="pais-circulo"
                        :disabled="!pais.habilitado"
                        :aria-disabled="!pais.habilitado"
                        :aria-label="pais.nombre"
                        :style="{
                            '--glow-1': pais.bandera_colores?.[0] || '#F2A93B',
                            '--glow-2': pais.bandera_colores?.[1] || '#F2A93B',
                            '--glow-3': pais.bandera_colores?.[2] || pais.bandera_colores?.[0] || '#F2A93B',
                        }"
                        @click="seleccionarPais(pais)"
                    >
                        <span class="pais-circulo-bandera" :style="{ background: pais.bandera_gradiente || 'var(--surface-2)' }" />
                        <span v-if="pais.habilitado" class="pais-circulo-acento" />
                    </button>
                    <span class="pais-circulo-label">{{ pais.nombre.toUpperCase() }}</span>
                    <span v-if="!pais.habilitado" class="pais-circulo-proximamente">Próximamente</span>
                </div>
            </div>
        </div>
    </main>

    <footer class="landing-footer"><span class="mono">TADA CREATIVE LAB</span></footer>
</template>

<style scoped>
.landing-vacio {
    margin-top: 24px;
    color: var(--text-muted);
    font-size: 0.9rem;
}
.landing-cuentas {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 14px;
    margin-top: 48px;
    text-align: left;
    animation: landing-fade 0.6s ease both;
}
.landing-cuenta {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    color: var(--text);
    font: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease, transform 0.15s ease;
}
.landing-cuenta:hover {
    border-color: var(--amber);
    transform: translateY(-2px);
}
.landing-cuenta:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.landing-cuenta-logo {
    display: grid;
    place-items: center;
    flex: none;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    color: #fff;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 15px;
}
.landing-cuenta-texto {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.landing-cuenta-nombre {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 16px;
}
.landing-cuenta-meta {
    color: var(--text-faint);
    font-size: 10.5px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.landing-cuenta-elegida {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin: 0 0 14px;
    color: var(--amber);
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.landing-cambiar {
    background: none;
    border: 0;
    padding: 0;
    color: var(--text-muted);
    font: inherit;
    font-size: 12px;
    text-transform: none;
    letter-spacing: 0;
    text-decoration: underline;
    text-underline-offset: 2px;
    cursor: pointer;
}
.landing-cambiar:hover {
    color: var(--text);
}
</style>
