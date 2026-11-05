<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import { MEDIO_CODIGO, METRICAS_POR_OBJETIVO, COLORES_CUENTA } from '@/cuentas';
import LogoCuenta from '@/Components/Cuenta/LogoCuenta.vue';
import '../../../css/cuentas.css';

// Crear / editar cuenta (diseño "Cuentas — Dashboard de creativos",
// 2026-11-03): 6 secciones en una sola página. Las conexiones nuevas
// (cuentas publicitarias, apps de AppsFlyer) se mandan junto con el resto;
// el backend valida cada cuenta publicitaria contra la API y contra TODAS
// las cuentas del dashboard antes de guardar (ver CuentasController).
const props = defineProps({
    pais: { type: String, required: true },
    cuenta: { type: Object, default: null },
    catalogo: { type: Object, required: true },
    puedeDesactivar: { type: Boolean, default: false },
});

const nueva = !props.cuenta;
const c = props.cuenta;

const form = reactive({
    nombre: c?.nombre ?? '',
    cliente: c?.cliente ?? '',
    color: c?.color ?? COLORES_CUENTA[2],
    servicios: [...(c?.servicios ?? [])],
    objetivos: [...(c?.objetivos ?? [])],
    medios: [...(c?.medios ?? [])],
    fuente_ga4: c?.fuente_ga4 ?? false,
    fuente_mmp: c?.fuente_mmp ?? false,
    venta_real: c?.venta_real ?? 'no',
    multi_pais: c?.multi_pais ?? false,
    paises: [...(c?.paises ?? ['MX'])],
    appsflyer_evento_principal: c?.credenciales?.appsflyer?.evento_principal ?? '',
    // De dónde sale AppsFlyer: API con token o BigQuery (Data Locker de EPA).
    appsflyer_origen: c?.credenciales?.appsflyer?.origen ?? 'api',
    appsflyer_bq_proyecto: c?.credenciales?.appsflyer?.bq_proyecto ?? 'bdd-epa-digital',
    appsflyer_bq_dataset: c?.credenciales?.appsflyer?.bq_dataset ?? '',
    appsflyer_bq_tablas: { installs: 'installs', installs_rt: 'installsretargeting', eventos: 'inapps', eventos_rt: 'inappsretargeting', ...(c?.credenciales?.appsflyer?.bq_tablas ?? {}) },
    appsflyer_zona_horaria: c?.credenciales?.appsflyer?.zona_horaria ?? 'America/Mexico_City',
    appsflyer_evento_secundario: c?.credenciales?.appsflyer?.evento_secundario ?? '',
});
// El token nunca llega del servidor: solo si hay uno y sus últimos 4.
// Vacío = no se cambia el guardado.
const tokenAppsFlyer = ref('');
// "Probar conexión" de BigQuery: revisa tablas y columnas, sin traer datos.
const pruebaBq = ref(null);
const probandoBq = ref(false);
const errorBq = ref('');
const ETIQUETA_TABLA = { installs: 'Instalaciones', installs_rt: 'Instalaciones retargeting', eventos: 'Eventos', eventos_rt: 'Eventos retargeting' };
async function probarBigQuery() {
    probandoBq.value = true;
    errorBq.value = '';
    pruebaBq.value = null;
    try {
        const { data } = await axios.post('/cuentas/fuentes/bigquery/probar', {
            bq_proyecto: form.appsflyer_bq_proyecto, bq_dataset: form.appsflyer_bq_dataset, bq_tablas: form.appsflyer_bq_tablas,
        });
        pruebaBq.value = data;
    } catch (e) {
        errorBq.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat()[0] || 'No se pudo probar la conexión.';
    } finally {
        probandoBq.value = false;
    }
}
const quitarTokenAppsFlyer = ref(false);
const credAppsFlyer = computed(() => props.cuenta?.credenciales?.appsflyer ?? { tiene_token: false });
const tendraTokenAppsFlyer = computed(() => !!tokenAppsFlyer.value.trim() || (credAppsFlyer.value.tiene_token && !quitarTokenAppsFlyer.value));
function fechaCorta(iso) {
    return iso ? new Date(iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
}
const activa = ref(c?.activa ?? true);
const conexionesActivas = reactive(Object.fromEntries((c?.conexiones ?? []).map((x) => [x.id, x.activa])));
// Tras guardar, la página recarga `cuenta`: las conexiones recién creadas
// entran con su estado.
watch(() => props.cuenta?.conexiones, (lista) => {
    for (const x of lista ?? []) {
        if (!(x.id in conexionesActivas)) conexionesActivas[x.id] = x.activa;
    }
});
const borradores = ref([]);
const appsBorrador = ref([]);
let siguienteClave = 1;

const mostrarErrores = ref(false);
const erroresServidor = ref({});
const guardando = ref(false);
const guardado = ref('');

const tieneApp = computed(() => form.servicios.includes('App'));
const medios = computed(() => Object.fromEntries(props.catalogo.medios.map((m) => [m.clave, m])));
const paisesPorCodigo = computed(() => Object.fromEntries(props.catalogo.paises.map((p) => [p.codigo, p])));
const paisesDeLaCuenta = computed(() => (form.multi_pais ? props.catalogo.paises.map((p) => p.codigo).filter((cod) => form.paises.includes(cod)) : ['MX']));

function alternar(lista, valor) {
    const i = lista.indexOf(valor);
    i > -1 ? lista.splice(i, 1) : lista.push(valor);
    guardado.value = '';
}

function alternarServicio(s) {
    alternar(form.servicios, s);
    if (s === 'App' && !form.servicios.includes('App')) {
        form.objetivos = form.objetivos.filter((o) => o !== 'Instalaciones');
        form.fuente_mmp = false;
    }
}

function alternarMulti() {
    form.multi_pais = !form.multi_pais;
    if (!form.multi_pais) form.paises = ['MX'];
    guardado.value = '';
}

// --- Cliente (combo con "crear nuevo") ------------------------------------
const comboAbierto = ref(false);
const comboTexto = ref('');
const opcionesCliente = computed(() => {
    const q = comboTexto.value.trim().toLowerCase();
    return props.catalogo.clientes.filter((o) => !q || o.nombre.toLowerCase().includes(q));
});
const puedeCrearCliente = computed(() => {
    const q = comboTexto.value.trim().toLowerCase();
    return !!q && !props.catalogo.clientes.some((o) => o.nombre.toLowerCase() === q);
});
function abrirCombo() {
    comboAbierto.value = true;
    comboTexto.value = '';
}
function elegirCliente(nombre) {
    form.cliente = nombre;
    comboAbierto.value = false;
    guardado.value = '';
}
function cerrarCombo(e) {
    if (!e.target.closest('.cu-combo')) comboAbierto.value = false;
}
onMounted(() => document.addEventListener('click', cerrarCombo));
onBeforeUnmount(() => document.removeEventListener('click', cerrarCombo));

// --- Métricas que verás -----------------------------------------------------
const metricas = computed(() => props.catalogo.objetivos
    .filter((o) => form.objetivos.includes(o))
    .map((o) => ({
        objetivo: o,
        pills: [
            ...METRICAS_POR_OBJETIVO[o].map((n) => ({ n, real: false })),
            ...(o === 'Ventas' && form.venta_real !== 'no' ? [{ n: 'Venta real', real: true }, { n: 'ROAS real', real: true }] : []),
        ],
    })));
const totalMetricas = computed(() => metricas.value.reduce((t, m) => t + m.pills.length, 0));
const mediosProximamente = computed(() => form.medios.filter((m) => !medios.value[m]?.conectado).length);

const OPCIONES_VENTA = [
    { clave: 'no', titulo: 'No', desc: 'Verás solo lo que reporta cada plataforma. No se calcula ROAS real.' },
    { clave: 'manual', titulo: 'Sí, manual', desc: 'Cada mes capturas los totales reales de venta y se reparten entre los anuncios según su peso en las conversiones.' },
    { clave: 'auto', titulo: 'Sí, automática', desc: 'La venta real llega sola desde tu fuente de datos y se asigna a cada anuncio, sin captura.' },
];

// --- Conexiones ---------------------------------------------------------------
function agregarBorrador(paisCodigo) {
    borradores.value.push({ k: siguienteClave++, pais: paisCodigo, plataforma: 'meta', id_externo: '', nombre: '' });
}
function agregarApp(paisCodigo) {
    appsBorrador.value.push({ k: siguienteClave++, pais: paisCodigo, plataforma: 'ios', app_id: '', nombre: '' });
}
function quitar(lista, k) {
    lista.value = lista.value.filter((x) => x.k !== k);
}

const bloques = computed(() => paisesDeLaCuenta.value.map((cod) => {
    const ads = (props.cuenta?.conexiones ?? []).filter((x) => x.pais === cod);
    const apps = (props.cuenta?.apps ?? []).filter((x) => x.pais === cod);
    const borr = borradores.value.filter((x) => x.pais === cod);
    const appsBorr = appsBorrador.value.filter((x) => x.pais === cod);
    const n = ads.length + borr.length;
    const mostrarApps = form.fuente_mmp && tieneApp.value;
    const nApps = apps.length + appsBorr.length;
    return {
        codigo: cod,
        nombre: paisesPorCodigo.value[cod]?.nombre ?? cod,
        bandera: paisesPorCodigo.value[cod]?.bandera,
        ads, apps, borr, appsBorr, mostrarApps,
        resumen: (n === 1 ? '1 CUENTA' : `${n} CUENTAS`) + (mostrarApps ? ` · ${nApps === 1 ? '1 APP' : `${nApps} APPS`}` : ''),
    };
}));

// --- Validación -----------------------------------------------------------------
function errorBorrador(b) {
    const v = (b.id_externo || '').trim();
    if (!v) return 'Escribe el ID de la cuenta publicitaria.';
    return /^(act_)?\d+$/i.test(v) ? '' : 'Usa solo números, sin espacios ni guiones (en Meta puede llevar "act_" adelante).';
}

const errores = computed(() => {
    const e = {};
    if (!form.nombre.trim()) e.nombre = 'Escribe el nombre de la cuenta.';
    if (!form.cliente.trim()) e.cliente = 'Elige un cliente o crea uno nuevo.';
    if (!/^#[0-9a-fA-F]{6}$/.test(form.color)) e.color = 'Usa un color hex de 6 dígitos, como #9184d9.';
    if (!form.objetivos.length) e.objetivos = 'Elige al menos un objetivo para saber qué métricas mostrarte.';
    if (!form.medios.length) e.medios = 'Elige al menos un medio.';
    if (form.multi_pais && form.paises.length < 2) e.paises = 'Elige al menos dos países, o apaga la opción.';
    // Solo los borradores que siguen en un país de la cuenta se mandan.
    borradoresVigentes.value.forEach((b, i) => {
        const err = errorBorrador(b);
        if (err) e[`conexiones_nuevas.${i}.id_externo`] = err;
    });
    appsVigentes.value.forEach((a, i) => {
        if (!a.app_id.trim()) e[`apps_nuevas.${i}.app_id`] = 'Escribe el ID de la app.';
    });
    if (form.fuente_mmp && tieneApp.value && tendraTokenAppsFlyer.value && !form.appsflyer_evento_principal.trim()) {
        e.appsflyer_evento_principal = 'Escribe el nombre exacto del evento principal, como aparece en AppsFlyer.';
    }
    return e;
});

const borradoresVigentes = computed(() => borradores.value.filter((b) => paisesDeLaCuenta.value.includes(b.pais)));
const appsVigentes = computed(() => (form.fuente_mmp && tieneApp.value ? appsBorrador.value.filter((a) => paisesDeLaCuenta.value.includes(a.pais)) : []));

// Errores visibles: los del cliente tras el primer intento, más los del servidor.
const visibles = computed(() => ({ ...(mostrarErrores.value ? errores.value : {}), ...erroresServidor.value }));
function err(campo) {
    return visibles.value[campo] || '';
}
function errBorrador(b) {
    const i = borradoresVigentes.value.indexOf(b);
    return i > -1 ? err(`conexiones_nuevas.${i}.id_externo`) : '';
}
function errApp(a) {
    const i = appsVigentes.value.indexOf(a);
    return i > -1 ? err(`apps_nuevas.${i}.app_id`) : '';
}
const totalErrores = computed(() => Object.keys(visibles.value).length);

const secciones = computed(() => [
    { id: 'identidad', n: '01', label: 'Identidad', err: !!(err('nombre') || err('cliente') || err('color')) },
    { id: 'negocio', n: '02', label: 'Negocio', err: !!err('objetivos') },
    { id: 'medios', n: '03', label: 'Medios', err: !!err('medios') },
    { id: 'medicion', n: '04', label: 'Medición', err: !!(err('fuente_mmp') || err('venta_real') || err('appsflyer_token') || err('appsflyer_evento_principal')) },
    { id: 'paises', n: '05', label: 'Países', err: !!err('paises') },
    { id: 'conexiones', n: '06', label: 'Conexiones', err: Object.keys(visibles.value).some((k) => k.startsWith('conexiones_nuevas') || k.startsWith('apps_nuevas')) },
    { id: 'nomenclatura', n: '07', label: 'Clasificación', err: !!err('nomenclatura') || Object.keys(visibles.value).some((k) => k.startsWith('nomenclatura_ejemplos')) },
]);

// --- Logo propio -----------------------------------------------------------------
// Se elige aquí y se sube al guardar (en una cuenta nueva no hay slug antes).
// Vista previa local con un object URL mientras tanto.
const logoArchivo = ref(null);
const logoPrevia = ref(null);
const quitarLogo = ref(false);
const logoError = ref('');
const LOGO_MAX_BYTES = 1024 * 1024;
const logoParaMostrar = computed(() => ({
    nombre: form.nombre,
    color: /^#[0-9a-fA-F]{6}$/.test(form.color) ? form.color : 'var(--text-muted)',
    logo: logoPrevia.value ?? (quitarLogo.value ? null : props.cuenta?.logo ?? null),
}));
function elegirLogo(e) {
    const archivo = e.target.files?.[0];
    e.target.value = '';
    logoError.value = '';
    if (!archivo) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(archivo.type)) {
        logoError.value = 'El logo debe ser PNG, JPG o WebP.';
        return;
    }
    if (archivo.size > LOGO_MAX_BYTES) {
        logoError.value = 'El logo pesa más de 1 MB. Prueba con una versión más ligera.';
        return;
    }
    if (logoPrevia.value) URL.revokeObjectURL(logoPrevia.value);
    logoArchivo.value = archivo;
    logoPrevia.value = URL.createObjectURL(archivo);
    quitarLogo.value = false;
    guardado.value = '';
}
function descartarLogo() {
    if (logoPrevia.value) URL.revokeObjectURL(logoPrevia.value);
    logoArchivo.value = null;
    logoPrevia.value = null;
    quitarLogo.value = !!props.cuenta?.logo;
    guardado.value = '';
}
// Después de guardar la cuenta: sube o quita el logo. Un error aquí no
// deshace la cuenta; se avisa junto al campo.
async function sincronizarLogo(slug) {
    if (logoArchivo.value) {
        const datos = new FormData();
        datos.append('logo', logoArchivo.value);
        await axios.post(`/cuentas/${slug}/logo`, datos);
    } else if (quitarLogo.value) {
        await axios.delete(`/cuentas/${slug}/logo`);
    }
    if (logoPrevia.value) URL.revokeObjectURL(logoPrevia.value);
    logoArchivo.value = null;
    logoPrevia.value = null;
    quitarLogo.value = false;
}

// --- Guardar --------------------------------------------------------------------
async function guardar() {
    mostrarErrores.value = true;
    erroresServidor.value = {};
    guardado.value = '';
    if (Object.keys(errores.value).length) return;

    guardando.value = true;
    const payload = {
        ...form,
        paises: form.multi_pais ? form.paises : ['MX'],
        conexiones_nuevas: borradoresVigentes.value.map(({ pais, plataforma, id_externo, nombre }) => ({ pais, plataforma, id_externo: id_externo.trim(), nombre })),
        apps_nuevas: appsVigentes.value.map(({ pais, plataforma, app_id, nombre }) => ({ pais, plataforma, app_id: app_id.trim(), nombre })),
        conexiones_activas: { ...conexionesActivas },
        appsflyer_token: tokenAppsFlyer.value.trim() || null,
        appsflyer_quitar_token: quitarTokenAppsFlyer.value,
        ...(c?.slug === 'tada' ? {} : {
            nomenclatura: deEditable(reglas.value),
            nomenclatura_ejemplos: hayCuestionario.value ? cuestionarioPayload() : null,
        }),
    };
    try {
        const { data } = nueva
            ? await axios.post('/cuentas', payload)
            : await axios.put(`/cuentas/${c.slug}`, payload);
        try {
            await sincronizarLogo(data.slug);
        } catch (e) {
            logoError.value = e.response?.data?.errors?.logo?.[0] || 'La cuenta se guardó, pero no se pudo subir el logo.';
            if (nueva) {
                router.visit(`/cuentas/${data.slug}/editar`);
                return;
            }
        }
        if (nueva) {
            router.visit('/cuentas');
            return;
        }
        borradores.value = [];
        appsBorrador.value = [];
        tokenAppsFlyer.value = '';
        quitarTokenAppsFlyer.value = false;
        mostrarErrores.value = false;
        guardado.value = 'Guardado.';
        router.reload({ only: ['cuenta'] });
        if (data.slug !== c.slug) router.visit(`/cuentas/${data.slug}/editar`);
    } catch (e) {
        const errs = e.response?.data?.errors;
        erroresServidor.value = errs
            ? Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
            : { general: e.response?.data?.message || 'No se pudo guardar la cuenta.' };
    } finally {
        guardando.value = false;
    }
}

// --- Clasificación (nomenclatura) ---------------------------------------------
// Cada equipo enseña su vocabulario: siglas de sus tipos de campaña
// (performance / branding), sus etapas de funnel con ejemplos y a qué
// equivalen, y nombres reales por nivel. Con eso la IA propone reglas
// (siglas o regex, como REGEXMATCH), se prueban contra los ejemplos y se
// guardan con la cuenta. Sin reglas, la cuenta se lee como TaDa.
const EJES = [
    { clave: 'performance', label: 'Performance' },
    { clave: 'branding', label: 'Branding' },
    { clave: 'otro', label: 'Otro' },
];
const EQUIVALENTES = [
    { clave: 'AWA', label: 'Awareness' },
    { clave: 'CONS', label: 'Consideración' },
    { clave: 'CNV', label: 'Conversión' },
    { clave: 'LOY', label: 'Loyalty' },
];
// Ejemplo de siglas en cada fila, según lo que es (no el mismo en todas).
const SIGLAS_EJEMPLO_EJE = { performance: 'siglas: lead, dtc', branding: 'siglas: brn, brand', otro: 'siglas separadas por coma' };
const SIGLAS_EJEMPLO_ETAPA = { AWA: 'siglas: see, awa', CONS: 'siglas: think, cons', CNV: 'siglas: do, cnv', LOY: 'siglas: care, loy' };
const lineas = (texto) => (texto || '').split('\n').map((l) => l.trim()).filter(Boolean);
const texto = (lista) => (lista ?? []).join('\n');

// Cuestionario (se guarda tal cual en nomenclatura_ejemplos).
const guardadoCuestionario = c?.nomenclatura_ejemplos ?? null;
const cuestionario = reactive({
    tipos: (guardadoCuestionario?.tipos ?? [
        { nombre: 'Performance', eje: 'performance', siglas: c?.slug === 'tada' ? 'dtc' : '' },
        { nombre: 'Branding', eje: 'branding', siglas: '' },
    ]).map((t) => ({ nombre: t.nombre, eje: t.eje, siglas: t.siglas ?? '', ejemplos: texto(t.ejemplos) })),
    etapas: (guardadoCuestionario?.etapas ?? EQUIVALENTES.map((e) => ({ nombre: e.label, equivalente: e.clave })))
        .map((e) => ({ nombre: e.nombre, equivalente: e.equivalente, siglas: e.siglas ?? '', ejemplos: texto(e.ejemplos) })),
    campanias: texto(guardadoCuestionario?.campanias),
    conjuntos: texto(guardadoCuestionario?.conjuntos),
    anuncios: texto(guardadoCuestionario?.anuncios),
    notas: guardadoCuestionario?.notas ?? '',
});
function cuestionarioPayload() {
    const conNombre = (x) => x.nombre.trim();
    return {
        tipos: cuestionario.tipos.filter(conNombre).map((t) => ({ nombre: t.nombre.trim(), eje: t.eje, siglas: t.siglas || null, ejemplos: lineas(t.ejemplos) })),
        etapas: cuestionario.etapas.filter(conNombre).map((e) => ({ nombre: e.nombre.trim(), equivalente: e.equivalente, siglas: e.siglas || null, ejemplos: lineas(e.ejemplos) })),
        campanias: lineas(cuestionario.campanias),
        conjuntos: lineas(cuestionario.conjuntos),
        anuncios: lineas(cuestionario.anuncios),
        notas: cuestionario.notas || null,
    };
}
const totalEjemplos = computed(() => {
    const q = cuestionarioPayload();
    return q.campanias.length + q.conjuntos.length + q.anuncios.length
        + [...q.tipos, ...q.etapas].reduce((t, x) => t + x.ejemplos.length, 0);
});
const hayCuestionario = computed(() => totalEjemplos.value > 0 || cuestionario.tipos.some((t) => t.siglas) || cuestionario.etapas.some((e) => e.siglas));

// Reglas v2 en forma editable: siglas como texto "a, b".
const siglasTexto = (r) => (Array.isArray(r?.siglas) ? r.siglas.join(', ') : (r?.siglas ?? ''));
const regla = (r) => ({ siglas: siglasTexto(r), patron: r?.patron ?? '', valor: r?.valor ?? '' });
function aEditable(r) {
    if (!r || (r.version ?? 1) < 2) return null;
    return {
        identificador: regla(r.identificador),
        tipos: (r.tipos_campania ?? []).map((t) => ({ nombre: t.nombre, eje: t.eje, ...regla(t) })),
        etapas: (r.funnel ?? []).map((e) => ({ nombre: e.nombre, equivalente: e.equivalente, ...regla(e) })),
        plataforma: (r.campos?.plataforma ?? []).map(regla),
        formato: (r.campos?.formato ?? []).map(regla),
        dimensiones: (r.dimensiones ?? []).map((d) => ({ nombre: d.nombre, reglas: (d.reglas ?? []).map(regla) })),
        arte: r.arte?.regex ?? '',
        // Catálogo de creativos (patrón → creativo, o post de TikTok → creativo).
        catalogo: (r.arte?.catalogo ?? []).map((c) => ({ post_id: c.post_id ?? '', siglas: siglasTexto(c), patron: c.patron ?? '', valor: c.valor ?? '' })),
        usarObjetivo: r.usar_objetivo_plataforma ?? true,
    };
}
function deEditable(e) {
    if (!e) return null;
    const condicion = (x) => ({ siglas: x.siglas, patron: x.patron.trim() || null });
    const conValor = (lista) => lista.filter((x) => x.valor.trim()).map((x) => ({ ...condicion(x), valor: x.valor.trim() }));
    return {
        version: 2,
        identificador: e.identificador.siglas.trim() || e.identificador.patron.trim() ? condicion(e.identificador) : null,
        tipos_campania: e.tipos.filter((t) => t.nombre.trim()).map((t) => ({ nombre: t.nombre.trim(), eje: t.eje, ...condicion(t) })),
        funnel: e.etapas.filter((x) => x.nombre.trim()).map((x) => ({ nombre: x.nombre.trim(), equivalente: x.equivalente, ...condicion(x) })),
        campos: { plataforma: conValor(e.plataforma), formato: conValor(e.formato) },
        dimensiones: e.dimensiones.filter((d) => d.nombre.trim()).map((d) => ({ nombre: d.nombre.trim(), reglas: conValor(d.reglas) })),
        arte: (e.arte.trim() || e.catalogo.some((c) => c.valor.trim()))
            ? {
                regex: e.arte.trim() || null,
                catalogo: e.catalogo.filter((c) => c.valor.trim() && (c.post_id || c.patron.trim() || c.siglas.trim()))
                    .map((c) => (c.post_id ? { post_id: c.post_id, valor: c.valor.trim() } : { siglas: c.siglas, patron: c.patron.trim() || null, valor: c.valor.trim() })),
            }
            : null,
        usar_objetivo_plataforma: e.usarObjetivo,
    };
}
const reglas = ref(aEditable(c?.nomenclatura));
const vistaPrevia = ref([]);
const resumen = ref({ tipos: [], etapas: [] });
const nomenclaturaEstado = ref('');
const nomenclaturaError = ref('');
const errorDe = (e, porDefecto) => Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || porDefecto;

function aplicarRespuesta(data) {
    reglas.value = aEditable(data.reglas);
    vistaPrevia.value = data.vista_previa;
    resumen.value = data.resumen;
    guardado.value = '';
}
async function proponerNomenclatura() {
    nomenclaturaError.value = '';
    nomenclaturaEstado.value = 'proponiendo';
    try {
        const { data } = await axios.post('/cuentas/nomenclatura/proponer', {
            nombre: form.nombre,
            ...cuestionarioPayload(),
            // El catálogo pegado se conserva: la IA no lo toca.
            catalogo: deEditable(reglas.value)?.arte?.catalogo ?? [],
        });
        aplicarRespuesta(data);
    } catch (e) {
        nomenclaturaError.value = errorDe(e, 'No se pudieron proponer reglas.');
    } finally {
        nomenclaturaEstado.value = '';
    }
}
// Sin reglas todavía: las arma solo con las siglas del cuestionario.
async function probarNomenclatura() {
    nomenclaturaError.value = '';
    nomenclaturaEstado.value = 'probando';
    try {
        const { data } = await axios.post('/cuentas/nomenclatura/probar', { reglas: deEditable(reglas.value), ...cuestionarioPayload() });
        aplicarRespuesta(data);
    } catch (e) {
        nomenclaturaError.value = errorDe(e, 'No se pudieron probar las reglas.');
    } finally {
        nomenclaturaEstado.value = '';
    }
}
function quitarNomenclatura() {
    reglas.value = null;
    vistaPrevia.value = [];
    resumen.value = { tipos: [], etapas: [] };
    guardado.value = '';
}

// Fórmula de Sheets (=IFS(REGEXMATCH(...))) -> reglas del campo elegido.
const formula = reactive({ abierta: false, texto: '', destino: 'etapas', error: '' });
const destinosFormula = computed(() => [
    { clave: 'etapas', label: 'Etapa del funnel' },
    { clave: 'tipos', label: 'Tipo de campaña' },
    { clave: 'plataforma', label: 'Plataforma' },
    { clave: 'formato', label: 'Formato' },
    { clave: 'catalogo', label: 'Catálogo de creativos' },
    ...(reglas.value?.dimensiones ?? []).map((d, i) => ({ clave: `dim-${i}`, label: d.nombre || `Dimensión ${i + 1}` })),
]);
async function convertirFormula() {
    formula.error = '';
    try {
        const { data } = await axios.post('/cuentas/nomenclatura/desde-formula', { formula: formula.texto });
        reglas.value ??= aEditable({ version: 2 });
        const destino = formula.destino;
        if (destino === 'etapas' || destino === 'tipos') {
            // Mismo nombre -> se le pone el patrón; nombre nuevo -> fila nueva.
            const lista = reglas.value[destino];
            for (const { patron, valor } of data.reglas) {
                const fila = lista.find((x) => x.nombre.toLowerCase() === valor.toLowerCase());
                if (fila) fila.patron = fila.patron ? `${fila.patron}|${patron}` : patron;
                else lista.push(destino === 'etapas'
                    ? { nombre: valor, equivalente: '', siglas: '', patron, valor: '' }
                    : { nombre: valor, eje: 'otro', siglas: '', patron, valor: '' });
            }
        } else {
            const lista = destino.startsWith('dim-') ? reglas.value.dimensiones[Number(destino.slice(4))].reglas : reglas.value[destino];
            if (destino === 'catalogo') {
                lista.push(...data.reglas.map((r) => ({ post_id: '', siglas: '', patron: r.patron, valor: r.valor })));
                formula.texto = '';
                formula.abierta = false;
                guardado.value = '';
                return;
            }
            lista.push(...data.reglas.map((r) => ({ siglas: '', patron: r.patron, valor: r.valor })));
        }
        formula.texto = '';
        formula.abierta = false;
        guardado.value = '';
    } catch (e) {
        formula.error = errorDe(e, 'No se pudo convertir la fórmula.');
    }
}

// --- Catálogo de creativos ----------------------------------------------------
// Se pega tal cual de su hoja (dos columnas: patrón y creativo, separadas por
// tabulador al copiar de Sheets). El (?i) de Sheets sobra: aquí nunca se
// distinguen mayúsculas.
const catalogoPegado = ref('');
const catalogoBusqueda = ref('');
const catalogoAviso = ref('');
const CATALOGO_VISIBLE = 50;
function agregarCatalogo() {
    reglas.value ??= aEditable({ version: 2 });
    let agregadas = 0;
    let ignoradas = 0;
    for (const linea of catalogoPegado.value.split('\n')) {
        const partes = linea.includes('\t') ? linea.split('\t') : linea.split(' → ');
        const patron = (partes[0] || '').trim().replace(/^\(\?i\)/, '');
        const valor = (partes[1] || '').trim();
        if (!patron || !valor || /^patr[oó]n/i.test(patron)) {
            if (linea.trim()) ignoradas++;
            continue;
        }
        const existente = reglas.value.catalogo.find((c) => c.patron === patron);
        if (existente) existente.valor = valor;
        else reglas.value.catalogo.push({ post_id: '', siglas: '', patron, valor });
        agregadas++;
    }
    catalogoAviso.value = `${agregadas} regla(s) agregadas${ignoradas ? `, ${ignoradas} línea(s) sin patrón y creativo` : ''}. Prueba con los ejemplos para ver cómo quedan.`;
    catalogoPegado.value = '';
    guardado.value = '';
}
const catalogoFiltrado = computed(() => {
    const q = catalogoBusqueda.value.trim().toLowerCase();
    return (reglas.value?.catalogo ?? [])
        .map((c, i) => ({ c, i }))
        .filter(({ c }) => !q || c.valor.toLowerCase().includes(q) || c.patron.toLowerCase().includes(q) || c.post_id.includes(q));
});
const catalogoPorPost = computed(() => (reglas.value?.catalogo ?? []).filter((c) => c.post_id).length);

const columnasDimension = computed(() => {
    const vistas = new Set(vistaPrevia.value.flatMap((f) => Object.keys(f.dimensiones ?? {})));
    const nombres = Object.fromEntries((reglas.value?.dimensiones ?? []).map((d) => [d.nombre.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''), d.nombre]));
    return [...vistas].map((k) => ({ clave: k, nombre: nombres[k] ?? k }));
});
const filaMal = (f) => !f.ok || (f.tipo === 'anuncio' && (!f.es_de_la_cuenta || !f.plataforma || !f.arte));
const NIVEL_CORTO = { campania: 'CAMP', conjunto: 'CONJ', anuncio: 'AD' };

// --- Desactivar / reactivar -------------------------------------------------
const modalAbierto = ref(false);
const confirmacion = ref('');
const errorModal = ref('');
async function desactivar() {
    errorModal.value = '';
    try {
        await axios.post(`/cuentas/${c.slug}/desactivar`, { confirmacion: confirmacion.value });
        activa.value = false;
        modalAbierto.value = false;
        guardado.value = 'Cuenta desactivada.';
    } catch (e) {
        errorModal.value = e.response?.data?.errors?.confirmacion?.[0] || e.response?.data?.message || 'No se pudo desactivar.';
    }
}
async function reactivar() {
    try {
        await axios.post(`/cuentas/${c.slug}/reactivar`);
        activa.value = true;
        guardado.value = 'Cuenta reactivada.';
    } catch (e) {
        erroresServidor.value = { general: e.response?.data?.message || 'No se pudo reactivar.' };
    }
}
</script>

<template>
    <Head :title="nueva ? 'Nueva cuenta' : `Editar ${cuenta.nombre}`" />

    <DashboardLayout :pais="pais" vista-activa="cuentas">
        <div class="cu">
            <div class="cu-page">
                <Link class="cu-back" href="/cuentas">‹ Cuentas</Link>
                <header class="cu-head">
                    <div>
                        <div class="cu-eyebrow">{{ nueva ? 'Cuentas · Nueva' : 'Cuentas · Editar' }}</div>
                        <h1 class="cu-title">{{ nueva ? 'Nueva cuenta' : (form.nombre || 'Cuenta sin nombre') }}</h1>
                        <p class="cu-subtitle">
                            {{ nueva
                                ? 'Configura la cuenta una vez y el dashboard arma sus métricas, medios y conexiones.'
                                : 'Cambia cómo se analiza esta cuenta. Los cambios aplican desde el próximo refresco de datos.' }}
                        </p>
                    </div>
                    <span v-if="!nueva" class="cu-badge" :class="activa ? 'cu-badge-on' : 'cu-badge-off'">
                        <span class="cu-dot" style="width: 6px; height: 6px; background: currentColor" />{{ activa ? 'Activa' : 'Inactiva' }}
                    </span>
                </header>

                <div v-if="totalErrores" class="cu-err-summary" role="alert">
                    <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5M12 16v.5" /></svg>
                    <div>
                        <strong style="font-weight: 600">{{ err('general') || (totalErrores === 1 ? 'Revisa 1 campo antes de guardar.' : `Revisa ${totalErrores} campos antes de guardar.`) }}</strong>
                        <div class="cu-hint">Los campos con problema están marcados en rojo, junto con lo que falta.</div>
                    </div>
                </div>

                <div class="cu-form-wrap">
                    <nav class="cu-sec-nav" aria-label="Secciones del formulario">
                        <a v-for="s in secciones" :key="s.id" :href="`#${s.id}`">
                            <span class="num">{{ s.n }}</span>{{ s.label }}
                            <span v-if="s.err" class="cu-dot errdot" aria-label="Con errores" />
                        </a>
                    </nav>

                    <div class="cu-form-col">
                        <!-- 01 Identidad -->
                        <section id="identidad" class="cu-panel" aria-labelledby="h-identidad">
                            <div class="cu-panel-head">
                                <div class="cu-eyebrow">01 · Identidad</div>
                                <h2 id="h-identidad" class="cu-panel-title">Quién es la cuenta</h2>
                                <p class="cu-hint">Así aparece en la lista, el selector y los reportes.</p>
                            </div>
                            <div class="cu-grid2">
                                <div class="cu-field">
                                    <label class="cu-field-label" for="f-nombre">Nombre de la cuenta</label>
                                    <input id="f-nombre" v-model="form.nombre" class="cu-input" :class="{ err: err('nombre') }" type="text" placeholder="Ej. Coppel Ecommerce" :aria-invalid="!!err('nombre')" @input="guardado = ''">
                                    <span v-if="err('nombre')" class="cu-field-err">{{ err('nombre') }}</span>
                                </div>
                                <div class="cu-field">
                                    <label class="cu-field-label" for="f-cliente">Cliente o grupo</label>
                                    <div class="cu-combo">
                                        <input
                                            id="f-cliente"
                                            class="cu-input"
                                            :class="{ err: err('cliente') }"
                                            type="text"
                                            role="combobox"
                                            :aria-expanded="comboAbierto"
                                            aria-controls="f-cliente-lista"
                                            aria-autocomplete="list"
                                            placeholder="Busca o crea un cliente"
                                            :value="comboAbierto ? comboTexto : form.cliente"
                                            @focus="abrirCombo"
                                            @input="comboTexto = $event.target.value; comboAbierto = true"
                                        >
                                        <svg class="cu-ic cu-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
                                        <div v-if="comboAbierto" id="f-cliente-lista" class="cu-menu" role="listbox" aria-label="Clientes" style="right: 0">
                                            <button
                                                v-for="o in opcionesCliente"
                                                :key="o.nombre"
                                                type="button"
                                                role="option"
                                                class="cu-opt"
                                                :class="{ on: form.cliente === o.nombre }"
                                                :aria-selected="form.cliente === o.nombre"
                                                @click="elegirCliente(o.nombre)"
                                            >
                                                <span>{{ o.nombre }}</span><span class="cu-opt-meta">{{ o.cuentas === 1 ? '1 cuenta' : `${o.cuentas} cuentas` }}</span>
                                            </button>
                                            <button v-if="puedeCrearCliente" type="button" class="cu-opt cu-opt-new" @click="elegirCliente(comboTexto.trim())">
                                                <span>+ Crear cliente “{{ comboTexto.trim() }}”</span><span class="cu-opt-meta">Nuevo</span>
                                            </button>
                                            <p v-if="!opcionesCliente.length && !puedeCrearCliente" class="cu-hint" style="padding: 8px 10px">Escribe el nombre del cliente.</p>
                                        </div>
                                    </div>
                                    <span v-if="err('cliente')" class="cu-field-err">{{ err('cliente') }}</span>
                                    <p class="cu-hint">Agrupa cuentas del mismo cliente, como Coppel Branding y Coppel Ecommerce.</p>
                                </div>
                            </div>
                            <div class="cu-grid2 cu-divider">
                                <div class="cu-field">
                                    <span class="cu-field-label">Logo</span>
                                    <div class="cu-logo-box">
                                        <LogoCuenta :cuenta="logoParaMostrar" :tamano="56" :radio="12" />
                                        <div style="display: flex; flex-direction: column; gap: 6px; min-width: 0">
                                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                                                <label for="f-logo" class="file-select-btn" style="padding: 6px 12px; font-size: 13px">{{ logoParaMostrar.logo ? 'Cambiar logo' : 'Subir logo' }}</label>
                                                <input id="f-logo" class="file-select-input" type="file" accept="image/png,image/jpeg,image/webp" @change="elegirLogo">
                                                <button v-if="logoParaMostrar.logo" type="button" class="cu-link-btn" @click="descartarLogo">Quitar</button>
                                            </div>
                                            <p class="cu-hint">PNG, JPG o WebP de hasta 1 MB; cuadrado se ve mejor. Sin logo se usan las iniciales con el color de la cuenta.</p>
                                            <span v-if="logoError" class="cu-field-err">{{ logoError }}</span>
                                            <span v-else-if="logoArchivo || quitarLogo" class="cu-hint">Se aplica al guardar.</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="cu-field">
                                    <span id="f-color-l" class="cu-field-label">Color de la cuenta</span>
                                    <div class="cu-swatches" role="radiogroup" aria-labelledby="f-color-l">
                                        <button
                                            v-for="col in COLORES_CUENTA"
                                            :key="col"
                                            type="button"
                                            class="cu-swatch"
                                            :class="{ on: form.color.toLowerCase() === col }"
                                            role="radio"
                                            :aria-checked="form.color.toLowerCase() === col"
                                            :aria-label="`Color ${col}`"
                                            :style="{ background: col }"
                                            @click="form.color = col; guardado = ''"
                                        />
                                        <label class="sr" for="f-hex">Color en hex</label>
                                        <input id="f-hex" v-model="form.color" class="cu-input mono" :class="{ err: err('color') }" style="width: 112px; height: 34px">
                                    </div>
                                    <span v-if="err('color')" class="cu-field-err">{{ err('color') }}</span>
                                    <p class="cu-hint">Se usa en el logo de respaldo y para distinguir la cuenta en el selector.</p>
                                </div>
                            </div>
                        </section>

                        <!-- 02 Negocio -->
                        <section id="negocio" class="cu-panel" aria-labelledby="h-negocio">
                            <div class="cu-panel-head">
                                <div class="cu-eyebrow">02 · Negocio</div>
                                <h2 id="h-negocio" class="cu-panel-title">Qué vende y qué busca</h2>
                                <p class="cu-hint">Los objetivos deciden qué métricas ves primero en Creativos e Inteligencia.</p>
                            </div>
                            <div class="cu-field">
                                <span id="f-svc-l" class="cu-field-label">Tipo de servicio</span>
                                <div class="cu-chips" role="group" aria-labelledby="f-svc-l">
                                    <button
                                        v-for="s in catalogo.servicios"
                                        :key="s"
                                        type="button"
                                        class="cu-chip"
                                        :class="{ on: form.servicios.includes(s) }"
                                        :aria-pressed="form.servicios.includes(s)"
                                        @click="alternarServicio(s)"
                                    ><span v-if="form.servicios.includes(s)" aria-hidden="true">✓</span>{{ s }}</button>
                                </div>
                            </div>
                            <div class="cu-field cu-divider">
                                <span id="f-obj-l" class="cu-field-label">Objetivos</span>
                                <div class="cu-chips" :class="{ err: err('objetivos') }" role="group" aria-labelledby="f-obj-l">
                                    <button
                                        v-for="o in catalogo.objetivos"
                                        :key="o"
                                        type="button"
                                        class="cu-chip"
                                        :class="{ on: form.objetivos.includes(o) }"
                                        :aria-pressed="form.objetivos.includes(o)"
                                        :disabled="o === 'Instalaciones' && !tieneApp"
                                        :title="o === 'Instalaciones' && !tieneApp ? 'Requiere el tipo de servicio App' : ''"
                                        @click="alternar(form.objetivos, o)"
                                    ><span v-if="form.objetivos.includes(o)" aria-hidden="true">✓</span>{{ o }}</button>
                                </div>
                                <span v-if="err('objetivos')" class="cu-field-err">{{ err('objetivos') }}</span>
                                <p v-if="!tieneApp" class="cu-hint">Instalaciones requiere el tipo de servicio App.</p>

                                <div class="cu-metrics" aria-live="polite">
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 6px">
                                        <span class="cu-filter-label">Métricas que verás</span>
                                        <span class="mono cu-faint" style="font-size: 10.5px">{{ totalMetricas ? `${totalMetricas} MÉTRICAS` : '' }}</span>
                                    </div>
                                    <p v-if="!metricas.length" class="cu-hint" style="padding: 8px 0">Elige uno o más objetivos para ver qué métricas tendrá el análisis de esta cuenta.</p>
                                    <div v-for="m in metricas" :key="m.objetivo" class="cu-mrow">
                                        <span style="font-weight: 600; font-size: 13px">{{ m.objetivo }}</span>
                                        <div class="cu-mpills"><span v-for="p in m.pills" :key="p.n" class="cu-mpill" :class="{ real: p.real }">{{ p.n }}</span></div>
                                    </div>
                                    <p v-if="form.venta_real !== 'no' && form.objetivos.includes('Ventas')" class="cu-hint" style="padding-top: 8px; border-top: 1px solid var(--border)">Las métricas en menta salen de la venta real que configuras en Medición.</p>
                                </div>
                            </div>
                        </section>

                        <!-- 03 Medios -->
                        <section id="medios" class="cu-panel" aria-labelledby="h-medios">
                            <div class="cu-panel-head">
                                <div class="cu-eyebrow">03 · Medios</div>
                                <h2 id="h-medios" class="cu-panel-title">Dónde pauta</h2>
                                <p class="cu-hint">Elige todos los medios de la cuenta. Solo Meta y TikTok traen datos hoy.</p>
                            </div>
                            <div class="cu-chips" :class="{ err: err('medios') }" role="group" aria-labelledby="h-medios">
                                <button
                                    v-for="m in catalogo.medios"
                                    :key="m.clave"
                                    type="button"
                                    class="cu-mchip"
                                    :class="{ on: form.medios.includes(m.clave) }"
                                    :aria-pressed="form.medios.includes(m.clave)"
                                    @click="alternar(form.medios, m.clave)"
                                >
                                    <span>{{ m.nombre }}</span>
                                    <span class="cu-mchip-st" :class="{ ok: m.conectado && !form.medios.includes(m.clave) }">
                                        <span class="cu-dot" style="width: 6px; height: 6px" :style="{ background: form.medios.includes(m.clave) ? 'var(--amber-ink)' : (m.conectado ? 'var(--mint)' : 'var(--text-faint)') }" />
                                        {{ m.conectado ? 'Conectado' : 'Próximamente' }}
                                    </span>
                                </button>
                            </div>
                            <span v-if="err('medios')" class="cu-field-err" style="margin-top: 10px">{{ err('medios') }}</span>
                            <p v-if="mediosProximamente" class="cu-hint" style="margin-top: 12px">
                                {{ mediosProximamente === 1
                                    ? 'Elegiste 1 medio Próximamente: se guarda en la cuenta, pero aún no trae datos.'
                                    : `Elegiste ${mediosProximamente} medios Próximamente: se guardan en la cuenta, pero aún no traen datos.` }}
                            </p>
                        </section>

                        <!-- 04 Medición -->
                        <section id="medicion" class="cu-panel" aria-labelledby="h-medicion">
                            <div class="cu-panel-head">
                                <div class="cu-eyebrow">04 · Medición</div>
                                <h2 id="h-medicion" class="cu-panel-title">De dónde salen los resultados</h2>
                                <p class="cu-hint">Puedes combinar fuentes. La plataforma siempre se incluye.</p>
                            </div>
                            <div class="cu-field">
                                <span class="cu-field-label">Fuentes de datos</span>
                                <div class="cu-src-grid">
                                    <label class="cu-src on">
                                        <input type="checkbox" checked disabled>
                                        <span style="display: flex; flex-direction: column; gap: 4px">
                                            <span style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap"><span class="cu-src-title">Plataforma</span><span class="cu-badge cu-badge-soon">Siempre incluida</span></span>
                                            <span class="cu-hint">Lo que reporta cada medio: gasto, impresiones, clics y conversiones.</span>
                                        </span>
                                    </label>
                                    <label class="cu-src" :class="{ on: form.fuente_ga4 }">
                                        <input v-model="form.fuente_ga4" type="checkbox">
                                        <span style="display: flex; flex-direction: column; gap: 4px">
                                            <span style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap"><span class="cu-src-title">GA4</span><span class="cu-badge cu-badge-soon">Próximamente</span></span>
                                            <span class="cu-hint">Sesiones, conversiones e ingresos del sitio, cruzados por UTM.</span>
                                        </span>
                                    </label>
                                    <label class="cu-src" :class="{ on: form.fuente_mmp && tieneApp, off: !tieneApp }">
                                        <input v-model="form.fuente_mmp" type="checkbox" :disabled="!tieneApp">
                                        <span style="display: flex; flex-direction: column; gap: 4px">
                                            <span style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap"><span class="cu-src-title">MMP · AppsFlyer</span><span v-if="!tieneApp" class="cu-badge cu-badge-soon">Requiere App</span></span>
                                            <span class="cu-hint">Installs y eventos dentro de la app, atribuidos por AppsFlyer.</span>
                                        </span>
                                    </label>
                                </div>
                                <span v-if="err('fuente_mmp')" class="cu-field-err">{{ err('fuente_mmp') }}</span>
                            </div>
                            <div v-if="form.fuente_mmp && tieneApp" class="cu-field cu-divider">
                                <span id="f-af-origen" class="cu-field-label">De dónde sale AppsFlyer</span>
                                <div class="cu-src-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 12px" role="radiogroup" aria-labelledby="f-af-origen">
                                    <label class="cu-src" :class="{ on: form.appsflyer_origen === 'api' }">
                                        <input v-model="form.appsflyer_origen" type="radio" name="af-origen" value="api" @change="guardado = ''">
                                        <span style="display: flex; flex-direction: column; gap: 4px"><span class="cu-src-title">API de AppsFlyer</span><span class="cu-hint">Con el token de la cuenta de AppsFlyer del cliente.</span></span>
                                    </label>
                                    <label class="cu-src" :class="{ on: form.appsflyer_origen === 'bigquery' }">
                                        <input v-model="form.appsflyer_origen" type="radio" name="af-origen" value="bigquery" @change="guardado = ''">
                                        <span style="display: flex; flex-direction: column; gap: 4px"><span class="cu-src-title">BigQuery de EPA</span><span class="cu-hint">Data Locker en el proyecto de datos de EPA. No necesita token.</span></span>
                                    </label>
                                </div>

                                <template v-if="form.appsflyer_origen === 'bigquery'">
                                    <div class="cu-cred-eventos" style="margin-top: 0">
                                        <div class="cu-field">
                                            <label class="cu-field-label" for="f-bq-proyecto">Proyecto</label>
                                            <input id="f-bq-proyecto" v-model="form.appsflyer_bq_proyecto" class="cu-input mono" type="text" placeholder="bdd-epa-digital" @input="guardado = ''; pruebaBq = null">
                                        </div>
                                        <div class="cu-field">
                                            <label class="cu-field-label" for="f-bq-dataset">Dataset</label>
                                            <input id="f-bq-dataset" v-model="form.appsflyer_bq_dataset" class="cu-input mono" :class="{ err: err('appsflyer_bq_dataset') }" type="text" placeholder="appsflyeropenbank" @input="guardado = ''; pruebaBq = null">
                                            <span v-if="err('appsflyer_bq_dataset')" class="cu-field-err">{{ err('appsflyer_bq_dataset') }}</span>
                                        </div>
                                    </div>
                                    <details class="cu-bq-avanzado">
                                        <summary class="cu-hint">Tablas y zona horaria</summary>
                                        <div class="cu-cred-eventos">
                                            <div v-for="(etiqueta, clave) in ETIQUETA_TABLA" :key="clave" class="cu-field">
                                                <label class="cu-field-label" :for="`f-bq-${clave}`">{{ etiqueta }}<span v-if="clave.endsWith('_rt')" class="cu-hint"> (opcional; vacío = no se usa)</span></label>
                                                <input :id="`f-bq-${clave}`" v-model="form.appsflyer_bq_tablas[clave]" class="cu-input mono" type="text" @input="guardado = ''; pruebaBq = null">
                                            </div>
                                            <div class="cu-field">
                                                <label class="cu-field-label" for="f-bq-zona">Zona horaria de las fechas</label>
                                                <input id="f-bq-zona" v-model="form.appsflyer_zona_horaria" class="cu-input mono" type="text" placeholder="America/Mexico_City" @input="guardado = ''">
                                            </div>
                                        </div>
                                    </details>
                                    <div class="cu-nom-acciones" style="margin-top: 10px">
                                        <button type="button" class="cu-btn cu-btn-sm" :disabled="probandoBq || !form.appsflyer_bq_dataset.trim()" @click="probarBigQuery">{{ probandoBq ? 'Probando…' : 'Probar conexión' }}</button>
                                        <span class="cu-hint">Revisa permisos, tablas y columnas, sin traer datos.</span>
                                    </div>
                                    <span v-if="errorBq" class="cu-field-err" role="alert">{{ errorBq }}</span>
                                    <div v-if="pruebaBq" class="cu-bq-prueba" role="status">
                                        <span class="cu-badge" :class="pruebaBq.ok ? 'cu-badge-on' : 'cu-badge-off'">{{ pruebaBq.ok ? 'Listo para importar' : 'Falta algo' }}</span>
                                        <ul>
                                            <li v-for="t in pruebaBq.tablas" :key="t.clave">
                                                <span class="mono">{{ t.tabla }}</span> ·
                                                <template v-if="!t.existe">{{ t.opcional ? 'no existe (opcional, se omite)' : 'no existe' }}</template>
                                                <template v-else-if="t.faltan.length">le faltan columnas: <span class="mono">{{ t.faltan.join(', ') }}</span></template>
                                                <template v-else>ok</template>
                                            </li>
                                        </ul>
                                        <span v-if="!pruebaBq.ok && pruebaBq.disponibles?.length" class="cu-hint">Tablas en el dataset: <span class="mono">{{ pruebaBq.disponibles.slice(0, 12).join(', ') }}</span></span>
                                    </div>
                                </template>

                                <template v-else>
                                <p class="cu-hint" style="margin-bottom: 6px">Cada cuenta usa el token de su propia cuenta de AppsFlyer. Sin token no se importa por API.</p>
                                <div class="cu-cred-estado">
                                    <span v-if="credAppsFlyer.tiene_token && !quitarTokenAppsFlyer" class="cu-badge cu-badge-on">Token ••••{{ credAppsFlyer.ultimos4 }}</span>
                                    <span v-else class="cu-badge cu-badge-off">Sin token</span>
                                    <span v-if="credAppsFlyer.tiene_token && !quitarTokenAppsFlyer" class="cu-hint">
                                        {{ credAppsFlyer.validado_en ? `Validado el ${fechaCorta(credAppsFlyer.validado_en)}` : 'Sin validar: se valida cuando la cuenta tenga apps' }}{{ credAppsFlyer.actualizado_por ? ` · ${credAppsFlyer.actualizado_por}` : '' }}
                                    </span>
                                    <button v-if="credAppsFlyer.tiene_token" type="button" class="cu-link-btn" @click="quitarTokenAppsFlyer = !quitarTokenAppsFlyer; guardado = ''">
                                        {{ quitarTokenAppsFlyer ? 'Conservar token' : 'Quitar token' }}
                                    </button>
                                </div>
                                <label class="cu-field-label" for="f-af-token" style="margin-top: 10px">{{ credAppsFlyer.tiene_token ? 'Reemplazar token' : 'Token de API (V2)' }}</label>
                                <input id="f-af-token" v-model="tokenAppsFlyer" class="cu-input mono" :class="{ err: err('appsflyer_token') }" type="password" autocomplete="off" spellcheck="false" placeholder="Pega el token de AppsFlyer → Security center → API tokens" :disabled="quitarTokenAppsFlyer" @input="guardado = ''">
                                <span v-if="err('appsflyer_token')" class="cu-field-err">{{ err('appsflyer_token') }}</span>
                                <p class="cu-hint">Al guardar se prueba contra una de las apps de la cuenta. Después solo se muestran los últimos 4 caracteres.</p>
                                </template>
                                <div class="cu-cred-eventos">
                                    <div class="cu-field">
                                        <label class="cu-field-label" for="f-af-ev1">Evento principal</label>
                                        <input id="f-af-ev1" v-model="form.appsflyer_evento_principal" class="cu-input mono" :class="{ err: err('appsflyer_evento_principal') }" type="text" placeholder="Ej. First Order Placed" @input="guardado = ''">
                                        <span v-if="err('appsflyer_evento_principal')" class="cu-field-err">{{ err('appsflyer_evento_principal') }}</span>
                                    </div>
                                    <div class="cu-field">
                                        <label class="cu-field-label" for="f-af-ev2">Evento secundario <span class="cu-hint">(opcional)</span></label>
                                        <input id="f-af-ev2" v-model="form.appsflyer_evento_secundario" class="cu-input mono" type="text" placeholder="Ej. Order Placed" @input="guardado = ''">
                                    </div>
                                </div>
                                <p class="cu-hint">Nombre exacto del evento en el SDK de la app, con mayúsculas y espacios.</p>
                            </div>
                            <div class="cu-field cu-divider">
                                <span id="f-venta-l" class="cu-field-label">¿Tu data se eleva a venta real?</span>
                                <p class="cu-hint" style="margin-bottom: 6px">La venta real reemplaza las conversiones que reporta cada plataforma por las ventas que de verdad registró el negocio.</p>
                                <div class="cu-src-grid" role="radiogroup" aria-labelledby="f-venta-l">
                                    <label v-for="v in OPCIONES_VENTA" :key="v.clave" class="cu-src" :class="{ on: form.venta_real === v.clave }">
                                        <input v-model="form.venta_real" type="radio" name="venta-real" :value="v.clave">
                                        <span style="display: flex; flex-direction: column; gap: 4px">
                                            <span class="cu-src-title">{{ v.titulo }}</span>
                                            <span class="cu-hint">{{ v.desc }}</span>
                                        </span>
                                    </label>
                                </div>
                                <p v-if="form.venta_real === 'manual'" class="cu-hint" style="margin-top: 4px">La captura mensual se hace en Cargar datos.</p>
                                <p v-if="form.venta_real === 'auto'" class="cu-hint" style="margin-top: 4px">Necesitas una fuente conectada con la venta del negocio. Si aún no la tienes, elige “Sí, manual” mientras tanto.</p>
                            </div>
                        </section>

                        <!-- 05 Países -->
                        <section id="paises" class="cu-panel" aria-labelledby="h-paises">
                            <div class="cu-panel-head">
                                <div class="cu-eyebrow">05 · Países</div>
                                <h2 id="h-paises" class="cu-panel-title">Dónde opera</h2>
                                <p class="cu-hint">Cada país tiene sus propias conexiones y se analiza por separado.</p>
                            </div>
                            <div class="cu-toggle-row">
                                <div>
                                    <div id="f-multi-l" class="cu-src-title">¿Tu cuenta tiene más de un país?</div>
                                    <p class="cu-hint">{{ form.multi_pais ? 'Elige los países donde opera. Cada uno se conecta y analiza por separado.' : 'Apagado: la cuenta opera solo en México.' }}</p>
                                </div>
                                <button type="button" class="cu-switch" :class="{ on: form.multi_pais }" role="switch" :aria-checked="form.multi_pais" aria-labelledby="f-multi-l" @click="alternarMulti" />
                            </div>
                            <div v-if="!form.multi_pais" class="cu-field" style="margin-top: 18px">
                                <span class="cu-field-label">País</span>
                                <div class="cu-fixed-country">
                                    <span class="cu-flag" :style="{ background: paisesPorCodigo.MX?.bandera }" role="img" aria-label="México" />
                                    <span style="font-weight: 600">México</span>
                                    <span class="mono" style="font-size: 11px; color: var(--text-muted)">MX</span>
                                </div>
                            </div>
                            <div v-else class="cu-field" style="margin-top: 18px">
                                <span id="f-paises-l" class="cu-field-label">Países de la cuenta</span>
                                <div class="cu-chips" role="group" aria-labelledby="f-paises-l">
                                    <button
                                        v-for="p in catalogo.paises"
                                        :key="p.codigo"
                                        type="button"
                                        class="cu-chip"
                                        :class="{ on: form.paises.includes(p.codigo) }"
                                        :aria-pressed="form.paises.includes(p.codigo)"
                                        @click="alternar(form.paises, p.codigo)"
                                    >
                                        <span class="cu-flag" :style="{ background: p.bandera }" aria-hidden="true" />{{ p.nombre }}
                                    </button>
                                </div>
                                <span v-if="err('paises')" class="cu-field-err">{{ err('paises') }}</span>
                            </div>
                        </section>

                        <!-- 06 Conexiones -->
                        <section id="conexiones" class="cu-panel" aria-labelledby="h-conexiones">
                            <div class="cu-panel-head">
                                <div class="cu-eyebrow">06 · Conexiones</div>
                                <h2 id="h-conexiones" class="cu-panel-title">Cuentas publicitarias y apps</h2>
                                <p class="cu-hint">De aquí se leen los datos, por país. Apaga una conexión para dejar de leerla sin borrarla. Al guardar validamos cada cuenta nueva contra Meta o TikTok.</p>
                            </div>
                            <div v-for="b in bloques" :key="b.codigo" class="cu-country-block">
                                <div class="cu-country-head">
                                    <span class="cu-flag" :style="{ background: b.bandera }" role="img" :aria-label="b.nombre" />
                                    <span class="cu-section-title" style="font-size: 14px">{{ b.nombre }}</span>
                                    <span class="mono" style="font-size: 10.5px; letter-spacing: .06em; margin-left: auto; color: var(--text-muted)">{{ b.resumen }}</span>
                                </div>
                                <div class="cu-conn-scroll">
                                    <div class="cu-conn-min">
                                        <div class="cu-conn-sub">Cuentas publicitarias</div>
                                        <p v-if="!b.ads.length && !b.borr.length" class="cu-hint" style="padding: 10px 16px 14px">Sin cuentas publicitarias en {{ b.nombre }} todavía.</p>
                                        <div v-for="r in b.ads" :key="r.id" class="cu-conn-row">
                                            <span class="cu-plat"><span class="cu-medio" aria-hidden="true">{{ MEDIO_CODIGO[r.plataforma] }}</span>{{ medios[r.plataforma]?.nombre }}</span>
                                            <span class="mono" style="font-size: 12.5px">{{ r.id_externo }}</span>
                                            <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ r.nombre }}</span>
                                            <span style="display: flex; justify-content: flex-end">
                                                <button
                                                    type="button"
                                                    class="cu-switch"
                                                    :class="{ on: conexionesActivas[r.id] }"
                                                    role="switch"
                                                    :aria-checked="!!conexionesActivas[r.id]"
                                                    :aria-label="`Leer datos de ${r.nombre}`"
                                                    @click="conexionesActivas[r.id] = !conexionesActivas[r.id]; guardado = ''"
                                                />
                                            </span>
                                        </div>
                                        <div v-for="d in b.borr" :key="d.k" class="cu-conn-row draft">
                                            <div class="cu-field">
                                                <label class="sr" :for="`dp-${d.k}`">Plataforma</label>
                                                <select :id="`dp-${d.k}`" v-model="d.plataforma" class="cu-input">
                                                    <option value="meta">Meta</option>
                                                    <option value="tiktok">TikTok</option>
                                                </select>
                                            </div>
                                            <div class="cu-field">
                                                <label class="sr" :for="`di-${d.k}`">ID de la cuenta</label>
                                                <input :id="`di-${d.k}`" v-model="d.id_externo" class="cu-input mono" :class="{ err: errBorrador(d) }" placeholder="ID de la cuenta" :aria-invalid="!!errBorrador(d)">
                                                <span v-if="errBorrador(d)" class="cu-field-err">{{ errBorrador(d) }}</span>
                                            </div>
                                            <div class="cu-field">
                                                <label class="sr" :for="`dn-${d.k}`">Nombre</label>
                                                <input :id="`dn-${d.k}`" v-model="d.nombre" class="cu-input" placeholder="Nombre (opcional, si no usamos el de la plataforma)">
                                            </div>
                                            <button type="button" class="cu-btn cu-btn-ghost cu-btn-sm" style="width: 40px; padding: 0; height: 40px" aria-label="Quitar esta fila" @click="quitar(borradores, d.k)">
                                                <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                                            </button>
                                        </div>

                                        <template v-if="b.mostrarApps">
                                            <div class="cu-conn-sub">Apps de AppsFlyer</div>
                                            <p v-if="!b.apps.length && !b.appsBorr.length" class="cu-hint" style="padding: 10px 16px 14px">Sin apps de AppsFlyer en {{ b.nombre }} todavía.</p>
                                            <div v-for="r in b.apps" :key="r.id" class="cu-conn-row">
                                                <span class="cu-plat"><span class="cu-medio" aria-hidden="true">AF</span>{{ r.plataforma === 'ios' ? 'iOS' : 'Android' }}</span>
                                                <span class="mono" style="font-size: 12.5px">{{ r.app_id }}</span>
                                                <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ r.nombre }}</span>
                                                <span />
                                            </div>
                                            <div v-for="a in b.appsBorr" :key="a.k" class="cu-conn-row draft">
                                                <div class="cu-field">
                                                    <label class="sr" :for="`ap-${a.k}`">Sistema</label>
                                                    <select :id="`ap-${a.k}`" v-model="a.plataforma" class="cu-input">
                                                        <option value="ios">iOS</option>
                                                        <option value="android">Android</option>
                                                    </select>
                                                </div>
                                                <div class="cu-field">
                                                    <label class="sr" :for="`ai-${a.k}`">ID de la app</label>
                                                    <input :id="`ai-${a.k}`" v-model="a.app_id" class="cu-input mono" :class="{ err: errApp(a) }" :placeholder="a.plataforma === 'ios' ? 'id1234567890' : 'com.ejemplo.app'">
                                                    <span v-if="errApp(a)" class="cu-field-err">{{ errApp(a) }}</span>
                                                </div>
                                                <div class="cu-field">
                                                    <label class="sr" :for="`an-${a.k}`">Nombre</label>
                                                    <input :id="`an-${a.k}`" v-model="a.nombre" class="cu-input" placeholder="Nombre para reconocerla">
                                                </div>
                                                <button type="button" class="cu-btn cu-btn-ghost cu-btn-sm" style="width: 40px; padding: 0; height: 40px" aria-label="Quitar esta app" @click="quitar(appsBorrador, a.k)">
                                                    <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div class="cu-conn-foot">
                                    <button type="button" class="cu-btn cu-btn-sm" @click="agregarBorrador(b.codigo)">
                                        <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true" style="width: 15px; height: 15px"><path d="M12 5v14M5 12h14" /></svg>
                                        Agregar cuenta publicitaria
                                    </button>
                                    <button v-if="b.mostrarApps" type="button" class="cu-btn cu-btn-sm cu-btn-ghost" @click="agregarApp(b.codigo)">
                                        <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true" style="width: 15px; height: 15px"><path d="M12 5v14M5 12h14" /></svg>
                                        Agregar app de AppsFlyer
                                    </button>
                                </div>
                            </div>
                        </section>

                        <!-- 07 Clasificación -->
                        <section id="nomenclatura" class="cu-panel" aria-labelledby="h-nomenclatura">
                            <div class="cu-panel-head">
                                <div class="cu-eyebrow">07 · Clasificación</div>
                                <h2 id="h-nomenclatura" class="cu-panel-title">Cómo nombran sus campañas</h2>
                                <p class="cu-hint">Enséñale al dashboard tu vocabulario: con qué siglas marcas performance o branding, cuáles son tus etapas del funnel y cómo se llaman tus campañas, conjuntos y anuncios. Con eso proponemos las reglas y las pruebas contra tus ejemplos. Sin reglas, la cuenta se lee como TaDa.</p>
                            </div>

                            <!-- TaDa usa su clasificador de siempre (ver Nomenclatura.php). -->
                            <div v-if="c?.slug === 'tada'" class="cu-nom-paso">
                                <p class="cu-hint">TaDa usa su clasificación propia (DTC/BRD, patrones de campaña y arte), que ya está ajustada con datos reales. No necesita configuración aquí.</p>
                            </div>
                            <template v-else>
                            <!-- Paso 1: tipos de campaña -->
                            <div class="cu-nom-paso">
                                <div class="cu-nom-paso-head"><span class="cu-nom-num">1</span><span class="cu-section-title">Tipos de campaña</span></div>
                                <p class="cu-hint">Las siglas que usas en el nombre para cada tipo, separadas por coma. Ej. Openbank: Performance = <span class="mono">lead</span>, Branding = <span class="mono">brn</span>.</p>
                                <div v-for="(t, i) in cuestionario.tipos" :key="`t${i}`" class="cu-nom-q">
                                    <input v-model="t.nombre" class="cu-input" placeholder="Nombre" :aria-label="`Nombre del tipo ${i + 1}`" @input="guardado = ''">
                                    <select v-model="t.eje" class="cu-input" :aria-label="`Eje de ${t.nombre || 'el tipo'}`" @change="guardado = ''">
                                        <option v-for="e in EJES" :key="e.clave" :value="e.clave">{{ e.label }}</option>
                                    </select>
                                    <input v-model="t.siglas" class="cu-input mono" :placeholder="SIGLAS_EJEMPLO_EJE[t.eje] || SIGLAS_EJEMPLO_EJE.otro" :aria-label="`Siglas de ${t.nombre || 'el tipo'}`" @input="guardado = ''">
                                    <button type="button" class="cu-link-btn" @click="cuestionario.tipos.splice(i, 1); guardado = ''">Quitar</button>
                                    <textarea v-model="t.ejemplos" class="cu-input mono cu-textarea cu-nom-q-ej" rows="2" spellcheck="false" :placeholder="`Ejemplos de campañas de ${t.nombre || 'este tipo'} (opcional, uno por línea)`" @input="guardado = ''" />
                                </div>
                                <button v-if="cuestionario.tipos.length < 8" type="button" class="cu-btn cu-btn-sm cu-btn-ghost" @click="cuestionario.tipos.push({ nombre: '', eje: 'otro', siglas: '', ejemplos: '' })">Agregar tipo</button>
                            </div>

                            <!-- Paso 2: funnel propio -->
                            <div class="cu-nom-paso">
                                <div class="cu-nom-paso-head"><span class="cu-nom-num">2</span><span class="cu-section-title">Tu funnel</span></div>
                                <p class="cu-hint">Renombra las etapas como las llaman ustedes (See, Think, Do, Care…), di a qué equivale cada una y pega 3 a 5 nombres de campañas reales de esa etapa. Si el nombre no dice la etapa, usamos el objetivo de la campaña en Meta o TikTok.</p>
                                <div v-for="(e, i) in cuestionario.etapas" :key="`e${i}`" class="cu-nom-q">
                                    <input v-model="e.nombre" class="cu-input" placeholder="Nombre de la etapa" :aria-label="`Nombre de la etapa ${i + 1}`" @input="guardado = ''">
                                    <select v-model="e.equivalente" class="cu-input" :aria-label="`Equivalente de ${e.nombre || 'la etapa'}`" @change="guardado = ''">
                                        <option v-for="q in EQUIVALENTES" :key="q.clave" :value="q.clave">= {{ q.label }}</option>
                                    </select>
                                    <input v-model="e.siglas" class="cu-input mono" :placeholder="SIGLAS_EJEMPLO_ETAPA[e.equivalente] || 'siglas separadas por coma'" :aria-label="`Siglas de ${e.nombre || 'la etapa'}`" @input="guardado = ''">
                                    <button type="button" class="cu-link-btn" @click="cuestionario.etapas.splice(i, 1); guardado = ''">Quitar</button>
                                    <textarea v-model="e.ejemplos" class="cu-input mono cu-textarea cu-nom-q-ej" rows="3" spellcheck="false" :placeholder="`3 a 5 campañas de ${e.nombre || 'esta etapa'}, una por línea`" @input="guardado = ''" />
                                </div>
                                <button v-if="cuestionario.etapas.length < 8" type="button" class="cu-btn cu-btn-sm cu-btn-ghost" @click="cuestionario.etapas.push({ nombre: '', equivalente: 'CNV', siglas: '', ejemplos: '' })">Agregar etapa</button>
                            </div>

                            <!-- Paso 3: ejemplos por nivel -->
                            <div class="cu-nom-paso">
                                <div class="cu-nom-paso-head"><span class="cu-nom-num">3</span><span class="cu-section-title">Ejemplos por nivel</span></div>
                                <p class="cu-hint">Nombres reales tal cual salen en la plataforma, uno por línea. Mezcla plataformas si puedes.</p>
                                <div class="cu-nom-grid cu-nom-grid-3">
                                    <div class="cu-field">
                                        <label class="cu-field-label" for="f-nom-camp">Campañas</label>
                                        <textarea id="f-nom-camp" v-model="cuestionario.campanias" class="cu-input mono cu-textarea" rows="5" spellcheck="false" placeholder="aw_obmx_lead_do_uac_android_install_tdd" @input="guardado = ''" />
                                    </div>
                                    <div class="cu-field">
                                        <label class="cu-field-label" for="f-nom-conj">Conjuntos / ad groups</label>
                                        <textarea id="f-nom-conj" v-model="cuestionario.conjuntos" class="cu-input mono cu-textarea" rows="5" spellcheck="false" placeholder="fb_obmx_lead_do_auct_app_event_android_lal_tdd_all" @input="guardado = ''" />
                                    </div>
                                    <div class="cu-field">
                                        <label class="cu-field-label" for="f-nom-ads">Anuncios</label>
                                        <textarea id="f-nom-ads" v-model="cuestionario.anuncios" class="cu-input mono cu-textarea" rows="5" spellcheck="false" placeholder="…_shaula-ponce_28-sep-26_influencer_madrid" @input="guardado = ''" />
                                    </div>
                                </div>
                            </div>

                            <!-- Paso 4: explicación + IA -->
                            <div class="cu-nom-paso">
                                <div class="cu-nom-paso-head"><span class="cu-nom-num">4</span><span class="cu-section-title">¿Qué significa cada parte?</span></div>
                                <textarea v-model="cuestionario.notas" class="cu-input cu-textarea" rows="2" placeholder="Ej. fb / obmx / lead / do = medio / cliente / línea de negocio / etapa del funnel; tdd y tdc son productos" @input="guardado = ''" />
                                <div class="cu-nom-acciones">
                                    <button type="button" class="cu-btn cu-btn-sm" :disabled="totalEjemplos < 3 || !!nomenclaturaEstado" @click="proponerNomenclatura">
                                        {{ nomenclaturaEstado === 'proponiendo' ? 'Analizando ejemplos…' : (reglas ? 'Volver a proponer con IA' : 'Proponer reglas con IA') }}
                                    </button>
                                    <button type="button" class="cu-btn cu-btn-sm cu-btn-ghost" :disabled="totalEjemplos < 3 || !!nomenclaturaEstado" @click="probarNomenclatura">
                                        {{ nomenclaturaEstado === 'probando' ? 'Probando…' : (reglas ? 'Probar con los ejemplos' : 'Probar solo con mis siglas') }}
                                    </button>
                                    <button v-if="reglas" type="button" class="cu-link-btn" @click="quitarNomenclatura">Quitar reglas</button>
                                    <span v-if="totalEjemplos < 3" class="cu-hint">Pega al menos 3 nombres reales.</span>
                                </div>
                                <span v-if="nomenclaturaError || err('nomenclatura')" class="cu-field-err" role="alert">{{ nomenclaturaError || err('nomenclatura') }}</span>
                            </div>

                            <!-- Paso 5: reglas editables -->
                            <div v-if="reglas" class="cu-nom-paso">
                                <div class="cu-nom-paso-head">
                                    <span class="cu-nom-num">5</span><span class="cu-section-title">Reglas</span>
                                    <button type="button" class="cu-link-btn" style="margin-left: auto" @click="formula.abierta = !formula.abierta">Pegar fórmula de Sheets</button>
                                </div>
                                <p class="cu-hint">Siglas = palabra completa (<span class="mono">do</span> no coincide dentro de <span class="mono">android</span>). Patrón = expresión regular, como en REGEXMATCH. Gana la primera que coincide, buscando en campaña, conjunto y anuncio.</p>

                                <div v-if="formula.abierta" class="cu-nom-formula">
                                    <textarea v-model="formula.texto" class="cu-input mono cu-textarea" rows="3" spellcheck="false" placeholder='=IFS(REGEXMATCH(A2,"see"),"See",REGEXMATCH(A2,"do_"),"Do")' />
                                    <div class="cu-nom-acciones" style="margin-top: 8px">
                                        <select v-model="formula.destino" class="cu-input" style="width: auto; height: 34px" aria-label="A qué campo aplica la fórmula">
                                            <option v-for="d in destinosFormula" :key="d.clave" :value="d.clave">{{ d.label }}</option>
                                        </select>
                                        <button type="button" class="cu-btn cu-btn-sm" :disabled="!formula.texto.trim()" @click="convertirFormula">Convertir en reglas</button>
                                    </div>
                                    <span v-if="formula.error" class="cu-field-err">{{ formula.error }}</span>
                                </div>

                                <div class="cu-nom-bloque">
                                    <span class="cu-field-label">Identifica a la cuenta</span>
                                    <div class="cu-nom-regla">
                                        <input v-model="reglas.identificador.siglas" class="cu-input mono" placeholder="siglas: obmx" aria-label="Siglas que identifican a la cuenta" @input="guardado = ''">
                                        <input v-model="reglas.identificador.patron" class="cu-input mono" placeholder="o patrón" aria-label="Patrón que identifica a la cuenta" @input="guardado = ''">
                                    </div>
                                    <span class="cu-hint">Los nombres que no lo traigan no se cargan en esta cuenta.</span>
                                </div>

                                <div class="cu-nom-bloque">
                                    <span class="cu-field-label">Tipos de campaña</span>
                                    <div v-for="(t, i) in reglas.tipos" :key="`rt${i}`" class="cu-nom-regla">
                                        <span class="cu-nom-valor">{{ t.nombre }} <span class="cu-hint">· {{ EJES.find((x) => x.clave === t.eje)?.label }}</span></span>
                                        <input v-model="t.siglas" class="cu-input mono" placeholder="siglas" :aria-label="`Siglas de ${t.nombre}`" @input="guardado = ''">
                                        <input v-model="t.patron" class="cu-input mono" placeholder="o patrón" :aria-label="`Patrón de ${t.nombre}`" @input="guardado = ''">
                                    </div>
                                </div>

                                <div class="cu-nom-bloque">
                                    <span class="cu-field-label">Funnel</span>
                                    <div v-for="(e, i) in reglas.etapas" :key="`re${i}`" class="cu-nom-regla">
                                        <span class="cu-nom-valor">{{ e.nombre }} <span class="cu-hint">· {{ EQUIVALENTES.find((x) => x.clave === e.equivalente)?.label || 'sin equivalente' }}</span></span>
                                        <input v-model="e.siglas" class="cu-input mono" placeholder="siglas" :aria-label="`Siglas de ${e.nombre}`" @input="guardado = ''">
                                        <input v-model="e.patron" class="cu-input mono" placeholder="o patrón" :aria-label="`Patrón de ${e.nombre}`" @input="guardado = ''">
                                    </div>
                                    <label class="cu-nom-check"><input v-model="reglas.usarObjetivo" type="checkbox" @change="guardado = ''"> Si el nombre no dice la etapa, usar el objetivo de la campaña en Meta / TikTok</label>
                                </div>

                                <div v-for="campo in [{ clave: 'plataforma', label: 'Plataforma', ej: 'meta, tiktok, google_ads…' }, { clave: 'formato', label: 'Formato', ej: 'VIDEO, IMAGEN o CATALOGO' }]" :key="campo.clave" class="cu-nom-bloque">
                                    <span class="cu-field-label">{{ campo.label }} <span class="cu-hint">({{ campo.ej }})</span></span>
                                    <div v-for="(r, i) in reglas[campo.clave]" :key="`${campo.clave}${i}`" class="cu-nom-regla">
                                        <input v-model="r.valor" class="cu-input mono" placeholder="valor" :aria-label="`Valor de ${campo.label}`" @input="guardado = ''">
                                        <input v-model="r.siglas" class="cu-input mono" placeholder="siglas" :aria-label="`Siglas de ${campo.label}`" @input="guardado = ''">
                                        <input v-model="r.patron" class="cu-input mono" placeholder="o patrón" :aria-label="`Patrón de ${campo.label}`" @input="guardado = ''">
                                        <button type="button" class="cu-link-btn" @click="reglas[campo.clave].splice(i, 1); guardado = ''">Quitar</button>
                                    </div>
                                    <button type="button" class="cu-link-btn" @click="reglas[campo.clave].push({ valor: '', siglas: '', patron: '' })">+ Agregar regla</button>
                                </div>

                                <div class="cu-nom-bloque">
                                    <span class="cu-field-label">Dimensiones propias <span class="cu-hint">(producto, sistema operativo, audiencia…)</span></span>
                                    <div v-for="(d, i) in reglas.dimensiones" :key="`d${i}`" class="cu-nom-dim">
                                        <div class="cu-nom-regla">
                                            <input v-model="d.nombre" class="cu-input" placeholder="Nombre, ej. Producto" :aria-label="`Nombre de la dimensión ${i + 1}`" @input="guardado = ''">
                                            <button type="button" class="cu-link-btn" @click="reglas.dimensiones.splice(i, 1); guardado = ''">Quitar dimensión</button>
                                        </div>
                                        <div v-for="(r, k) in d.reglas" :key="`d${i}r${k}`" class="cu-nom-regla">
                                            <input v-model="r.valor" class="cu-input" placeholder="valor, ej. TDD" :aria-label="`Valor de ${d.nombre}`" @input="guardado = ''">
                                            <input v-model="r.siglas" class="cu-input mono" placeholder="siglas" :aria-label="`Siglas de ${d.nombre}`" @input="guardado = ''">
                                            <input v-model="r.patron" class="cu-input mono" placeholder="o patrón" :aria-label="`Patrón de ${d.nombre}`" @input="guardado = ''">
                                            <button type="button" class="cu-link-btn" @click="d.reglas.splice(k, 1); guardado = ''">Quitar</button>
                                        </div>
                                        <button type="button" class="cu-link-btn" @click="d.reglas.push({ valor: '', siglas: '', patron: '' })">+ Agregar valor</button>
                                    </div>
                                    <button v-if="reglas.dimensiones.length < 6" type="button" class="cu-btn cu-btn-sm cu-btn-ghost" @click="reglas.dimensiones.push({ nombre: '', reglas: [{ valor: '', siglas: '', patron: '' }] })">Agregar dimensión</button>
                                </div>

                                <div class="cu-nom-bloque">
                                    <span class="cu-field-label">Catálogo de creativos <span class="cu-hint">(patrón → creativo; agrupa el mismo creativo aunque cambie la campaña)</span></span>
                                    <span class="cu-hint">
                                        Pega tu catálogo: copia las dos columnas de tu hoja (patrón y nombre del creativo) y pégalas aquí.
                                        En Spark Ads el patrón se busca en el texto del post. Gana la primera que coincide.
                                    </span>
                                    <textarea v-model="catalogoPegado" class="cu-input mono cu-textarea" rows="3" spellcheck="false" placeholder="(?i)_outfit-match|match con tu tarjeta	Outfit Match" aria-label="Pegar catálogo de creativos" />
                                    <div class="cu-nom-acciones" style="margin-top: 0">
                                        <button type="button" class="cu-btn cu-btn-sm" :disabled="!catalogoPegado.trim()" @click="agregarCatalogo">Agregar al catálogo</button>
                                        <span class="cu-hint">{{ reglas.catalogo.length }} regla(s){{ catalogoPorPost ? ` · ${catalogoPorPost} asignadas por post desde "Anuncios sin creativo"` : '' }}</span>
                                    </div>
                                    <span v-if="catalogoAviso" class="cu-hint" style="color: var(--mint)">{{ catalogoAviso }}</span>
                                    <template v-if="reglas.catalogo.length">
                                        <input v-model="catalogoBusqueda" class="cu-input" style="height: 34px; max-width: 320px" placeholder="Buscar en el catálogo…" aria-label="Buscar en el catálogo">
                                        <div v-for="{ c, i } in catalogoFiltrado.slice(0, CATALOGO_VISIBLE)" :key="`cat${i}`" class="cu-nom-regla">
                                            <input v-model="c.valor" class="cu-input" placeholder="creativo" :aria-label="`Creativo de la regla ${i + 1}`" @input="guardado = ''">
                                            <span v-if="c.post_id" class="cu-input mono" style="display: flex; align-items: center; color: var(--text-muted)" :title="`Post de TikTok ${c.post_id}`">post {{ c.post_id }}</span>
                                            <input v-else v-model="c.patron" class="cu-input mono" placeholder="patrón" :aria-label="`Patrón de la regla ${i + 1}`" @input="guardado = ''">
                                            <span />
                                            <button type="button" class="cu-link-btn" @click="reglas.catalogo.splice(i, 1); guardado = ''">Quitar</button>
                                        </div>
                                        <span v-if="catalogoFiltrado.length > CATALOGO_VISIBLE" class="cu-hint">Mostrando {{ CATALOGO_VISIBLE }} de {{ catalogoFiltrado.length }}; busca para encontrar las demás.</span>
                                    </template>
                                </div>

                                <div class="cu-nom-bloque">
                                    <span class="cu-field-label">Arte por expresión regular <span class="cu-hint">(respaldo si el catálogo no reconoce el anuncio)</span></span>
                                    <input v-model="reglas.arte" class="cu-input mono" placeholder="Expresión regular; el grupo 1 es el arte" aria-label="Regla del arte" @input="guardado = ''">
                                </div>
                            </div>

                            <!-- Paso 6: vista previa -->
                            <div v-if="vistaPrevia.length" class="cu-nom-paso">
                                <div class="cu-nom-paso-head"><span class="cu-nom-num">6</span><span class="cu-section-title">Vista previa</span></div>
                                <div v-if="resumen.etapas.some((x) => x.total) || resumen.tipos.some((x) => x.total)" class="cu-nom-resumen">
                                    <span v-for="x in [...resumen.tipos, ...resumen.etapas].filter((y) => y.total)" :key="x.nombre" class="cu-badge" :class="x.aciertos === x.total ? 'cu-badge-on' : 'cu-badge-off'">
                                        {{ x.nombre }}: {{ x.aciertos }} de {{ x.total }}
                                    </span>
                                </div>
                                <div class="cu-nom-preview">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Nombre</th><th>Esperado</th><th>¿De la cuenta?</th><th>Tipo</th><th>Etapa</th><th>Plataforma</th><th>Formato</th>
                                                <th v-for="col in columnasDimension" :key="col.clave">{{ col.nombre }}</th>
                                                <th>Arte</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(f, i) in vistaPrevia" :key="i" :class="{ incompleta: filaMal(f) }">
                                                <td class="mono cu-nom-nombre" :title="f.nombre"><span class="cu-nom-tipo">{{ NIVEL_CORTO[f.tipo] }}</span>{{ f.nombre }}</td>
                                                <td>{{ f.esperado || '' }}</td>
                                                <td><span class="cu-badge" :class="f.es_de_la_cuenta ? 'cu-badge-on' : 'cu-badge-off'">{{ f.es_de_la_cuenta ? 'Sí' : 'No' }}</span></td>
                                                <td :title="f.reglas_aplicadas?.tipo_campania">{{ f.tipo_campania || '—' }}</td>
                                                <td :title="f.reglas_aplicadas?.funnel">{{ f.funnel_propio || f.funnel || '—' }}</td>
                                                <td class="mono" :title="f.reglas_aplicadas?.plataforma">{{ f.plataforma || '—' }}</td>
                                                <td class="mono">{{ f.formato || '—' }}</td>
                                                <td v-for="col in columnasDimension" :key="col.clave">{{ f.dimensiones?.[col.clave] || '—' }}</td>
                                                <td class="mono">{{ f.tipo === 'anuncio' ? (f.arte || '—') : '' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p class="cu-hint" style="padding: 10px 12px">En rojo: ejemplos que no salen como esperabas o anuncios que no se leerían bien. Pasa el cursor sobre un valor para ver qué regla lo clasificó. Ajusta y vuelve a probar antes de guardar.</p>
                                </div>
                            </div>
                            </template>
                        </section>

                        <div class="cu-actionbar">
                            <button v-if="!nueva && activa && puedeDesactivar" type="button" class="cu-btn cu-btn-danger" @click="modalAbierto = true; confirmacion = ''; errorModal = ''">
                                <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0" /></svg>
                                Desactivar cuenta
                            </button>
                            <button v-if="!nueva && !activa && puedeDesactivar" type="button" class="cu-btn" @click="reactivar">
                                <svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0" /></svg>
                                Reactivar cuenta
                            </button>
                            <div style="flex: 1" />
                            <span v-if="guardado" class="cu-saved" role="status"><span aria-hidden="true">✓</span>{{ guardado }}</span>
                            <Link class="cu-btn cu-btn-ghost" href="/cuentas">Cancelar</Link>
                            <button type="button" class="cu-btn cu-btn-primary" :disabled="guardando" @click="guardar">
                                {{ guardando ? 'Guardando…' : (nueva ? 'Crear cuenta' : 'Guardar') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="modalAbierto" class="cu-overlay" @click.self="modalAbierto = false">
                <div class="cu-modal" role="dialog" aria-modal="true" aria-labelledby="m-titulo">
                    <div class="cu-modal-ic"><svg class="cu-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0" /></svg></div>
                    <h2 id="m-titulo" class="cu-panel-title" style="font-size: 20px; margin: 0 0 6px">¿Desactivar {{ cuenta.nombre }}?</h2>
                    <p class="cu-hint" style="font-size: 13px">La cuenta deja de aparecer en el selector y sus datos dejan de actualizarse.</p>
                    <ul>
                        <li>Los datos históricos se conservan.</li>
                        <li>Sus conexiones se pausan, no se borran.</li>
                        <li>Puedes reactivarla desde esta misma página.</li>
                    </ul>
                    <div class="cu-field" style="margin-top: 20px">
                        <label class="cu-field-label" for="m-confirmar">Escribe <span class="mono" style="color: var(--coral)">{{ cuenta.nombre }}</span> para confirmar</label>
                        <input id="m-confirmar" v-model="confirmacion" class="cu-input" autocomplete="off">
                        <span v-if="errorModal" class="cu-field-err">{{ errorModal }}</span>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; flex-wrap: wrap">
                        <button type="button" class="cu-btn cu-btn-ghost" @click="modalAbierto = false">Cancelar</button>
                        <button type="button" class="cu-btn cu-btn-danger-solid" :disabled="confirmacion.trim() !== cuenta.nombre" @click="desactivar">Desactivar cuenta</button>
                    </div>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>
