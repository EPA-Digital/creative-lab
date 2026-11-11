<script setup>
// Ícono (i) con la explicación en un globo al pasar el cursor o al
// enfocarlo con teclado -- para notas que no necesitan estar siempre a la vista.
defineProps({
    // Texto accesible del botón (lectores de pantalla).
    etiqueta: { type: String, default: 'Más información' },
    // Hacia dónde se abre el globo: 'derecha' (default) o 'izquierda'.
    lado: { type: String, default: 'derecha' },
});
</script>

<template>
    <span class="info-tip" :class="`info-tip--${lado}`">
        <button type="button" class="info-tip-btn" :aria-label="etiqueta">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 11v5" />
                <path d="M12 8h.01" />
            </svg>
        </button>
        <span class="info-tip-globo" role="tooltip"><slot /></span>
    </span>
</template>

<style scoped>
.info-tip {
    position: relative;
    display: inline-flex;
    align-items: center;
    vertical-align: middle;
}
.info-tip-btn {
    display: inline-flex;
    padding: 2px;
    background: none;
    border: none;
    border-radius: 999px;
    color: var(--text-faint);
    cursor: help;
    transition: color 0.15s ease;
}
.info-tip-btn:hover,
.info-tip-btn:focus-visible {
    color: var(--accent-text);
}
.info-tip-btn:focus-visible {
    outline: 2px solid var(--amber);
    outline-offset: 2px;
}
.info-tip-globo {
    position: absolute;
    top: calc(100% + 8px);
    left: -8px;
    z-index: 30;
    width: max-content;
    max-width: 320px;
    padding: 10px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--shadow);
    color: var(--text-muted);
    font-family: 'Inter', sans-serif;
    font-size: 0.75rem;
    font-weight: 400;
    line-height: 1.45;
    letter-spacing: normal;
    text-transform: none;
    text-align: left;
    white-space: normal;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-4px);
    transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s;
    pointer-events: none;
}
.info-tip--izquierda .info-tip-globo {
    left: auto;
    right: -8px;
}
.info-tip:hover .info-tip-globo,
.info-tip:focus-within .info-tip-globo {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}
</style>
