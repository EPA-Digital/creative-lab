<script setup>
import { ref, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';

// Puerto de index.html + renderSelectorPais (motor.js:1833-1880) -- la
// jerarquía topbar/selector/footer y el comportamiento (click en un país
// habilitado navega a su análisis) son literales; el detalle visual del
// anillo animado de selección (reproducirAnimacionSeleccionPais, 32 líneas
// radiales) se omitió a propósito -- el usuario confirmó que el detalle
// visual de los círculos puede resolverse simple en Vue, no es una regla
// estricta como sí lo es la jerarquía de secciones.
const props = defineProps({
    paises: { type: Array, required: true },
});

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
    router.visit(`/pais/${pais.id}/analisis`);
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
        <div class="selector-pais-view">
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
