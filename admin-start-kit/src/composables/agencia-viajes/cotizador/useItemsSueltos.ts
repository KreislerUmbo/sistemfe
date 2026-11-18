import { computed, ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useToast } from '@/composables/useToast';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import type { Alternativa, AlternativaItem } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que los ítems sueltos necesitan del núcleo y del drawer. */
export interface DependenciasItemsSueltos {
    alternativaSoloLectura: ComputedRef<boolean>;
    cargarCotizacion: () => Promise<void>;
    aplicarItemActualizado: (item: AlternativaItem | null | undefined, alternativa?: Partial<Alternativa> | null) => boolean;
    aplicarAlternativaActualizada: (nueva: Partial<Alternativa> | null | undefined) => boolean;
    drawerBibliotecaAbierto: Ref<boolean>;
    alVolverAGrid: (fn: () => void) => void;
}

/**
 * Ítem manual, pasaje aéreo suelto, promover un ítem a proveedor real y eliminar un ítem o un
 * grupo del lienzo. Registra en el drawer el reinicio del formulario manual (es un paso del
 * drawer). No crea watchers.
 */
export function useItemsSueltos(deps: DependenciasItemsSueltos) {
    const {
        alternativaSoloLectura, cargarCotizacion, aplicarItemActualizado, aplicarAlternativaActualizada,
        drawerBibliotecaAbierto, alVolverAGrid,
    } = deps;
    const toast = useToast();

    const mostrarFormManual = ref(false);
    const mostrarFormPasajeAereo = ref(false);
    // Edición estructural de un pasaje aéreo suelto: mismo patrón que itemManualEnEdicion (null = alta).
    const itemPasajeAereoEnEdicion = ref<AlternativaItem | null>(null);
    // Ítem manual en edición (null = alta). ItemManualForm.vue se prellena solo cuando viene
    // poblado, ver su watch(itemExistente).
    const itemManualEnEdicion = ref<AlternativaItem | null>(null);
    // El formulario manual es un paso del drawer: volver al grid lo cierra.
    alVolverAGrid(() => {
        mostrarFormManual.value = false;
        itemManualEnEdicion.value = null;
    });

    // «Ver detalle» de un ítem ya reservado abre el mismo formulario con los campos deshabilitados.
    const formManualSoloLectura = computed(() => alternativaSoloLectura.value || !!itemManualEnEdicion.value?.reserva_item);
    const formPasajeSoloLectura = computed(() => alternativaSoloLectura.value || !!itemPasajeAereoEnEdicion.value?.reserva_item);

    // T-100 — Por qué un servicio está en solo lectura: lo tomó una reserva. Una cancelada tampoco lo
    // libera (lo lee para su historial): se cambia duplicando la alternativa. Mismo texto que
    // ReservaItem::mensajeBloqueo() en el backend.
    const avisoItemReservado = (item: AlternativaItem | null | undefined): string | null => {
        const ri = item?.reserva_item;
        if (!ri) return null;
        const r = ri.reserva;
        const codigo = r?.codigo ?? (r ? `#${r.id}` : null);
        if (!codigo) return 'Este servicio ya tiene una reserva generada.';
        return r?.estado === 'cancelada'
            ? `Lo tomó la reserva ${codigo} (cancelada), que lo conserva en su historial. Para cambiarlo, duplica la alternativa.`
            : `Lo tomó la reserva ${codigo}. Para cambiarlo, usa Modificar reserva.`;
    };

    // El drawer solo se ve con drawerBibliotecaAbierto: pasar al paso 'manual' no alcanza, hay que abrirlo.
    const abrirEdicionManual = (item: AlternativaItem) => {
        itemManualEnEdicion.value = item;
        mostrarFormManual.value = true;
        drawerBibliotecaAbierto.value = true;
    };
    // «Promover a proveedor real» vive en su propio modal, fuera del drawer (no es un paso más de
    // pasoDrawer).
    const itemParaPromover = ref<AlternativaItem | null>(null);
    const abrirPromoverProveedor = (item: AlternativaItem) => {
        itemParaPromover.value = item;
    };
    const onProveedorPromovido = async (_res: any) => {
        itemParaPromover.value = null;
        toast.success('Proveedor creado correctamente');
        await cargarCotizacion();
    };

    // Edición estructural de un ítem manual (ItemManualForm.vue en modo edición). Como
    // onServicioSueltoAgregado, pero sin volver a "grid" primero: ya estamos en el paso 'manual'
    // del drawer, solo hay que cerrarlo.
    const onServicioSueltoActualizado = async (_item: AlternativaItem) => {
        mostrarFormManual.value = false;
        itemManualEnEdicion.value = null;
        toast.success('Ítem manual actualizado');
        await cargarCotizacion();
    };

    // El pasaje aéreo suelto vive fuera del drawer (no es parte de la biblioteca de proveedores/tours).
    const onPasajeAereoAgregado = async (item: AlternativaItem, alternativa?: Partial<Alternativa> | null) => {
        mostrarFormPasajeAereo.value = false;
        toast.success('Pasaje aéreo agregado');
        if (!alternativa || !aplicarItemActualizado(item, alternativa)) await cargarCotizacion();
    };

    // Este form vive fuera del drawer (no hay Teleport que gatear): alcanza con abrir el toggle.
    const abrirEdicionPasajeAereo = (item: AlternativaItem) => {
        itemPasajeAereoEnEdicion.value = item;
        mostrarFormPasajeAereo.value = true;
    };

    // El botón "Cerrar"/"Pasaje aéreo suelto" también limpia el modo edición: sin esto, cerrar
    // mientras se editaba y volver a abrir para AGREGAR reabriría el form prellenado con el anterior.
    const toggleFormPasajeAereo = () => {
        mostrarFormPasajeAereo.value = !mostrarFormPasajeAereo.value;
        if (!mostrarFormPasajeAereo.value) itemPasajeAereoEnEdicion.value = null;
    };

    const onPasajeAereoActualizado = async (_item: AlternativaItem) => {
        mostrarFormPasajeAereo.value = false;
        itemPasajeAereoEnEdicion.value = null;
        toast.success('Pasaje aéreo actualizado');
        await cargarCotizacion();
    };

    // Un ítem cuya alternativa ya generó una reserva (p. ej. vía Venta Directa) devuelve 422 desde
    // AlternativaItemController::destroy(): sin captura, el botón parecería "no hacer nada".
    const eliminandoItemId = ref<number | null>(null);
    const eliminarItem = async (item: AlternativaItem) => {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Eliminar este ítem?',
            text: 'Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        eliminandoItemId.value = item.id;
        try {
            const res = await alternativaItemService.eliminar(item.id);
            toast.success('Ítem eliminado');
            // Una línea de tour se quita con toda su carga y esa respuesta no trae la alternativa: recarga completa.
            if (!aplicarAlternativaActualizada(res.alternativa)) await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar el ítem', 'error');
        } finally {
            eliminandoItemId.value = null;
        }
    };

    // Borra de una vez todo el grupo_opcion_id (un bloque "N opciones de hotel"), en vez de fila por
    // fila con eliminarItem(). Mismo patrón de confirmación y try/catch.
    const eliminandoGrupoId = ref<string | null>(null);
    const eliminarGrupoLienzo = async (fila: { grupoId: string; items: AlternativaItem[] }) => {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Eliminar todo este bloque?',
            text: `Se van a eliminar las ${fila.items.length} opciones de hotel de este grupo. Esta acción no se puede deshacer.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar todo',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        eliminandoGrupoId.value = fila.grupoId;
        try {
            await alternativaItemService.eliminarGrupo(fila.items[0].alternativa_id, fila.grupoId);
            toast.success('Grupo eliminado');
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar el grupo', 'error');
        } finally {
            eliminandoGrupoId.value = null;
        }
    };

    return {
        mostrarFormManual, mostrarFormPasajeAereo, itemPasajeAereoEnEdicion, itemManualEnEdicion,
        formManualSoloLectura, formPasajeSoloLectura, avisoItemReservado, abrirEdicionManual, itemParaPromover,
        abrirPromoverProveedor, onProveedorPromovido, onServicioSueltoActualizado, onPasajeAereoAgregado,
        abrirEdicionPasajeAereo, toggleFormPasajeAereo, onPasajeAereoActualizado, eliminandoItemId, eliminarItem,
        eliminandoGrupoId, eliminarGrupoLienzo,
    };
}
