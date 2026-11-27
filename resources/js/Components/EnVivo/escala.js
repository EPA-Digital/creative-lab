// Utilidades de las gráficas de En vivo (2026-11-27): ancho medido, escala
// "bonita" y formatos. Sin librerías: SVG a mano como el resto del dashboard.
import { onBeforeUnmount, onMounted, ref } from 'vue';

export const COLOR_PLATAFORMA = { meta: 'var(--serie-1)', tiktok: 'var(--serie-6)', google: 'var(--serie-3)' };
export const NOMBRE_PLATAFORMA = { meta: 'Meta', tiktok: 'TikTok', google: 'Google' };
export const ORDEN_PLATAFORMAS = ['meta', 'tiktok', 'google'];

/** Ancho real del contenedor (se actualiza al cambiar el tamaño). */
export function useAncho(el, inicial = 600) {
    const ancho = ref(inicial);
    let obs = null;
    onMounted(() => {
        if (!el.value) return;
        ancho.value = el.value.clientWidth || inicial;
        obs = new ResizeObserver(([e]) => (ancho.value = Math.max(240, Math.round(e.contentRect.width))));
        obs.observe(el.value);
    });
    onBeforeUnmount(() => obs?.disconnect());
    return ancho;
}

/** Máximo redondeado hacia arriba y 4 marcas: 0, ¼, ½, ¾, máx. */
export function escala(max) {
    if (!(max > 0)) return { max: 1, marcas: [0, 1] };
    const paso0 = max / 4;
    const mag = 10 ** Math.floor(Math.log10(paso0));
    const paso = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((p) => p >= paso0);
    const tope = paso * Math.ceil(max / paso);
    const marcas = [];
    for (let v = 0; v <= tope + paso / 2; v += paso) marcas.push(+v.toFixed(6));
    return { max: tope, marcas };
}

export const usd = (v) => '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const usdCorto = (v) =>
    v >= 1000 ? '$' + (v / 1000).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + 'k' : '$' + Math.round(v).toLocaleString('es-MX');
export const ent = (v) => Math.round(v).toLocaleString('es-MX');
export const pct = (v) => v.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
/** "2026-11-27" → "27 nov"; "10:00" se queda igual. */
export const etiquetaPunto = (p) => (/^\d{4}-\d{2}-\d{2}$/.test(p) ? `${+p.slice(8)} ${MESES[+p.slice(5, 7) - 1]}` : p);

/** Barra con solo las esquinas de arriba redondeadas (el extremo del dato). */
export function barra(x, y, w, h, r = 4) {
    if (h <= 0 || w <= 0) return '';
    const rr = Math.min(r, w / 2, h);
    return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`;
}
