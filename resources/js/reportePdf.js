// Descarga de reportes PDF (2026-11-17): con benchmark del mes desde
// Creativos, y la comparación por grupos desde Inteligencia. Se piden como
// blob para mostrar "Generando…" y avisar si falla. Endpoints solo EPA.
import axios from 'axios';
import { urlPais } from '@/rutas';

async function descargar(url, params, nombrePorDefecto) {
    try {
        const { data, headers } = await axios.get(url, { params, responseType: 'blob' });
        const nombre = /filename="?([^";]+)"?/.exec(headers['content-disposition'] || '')?.[1] || nombrePorDefecto;
        const href = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = href;
        a.download = nombre;
        a.click();
        URL.revokeObjectURL(href);
    } catch (err) {
        let mensaje = 'No se pudo generar el reporte.';
        try {
            mensaje = JSON.parse(await err.response?.data?.text())?.message || mensaje;
        } catch {
            /* la respuesta de error no era JSON */
        }
        throw new Error(mensaje);
    }
}

export function descargarPdfBenchmark(pais, mes, filtros = {}) {
    return descargar(
        `${urlPais(pais)}/importar/mensual/${mes}/exportar`,
        { formato: 'pdf', agrupar: 'arte', benchmark: 1, ...filtros },
        `reporte-creativos-benchmark-${pais}-${mes}.pdf`,
    );
}

export function descargarPdfComparacion(pais, params) {
    return descargar(`${urlPais(pais)}/inteligencia/grupos/pdf`, params, `comparacion-creativos-${pais}.pdf`);
}
