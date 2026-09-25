<script setup>
// Rediseño 2026-09-25 (pedido explícito, spec detallado con boceto) --
// layout compartido por TODA la familia de páginas de auth sin sesión:
// login, aceptar invitación/reset de contraseña, verificar/enrolar TOTP,
// códigos de recuperación, y las que quedaban con el estilo default de
// Breeze (olvidé mi contraseña, resetear, confirmar contraseña, verificar
// email). Reemplaza a GuestLayout.vue en todas -- ese queda sin uso, se
// deja el archivo por si algún día vuelve a hacer falta el look genérico.

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
