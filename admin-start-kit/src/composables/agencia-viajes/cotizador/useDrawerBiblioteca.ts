import { computed, ref, type Ref } from 'vue';
import { useToast } from '@/composables/useToast';
import type { Alternativa, AlternativaItem } from '@/types/agencia-viajes';

/** Lo que el drawer necesita del núcleo. */
export interface NucleoDrawer {
    aplicarItemActualizado: (item: AlternativaItem | null | undefined, alternativa?: Partial<Alternativa> | null) => boolean;
    cargarCotizacion: () => Promise<void>;
}

/**
 * Drawer de la biblioteca: abrir, cerrar y volver al grid.
 *
 * Volver al grid reinicia estado de otros bloques (la matriz de hotel, el modo de precio, el ítem
 * manual), así que el drawer no los conoce: cada bloque registra su propio reinicio con
 * `alVolverAGrid(fn)`, y se ejecutan en el orden en que se registraron.
 */
export function useDrawerBiblioteca(nucleo: NucleoDrawer) {
    const { aplicarItemActualizado, cargarCotizacion } = nucleo;
    const toast = useToast();

    const drawerBibliotecaAbierto = ref(false);
    const reinicios: Array<() => void> = [];

    const alVolverAGrid = (fn: () => void) => { reinicios.push(fn); };
    const volverAGridDrawer = () => { for (const fn of reinicios) fn(); };

    const abrirDrawerBiblioteca = () => { drawerBibliotecaAbierto.value = true; };
    const cerrarDrawerBiblioteca = () => {
        drawerBibliotecaAbierto.value = false;
        volverAGridDrawer();
    };

    // Un servicio suelto agregado desde el drawer (biblioteca, matriz de hotel, modo de precio,
    // manual, mayorista): el drawer no se cierra, vuelve al grid para seguir agregando. Cargar un
    // tour o paquete sí lo cierra (ver clicResultadoPlantilla en la biblioteca).
    const onServicioSueltoAgregado = async (item: AlternativaItem, alternativa?: Partial<Alternativa> | null) => {
        volverAGridDrawer();
        toast.success('Servicio agregado');
        if (!alternativa || !aplicarItemActualizado(item, alternativa)) await cargarCotizacion();
    };

    return {
        drawerBibliotecaAbierto, abrirDrawerBiblioteca, cerrarDrawerBiblioteca, volverAGridDrawer, alVolverAGrid,
        onServicioSueltoAgregado,
    };
}

export type PasoDrawer = 'grid' | 'matrizHotel' | 'modoPrecio' | 'manual';

/**
 * Paso y título del drawer. Recibe el estado de los bloques que viven dentro de él; la página lo
 * llama cuando ya existen los tres.
 */
export function pasoDrawerDe(estados: {
    matrizHotelActiva: Ref<unknown>;
    modoPrecioPendiente: Ref<unknown>;
    mostrarFormManual: Ref<boolean>;
}) {
    const pasoDrawer = computed<PasoDrawer>(() => {
        if (estados.matrizHotelActiva.value) return 'matrizHotel';
        if (estados.modoPrecioPendiente.value) return 'modoPrecio';
        if (estados.mostrarFormManual.value) return 'manual';
        return 'grid';
    });

    const tituloDrawer = computed(() => {
        switch (pasoDrawer.value) {
            case 'matrizHotel': return 'Elegí la habitación';
            case 'modoPrecio': return 'Cómo se cobra';
            case 'manual': return 'Agregar servicio sin catálogo';
            default: return 'Agregar servicio';
        }
    });

    return { pasoDrawer, tituloDrawer };
}
