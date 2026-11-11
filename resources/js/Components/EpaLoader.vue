<script setup>
// Loader EPA "Ensamble" (diseño 2026-11-11, hecho en Claude Design): las 3
// barras del logo llegan volando con rebote, el logo gira 120° y las barras
// salen. Solo SVG + CSS. El color sale de --epa-logo (app.css), que cambia
// con el tema: azul claro sobre fondo oscuro, azul EPA oficial sobre claro.
defineProps({
    // Lado del logo en px.
    tamano: { type: Number, default: 150 },
});

// Las tres barras son la misma pieza girada 0°, 120° y 240°.
const GIROS = [0, 120, 240];
</script>

<template>
    <svg class="epa-loader" :width="tamano" :height="tamano" viewBox="-110 -110 220 220" aria-hidden="true">
        <g class="epa-giro">
            <g v-for="(giro, i) in GIROS" :key="giro" :transform="giro ? `rotate(${giro})` : null">
                <g class="epa-pieza" :style="{ animationDelay: `calc(var(--epa-loader-ciclo) * ${i * 0.04})` }">
                    <polygon points="-56,-46 -39,-36.5 -39,96 -56,86" />
                    <polygon points="-35,-34.5 -18,-25 -18,86 -35,96" />
                </g>
            </g>
        </g>
    </svg>
</template>

<style scoped>
.epa-loader {
    --epa-loader-ciclo: 3s;
    overflow: visible;
    fill: var(--epa-logo, #2f7bff);
}
.epa-giro {
    transform-origin: 0 0;
    animation: epa-giro var(--epa-loader-ciclo) cubic-bezier(0.7, 0, 0.3, 1) infinite;
}
.epa-pieza {
    animation: epa-vuelo var(--epa-loader-ciclo) cubic-bezier(0.2, 1.3, 0.4, 1) infinite;
}
@keyframes epa-vuelo {
    0% { transform: translate(-140px, 220px) rotate(-60deg); opacity: 0; }
    30%, 72% { transform: none; opacity: 1; }
    90%, 100% { transform: translate(140px, -220px) rotate(60deg); opacity: 0; }
}
@keyframes epa-giro {
    0%, 35% { transform: rotate(0deg); }
    68%, 100% { transform: rotate(120deg); }
}
@media (prefers-reduced-motion: reduce) {
    .epa-giro,
    .epa-pieza {
        animation: none;
    }
}
</style>
