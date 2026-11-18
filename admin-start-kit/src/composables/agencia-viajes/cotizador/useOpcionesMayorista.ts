import { ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useToast } from '@/composables/useToast';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import { generarUuidGrupo } from '@/helpers/agencia-viajes/cotizador/grupoOpcion';
import type { Alternativa, AlternativaDestino, AlternativaItem, OpcionMayorista } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que el comparador de mayoristas necesita del núcleo, del lienzo y del drawer. */
export interface DependenciasOpcionesMayorista {
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    cargarCotizacion: () => Promise<void>;
    alternativaDestinos: ComputedRef<AlternativaDestino[]>;
    destinoActivoId: Ref<number | null>;
    mostrarChipsDestino: ComputedRef<boolean>;
    diaActivoParaAgregar: ComputedRef<number>;
    cantidadAdultosCotizacion: ComputedRef<number>;
    onServicioSueltoAgregado: (item: AlternativaItem, alternativa?: Partial<Alternativa> | null) => Promise<void>;
}

/**
 * Comparador de mayoristas (modo Internacional): listar las opciones de la alternativa, elegir,
 * descartar, reactivar, eliminar, y agregar al lienzo un hotel o un grupo de hoteles de la matriz.
 * No crea watchers: los que cruzan bloques (`modo`, `destinoActivoId`) viven en la página.
 */
export function useOpcionesMayorista(deps: DependenciasOpcionesMayorista) {
    const {
        alternativaActiva, cargarCotizacion, alternativaDestinos, destinoActivoId, mostrarChipsDestino,
        diaActivoParaAgregar, cantidadAdultosCotizacion, onServicioSueltoAgregado,
    } = deps;
    const toast = useToast();

    const opcionesMayorista = ref<OpcionMayorista[]>([]);
    const opcionHotelesActivaId = ref<number | null>(null);
    const mostrarFormMayorista = ref(false);
    const opcionMayoristaEnEdicionId = ref<number | null>(null);

    // El comparador actúa sobre el destino activo: el backend manda todas las opciones de la
    // alternativa y el filtro es del lado del cliente (sin endpoint nuevo).
    const cargarOpcionesMayorista = async () => {
        if (!alternativaActiva.value) return;
        const res = await opcionMayoristaService.listar(alternativaActiva.value.id);
        const primerDestinoId = alternativaDestinos.value[0]?.id ?? null;
        opcionesMayorista.value = mostrarChipsDestino.value
            ? res.opciones_mayorista.filter((op: OpcionMayorista) => (op.alternativa_destino_id ?? primerDestinoId) === destinoActivoId.value)
            : res.opciones_mayorista;
    };

    const verHoteles = (op: OpcionMayorista) => {
        opcionHotelesActivaId.value = opcionHotelesActivaId.value === op.id ? null : op.id;
    };

    // Eliminar es borrado real (distinto de descartar, que solo oculta). El backend responde 422 si
    // algún ítem de la opción ya tiene reserva o si la alternativa fue aceptada; se muestra tal cual.
    const eliminandoOpcionMayoristaId = ref<number | null>(null);
    const eliminarOpcionMayorista = async (op: OpcionMayorista) => {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Eliminar esta opción de mayorista?',
            text: 'Se eliminan también sus hoteles, opcionales y tours vinculados (el contenido real de los tours no se borra, solo el vínculo), y cualquier ítem ya agregado al lienzo desde esta opción. Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        eliminandoOpcionMayoristaId.value = op.id;
        try {
            await opcionMayoristaService.eliminar(op.id);
            toast.success('Opción de mayorista eliminada');
            await Promise.all([cargarCotizacion(), cargarOpcionesMayorista()]);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la opción', 'error');
        } finally {
            eliminandoOpcionMayoristaId.value = null;
        }
    };

    const eligiendoOpcionId = ref<number | null>(null);
    const elegirOpcion = async (op: OpcionMayorista) => {
        eligiendoOpcionId.value = op.id;
        try {
            await opcionMayoristaService.elegir(op.id);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo elegir esta opción', 'error');
        } finally {
            eligiendoOpcionId.value = null;
        }
    };

    const descartandoOpcionId = ref<number | null>(null);
    const descartarOpcion = async (op: OpcionMayorista) => {
        descartandoOpcionId.value = op.id;
        try {
            await opcionMayoristaService.descartar(op.id);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo descartar esta opción', 'error');
        } finally {
            descartandoOpcionId.value = null;
        }
    };

    const reactivandoOpcionId = ref<number | null>(null);
    const reactivarOpcion = async (op: OpcionMayorista) => {
        reactivandoOpcionId.value = op.id;
        try {
            await opcionMayoristaService.reactivar(op.id);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo reactivar esta opción', 'error');
        } finally {
            reactivandoOpcionId.value = null;
        }
    };

    const abrirFormEditarOpcionMayorista = (op: OpcionMayorista) => {
        opcionMayoristaEnEdicionId.value = op.id;
        mostrarFormMayorista.value = false;
    };

    // Compartido por el formulario inline de edición y el de "agregar nueva": cierra el que esté
    // abierto y refresca.
    const onOpcionMayoristaGuardada = async (opcion?: OpcionMayorista) => {
        mostrarFormMayorista.value = false;
        opcionMayoristaEnEdicionId.value = null;
        await cargarOpcionesMayorista();
        // La primera opción nace elegida (backend): su sección de hoteles queda abierta.
        if (opcion?.estado === 'elegida') opcionHotelesActivaId.value = opcion.id;
    };

    const agregarItemMayorista = async (op: OpcionMayorista, opcionHotelTarifaId: number, cantidad: number) => {
        if (!alternativaActiva.value) return;
        try {
            const res = await alternativaItemService.agregarMayorista(alternativaActiva.value.id, {
                opcion_mayorista_id: op.id,
                opcion_hotel_tarifa_id: opcionHotelTarifaId,
                cantidad,
                dia_referencial: diaActivoParaAgregar.value,
                alternativa_destino_id: destinoActivoId.value,
            });
            await onServicioSueltoAgregado(res.alternativa_item, res.alternativa);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
        }
    };

    // Varios hoteles para comparar. `precio_venta` es por persona: la cantidad es el total de
    // adultos de la cotización, si no el total del grupo saldría por una fracción del real.
    const agregarGrupoMayorista = async (op: OpcionMayorista, opcionHotelTarifaIds: number[]) => {
        if (!alternativaActiva.value) return;
        const grupoOpcionId = generarUuidGrupo();
        try {
            let ultimoItem: AlternativaItem | null = null;
            for (const opcionHotelTarifaId of opcionHotelTarifaIds) {
                const res = await alternativaItemService.agregarMayorista(alternativaActiva.value.id, {
                    opcion_mayorista_id: op.id,
                    opcion_hotel_tarifa_id: opcionHotelTarifaId,
                    cantidad: cantidadAdultosCotizacion.value,
                    dia_referencial: diaActivoParaAgregar.value,
                    alternativa_destino_id: destinoActivoId.value,
                    grupo_opcion_id: grupoOpcionId,
                });
                ultimoItem = res.alternativa_item;
            }
            if (ultimoItem) await onServicioSueltoAgregado(ultimoItem);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar el grupo', 'error');
        }
    };

    return {
        opcionesMayorista, opcionHotelesActivaId, mostrarFormMayorista, opcionMayoristaEnEdicionId,
        cargarOpcionesMayorista, verHoteles, eliminandoOpcionMayoristaId, eliminarOpcionMayorista,
        eligiendoOpcionId, elegirOpcion, descartandoOpcionId, descartarOpcion, reactivandoOpcionId, reactivarOpcion,
        abrirFormEditarOpcionMayorista, onOpcionMayoristaGuardada, agregarItemMayorista, agregarGrupoMayorista,
    };
}
