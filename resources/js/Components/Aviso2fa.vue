<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

// "Aún no tienes la verificación en dos pasos" (pedido explícito
// 2026-10-21) -- para quien ya está logueado sin TOTP (entra con Google, o
// fue invitado antes de que existiera el selector de método). Lleva a
// ActivarDosPasosController. "Más tarde" lo oculta solo por esta sesión
// del navegador -- en la próxima vuelve a aparecer.
const CLAVE = 'tada-aviso-2fa-oculto';

const page = usePage();
const oculto = ref(false);
const visible = computed(() => page.props.auth?.user && !page.props.auth?.tiene_2fa && !oculto.value);

onMounted(() => {
    try {
        oculto.value = sessionStorage.getItem(CLAVE) === '1';
    } catch { /* sessionStorage no disponible */ }
});

function masTarde() {
    oculto.value = true;
    try {
        sessionStorage.setItem(CLAVE, '1');
    } catch { /* sessionStorage no disponible */ }
}
</script>

<template>
    <div v-if="visible" class="aviso-2fa" role="status">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="5" y="11" width="14" height="10" rx="2" />
            <path d="M8 11V7a4 4 0 0 1 8 0v4" />
        </svg>
        <p class="aviso-2fa-texto">
            <strong>Aún no tienes la verificación en dos pasos.</strong>
            Actívala con Google Authenticator para proteger tu cuenta.
        </p>
        <Link :href="route('seguridad.2fa')" class="aviso-2fa-btn">Activar ahora</Link>
        <button type="button" class="aviso-2fa-cerrar" @click="masTarde">Más tarde</button>
    </div>
</template>

<style scoped>
.aviso-2fa {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 16px 32px 0;
    padding: 12px 16px;
    border: 1px solid color-mix(in srgb, var(--amber) 45%, transparent);
    border-radius: 14px;
    background: color-mix(in srgb, var(--amber) 12%, var(--surface));
    color: var(--amber);
}
.aviso-2fa-texto {
    flex: 1;
    margin: 0;
    color: var(--text);
    font-size: 0.85rem;
}
.aviso-2fa-texto strong {
    font-weight: 600;
}
.aviso-2fa-btn {
    padding: 7px 14px;
    border-radius: 8px;
    background: var(--amber);
    color: var(--amber-ink);
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    transition: opacity 0.15s ease;
}
.aviso-2fa-btn:hover {
    opacity: 0.9;
}
.aviso-2fa-cerrar {
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--text-muted);
    font-size: 0.8rem;
    cursor: pointer;
    white-space: nowrap;
    transition: border-color 0.15s ease, color 0.15s ease;
}
.aviso-2fa-cerrar:hover {
    border-color: var(--amber);
    color: var(--text);
}
.aviso-2fa-btn:focus-visible,
.aviso-2fa-cerrar:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
@media (max-width: 640px) {
    .aviso-2fa {
        flex-wrap: wrap;
        margin: 12px 16px 0;
    }
    .aviso-2fa-texto {
        flex-basis: 100%;
    }
}
</style>
