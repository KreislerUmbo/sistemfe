import { computed, ref, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaService } from '@/services/admin/alternativaService';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { convertirMonedaLocal, filasResumen as filasResumenDe, sumaPorGrupos, totalDeLinea, totalEfectivo } from '@/helpers/agencia-viajes/cotizador/totales';
import type { Alternativa, AlternativaItem } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

type EdicionItem = { descuento_pct: number; precio_convertido: number; monto_descuento: number };
type PayloadEdicion = { descuento_pct?: number; precio_convertido?: number };

/** Lo que este bloque necesita del núcleo (useCotizacionEditor). */
export interface NucleoPrecios {
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    itemsSinAjuste: ComputedRef<AlternativaItem[]>;
    cargarCotizacion: () => Promise<void>;
    aplicarItemActualizado: (item: AlternativaItem | null | undefined, alternativa?: Partial<Alternativa> | null) => boolean;
    aplicarAlternativaActualizada: (nueva: Partial<Alternativa> | null | undefined) => boolean;
}

// El ítem cuya fila tiene el foco (las filas llevan data-item-id).
const itemConFocoEnPantalla = (): number[] => {
    const fila = document.activeElement instanceof HTMLElement ? document.activeElement.closest('[data-item-id]') : null;
    return (fila?.getAttribute('data-item-id') ?? '').split(/\s+/).map(Number).filter(Boolean);
};

const redondear2 = (n: number) => Math.round(n * 100) / 100;

/**
 * Edición de precios del cotizador: precio / descuento por ítem (en vivo, con debounce),
 * descuento global (en % o en monto), aviso de piso y totales de vista previa.
 *
 * El backend siempre calcula el precio final (PriceEngineService): acá solo se muestra una
 * vista previa con la misma fórmula mientras se espera la respuesta.
 *
 * `itemConFoco` se recibe por parámetro para poder probarlo sin DOM; por defecto lee el foco real.
 */
export function useEdicionPrecios(nucleo: NucleoPrecios, opciones: { itemConFoco?: () => number[] } = {}) {
    const { alternativaActiva, itemsSinAjuste, cargarCotizacion, aplicarItemActualizado, aplicarAlternativaActualizada } = nucleo;
    const itemConFoco = opciones.itemConFoco ?? itemConFocoEnPantalla;

    // monto_descuento solo existe para mostrar el input cuando modo_descuento_item='monto' (se deriva
    // de precio de lista − precio_convertido, nunca al revés): al backend va precio_convertido.
    const edicionItems = ref<Record<number, EdicionItem>>({});
    const alertasPiso = ref<Record<number, boolean>>({});
    const edicionTimeouts: Record<number, ReturnType<typeof setTimeout>> = {};
    // H13: ítems con una edición escrita que todavía no se envió (o se está enviando). Ni la
    // recarga ni la respuesta de otro guardado pisan lo que el vendedor tiene en esos campos.
    const edicionPendiente = new Set<number>();
    // Ítems cuyo guardado está en vuelo (indicador junto al campo; el campo sigue editable).
    // Hasta que el servidor responde, un refresco de otro ítem tampoco pisa lo que se escribió.
    const guardandoEdicion = ref<number[]>([]);

    const precioListaConvertidoDe = (item: AlternativaItem) => {
        if (!alternativaActiva.value) return Number(item.precio_venta_snapshot);
        return convertirMonedaLocal(Number(item.precio_venta_snapshot), item.moneda_costo, alternativaActiva.value.moneda_cotizacion, Number(alternativaActiva.value.tipo_cambio_aplicado));
    };

    const edicionDesde = (item: AlternativaItem | undefined, descuentoPct: unknown, precioConvertido: number): EdicionItem => {
        const precioLista = item ? precioListaConvertidoDe(item) : precioConvertido;
        return {
            descuento_pct: Number(descuentoPct ?? 0),
            precio_convertido: precioConvertido,
            monto_descuento: redondear2(precioLista - precioConvertido),
        };
    };

    // ── Totales de vista previa: el precio que se está escribiendo pesa antes que el guardado ──
    const totalConvertidoLocal = (item: AlternativaItem) =>
        totalDeLinea(edicionItems.value[item.id]?.precio_convertido ?? Number(item.precio_convertido), item);
    const totalEfectivoLocal = (todos: AlternativaItem[]) => totalEfectivo(todos, totalConvertidoLocal);
    const subtotalGrupo = (items: AlternativaItem[]) => totalEfectivoLocal(items);
    const filasResumen = (items: AlternativaItem[]) => filasResumenDe(items, totalConvertidoLocal);
    const totalLocal = computed(() => totalEfectivoLocal(itemsSinAjuste.value));

    // ── Descuento global ──
    // El backend lo reparte a cada ítem respetando su piso (AlternativaController::aplicarDescuentoGlobal()).
    const descuentoGlobalLocal = ref(0);
    // El monto no se guarda: el backend lo resuelve a un % efectivo (descuento_global_pct). Al recargar,
    // el input en monto se reconstruye desde ese % para no volver a 0.
    const descuentoGlobalMontoLocal = ref(0);
    const lineasFueraDePiso = ref<Array<{ alternativa_item_id: number; precio_minimo_permitido: number | null }>>([]);

    // Mismo criterio de grupos que el total, para que monto → % → monto sea estable al recargar.
    const precioListaTotalDe = (item: AlternativaItem) => totalDeLinea(precioListaConvertidoDe(item), item);
    const calcularMontoGlobalEquivalente = () => {
        const pct = Number(alternativaActiva.value?.descuento_global_pct ?? 0);
        if (!pct) return 0;
        return redondear2(sumaPorGrupos(itemsSinAjuste.value, precioListaTotalDe) * (pct / 100));
    };

    // Tras recargar o aplicar una respuesta: los campos toman lo guardado, salvo los ítems que el
    // vendedor está editando (H13).
    const inicializarEdicionItems = () => {
        const conFoco = itemConFoco();
        itemsSinAjuste.value.forEach((item) => {
            if (edicionPendiente.has(item.id) || guardandoEdicion.value.includes(item.id) || conFoco.includes(item.id)) return; // H13
            edicionItems.value[item.id] = edicionDesde(item, item.descuento_pct, Number(item.precio_convertido));
        });
        descuentoGlobalLocal.value = Number(alternativaActiva.value?.descuento_global_pct ?? 0);
        descuentoGlobalMontoLocal.value = calcularMontoGlobalEquivalente();
        lineasFueraDePiso.value = [];
    };

    // Mientras se aplica, el campo queda deshabilitado con su indicador (recalcula todas las líneas).
    const aplicandoDescuentoGlobal = ref(false);
    const aplicarDescuentoGlobal = async (data: { descuento_global_pct?: number; descuento_global_monto?: number }) => {
        if (!alternativaActiva.value) return;
        aplicandoDescuentoGlobal.value = true;
        try {
            const res = await alternativaService.actualizar(alternativaActiva.value.id, data);
            if (!aplicarAlternativaActualizada(res.alternativa)) await cargarCotizacion();
            // Después de aplicar: la aplicación reinicia `lineasFueraDePiso` y debe quedar el aviso nuevo.
            lineasFueraDePiso.value = res.lineas_fuera_de_piso ?? [];
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo aplicar el descuento global', 'error');
        } finally {
            aplicandoDescuentoGlobal.value = false;
        }
    };
    const onEditarDescuentoGlobal = () => aplicarDescuentoGlobal({ descuento_global_pct: descuentoGlobalLocal.value });
    // Mismo endpoint, en monto: el backend lo reparte proporcionalmente y valida el piso igual.
    const onEditarDescuentoGlobalMonto = () => aplicarDescuentoGlobal({ descuento_global_monto: descuentoGlobalMontoLocal.value });

    // ── Edición por ítem ──
    // Tres caminos según configuracion_agencia, con el mismo debounce y endpoint: cambia qué campo
    // se manda y cómo se deriva el otro para la vista previa.
    const enviarEdicion = async (itemId: number, payload: PayloadEdicion) => {
        edicionPendiente.delete(itemId);
        guardandoEdicion.value.push(itemId);
        try {
            const res = await alternativaItemService.actualizar(itemId, payload);
            alertasPiso.value[itemId] = !!res.alerta_piso;
            // Se actualiza solo este ítem y el total de la alternativa (si no se puede, se recarga).
            if (!aplicarItemActualizado(res.alternativa_item, res.alternativa)) await cargarCotizacion();
            // Los campos de ESTE ítem toman lo que quedó guardado (p. ej. 100.555 → 100.56), salvo que
            // el vendedor haya vuelto a escribir o haya otro guardado suyo en vuelo: ahí manda lo escrito.
            const otroGuardadoEnVuelo = guardandoEdicion.value.filter((id) => id === itemId).length > 1;
            if (!edicionPendiente.has(itemId) && !otroGuardadoEnVuelo) {
                const item = alternativaActiva.value?.items?.find((i) => i.id === itemId);
                edicionItems.value[itemId] = edicionDesde(item, res.alternativa_item.descuento_pct, Number(res.alternativa_item.precio_convertido));
            }
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar', 'error');
        } finally {
            const i = guardandoEdicion.value.indexOf(itemId);
            if (i !== -1) guardandoEdicion.value.splice(i, 1);
        }
    };

    const programarEnvioEdicion = (itemId: number, payload: () => PayloadEdicion) => {
        clearTimeout(edicionTimeouts[itemId]);
        edicionPendiente.add(itemId);
        edicionTimeouts[itemId] = setTimeout(() => { void enviarEdicion(itemId, payload()); }, 400);
    };

    // permitir_descuento_item=false → «Precio de venta»: se edita y se manda el precio directo.
    const onEditarPrecio = (item: AlternativaItem) => {
        programarEnvioEdicion(item.id, () => ({ precio_convertido: edicionItems.value[item.id].precio_convertido }));
    };

    // modo_descuento_item='porcentaje': se manda descuento_pct (el backend deriva el precio); acá se
    // recalcula el precio para la vista previa con la misma fórmula del backend.
    const onEditarDescuentoPct = (item: AlternativaItem) => {
        const edicion = edicionItems.value[item.id];
        const precioLista = precioListaConvertidoDe(item);
        edicion.precio_convertido = redondear2(precioLista * (1 - edicion.descuento_pct / 100));
        edicion.monto_descuento = redondear2(precioLista - edicion.precio_convertido);
        programarEnvioEdicion(item.id, () => ({ descuento_pct: edicion.descuento_pct }));
    };

    // modo_descuento_item='monto': el precio se deriva acá (lista − monto) y es lo que se manda; el %
    // se calcula solo para el badge informativo.
    const onEditarMontoDescuentoItem = (item: AlternativaItem) => {
        const edicion = edicionItems.value[item.id];
        const precioLista = precioListaConvertidoDe(item);
        edicion.precio_convertido = redondear2(precioLista - edicion.monto_descuento);
        edicion.descuento_pct = precioLista > 0 ? Math.round((1 - edicion.precio_convertido / precioLista) * 10000) / 100 : 0;
        programarEnvioEdicion(item.id, () => ({ precio_convertido: edicion.precio_convertido }));
    };

    return {
        edicionItems,
        alertasPiso,
        guardandoEdicion,
        inicializarEdicionItems,
        totalConvertidoLocal,
        totalEfectivoLocal,
        subtotalGrupo,
        filasResumen,
        totalLocal,
        descuentoGlobalLocal,
        descuentoGlobalMontoLocal,
        lineasFueraDePiso,
        aplicandoDescuentoGlobal,
        onEditarDescuentoGlobal,
        onEditarDescuentoGlobalMonto,
        onEditarPrecio,
        onEditarDescuentoPct,
        onEditarMontoDescuentoItem,
    };
}
