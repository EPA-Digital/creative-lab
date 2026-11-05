import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Filtro "Tipo de campaña" (clasificación propia de cada cuenta, ver
// ReglasNomenclatura): los tipos que la cuenta definió (ej. Openbank:
// Performance / Branding) más "Sin tipo". Las cuentas sin tipos (TaDa) no
// lo muestran -- siguen con su filtro DTC/BRD.
export function useFiltroTipoCampania(creativos) {
    const tipos = computed(() => usePage().props.cuentaActual?.tiposCampania ?? []);
    const seleccionado = ref('TODOS');
    const visible = computed(() => tipos.value.length > 0);
    const opciones = computed(() => [
        { key: 'TODOS', label: 'Todos' },
        ...tipos.value.map((t) => ({ key: t.nombre, label: t.nombre })),
        { key: 'SIN_TIPO', label: 'Sin tipo' },
    ]);
    const tipoDe = (c) => c.tipo_campania_nombre || 'SIN_TIPO';
    const counts = computed(() => {
        const n = { TODOS: creativos.value.length };
        for (const c of creativos.value) n[tipoDe(c)] = (n[tipoDe(c)] || 0) + 1;
        return n;
    });
    const filtrados = computed(() => (seleccionado.value === 'TODOS'
        ? creativos.value
        : creativos.value.filter((c) => tipoDe(c) === seleccionado.value)));

    return { visible, opciones, seleccionado, counts, filtrados };
}
