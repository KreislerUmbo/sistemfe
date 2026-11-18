import { computed, ref, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { escaparHtml } from '@/helpers/escaparHtml';
import { useToast } from '@/composables/useToast';
import { cotizacionService } from '@/services/admin/cotizacionService';
import type { Alternativa, AlternativaItem, Cotizacion } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que los pasajeros necesitan del núcleo. */
export interface DependenciasPasajeros {
    cotizacion: Ref<Cotizacion | null>;
    alternativaActiva: Readonly<Ref<Alternativa | null>>;
    cargarCotizacion: () => Promise<void>;
}

const etiquetasPaxResumen: Record<string, [string, string]> = {
    adulto: ['adulto', 'adultos'],
    nino: ['niño', 'niños'],
    infante: ['infante', 'infantes'],
};

const esItemPaqueteMayorista = (item: AlternativaItem) => item.origen_tipo === 'mayorista' && !!item.opcion_hotel_tarifa_id;

/**
 * Pasajeros de la cotización: resúmenes de «cuántos viajan», avisos del paquete de mayorista (solo
 * adultos) y edición de la lista. No crea watchers.
 */
export function usePasajerosCotizacion(deps: DependenciasPasajeros) {
    const { cotizacion, alternativaActiva, cargarCotizacion } = deps;
    const toast = useToast();

    const resumenPax = computed(() => {
        const pax = cotizacion.value?.pasajeros ?? [];
        const counts: Record<string, number> = {};
        pax.forEach((p) => { counts[p.tipo_pax] = (counts[p.tipo_pax] ?? 0) + 1; });
        return Object.entries(counts).map(([t, n]) => `${n} ${t}`).join(', ') || 'sin pasajeros';
    });

    // Para cuántas personas ya está calculado el Total del panel. Mismo criterio de texto que
    // "TOTAL {N ADULTOS}" del PDF (alternativa.blade.php), pero en minúscula y entre paréntesis
    // porque acá es un subtítulo, no el título de una caja destacada.
    const resumenPaxTotal = computed(() => {
        const pax = cotizacion.value?.pasajeros ?? [];
        const counts: Record<string, number> = {};
        pax.forEach((p) => { counts[p.tipo_pax] = (counts[p.tipo_pax] ?? 0) + 1; });
        const partes = Object.entries(counts).map(([tipo, n]) => {
            const [singular, plural] = etiquetasPaxResumen[tipo] ?? [tipo, `${tipo}s`];
            return `${n} ${n === 1 ? singular : plural}`;
        });
        return partes.length ? `(${partes.join(' + ')})` : '';
    });

    // T-036 (H22): el paquete de mayorista se cotiza solo por adultos. Se hace visible:
    // la línea dice "× N adultos" y, si hay niños/infantes, el total lo avisa (sin bloquear).
    const textoAdultosMayorista = (item: AlternativaItem) => {
        if (!esItemPaqueteMayorista(item)) return '';
        const n = Number(item.cantidad) || 1;
        return `× ${n} ${n === 1 ? 'adulto' : 'adultos'}`;
    };
    const notaMayoristaSinNinos = computed(() => {
        const hayPaquete = (alternativaActiva.value?.items ?? []).some(esItemPaqueteMayorista);
        const hayNinos = (cotizacion.value?.pasajeros ?? []).some((p) => p.tipo_pax !== 'adulto');
        return hayPaquete && hayNinos;
    });

    // El precio de cada tipo de habitación de una OpcionMayorista ya es el total del paquete por
    // persona (aéreo + tours + hotel), así que la cantidad por defecto es la de pasajeros ADULTOS,
    // no 1 (que tiene sentido como "noches" en el hotel local, no acá).
    const cantidadAdultosCotizacion = computed(() => {
        const n = (cotizacion.value?.pasajeros ?? []).filter((p) => p.tipo_pax === 'adulto').length;
        return n > 0 ? n : 1;
    });

    // El backend bloquea el guardado si ya hay una alternativa aceptada (reserva real generada);
    // acá se refleja el mismo candado para no dejar el ícono habilitado sin sentido.
    const hayAlternativaAceptada = computed(() => (cotizacion.value?.alternativas ?? []).some((a) => a.estado === 'aceptada'));

    const editandoPasajeros = ref(false);
    const formPasajeros = ref<Array<{ id?: number; edad: number; residencia: 'nacional' | 'extranjero' }>>([]);
    const guardandoPasajeros = ref(false);

    const tipoPorEdadEdicion = (edad: number) => {
        if (edad <= 2) return { label: 'Infante', clase: 'bg-info-subtle text-info' };
        if (edad <= 12) return { label: 'Niño', clase: 'bg-warning-subtle text-warning' };
        return { label: 'Adulto', clase: 'bg-success-subtle text-success' };
    };

    const abrirEdicionPasajeros = () => {
        if (!cotizacion.value) return;
        formPasajeros.value = (cotizacion.value.pasajeros ?? []).map((p) => ({ id: p.id, edad: p.edad, residencia: p.residencia ?? 'nacional' }));
        editandoPasajeros.value = true;
    };

    const agregarPaxEdicion = (edadSugerida: number) => {
        formPasajeros.value.push({ edad: edadSugerida, residencia: 'nacional' });
    };

    const guardarPasajeros = async () => {
        if (!cotizacion.value || formPasajeros.value.length === 0) return;

        guardandoPasajeros.value = true;
        try {
            const res = await cotizacionService.actualizarPasajeros(cotizacion.value.id, formPasajeros.value);
            editandoPasajeros.value = false;
            await cargarCotizacion();

            const recalculados: number = res.items_recalculados ?? 0;
            const paraRevisar: Array<{ alternativa_nombre: string; motivo: string }> = res.items_para_revisar ?? [];

            if (paraRevisar.length > 0) {
                const listaHtml = paraRevisar.map((it) => `<li>${escaparHtml(it.alternativa_nombre)}: ${escaparHtml(it.motivo)}</li>`).join('');
                (Swal as TVueSwalInstance).fire({
                    icon: 'warning',
                    title: 'Pasajeros actualizados',
                    html: `${recalculados} ítem(s) recalculado(s) automáticamente.<br><br><b>${paraRevisar.length} ítem(s) necesitan revisión manual de precio:</b><ul class="text-start small mb-0">${listaHtml}</ul>`,
                });
            } else if (recalculados > 0) {
                toast.success(`Pasajeros actualizados — ${recalculados} ítem(s) recalculado(s) automáticamente.`);
            } else {
                toast.success('Pasajeros actualizados correctamente.');
            }
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar los pasajeros', 'error');
        } finally {
            guardandoPasajeros.value = false;
        }
    };

    return {
        resumenPax, resumenPaxTotal, esItemPaqueteMayorista, textoAdultosMayorista, notaMayoristaSinNinos,
        cantidadAdultosCotizacion, hayAlternativaAceptada, editandoPasajeros, formPasajeros, guardandoPasajeros,
        tipoPorEdadEdicion, abrirEdicionPasajeros, agregarPaxEdicion, guardarPasajeros,
    };
}
