import { ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useToast } from '@/composables/useToast';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import { proveedorService } from '@/services/admin/proveedorService';
import type { OpcionMayorista, ProveedorTarifa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que los hoteles de la matriz mayorista necesitan de la página y del comparador. */
export interface DependenciasHotelesMayorista {
    cargarCotizacion: () => Promise<void>;
    cargarOpcionesMayorista: () => Promise<void>;
}

type TarifaForm = { tipo_habitacion: string; precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null };

const formHotelVacio = () => ({
    nombre_hotel: '',
    proveedor_id: null as number | null,
    tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null }] as TarifaForm[],
});

/**
 * Hoteles de las opciones de mayorista: alta con fotos, edición inline desde el picker de
 * habitaciones (hotel, tarifas, fotos) y promoción de un hotel a proveedor. No crea watchers.
 */
export function useHotelesMayorista(deps: DependenciasHotelesMayorista) {
    const { cargarCotizacion, cargarOpcionesMayorista } = deps;
    const toast = useToast();

    const mostrarFormHotel = ref<number | null>(null);
    const formHotel = ref(formHotelVacio());

    // El hotel recién creado todavía no tiene id mientras se completa el formulario: las fotos se
    // guardan como archivos pendientes y se suben después de crearlo (ver guardarHotel).
    const fotosHotelNuevo = ref<Array<{ file: File; previewUrl: string; tipoFoto: 'fachada' | 'habitacion' }>>([]);
    const onFotosHotelNuevoSeleccionadas = (event: Event) => {
        const input = event.target as HTMLInputElement;
        const archivos = Array.from(input.files ?? []);
        input.value = '';
        const disponibles = 3 - fotosHotelNuevo.value.length;
        if (disponibles <= 0) {
            (Swal as TVueSwalInstance).fire('Límite alcanzado', 'Ya tienes el máximo de 3 fotos por hotel.', 'warning');
            return;
        }
        archivos.slice(0, disponibles).forEach((file) => fotosHotelNuevo.value.push({ file, previewUrl: URL.createObjectURL(file), tipoFoto: 'habitacion' }));
    };
    const quitarFotoHotelNuevo = (idx: number) => {
        URL.revokeObjectURL(fotosHotelNuevo.value[idx].previewUrl);
        fotosHotelNuevo.value.splice(idx, 1);
    };

    // Tarifas del proveedor-hotel elegido, para "usar tarifa registrada".
    const tarifasHotelProveedorSeleccionado = ref<ProveedorTarifa[]>([]);

    const onCambiarProveedorHotel = async () => {
        formHotel.value.tarifas.forEach((tf) => { tf.proveedor_tarifa_id = null; });
        tarifasHotelProveedorSeleccionado.value = formHotel.value.proveedor_id
            ? (await proveedorService.tarifasHotel(formHotel.value.proveedor_id)).proveedor_tarifas
            : [];
    };

    const tarifasHotelParaTipo = (tipoHabitacion: string) => {
        return tarifasHotelProveedorSeleccionado.value.filter((t) => t.tipo_habitacion === tipoHabitacion);
    };

    const onElegirTarifaRegistrada = (tf: { precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null }, event: Event) => {
        const id = Number((event.target as HTMLSelectElement).value) || null;
        tf.proveedor_tarifa_id = id;
        const tarifa = tarifasHotelProveedorSeleccionado.value.find((t) => t.id === id);
        if (tarifa) {
            tf.precio_costo = Number(tarifa.precio_costo);
            tf.precio_venta = Number(tarifa.precio_venta_adulto);
        }
    };

    const guardandoHotelMayorista = ref(false);
    const guardarHotel = async (op: OpcionMayorista) => {
        guardandoHotelMayorista.value = true;
        try {
            const res = await opcionMayoristaService.crearHotel(op.id, formHotel.value);
            // Secuencial (no Promise.all): el backend valida el tope por tipo (1 fachada / 2
            // habitación) contra lo ya guardado; en paralelo, dos fachadas pasarían el control.
            for (const foto of fotosHotelNuevo.value) {
                await opcionMayoristaService.agregarFotoHotel(res.opcion_hotel.id, foto.file, foto.tipoFoto);
            }
            mostrarFormHotel.value = null;
            formHotel.value = formHotelVacio();
            fotosHotelNuevo.value.forEach((f) => URL.revokeObjectURL(f.previewUrl));
            fotosHotelNuevo.value = [];
            tarifasHotelProveedorSeleccionado.value = [];
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
        } finally {
            guardandoHotelMayorista.value = false;
        }
    };

    // Edición inline desde HabitacionMatrixPicker: el picker junta los datos de cada mini-formulario
    // y emite el payload; acá solo se llama al servicio y se refresca.
    const onGuardarHotelMatrix = async (payload: { id: number; nombre_hotel: string }) => {
        try {
            await opcionMayoristaService.actualizarHotel(payload.id, { nombre_hotel: payload.nombre_hotel });
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar el hotel', 'error');
        }
    };

    const onEliminarHotelMatrix = async (hotelId: number) => {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Eliminar este hotel?', text: 'Se eliminan también todos sus tipos de habitación cargados. Esta acción no se puede deshacer.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        try {
            await opcionMayoristaService.eliminarHotel(hotelId);
            toast.success('Hotel eliminado');
            await Promise.all([cargarCotizacion(), cargarOpcionesMayorista()]);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar el hotel', 'error');
        }
    };

    // Fotos: inmediatas (sin "guardar" que las agrupe), una por vez con su tipo.
    const onAgregarFotosHotelMatrix = async (payload: { hotelId: number; archivo: File; tipoFoto: 'fachada' | 'habitacion' }) => {
        try {
            await opcionMayoristaService.agregarFotoHotel(payload.hotelId, payload.archivo, payload.tipoFoto);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar la foto', 'error');
        }
    };

    const onEliminarFotoHotelMatrix = async (payload: { hotelId: number; path: string }) => {
        try {
            await opcionMayoristaService.eliminarFotoHotel(payload.hotelId, payload.path);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la foto', 'error');
        }
    };

    // Promover un hotel a proveedor: modal propio, fuera del drawer.
    const hotelParaPromover = ref<{ id: number; nombre_hotel: string } | null>(null);
    const onPromoverHotelMatrix = (payload: { hotelId: number; hotelNombre: string }) => {
        hotelParaPromover.value = { id: payload.hotelId, nombre_hotel: payload.hotelNombre };
    };
    const onHotelPromovido = async (_res: any) => {
        hotelParaPromover.value = null;
        toast.success('Proveedor creado correctamente');
        await cargarOpcionesMayorista();
    };

    const onGuardarTarifaMatrix = async (payload: { id: number; tipo_habitacion: string; precio_costo: number; precio_venta: number }) => {
        try {
            await opcionMayoristaService.actualizarTarifaHotel(payload.id, payload);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar la tarifa', 'error');
        }
    };

    const onEliminarTarifaMatrix = async (tarifaId: number) => {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Eliminar este tipo de habitación?', text: 'Esta acción no se puede deshacer.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        try {
            await opcionMayoristaService.eliminarTarifaHotel(tarifaId);
            toast.success('Tipo de habitación eliminado');
            await Promise.all([cargarCotizacion(), cargarOpcionesMayorista()]);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar', 'error');
        }
    };

    const onAgregarTarifaMatrix = async (payload: { hotelId: number; tipo_habitacion: string; precio_costo: number; precio_venta: number }) => {
        try {
            await opcionMayoristaService.agregarTarifaHotel(payload.hotelId, payload);
            await cargarOpcionesMayorista();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar el tipo de habitación', 'error');
        }
    };

    // Filas para la vista agrupada del picker: la cabecera de cada grupo ya muestra el hotel, así
    // que `tipo_habitacion` queda solo con el tipo de habitación.
    const tarifasHotelPlanas = (op: OpcionMayorista) => {
        const filas: Array<{ id: number; tipo_habitacion: string; precio: number; registrada: boolean; hotelId: number; hotelNombre: string; precioCosto: number; hotelFotos: Array<{ path: string; tipo_foto: 'fachada' | 'habitacion'; url: string }>; hotelProveedorId: number | null }> = [];
        (op.opciones_hotel ?? []).forEach((h) => {
            (h.opciones_hotel_tarifas ?? []).forEach((t) => {
                filas.push({
                    id: t.id, tipo_habitacion: t.tipo_habitacion, precio: Number(t.precio_venta), registrada: !!t.proveedor_tarifa_id,
                    hotelId: h.id, hotelNombre: h.nombre_hotel, precioCosto: Number(t.precio_costo), hotelFotos: h.fotos ?? [],
                    hotelProveedorId: h.proveedor_id ?? null,
                });
            });
        });
        return filas;
    };

    return {
        mostrarFormHotel, formHotel, fotosHotelNuevo, onFotosHotelNuevoSeleccionadas, quitarFotoHotelNuevo,
        onCambiarProveedorHotel, tarifasHotelParaTipo, onElegirTarifaRegistrada, guardandoHotelMayorista, guardarHotel,
        onGuardarHotelMatrix, onEliminarHotelMatrix, onAgregarFotosHotelMatrix, onEliminarFotoHotelMatrix,
        hotelParaPromover, onPromoverHotelMatrix, onHotelPromovido, onGuardarTarifaMatrix, onEliminarTarifaMatrix,
        onAgregarTarifaMatrix, tarifasHotelPlanas,
    };
}
