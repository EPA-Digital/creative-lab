// Compartido por Cuentas/Index.vue y Cuentas/Form.vue (diseño 2026-11-03).

// Sigla de cada medio para los círculos de la lista y las conexiones.
export const MEDIO_CODIGO = {
    meta: 'ME',
    tiktok: 'TT',
    google_ads: 'GA',
    youtube: 'YT',
    dv360: 'DV',
    amazon_ads: 'AZ',
    mercado_libre: 'ML',
    walmart_connect: 'WC',
    spotify: 'SP',
    pinterest: 'PI',
    apple_search_ads: 'AS',
};

// "Métricas que verás" según los objetivos de la cuenta.
export const METRICAS_POR_OBJETIVO = {
    'Awareness': ['Alcance', 'Impresiones', 'CPM', 'Frecuencia'],
    'Consideración': ['Reproducciones de video', 'ThruPlay', 'CPV', 'Interacciones'],
    'Tráfico a sitio': ['Clics', 'CTR', 'CPC', 'Visitas a landing'],
    'Ventas': ['Conversiones', 'CPA', 'ROAS', 'Ingresos'],
    'Instalaciones': ['Installs', 'CPI', 'Tasa de instalación'],
    'Leads': ['Leads', 'CPL', 'Tasa de conversión'],
    'Recompra': ['Compras recurrentes', 'Tasa de recompra', 'CPA de recompra'],
    'Visitas a tienda': ['Visitas a tienda', 'Costo por visita'],
};

export const COLORES_CUENTA = ['#9184d9', '#8b6bff', '#4fb6ff', '#59e0bd', '#d8a94a', '#ff8f84', '#e58fb6', '#9397ab'];

// Iniciales del logo de respaldo: "Coppel Branding" -> "CB", "TaDa" -> "TA".
export function iniciales(nombre) {
    const palabras = (nombre || '').trim().split(/\s+/).filter(Boolean);
    if (palabras.length > 1) return (palabras[0][0] + palabras[1][0]).toUpperCase();
    return (palabras[0] || '+').slice(0, 2).toUpperCase();
}
