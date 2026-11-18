import { computed, nextTick, ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useModificacionReserva, mensajeDeError, centavosATexto, motivoDePenalidad, textoMonto } from '@/composables/agencia-viajes/useModificacionReserva';
import { reservaModificacionService, type PenalidadPayload, type PasajeroEdadEstimada } from '@/services/admin/reservaModificacionService';
import type { ModificacionDeCotizacion } from '@/services/admin/cotizacionService';
import { imprimirComprobante } from '@/composables/usePrintComprobante';
import type { AvisoSalida } from '@/helpers/avisosProveedores';
import type { AlternativaDestino, AlternativaItem } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que la modificación de reserva necesita del núcleo, de los destinos y de los días. */
export interface DependenciasModificacion {
    modificacionInfo: Ref<ModificacionDeCotizacion | null>;
    itemsSinAjuste: ComputedRef<AlternativaItem[]>;
    cargarCotizacion: () => Promise<void>;
    navegar: (ruta: string) => void;
    alternativaDestinos: ComputedRef<AlternativaDestino[]>;
    destinoActivoId: Ref<number | null>;
    mostrarChipsDestino: ComputedRef<boolean>;
    diaActivo: Ref<number>;
    mostrarTabsDia: ComputedRef<boolean>;
}

/**
 * T-007: la cotización de una modificación de reserva (origen='modificacion') trabaja con el panel
 * «Diferencia». El panel solo muestra lo que calcula el backend; aplicar manda la huella que se vio.
 * Acá viven la penalidad por pasajero quitado, aplicar/descartar, el PDF de la modificación y el
 * resaltado del ítem a revisar. Usa el `useModificacionReserva` de la reserva; no crea watchers.
 */
export function useModificacionEnCotizador(deps: DependenciasModificacion) {
    const {
        modificacionInfo, itemsSinAjuste, cargarCotizacion, navegar, alternativaDestinos, destinoActivoId,
        mostrarChipsDestino, diaActivo, mostrarTabsDia,
    } = deps;

    const reservaIdModificacion = computed(() => modificacionInfo.value?.reserva_id ?? null);
    const modificacion = useModificacionReserva(reservaIdModificacion);
    const avisoEdadEstimada = ref<PasajeroEdadEstimada[]>([]);
    const procesandoModificacion = ref(false);
    const descargandoPdfModificacion = ref(false);

    const idsParaRevisar = computed(() => new Set(
        (modificacion.diferencia.value?.bloqueos ?? [])
            .filter((b) => (b.tipo === 'para_revisar' || b.tipo === 'servicio_sin_pasajeros') && b.alternativa_item_id)
            .map((b) => b.alternativa_item_id as number)
    ));
    // «Revisado» solo donde el backend deja confirmar (las «sin pasajeros» se arreglan en el lienzo).
    const idsParaRevisarConfirmables = computed(() => new Set(
        (modificacion.diferencia.value?.bloqueos ?? [])
            .filter((b) => b.tipo === 'para_revisar' && b.alternativa_item_id)
            .map((b) => b.alternativa_item_id as number)
    ));

    const itemResaltadoId = ref<number | null>(null);
    const claseResaltado = (ids: number[]) => ({
        'border-warning bg-warning-subtle': ids.some((id) => idsParaRevisar.value.has(id)),
        'resaltado-item': itemResaltadoId.value !== null && ids.includes(itemResaltadoId.value),
    });

    // Lleva al ítem: cambia al destino y día donde está, lo resalta unos segundos y hace scroll.
    const irAItem = async (id: number) => {
        const item = itemsSinAjuste.value.find((i) => i.id === id);
        if (!item) return;
        if (mostrarChipsDestino.value) destinoActivoId.value = item.alternativa_destino_id ?? alternativaDestinos.value[0]?.id ?? null;
        if (mostrarTabsDia.value) diaActivo.value = item.dia_referencial ?? 0;
        itemResaltadoId.value = id;
        await nextTick();
        document.querySelector(`[data-item-id~="${id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => { if (itemResaltadoId.value === id) itemResaltadoId.value = null; }, 3000);
    };

    const confirmarRevisados = async (ids: number[]) => {
        try {
            await modificacion.confirmarRevisados(ids);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo confirmar', 'error');
            await modificacion.cargar();
        }
    };

    const abrirComprobanteBloqueo = (saleId: number) => { void imprimirComprobante(saleId); };

    // Penalidad por pasajero quitado (R-AGE-05): IGV y destino empiezan vacíos.
    const penalidadPasajero = ref<{ reserva_pasajero_id: number; nombre: string | null } | null>(null);
    const penalidadItemId = ref<number | null>(null);
    const penalidadInicial = ref<{ monto: string; motivo: string; tip_afe_igv: string | null; destino_tributario: string | null } | null>(null);
    const guardandoPenalidad = ref(false);
    const errorPenalidad = ref<string | null>(null);

    const abrirPenalidad = (reservaPasajeroId: number) => {
        const dif = modificacion.diferencia.value;
        const pasajero = dif?.pasajeros.find((p) => p.reserva_pasajero_id === reservaPasajeroId);
        if (!dif || !pasajero) return;
        const linea = pasajero.penalidad_alternativa_item_id
            ? dif.lineas.find((l) => l.alternativa_item_id === pasajero.penalidad_alternativa_item_id)
            : undefined;
        penalidadItemId.value = pasajero.penalidad_alternativa_item_id;
        penalidadInicial.value = linea
            ? { monto: centavosATexto(linea.nuevo_centavos), motivo: motivoDePenalidad(linea.descripcion), tip_afe_igv: linea.tip_afe_igv, destino_tributario: linea.destino_tributario }
            : null;
        errorPenalidad.value = null;
        penalidadPasajero.value = { reserva_pasajero_id: reservaPasajeroId, nombre: pasajero.nombre };
    };

    const guardarPenalidad = async (payload: PenalidadPayload) => {
        if (!penalidadPasajero.value) return;
        guardandoPenalidad.value = true;
        errorPenalidad.value = null;
        try {
            await modificacion.guardarPenalidad(penalidadPasajero.value.reserva_pasajero_id, payload, penalidadItemId.value);
            penalidadPasajero.value = null;
            await cargarCotizacion();
        } catch (error: any) {
            errorPenalidad.value = error.response?.data?.message ?? 'No se pudo guardar la penalidad';
        } finally {
            guardandoPenalidad.value = false;
        }
    };

    const borrarPenalidad = async (itemId: number) => {
        const confirmacion = await (Swal as TVueSwalInstance).fire({ title: '¿Borrar la penalidad?', icon: 'question', showCancelButton: true, confirmButtonText: 'Sí, borrar', cancelButtonText: 'Cancelar' });
        if (!confirmacion.isConfirmed) return;
        try {
            await modificacion.borrarPenalidad(itemId);
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo borrar la penalidad', 'error');
        }
    };

    const descargarPdfModificacion = async () => {
        if (!modificacionInfo.value) return;
        descargandoPdfModificacion.value = true;
        try {
            const blob = await reservaModificacionService.pdf(modificacionInfo.value.reserva_id);
            const blobUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.setAttribute('download', `modificacion-${modificacionInfo.value.reserva_codigo ?? modificacionInfo.value.reserva_id}.pdf`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(blobUrl);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('No se pudo generar el PDF', await mensajeDeError(error, 'No se pudo generar el PDF'), 'warning');
        } finally {
            descargandoPdfModificacion.value = false;
        }
    };

    // Mensaje para la reserva (se muestra al volver): aplicar, descartar.
    const dejarMensajeParaReserva = (reservaId: number, datos: { mensaje: string; alerta_cupo_excedido?: boolean; avisos_salidas?: AvisoSalida[] }) => {
        try { sessionStorage.setItem(`modificacion-resultado-${reservaId}`, JSON.stringify(datos)); } catch { /* sin storage: no se muestra el mensaje */ }
    };

    const descartarModificacion = async () => {
        if (!modificacionInfo.value) return;
        const reservaId = modificacionInfo.value.reserva_id;
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Descartar la modificación?', text: 'La reserva no cambia. Se pierde lo que armaste en esta versión.', icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Sí, descartar', cancelButtonText: 'Seguir editando',
        });
        if (!confirmacion.isConfirmed) return;
        procesandoModificacion.value = true;
        try {
            const res = await reservaModificacionService.descartar(reservaId);
            dejarMensajeParaReserva(reservaId, { mensaje: res.message });
            navegar(`/agencia-viajes/reservas/${reservaId}`);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo descartar', 'error');
            await cargarCotizacion();
        } finally {
            procesandoModificacion.value = false;
        }
    };

    const aplicarModificacion = async () => {
        const dif = modificacion.diferencia.value;
        if (!modificacionInfo.value || !dif) return;
        const reservaId = modificacionInfo.value.reserva_id;
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Aplicar a la reserva?',
            text: `Diferencia: ${textoMonto(dif.totales.diferencia, dif.moneda)}. La reserva pasa a la versión nueva y no se puede deshacer.`,
            icon: 'question', showCancelButton: true, confirmButtonText: 'Sí, aplicar', cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;
        procesandoModificacion.value = true;
        try {
            const res = await modificacion.aplicar();
            dejarMensajeParaReserva(reservaId, { mensaje: res.message, alerta_cupo_excedido: res.alerta_cupo_excedido, avisos_salidas: res.avisos_salidas });
            navegar(`/agencia-viajes/reservas/${reservaId}`);
        } catch (error: any) {
            const mensaje: string = error.response?.data?.message ?? 'No se pudo aplicar la modificación';
            const estado = error.response?.status;
            if (estado === 404 || mensaje.startsWith('Esta modificación ya no está abierta')) {
                // Se aplicó o descartó en otra pestaña: se recarga para ver el estado real.
                await (Swal as TVueSwalInstance).fire('La reserva cambió', 'Esta modificación ya no está abierta (se aplicó o descartó en otro lugar).', 'warning');
                await cargarCotizacion();
            } else if (mensaje.startsWith('La reserva cambió')) {
                await modificacion.cargar();
                await (Swal as TVueSwalInstance).fire('La reserva cambió', `${mensaje} Ya se volvió a calcular la diferencia.`, 'warning');
            } else if (mensaje.includes('cambia el mayorista')) {
                await (Swal as TVueSwalInstance).fire('No se puede aplicar desde aquí', mensaje, 'warning');
            } else {
                await modificacion.cargar();
                await (Swal as TVueSwalInstance).fire('No se pudo aplicar', mensaje, 'warning');
            }
        } finally {
            procesandoModificacion.value = false;
        }
    };

    return {
        modificacion, avisoEdadEstimada, procesandoModificacion, descargandoPdfModificacion, idsParaRevisar,
        idsParaRevisarConfirmables, claseResaltado, irAItem, confirmarRevisados, abrirComprobanteBloqueo,
        penalidadPasajero, penalidadInicial, guardandoPenalidad, errorPenalidad, abrirPenalidad, guardarPenalidad,
        borrarPenalidad, descargarPdfModificacion, descartarModificacion, aplicarModificacion,
    };
}
