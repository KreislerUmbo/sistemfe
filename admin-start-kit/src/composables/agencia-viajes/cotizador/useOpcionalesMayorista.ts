import { ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useToast } from '@/composables/useToast';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import type { Alternativa, AlternativaItem, OpcionMayorista, OpcionMayoristaOpcional, OpcionMayoristaTour } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que los opcionales y tours de un mayorista necesitan del núcleo, del lienzo y del drawer. */
export interface DependenciasOpcionalesMayorista {
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    destinoActivoId: Ref<number | null>;
    diaActivoParaAgregar: ComputedRef<number>;
    cantidadAdultosCotizacion: ComputedRef<number>;
    cargarOpcionesMayorista: () => Promise<void>;
    onServicioSueltoAgregado: (item: AlternativaItem, alternativa?: Partial<Alternativa> | null) => Promise<void>;
}

const formOpcionalVacio = () => ({
    nombre: '', precio_por_persona: 0, moneda: 'USD' as 'PEN' | 'USD', incluye: '', no_incluye: '',
});

/**
 * Opcionales (San Blas, Taboga…) y tours incluidos de una opción de mayorista: alta, edición,
 * borrado y agregar un opcional al lienzo. No crea watchers.
 */
export function useOpcionalesMayorista(deps: DependenciasOpcionalesMayorista) {
    const {
        alternativaActiva, destinoActivoId, diaActivoParaAgregar, cantidadAdultosCotizacion,
        cargarOpcionesMayorista, onServicioSueltoAgregado,
    } = deps;
    const toast = useToast();

    // ── Opcionales ───────────────────────────────────────────────────────
    const mostrarOpcionalesId = ref<number | null>(null);
    const mostrarFormOpcional = ref<number | null>(null);
    const formOpcional = ref(formOpcionalVacio());

    const verOpcionales = (op: OpcionMayorista) => {
        mostrarOpcionalesId.value = mostrarOpcionalesId.value === op.id ? null : op.id;
    };

    const guardandoOpcional = ref(false);
    const guardarOpcional = async (op: OpcionMayorista) => {
        if (!formOpcional.value.nombre.trim()) return;
        guardandoOpcional.value = true;
        try {
            await opcionMayoristaService.crearOpcional(op.id, { ...formOpcional.value });
            mostrarFormOpcional.value = null;
            formOpcional.value = formOpcionalVacio();
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar el opcional', 'error');
        } finally {
            guardandoOpcional.value = false;
        }
    };

    const opcionalEnEdicionId = ref<number | null>(null);
    const formEdicionOpcional = ref(formOpcionalVacio());
    const abrirEdicionOpcional = (opl: OpcionMayoristaOpcional) => {
        opcionalEnEdicionId.value = opcionalEnEdicionId.value === opl.id ? null : opl.id;
        formEdicionOpcional.value = {
            nombre: opl.nombre, precio_por_persona: Number(opl.precio_por_persona), moneda: opl.moneda,
            incluye: opl.incluye ?? '', no_incluye: opl.no_incluye ?? '',
        };
    };
    const guardandoEdicionOpcional = ref(false);
    const guardarEdicionOpcional = async (opl: OpcionMayoristaOpcional) => {
        guardandoEdicionOpcional.value = true;
        try {
            await opcionMayoristaService.actualizarOpcional(opl.id, formEdicionOpcional.value);
            opcionalEnEdicionId.value = null;
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar el opcional', 'error');
        } finally {
            guardandoEdicionOpcional.value = false;
        }
    };

    const eliminandoOpcionalId = ref<number | null>(null);
    const eliminarOpcionalCargado = async (opl: OpcionMayoristaOpcional) => {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Eliminar este opcional?', text: 'Esta acción no se puede deshacer.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        eliminandoOpcionalId.value = opl.id;
        try {
            await opcionMayoristaService.eliminarOpcional(opl.id);
            toast.success('Opcional eliminado');
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar el opcional', 'error');
        } finally {
            eliminandoOpcionalId.value = null;
        }
    };

    // Un opcional elegido de verdad suma al total: mismo endpoint que el hotel de la matriz, con la
    // cantidad "por persona" (adultos de la cotización).
    const agregandoOpcionalAlLienzoId = ref<number | null>(null);
    const agregarOpcionalAlLienzo = async (op: OpcionMayorista, opl: OpcionMayoristaOpcional) => {
        if (!alternativaActiva.value) return;
        agregandoOpcionalAlLienzoId.value = opl.id;
        try {
            const res = await alternativaItemService.agregarMayorista(alternativaActiva.value.id, {
                opcion_mayorista_id: op.id,
                opcion_mayorista_opcional_id: opl.id,
                cantidad: cantidadAdultosCotizacion.value,
                dia_referencial: diaActivoParaAgregar.value,
                alternativa_destino_id: destinoActivoId.value,
            });
            await onServicioSueltoAgregado(res.alternativa_item, res.alternativa);
            toast.success(`"${opl.nombre}" agregado al lienzo`);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar el opcional', 'error');
        } finally {
            agregandoOpcionalAlLienzoId.value = null;
        }
    };

    // ── Tours incluidos ──────────────────────────────────────────────────
    const mostrarToursId = ref<number | null>(null);
    const mostrarFormTourIncluido = ref<number | null>(null);
    const tourEnEdicionId = ref<number | null>(null);

    const verTours = (op: OpcionMayorista) => {
        mostrarToursId.value = mostrarToursId.value === op.id ? null : op.id;
    };

    const onTourIncluidoAgregado = async (op: OpcionMayorista, _tour: OpcionMayoristaTour) => {
        mostrarFormTourIncluido.value = null;
        await cargarOpcionesMayorista();
    };

    const quitarTourIncluido = async (tour: OpcionMayoristaTour, op: OpcionMayorista) => {
        try {
            await opcionMayoristaService.quitarTour(tour.id);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo quitar el tour', 'error');
        }
    };

    // Editar un tour incluido usa el mismo formulario del alta; abrirlo cierra el de alta.
    const abrirEdicionTour = (tour: OpcionMayoristaTour) => {
        mostrarFormTourIncluido.value = null;
        tourEnEdicionId.value = tour.id;
    };
    const onTourIncluidoActualizado = async (_tour: OpcionMayoristaTour) => {
        tourEnEdicionId.value = null;
        await cargarOpcionesMayorista();
    };

    return {
        mostrarOpcionalesId, mostrarFormOpcional, formOpcional, verOpcionales, guardandoOpcional, guardarOpcional,
        opcionalEnEdicionId, formEdicionOpcional, abrirEdicionOpcional, guardandoEdicionOpcional, guardarEdicionOpcional,
        eliminandoOpcionalId, eliminarOpcionalCargado, agregandoOpcionalAlLienzoId, agregarOpcionalAlLienzo,
        mostrarToursId, mostrarFormTourIncluido, tourEnEdicionId, verTours, onTourIncluidoAgregado, quitarTourIncluido,
        abrirEdicionTour, onTourIncluidoActualizado,
    };
}
