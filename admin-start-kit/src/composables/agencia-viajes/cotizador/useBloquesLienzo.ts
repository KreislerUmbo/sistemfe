import { computed, ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { useToast } from '@/composables/useToast';
import { agruparPorTour, type BloqueItem } from '@/helpers/agencia-viajes/cotizador/bloquesLienzo';
import type { Alternativa, AlternativaDestino, AlternativaItem } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que los bloques necesitan del núcleo, de los precios, de los destinos y de los días. */
export interface DependenciasBloques {
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    itemsSinAjuste: ComputedRef<AlternativaItem[]>;
    cargarCotizacion: () => Promise<void>;
    aplicarAlternativaActualizada: (nueva: Partial<Alternativa> | null | undefined) => boolean;
    totalEfectivoLocal: (items: AlternativaItem[]) => number;
    alternativaDestinos: ComputedRef<AlternativaDestino[]>;
    itemsVisiblesLienzo: ComputedRef<AlternativaItem[]>;
    diaActivo: Ref<number>;
}

export type BloqueDestino = { destinoId: number; destinoTexto: string; subtotal: number; grupos: BloqueItem[] };

/**
 * Bloques del lienzo (ítems agrupados por tour) y del panel de precio: mover, reasignar de día,
 * eliminar, elegir la opción de un grupo y expandir/colapsar.
 */
export function useBloquesLienzo(deps: DependenciasBloques) {
    const {
        alternativaActiva, itemsSinAjuste, cargarCotizacion, aplicarAlternativaActualizada,
        totalEfectivoLocal, alternativaDestinos, itemsVisiblesLienzo, diaActivo,
    } = deps;
    const toast = useToast();

    const bloquesLienzo = computed<BloqueItem[]>(() => agruparPorTour(itemsVisiblesLienzo.value));
    // Con un solo destino, el panel de precio muestra todos los ítems agrupados por tour.
    const gruposPrecioPanel = computed<BloqueItem[]>(() => agruparPorTour(itemsSinAjuste.value));

    // Con varios destinos, el panel suma además un subtotal colapsable por destino. El total general
    // (totalLocal) no cambia: sigue sumando todos los ítems.
    const gruposPrecioPorDestino = computed<BloqueDestino[]>(() => {
        const primerDestinoId = alternativaDestinos.value[0]?.id ?? null;
        return alternativaDestinos.value.map((destino) => {
            const items = itemsSinAjuste.value.filter((i) => (i.alternativa_destino_id ?? primerDestinoId) === destino.id);
            return {
                destinoId: destino.id,
                destinoTexto: destino.destino_texto,
                subtotal: totalEfectivoLocal(items),
                grupos: agruparPorTour(items),
            };
        });
    });

    const eligiendoGrupoItemId = ref<number | null>(null);
    const elegirGrupoDesdeLienzo = async (item: AlternativaItem) => {
        eligiendoGrupoItemId.value = item.id;
        try {
            const res = await alternativaItemService.elegirGrupo(item.id);
            if (!aplicarAlternativaActualizada(res.alternativa)) await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo marcar la opción elegida', 'error');
        } finally {
            eligiendoGrupoItemId.value = null;
        }
    };

    const onReasignarDiaItem = async (item: AlternativaItem, event: Event) => {
        const valor = Number((event.target as HTMLSelectElement).value);
        if (!valor) return;
        try {
            await alternativaItemService.reasignarDia(item.id, valor);
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo mover el ítem', 'error');
        }
    };

    const onMoverBloque = async (bloque: BloqueItem, event: Event) => {
        const valor = Number((event.target as HTMLSelectElement).value);
        if (!bloque.tourOrigenId || !alternativaActiva.value || !valor || valor === diaActivo.value) return;
        try {
            await alternativaItemService.moverBloque(alternativaActiva.value.id, { tour_origen_id: bloque.tourOrigenId, dia_referencial: valor });
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo mover el bloque', 'error');
        }
    };

    // Elimina todos los ítems del bloque (mismo tour_origen_id) en una sola operación atómica
    // (T-027): un DELETE por ítem en paralelo dejaba mal guardado el total de la alternativa.
    const eliminandoBloqueId = ref<number | null>(null);
    const eliminarBloque = async (bloque: BloqueItem) => {
        if (!bloque.tourOrigenId || !alternativaActiva.value) return;

        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: `¿Eliminar "${bloque.tourNombre ?? 'este tour'}"?`,
            text: `Se eliminarán sus ${bloque.items.length} ítem(s).`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        eliminandoBloqueId.value = bloque.tourOrigenId;
        try {
            await alternativaItemService.eliminarBloque(alternativaActiva.value.id, bloque.items.map((i) => i.id));
            toast.success('Tour eliminado');
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar el tour', 'error');
        } finally {
            eliminandoBloqueId.value = null;
            await cargarCotizacion();
        }
    };

    // ── Panel de precio: grupos colapsables por tour y por destino ──
    const gruposExpandido = ref<Set<number>>(new Set());
    const toggleGrupoExpandido = (tourOrigenId: number) => {
        if (gruposExpandido.value.has(tourOrigenId)) gruposExpandido.value.delete(tourOrigenId);
        else gruposExpandido.value.add(tourOrigenId);
    };

    // Cada destino arranca expandido (lo agrega el watch de alternativaDestinos de la página).
    const destinosExpandido = ref<Set<number>>(new Set());
    const toggleDestinoExpandido = (destinoId: number) => {
        if (destinosExpandido.value.has(destinoId)) destinosExpandido.value.delete(destinoId);
        else destinosExpandido.value.add(destinoId);
    };

    return {
        bloquesLienzo,
        gruposPrecioPanel,
        gruposPrecioPorDestino,
        eligiendoGrupoItemId,
        elegirGrupoDesdeLienzo,
        onReasignarDiaItem,
        onMoverBloque,
        eliminandoBloqueId,
        eliminarBloque,
        gruposExpandido,
        toggleGrupoExpandido,
        destinosExpandido,
        toggleDestinoExpandido,
    };
}
