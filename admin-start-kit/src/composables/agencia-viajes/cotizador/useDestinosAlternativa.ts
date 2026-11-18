import { computed, ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaDestinoService } from '@/services/admin/alternativaDestinoService';
import { sumarDiasISO } from '@/helpers/fecha';
import type { Alternativa, AlternativaItem } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que este bloque necesita del núcleo (useCotizacionEditor). */
export interface NucleoDestinos {
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    itemsSinAjuste: ComputedRef<AlternativaItem[]>;
    cargarCotizacion: () => Promise<void>;
}

/**
 * Chips de destino de la alternativa activa y su formulario (crear, editar, eliminar).
 *
 * Toda alternativa tiene al menos un destino. Con uno solo los chips no se muestran y nada de
 * lo que filtra por destino cambia de comportamiento.
 */
export function useDestinosAlternativa(nucleo: NucleoDestinos) {
    const { alternativaActiva, itemsSinAjuste, cargarCotizacion } = nucleo;

    const alternativaDestinos = computed(() => alternativaActiva.value?.destinos ?? []);
    // Id de la FILA alternativa_destinos (lo que OpcionMayoristaForm manda como alternativa_destino_id).
    const destinoActivoId = ref<number | null>(null);
    const mostrarChipsDestino = computed(() => alternativaDestinos.value.length > 1);

    // TourIncluidoForm necesita otro id: el destino_atractivo_id real (árbol de DestinoTreeSelect).
    const destinoAtractivoIdActivo = computed(() => {
        return alternativaDestinos.value.find((d) => d.id === destinoActivoId.value)?.destino_atractivo_id ?? null;
    });

    // Se llama a mano (no es un watch) tras recargar y al cambiar de alternativa: inicializarDias()
    // lee itemsDelDestinoActivo justo después, y un watch propio correría recién en el próximo flush.
    // Si el destino activo ya no está (alternativa nueva, destino borrado), cae al primero.
    const resolverDestinoActivo = () => {
        const destinos = alternativaDestinos.value;
        if (!destinos.some((d) => d.id === destinoActivoId.value)) {
            destinoActivoId.value = destinos[0]?.id ?? null;
        }
    };

    // Un ítem sin alternativa_destino_id cuenta como del PRIMER destino: nunca se oculta un ítem real.
    const itemsDelDestinoActivo = computed(() => {
        const items = itemsSinAjuste.value;
        if (!mostrarChipsDestino.value) return items;
        const primerDestinoId = alternativaDestinos.value[0]?.id ?? null;
        return items.filter((i) => (i.alternativa_destino_id ?? primerDestinoId) === destinoActivoId.value);
    });

    // ── Formulario de destino ──
    const guardandoDestino = ref(false);
    const mostrarFormDestino = ref(false);
    // null = creando uno nuevo; con id = editando ese destino.
    const destinoEnEdicionId = ref<number | null>(null);
    const nuevoDestinoAtractivoId = ref<number | null>(null);
    const nuevoDestinoTexto = ref('');
    const nuevoDestinoFechaInicio = ref('');
    const nuevoDestinoFechaFin = ref('');

    const abrirFormDestino = () => {
        destinoEnEdicionId.value = null;
        // Sugerencia editable: el día siguiente al fin del último destino.
        const ultimo = alternativaDestinos.value[alternativaDestinos.value.length - 1];
        if (ultimo?.fecha_fin) {
            nuevoDestinoFechaInicio.value = sumarDiasISO(ultimo.fecha_fin, 1);
        } else {
            nuevoDestinoFechaInicio.value = '';
        }
        nuevoDestinoAtractivoId.value = null;
        nuevoDestinoTexto.value = '';
        nuevoDestinoFechaFin.value = '';
        mostrarFormDestino.value = true;
    };

    const abrirFormEditarDestinoActivo = () => {
        const destino = alternativaDestinos.value.find((d) => d.id === destinoActivoId.value);
        if (!destino) return;
        destinoEnEdicionId.value = destino.id;
        nuevoDestinoAtractivoId.value = destino.destino_atractivo_id ?? null;
        nuevoDestinoTexto.value = destino.destino_texto;
        nuevoDestinoFechaInicio.value = destino.fecha_inicio ?? '';
        nuevoDestinoFechaFin.value = destino.fecha_fin ?? '';
        mostrarFormDestino.value = true;
    };

    const guardarNuevoDestino = async () => {
        if (!alternativaActiva.value || !nuevoDestinoTexto.value.trim()) return;
        guardandoDestino.value = true;
        try {
            const payload = {
                destino_atractivo_id: nuevoDestinoAtractivoId.value,
                destino_texto: nuevoDestinoTexto.value.trim(),
                fecha_inicio: nuevoDestinoFechaInicio.value || null,
                fecha_fin: nuevoDestinoFechaFin.value || null,
            };
            const destino = destinoEnEdicionId.value
                ? await alternativaDestinoService.actualizar(alternativaActiva.value.id, destinoEnEdicionId.value, payload)
                : await alternativaDestinoService.crear(alternativaActiva.value.id, payload);
            mostrarFormDestino.value = false;
            await cargarCotizacion();
            destinoActivoId.value = destino.id;
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar el destino', 'error');
        } finally {
            guardandoDestino.value = false;
        }
    };

    const eliminarDestinoActivo = async () => {
        if (!alternativaActiva.value || destinoActivoId.value === null) return;
        const confirmado = await Swal.fire({
            title: '¿Eliminar este destino?', text: 'Esta acción no se puede deshacer.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar',
        });
        if (!confirmado.isConfirmed) return;
        try {
            await alternativaDestinoService.eliminar(alternativaActiva.value.id, destinoActivoId.value);
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar el destino', 'error');
        }
    };

    return {
        alternativaDestinos,
        destinoActivoId,
        mostrarChipsDestino,
        destinoAtractivoIdActivo,
        resolverDestinoActivo,
        itemsDelDestinoActivo,
        guardandoDestino,
        mostrarFormDestino,
        destinoEnEdicionId,
        nuevoDestinoAtractivoId,
        nuevoDestinoTexto,
        nuevoDestinoFechaInicio,
        nuevoDestinoFechaFin,
        abrirFormDestino,
        abrirFormEditarDestinoActivo,
        guardarNuevoDestino,
        eliminarDestinoActivo,
    };
}
