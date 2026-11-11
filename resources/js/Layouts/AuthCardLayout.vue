<script setup>
// Rediseño 2026-09-25 (pedido explícito, spec detallado con boceto) --
// layout compartido por TODA la familia de páginas de auth sin sesión:
// login, aceptar invitación/reset de contraseña, verificar/enrolar TOTP,
// códigos de recuperación, y las que quedaban con el estilo default de
// Breeze (olvidé mi contraseña, resetear, confirmar contraseña, verificar
// email). Reemplaza a GuestLayout.vue en todas -- ese queda sin uso, se
// deja el archivo por si algún día vuelve a hacer falta el look genérico.
import EpaLoader from '@/Components/EpaLoader.vue';

// Spotlight que sigue al cursor por TODA la página (pedido explícito
// 2026-09-25 -- "no solo en ese cuadrito, sino en toda esa página").
// Coordenadas de viewport directas (clientX/Y), sin rect -- el overlay es
// position:fixed, cubre la ventana entera sin importar el scroll.
function moverBrillo(evento) {
    document.documentElement.style.setProperty('--brillo-x', `${evento.clientX}px`);
    document.documentElement.style.setProperty('--brillo-y', `${evento.clientY}px`);
}
</script>

<template>
    <div class="auth-page" @mousemove="moverBrillo">
        <div class="auth-page-brillo" aria-hidden="true" />

        <!-- Paleta EPA (2026-11-11): triángulo de EPA en líneas finas, fijo, detrás de la tarjeta. -->
        <svg aria-hidden="true" viewBox="-110 -110 220 220" class="login-bg-logo">
            <defs>
                <pattern id="hatchLogin" patternUnits="userSpaceOnUse" width="2.4" height="2.4" patternTransform="rotate(30)">
                    <rect width="2.4" height=".55" fill="#003AD6" />
                </pattern>
                <g id="barLogin">
                    <polygon points="-56,-46 -39,-36.5 -39,96 -56,86" />
                    <polygon points="-35,-34.5 -18,-25 -18,86 -35,96" />
                </g>
            </defs>
            <g fill="url(#hatchLogin)">
                <use href="#barLogin" />
                <use href="#barLogin" transform="rotate(120)" />
                <use href="#barLogin" transform="rotate(240)" />
            </g>
        </svg>

        <div class="auth-loader" aria-hidden="true"><EpaLoader :tamano="76" /></div>

        <div class="auth-logos">
            <img src="/images/tada-logo-purple.png" alt="TaDa" class="auth-logo-tada" />
            <span class="auth-logo-sep" aria-hidden="true" />
            <img src="/images/epa-logo-crop.png" alt="EPA" class="auth-logo-epa" />
        </div>

        <div class="auth-card">
            <slot />
        </div>

        <p class="auth-footer">
            Acceso restringido al equipo TaDa ·
            <img src="/images/epa-logo-crop.png" alt="EPA" class="auth-footer-epa" />
        </p>
    </div>
</template>
