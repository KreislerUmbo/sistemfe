import { ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { escaparHtml } from '@/helpers/escaparHtml';
import { useToast } from '@/composables/useToast';
import type { BloqueItem } from '@/helpers/agencia-viajes/cotizador/bloquesLienzo';
import type { Alternativa, AlternativaItem, Cotizacion, TemporadaDesactualizada } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que la línea de tour necesita del núcleo, de los precios y de los días. */
export interface DependenciasLineaTour {
    cotizacion: Ref<Cotizacion | null>;
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    alternativaSoloLectura: ComputedRef<boolean>;
    cargarCotizacion: () => Promise<void>;
    subtotalGrupo: (items: AlternativaItem[]) => number;
    diaActivo: Ref<number>;
}

/**
 * Línea de tour del lienzo: cada carga de un tour (o combo) con su precio, su desglose por
 * pasajero, sus Incluye, mover y quitar; y el aviso de temporadas desactualizadas.
 */
export function useLineaTour(deps: DependenciasLineaTour) {
    const { cotizacion, alternativaActiva, alternativaSoloLectura, cargarCotizacion, subtotalGrupo, diaActivo } = deps;
    const toast = useToast();

    const lineaTourBloqueada = (bloque: BloqueItem) =>
        alternativaSoloLectura.value || bloque.items.some((i) => !!i.reserva_item);

    // Temporada aplicada a la carga (null = precio base).
    const temporadaDeCarga = (bloque: BloqueItem) => bloque.items[0]?.tour_linea?.temporada_nombre ?? null;

    // "2 adultos × PEN 150.00 + 1 niño × PEN 130.00", con los precios con que se cotizó la línea
    // (tour_linea.precios) y la residencia de cada pasajero.
    const desglosePrecioTour = (bloque: BloqueItem): string | null => {
        const linea = bloque.items[0];
        const precios = linea?.tour_linea?.precios;
        if (!precios) return null;

        const pax = cotizacion.value?.pasajeros ?? [];
        const moneda = linea.moneda_costo;
        const tipos: Array<{ clave: 'adulto' | 'nino' | 'adulto_ext' | 'nino_ext'; tipo: 'adulto' | 'nino'; ext: boolean; etiqueta: string }> = [
            { clave: 'adulto', tipo: 'adulto', ext: false, etiqueta: 'adulto' },
            { clave: 'nino', tipo: 'nino', ext: false, etiqueta: 'niño' },
            { clave: 'adulto_ext', tipo: 'adulto', ext: true, etiqueta: 'adulto extranjero' },
            { clave: 'nino_ext', tipo: 'nino', ext: true, etiqueta: 'niño extranjero' },
        ];
        const partes = tipos.flatMap((t) => {
            const n = pax.filter((p) => p.tipo_pax === t.tipo && (p.residencia === 'extranjero') === t.ext).length;
            return n > 0 ? [`${n} ${t.etiqueta}${n > 1 ? 's' : ''} × ${moneda} ${Number(precios[t.clave]).toFixed(2)}`] : [];
        });
        const infantes = pax.filter((p) => p.tipo_pax === 'infante').length;
        if (infantes) partes.push(`${infantes} infante${infantes > 1 ? 's' : ''} sin costo`);

        return partes.length ? partes.join(' + ') : null;
    };

    // Incluye del tour con el costo por persona copiado al cotizar.
    const incluyeDeCarga = (bloque: BloqueItem) => bloque.items.flatMap((i) => i.desglose_costo_snapshot ?? []);
    const incluyeAbierto = ref<Set<string>>(new Set());
    const toggleIncluye = (carga: string) => {
        if (incluyeAbierto.value.has(carga)) incluyeAbierto.value.delete(carga);
        else incluyeAbierto.value.add(carga);
    };

    const guardandoPrecioCarga = ref<string | null>(null);
    // H13: lo que se está escribiendo en el precio de un tour. Vue vuelve a fijar el `value` del
    // campo en cada re-render; sin este borrador, guardar otro ítem borraba el texto a medio escribir.
    // Se descarta al confirmar el campo (@change), se guarde o no.
    const borradorPrecioCarga = ref<Record<string, string>>({});
    const onPrecioCarga = async (bloque: BloqueItem, event: Event) => {
        const precio = Number((event.target as HTMLInputElement).value);
        if (bloque.carga) delete borradorPrecioCarga.value[bloque.carga];
        if (!alternativaActiva.value || !bloque.carga || Number.isNaN(precio) || precio < 0) return;
        if (Math.abs(precio - subtotalGrupo(bloque.items)) < 0.005) return;

        guardandoPrecioCarga.value = bloque.carga;
        borradorPrecioCarga.value[bloque.carga] = String(precio); // se muestra lo confirmado mientras guarda
        try {
            await alternativaItemService.precioCarga(alternativaActiva.value.id, bloque.carga, precio);
            await cargarCotizacion();
            toast.success('Precio del tour actualizado');
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cambiar el precio', 'error');
        } finally {
            if (bloque.carga) delete borradorPrecioCarga.value[bloque.carga];
            guardandoPrecioCarga.value = null;
        }
    };

    // Un combo se mueve entero (conserva la distancia entre sus días).
    const onMoverCarga = async (bloque: BloqueItem, event: Event) => {
        const valor = Number((event.target as HTMLSelectElement).value);
        const carga = bloque.cargaCombo ?? bloque.carga;
        if (!alternativaActiva.value || !carga || !valor || valor === diaActivo.value) return;
        try {
            await alternativaItemService.moverCarga(alternativaActiva.value.id, { carga, dia_referencial: valor });
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo mover el tour', 'error');
        }
    };

    const eliminandoCarga = ref<string | null>(null);
    const eliminarCargaTour = async (bloque: BloqueItem) => {
        if (!alternativaActiva.value || !bloque.carga) return;

        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: `¿Quitar "${bloque.tourNombre ?? 'este tour'}"?`,
            text: bloque.cargaCombo ? `Es parte del combo "${bloque.comboNombre}": puedes quitar solo este tour o el combo entero.` : 'Se quita el tour de esta alternativa.',
            icon: 'warning',
            showCancelButton: true,
            showDenyButton: !!bloque.cargaCombo,
            confirmButtonText: bloque.cargaCombo ? 'Solo este tour' : 'Sí, quitar',
            denyButtonText: 'Todo el combo',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed && !confirmacion.isDenied) return;

        const carga = confirmacion.isDenied ? bloque.cargaCombo! : bloque.carga;
        eliminandoCarga.value = bloque.carga;
        try {
            await alternativaItemService.eliminarCarga(alternativaActiva.value.id, carga);
            toast.success('Tour quitado');
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo quitar el tour', 'error');
        } finally {
            eliminandoCarga.value = null;
            await cargarCotizacion();
        }
    };

    // Tours cuya temporada ya no es la de su día (cambió la fecha de la cotización o se movió el
    // tour de día). Solo se avisa; recalcular es a pedido del vendedor.
    const temporadasDesactualizadas = ref<TemporadaDesactualizada[]>([]);
    const cargarTemporadasTours = async () => {
        const alt = alternativaActiva.value;
        if (!alt || !['borrador', 'enviada'].includes(alt.estado) || !(alt.items ?? []).some((i) => i.origen_tipo === 'tour')) {
            temporadasDesactualizadas.value = [];
            return;
        }
        try {
            temporadasDesactualizadas.value = (await alternativaItemService.temporadasTours(alt.id)).temporadas_desactualizadas;
        } catch {
            temporadasDesactualizadas.value = [];
        }
    };
    const recalculandoTemporadas = ref(false);
    const recalcularTemporadas = async () => {
        if (!alternativaActiva.value) return;
        recalculandoTemporadas.value = true;
        try {
            const res = await alternativaItemService.recalcularTemporadas(alternativaActiva.value.id);
            await cargarCotizacion();
            if (res.omitidas.length) {
                (Swal as TVueSwalInstance).fire({
                    icon: 'info',
                    title: `${res.recalculadas} tour(s) recalculado(s)`,
                    html: `<p class="text-start mb-1">No se tocaron:</p><ul class="text-start small mb-0">${res.omitidas.map((o) => `<li>${escaparHtml(o)}</li>`).join('')}</ul>`,
                });
            } else {
                toast.success('Precios por temporada recalculados');
            }
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo recalcular', 'error');
        } finally {
            recalculandoTemporadas.value = false;
        }
    };

    return {
        lineaTourBloqueada,
        temporadaDeCarga,
        desglosePrecioTour,
        incluyeDeCarga,
        incluyeAbierto,
        toggleIncluye,
        guardandoPrecioCarga,
        borradorPrecioCarga,
        onPrecioCarga,
        onMoverCarga,
        eliminandoCarga,
        eliminarCargaTour,
        temporadasDesactualizadas,
        cargarTemporadasTours,
        recalculandoTemporadas,
        recalcularTemporadas,
    };
}
