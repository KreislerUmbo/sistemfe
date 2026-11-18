import { computed, ref, type ComputedRef, type Ref } from 'vue';
import { etiquetaItem } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import type { RevisionEntrada } from '@/services/admin/revisionAlternativaService';
import type { AlternativaItem } from '@/types/agencia-viajes';

/** Lo que la revisión necesita del núcleo, de los precios, de la vigencia y del drawer. */
export interface DependenciasRevision {
    versionCotizacion: Ref<number>;
    alternativaActivaId: Ref<number | null>;
    totalLocal: ComputedRef<number>;
    itemsSinAjuste: ComputedRef<AlternativaItem[]>;
    irAItem: (id: number) => Promise<void>;
    extendiendoVigencia: Ref<boolean>;
    drawerBibliotecaAbierto: Ref<boolean>;
}

/**
 * T-022: revisión antes de enviar. RevisionAlternativa.vue pide la lista al backend; acá solo se
 * guarda (para los botones de envío) y se navega al servicio o formulario donde se corrige.
 * No crea watchers.
 */
export function useRevisionAlternativa(deps: DependenciasRevision) {
    const {
        versionCotizacion, alternativaActivaId, totalLocal, itemsSinAjuste, irAItem, extendiendoVigencia,
        drawerBibliotecaAbierto,
    } = deps;

    const revisionActual = ref<RevisionEntrada[]>([]);
    // Cambia cuando la alternativa cambia (mismo momento en que se refresca el total): el componente
    // espera medio segundo antes de volver a pedir, para no llamar en cada tecla. versionCotizacion
    // (núcleo) cambia tras cada guardado; el total local cubre las ediciones en vivo.
    const claveRevision = computed(() => `${versionCotizacion.value}|${alternativaActivaId.value}|${totalLocal.value}`);
    const nombreItemRevision = (id: number): string => {
        const item = itemsSinAjuste.value.find((i) => i.id === id);
        return item ? etiquetaItem(item) : 'Servicio';
    };
    const irARevision = (entrada: RevisionEntrada, itemId: number | null) => {
        if (itemId !== null) {
            void irAItem(itemId);
        } else if (entrada.destino.seccion === 'datos_cotizacion') {
            extendiendoVigencia.value = true;
        } else if (entrada.destino.seccion === 'lienzo') {
            drawerBibliotecaAbierto.value = true;
        }
    };

    return { revisionActual, claveRevision, nombreItemRevision, irARevision };
}
