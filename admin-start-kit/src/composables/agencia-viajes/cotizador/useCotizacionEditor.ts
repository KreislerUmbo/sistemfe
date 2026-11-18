import { computed, ref, watch } from 'vue';
import { cotizacionService, type ModificacionDeCotizacion } from '@/services/admin/cotizacionService';
import type { Alternativa, AlternativaItem, ConfiguracionAgencia, Cotizacion } from '@/types/agencia-viajes';

/**
 * 'completo': tras cargarCotizacion() (la cotización entera se reemplazó).
 * 'parcial': tras aplicar un ítem o una alternativa devueltos por el backend.
 */
export type TipoRefresco = 'completo' | 'parcial';

/**
 * Núcleo del cotizador: la cotización, la alternativa activa y cómo se refrescan.
 *
 * El núcleo no conoce a los bloques (precios, días, destinos…): tras cada recarga o
 * actualización parcial avisa a quien se registró con alRefrescar(), en el orden de registro.
 * Así la página decide, en un solo lugar, qué se reinicia y en qué orden.
 */
export function useCotizacionEditor(cotizacionId: number) {
    const cotizacion = ref<Cotizacion | null>(null);
    const alternativaActivaId = ref<number | null>(null);

    // Se carga una vez al montar (singleton por tenant) y gobierna los inputs de descuento.
    const configAgencia = ref<ConfiguracionAgencia | null>(null);

    // Cotización de una modificación de reserva (origen='modificacion'); null en las demás.
    const modificacionInfo = ref<ModificacionDeCotizacion | null>(null);

    const alternativaActiva = computed<Alternativa | null>(() =>
        cotizacion.value?.alternativas?.find((a) => a.id === alternativaActivaId.value) ?? null
    );

    // Los «Ajuste — …» (T-005/T-072) no se suman ni se listan en el lienzo: el precio ya está en el
    // servicio que ajustan. Se muestran aparte, sin sumar (itemsAjuste).
    const itemsSinAjuste = computed(() => (alternativaActiva.value?.items ?? []).filter((i) => !i.ajuste_de_item_id));
    const itemsAjuste = computed(() => (alternativaActiva.value?.items ?? []).filter((i) => !!i.ajuste_de_item_id));

    // T-074 — la alternativa reservada (aceptada, o reemplazada por una modificación) no se toca desde
    // el lienzo: solo cambia por Modificar reserva, que trabaja sobre una versión nueva en borrador.
    // La versión borrador de una modificación abierta sí es editable. El backend ya lo bloquea.
    const alternativaSoloLectura = computed(() =>
        ['aceptada', 'reemplazada'].includes(alternativaActiva.value?.estado ?? '') && modificacionInfo.value?.estado !== 'abierta');

    // Cambia con cada recarga o actualización parcial: la revisión (T-022) la usa para volver a pedir.
    const versionCotizacion = ref(0);
    watch(cotizacion, () => { versionCotizacion.value++; });

    const suscriptores: Array<(tipo: TipoRefresco) => void> = [];
    const alRefrescar = (fn: (tipo: TipoRefresco) => void) => { suscriptores.push(fn); };
    const avisar = (tipo: TipoRefresco) => { for (const fn of suscriptores) fn(tipo); };

    const cargarCotizacion = async () => {
        const res = await cotizacionService.obtener(cotizacionId);
        cotizacion.value = res.cotizacion;
        modificacionInfo.value = res.modificacion ?? null;
        if (!alternativaActivaId.value && cotizacion.value.alternativas?.length) {
            // T-080: solo la primera carga; abre la reservada, si no la reemplazada, si no la primera.
            const alts = cotizacion.value.alternativas;
            const inicial = alts.find((a: any) => a.estado === 'aceptada') ?? alts.find((a: any) => a.estado === 'reemplazada') ?? alts[0];
            alternativaActivaId.value = inicial.id;
        }
        avisar('completo');
    };

    // ── Actualización parcial (T-032) ────────────────────────────────────
    // Las acciones que devuelven el ítem o la alternativa ya actualizados usan SOLO estos dos
    // helpers en vez de cargarCotizacion(). Devuelven false si no pudieron aplicar (respuesta sin
    // lo necesario, alternativa que ya no está): la acción cae entonces a cargarCotizacion().
    const despuesDeAplicarParcial = () => {
        versionCotizacion.value++; // el watch de `cotizacion` no ve cambios internos: avisa a la revisión
        avisar('parcial');
    };

    // Una alternativa devuelta por el backend (columnas, y `items` cuando cambian varios): se mezcla
    // sobre la que ya está en memoria, conservando las relaciones que la respuesta no trae.
    const aplicarAlternativaActualizada = (nueva: Partial<Alternativa> | null | undefined): boolean => {
        const actual = cotizacion.value?.alternativas?.find((a) => a.id === nueva?.id);
        if (!nueva || !actual) return false;
        const { items, ...columnas } = nueva;
        Object.assign(actual, columnas);
        if (items) {
            const previos = new Map((actual.items ?? []).map((i) => [i.id, i]));
            actual.items = items.map((i) => Object.assign(previos.get(i.id) ?? {}, i)) as AlternativaItem[];
        }
        despuesDeAplicarParcial();
        return true;
    };

    // Un ítem devuelto por el backend: reemplaza al que tiene su mismo id o, si es nuevo, se agrega.
    // `alternativa` (sin ítems) trae el total vigente de la alternativa.
    const aplicarItemActualizado = (item: AlternativaItem | null | undefined, alternativa?: Partial<Alternativa> | null): boolean => {
        const alt = cotizacion.value?.alternativas?.find((a) => a.id === item?.alternativa_id);
        if (!item || !alt) return false;
        const lista = alt.items ?? (alt.items = []);
        const existente = lista.find((i) => i.id === item.id);
        if (existente) Object.assign(existente, item);
        else lista.push(item);
        if (alternativa) {
            const { items: _omitidos, ...columnas } = alternativa;
            Object.assign(alt, columnas);
        }
        despuesDeAplicarParcial();
        return true;
    };

    return {
        cotizacion,
        alternativaActivaId,
        alternativaActiva,
        itemsSinAjuste,
        itemsAjuste,
        configAgencia,
        modificacionInfo,
        alternativaSoloLectura,
        versionCotizacion,
        cargarCotizacion,
        aplicarItemActualizado,
        aplicarAlternativaActualizada,
        alRefrescar,
    };
}
