// Horario de todo el dashboard: CDMX (2026-11-11). La base guarda en UTC;
// todo lo que se MUESTRA pasa por acá, sin importar el horario de la
// computadora de quien lo ve.
export const ZONA_HORARIA = 'America/Mexico_City';

// Las fechas de la base llegan como ISO con zona ("...Z", "+00:00") o como
// "2026-11-04 19:42:00" sin zona -- esas también son UTC; el navegador las
// leería como hora local si no se marca.
export function aFecha(valor) {
    if (!valor) return null;
    if (valor instanceof Date) return valor;
    const texto = String(valor).trim();
    const tieneZona = /[zZ]$|[+-]\d{2}:?\d{2}$/.test(texto);
    const fecha = new Date(tieneZona ? texto : `${texto.replace(' ', 'T')}Z`);
    return Number.isNaN(fecha.getTime()) ? null : fecha;
}

// "11 nov, 11:03 a.m."
export function fechaHora(valor) {
    const fecha = aFecha(valor);
    if (!fecha) return '';
    const dia = fecha.toLocaleDateString('es-MX', { timeZone: ZONA_HORARIA, day: 'numeric', month: 'short' }).replace('.', '');
    const hora = fecha.toLocaleTimeString('es-MX', { timeZone: ZONA_HORARIA, hour: 'numeric', minute: '2-digit', hour12: true });
    return `${dia}, ${hora}`;
}

// "4 nov 2026"
export function fechaCorta(valor) {
    const fecha = aFecha(valor);
    if (!fecha) return '';
    return fecha.toLocaleDateString('es-MX', { timeZone: ZONA_HORARIA, day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '');
}

// Hoy en CDMX como YYYY-MM-DD (a las 7 p.m. de CDMX en UTC ya es mañana).
export function hoyCdmx() {
    return new Date().toLocaleDateString('en-CA', { timeZone: ZONA_HORARIA });
}
