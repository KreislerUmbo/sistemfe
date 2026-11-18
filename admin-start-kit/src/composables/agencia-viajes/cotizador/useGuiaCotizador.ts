import { ref, watch, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { guiaService } from '@/services/admin/guiaService';
import type { Alternativa, AlternativaItem, ConfiguracionAgencia, DestinoTributario, GuiaTarifa, TipAfeIgv } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que la guía necesita del núcleo, del lienzo y del drawer. */
export interface DependenciasGuia {
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    configAgencia: Ref<ConfiguracionAgencia | null>;
    diaActivoParaAgregar: ComputedRef<number>;
    destinoActivoId: Ref<number | null>;
    onServicioSueltoAgregado: (item: AlternativaItem, alternativa?: Partial<Alternativa> | null) => Promise<void>;
}

/**
 * Guías como ítem (fix guia-como-item-real). Mismo patrón que paquetes/detalle.vue: elegir el guía
 * primero y después su lista de tarifas (una por destino/modalidad; cada tarifa ya trae su propio
 * destino). No hay endpoint de búsqueda unificada para guías (guiaService no lo tiene, a diferencia
 * de bibliotecaCotizadorService), así que vive aparte del grid de biblioteca, como el comparador de
 * mayoristas: es el tercer modo del cotizador. Crea un watcher (los impuestos por defecto).
 */
export function useGuiaCotizador(deps: DependenciasGuia) {
    const { alternativaActiva, configAgencia, diaActivoParaAgregar, destinoActivoId, onServicioSueltoAgregado } = deps;

    const guiaSeleccionadaIdCotizador = ref<number | null>(null);
    const tarifasGuiaCotizador = ref<GuiaTarifa[]>([]);
    const guiaTarifaSeleccionadaCotizador = ref<GuiaTarifa | null>(null);
    const cantidadDiasGuia = ref(1);
    const agregandoGuia = ref(false);
    // Impuestos: prellenados con el default de la agencia apenas está disponible; editables antes
    // de agregar el ítem.
    const tipAfeIgvGuia = ref<TipAfeIgv>('10');
    const destinoTributarioGuia = ref<DestinoTributario>('nacional');
    watch(configAgencia, (c) => {
        if (c) {
            tipAfeIgvGuia.value = c.tip_afe_igv_default;
            destinoTributarioGuia.value = c.destino_tributario_default;
        }
    }, { immediate: true });

    const cargarTarifasGuiaCotizador = async () => {
        guiaTarifaSeleccionadaCotizador.value = null;
        tarifasGuiaCotizador.value = [];
        if (!guiaSeleccionadaIdCotizador.value) return;
        const res = await guiaService.obtener(guiaSeleccionadaIdCotizador.value);
        tarifasGuiaCotizador.value = res.guia.guia_tarifas ?? [];
    };

    const agregarItemGuiaCotizador = async () => {
        if (!alternativaActiva.value || !guiaTarifaSeleccionadaCotizador.value) return;
        agregandoGuia.value = true;
        try {
            const res = await alternativaItemService.agregarGuia(alternativaActiva.value.id, {
                guia_tarifa_id: guiaTarifaSeleccionadaCotizador.value.id,
                cantidad: cantidadDiasGuia.value || 1,
                dia_referencial: diaActivoParaAgregar.value,
                tip_afe_igv: tipAfeIgvGuia.value,
                destino_tributario: destinoTributarioGuia.value,
                alternativa_destino_id: destinoActivoId.value,
            });
            guiaTarifaSeleccionadaCotizador.value = null;
            cantidadDiasGuia.value = 1;
            await onServicioSueltoAgregado(res.alternativa_item, res.alternativa);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar el guía', 'error');
        } finally {
            agregandoGuia.value = false;
        }
    };

    return {
        guiaSeleccionadaIdCotizador, tarifasGuiaCotizador, guiaTarifaSeleccionadaCotizador, cantidadDiasGuia,
        agregandoGuia, tipAfeIgvGuia, destinoTributarioGuia, cargarTarifasGuiaCotizador, agregarItemGuiaCotizador,
    };
}
