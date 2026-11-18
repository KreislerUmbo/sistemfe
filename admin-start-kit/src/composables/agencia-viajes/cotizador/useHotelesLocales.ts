import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { opcionHotelService } from '@/services/admin/opcionHotelService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import { proveedorService } from '@/services/admin/proveedorService';
import { diferenciaDiasISO } from '@/helpers/fecha';
import { generarUuidGrupo } from '@/helpers/agencia-viajes/cotizador/grupoOpcion';
import type {
    Alternativa, AlternativaDestino, AlternativaItem, ConfiguracionAgencia, Cotizacion, OpcionHotelTarifa, ProveedorTarifa,
} from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que los hoteles Local/Nacional necesitan del núcleo, del lienzo y del drawer. */
export interface DependenciasHotelesLocales {
    cotizacion: Ref<Cotizacion | null>;
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    itemsSinAjuste: ComputedRef<AlternativaItem[]>;
    configAgencia: Ref<ConfiguracionAgencia | null>;
    alternativaDestinos: ComputedRef<AlternativaDestino[]>;
    destinoActivoId: Ref<number | null>;
    diaActivoParaAgregar: ComputedRef<number>;
    alVolverAGrid: (fn: () => void) => void;
    onServicioSueltoAgregado: (item: AlternativaItem, alternativa?: Partial<Alternativa> | null) => Promise<void>;
}

export type FotoHotelAdhoc = { path: string; tipo_foto: 'fachada' | 'habitacion'; url: string };
export type GrupoHotelAbierto = { id: string; hoteles: number; cantidad: number };

/** Lo que muestra el picker de habitaciones del drawer. */
export type MatrizHotel = {
    tarifas: Array<{ id: number; tipo_habitacion: string; precio: number; precioVentaCamaAdicional: number | null }>;
    moneda: string;
    nombreProveedor: string;
    edadMaxInfanteGratis?: number;
    edadMaxNinoCamaAdicional?: number;
    // true: las tarifas son de un hotel no registrado (OpcionHotel ad-hoc) y no de un proveedor_tarifa.
    // El picker es el mismo; cambia a qué función se manda lo elegido.
    adhoc?: boolean;
    // Hotel no registrado: su id y sus fotos. Hotel de proveedor: su id, para el enlace a la galería.
    hotelId?: number;
    fotos?: FotoHotelAdhoc[];
    proveedorId?: number | null;
    // Si ya hay una comparación sin resolver en el día activo, el hotel se suma a ese grupo y el picker
    // arranca con la misma cantidad de noches.
    grupoAbierto?: GrupoHotelAbierto | null;
};

type TarifaHotelLocal = { tipo_habitacion: string; precio_costo: number; precio_venta: number; tip_afe_igv: string; destino_tributario: string };

/**
 * Hoteles de la pestaña Local/Nacional desde el drawer: matriz de habitaciones (de un proveedor o
 * de un hotel no registrado), modo de precio de un servicio que no es hotel, comparación de varios
 * hoteles en un grupo y fotos del hotel no registrado.
 */
export function useHotelesLocales(deps: DependenciasHotelesLocales) {
    const {
        cotizacion, alternativaActiva, itemsSinAjuste, configAgencia, alternativaDestinos, destinoActivoId,
        diaActivoParaAgregar, alVolverAGrid, onServicioSueltoAgregado,
    } = deps;

    // Noches por defecto (T-034): las del destino activo si tiene ambas fechas, si no las de la
    // cotización, si no 1. Si sale ≤ 0 (fechas al revés), 1. El texto solo existe si hubo fechas.
    const nochesHotelDefault = computed<{ noches: number; texto?: string }>(() => {
        const destino = alternativaDestinos.value.find((d) => d.id === destinoActivoId.value);
        const cot = cotizacion.value;
        const delDestino = !!(destino?.fecha_inicio && destino?.fecha_fin);
        const desde = delDestino ? destino!.fecha_inicio : cot?.fecha_viaje_desde;
        const hasta = delDestino ? destino!.fecha_fin : cot?.fecha_viaje_hasta;
        if (!desde || !hasta) return { noches: 1 };
        const noches = diferenciaDiasISO(desde, hasta);
        if (noches <= 0) return { noches: 1 };
        const corto = (f: string) => `${f.slice(8, 10)}/${f.slice(5, 7)}`;
        return { noches, texto: `${noches} ${noches === 1 ? 'noche' : 'noches'} (${corto(desde)} – ${corto(hasta)})` };
    });

    const matrizHotelActiva = ref<MatrizHotel | null>(null);
    const modoPrecioPendiente = ref<{ id: number; nombre: string } | null>(null);
    alVolverAGrid(() => {
        matrizHotelActiva.value = null;
        modoPrecioPendiente.value = null;
    });

    // La comparación de hoteles vive en los datos (grupo_opcion_id), no en la sesión: cerrar el drawer
    // no la pierde. Un grupo está abierto solo si NINGUNA fila tiene opcion_elegida=true: en un grupo
    // resuelto las no elegidas quedan en false para siempre. Una sola canasta a la vez: si hubiera
    // más de un grupo abierto en el día, se toma el primero.
    const grupoHotelAbiertoDiaActivo = computed<GrupoHotelAbierto | null>(() => {
        const itemsHotelDelDia = itemsSinAjuste.value.filter((item) =>
            item.origen_tipo === 'proveedor'
            && !!item.grupo_opcion_id
            && item.dia_referencial === diaActivoParaAgregar.value
            && (item.proveedor_tarifa?.tipo_habitacion || item.opcion_hotel_tarifa),
        );

        const porGrupo = new Map<string, AlternativaItem[]>();
        for (const item of itemsHotelDelDia) {
            const lista = porGrupo.get(item.grupo_opcion_id!) ?? [];
            lista.push(item);
            porGrupo.set(item.grupo_opcion_id!, lista);
        }

        for (const [grupoId, items] of porGrupo) {
            if (items.some((i) => i.opcion_elegida === true)) continue; // ya resuelto

            // Hotel real = mismo proveedor_servicio_id; ad-hoc = mismo opcion_hotel_id (no
            // opcion_hotel_tarifa_id: dos tipos de habitación del mismo hotel no son "2 hoteles").
            const hotelesIds = new Set(items.map((i) =>
                i.proveedor_tarifa?.proveedor_servicio_id ?? `adhoc-${i.opcion_hotel_tarifa?.opcion_hotel_id}`,
            ));
            return { id: grupoId, hoteles: hotelesIds.size, cantidad: items[0].cantidad };
        }

        return null;
    });

    // Clic en una tarifa de proveedor de la biblioteca: un hotel abre la matriz completa de ese
    // proveedor_servicio; cualquier otro servicio pregunta cómo se cobra.
    const clicBibliotecaItem = async (tarifa: ProveedorTarifa) => {
        if (tarifa.tipo_habitacion) {
            const res = await proveedorService.listarTarifas(tarifa.proveedor_servicio_id, { solo_vigentes: true });
            // Sin el tramo de edad configurado en el proveedor, el picker no ofrece cama adicional.
            const alojamiento = tarifa.proveedor_servicio?.proveedor?.alojamiento_detalle;
            matrizHotelActiva.value = {
                tarifas: res.proveedor_tarifas.map((t) => ({
                    id: t.id,
                    tipo_habitacion: t.tipo_habitacion ?? '—',
                    precio: Number(t.precio_venta_adulto),
                    precioVentaCamaAdicional: t.precio_venta_cama_adicional != null ? Number(t.precio_venta_cama_adicional) : null,
                })),
                moneda: tarifa.moneda,
                nombreProveedor: tarifa.proveedor_servicio?.proveedor?.nombre_comercial ?? tarifa.proveedor_servicio?.proveedor?.razon_social ?? '',
                edadMaxInfanteGratis: alojamiento?.edad_max_infante_gratis,
                edadMaxNinoCamaAdicional: alojamiento?.edad_max_nino_cama_adicional,
                proveedorId: tarifa.proveedor_servicio?.proveedor?.id ?? null,
                grupoAbierto: grupoHotelAbiertoDiaActivo.value,
            };
            return;
        }

        modoPrecioPendiente.value = { id: tarifa.id, nombre: tarifa.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'este ítem' };
    };

    const confirmandoModoPrecio = ref<'tarifa_fija' | 'por_persona' | null>(null);
    const confirmarModoPrecio = async (modoPrecio: 'tarifa_fija' | 'por_persona') => {
        if (!modoPrecioPendiente.value || !alternativaActiva.value) return;
        confirmandoModoPrecio.value = modoPrecio;
        try {
            const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
                proveedor_tarifa_id: modoPrecioPendiente.value.id,
                modo_precio: modoPrecio,
                cantidad: 1,
                dia_referencial: diaActivoParaAgregar.value,
                alternativa_destino_id: destinoActivoId.value,
            });
            await onServicioSueltoAgregado(res.alternativa_item, res.alternativa);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
        } finally {
            modoPrecioPendiente.value = null;
            confirmandoModoPrecio.value = null;
        }
    };

    const agregarItemProveedorHotel = async (
        proveedorTarifaId: number,
        cantidad: number,
        paxIncluidos: number[] | null = null,
        camasAdicionalesNino: number = 0,
    ) => {
        if (!alternativaActiva.value) return;
        try {
            const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
                proveedor_tarifa_id: proveedorTarifaId,
                modo_precio: 'tarifa_fija',
                cantidad,
                dia_referencial: diaActivoParaAgregar.value,
                pax_incluidos: paxIncluidos,
                camas_adicionales_nino: camasAdicionalesNino,
                alternativa_destino_id: destinoActivoId.value,
            });
            await onServicioSueltoAgregado(res.alternativa_item, res.alternativa);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
        }
    };

    // «+ Agregar hotel no registrado»: crea un OpcionHotel ad-hoc sin opción de mayorista y abre el
    // mismo picker que los hoteles registrados (matrizHotelActiva.adhoc lo distingue en el template).
    const mostrarFormHotelLocal = ref(false);
    // Cada tipo de habitación nace con el tratamiento tributario por defecto de la agencia.
    const tarifaHotelLocalVacia = (): TarifaHotelLocal => ({
        tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0,
        tip_afe_igv: configAgencia.value?.tip_afe_igv_default ?? '10',
        destino_tributario: configAgencia.value?.destino_tributario_default ?? 'nacional',
    });
    const formHotelLocal = ref<{ nombre_hotel: string; moneda: 'PEN' | 'USD'; proveedor_id: number | null; tarifas: TarifaHotelLocal[] }>({
        nombre_hotel: '', moneda: 'PEN', proveedor_id: null,
        tarifas: [tarifaHotelLocalVacia()],
    });
    // El form se arma antes de que cargue configAgencia: al abrirlo, si nadie tocó todavía la fila
    // inicial, se rearma con el default ya cargado.
    watch(mostrarFormHotelLocal, (abierto) => {
        const f = formHotelLocal.value;
        const intacto = !f.nombre_hotel && f.tarifas.length === 1 && !f.tarifas[0].precio_costo && !f.tarifas[0].precio_venta;
        if (abierto && intacto) f.tarifas = [tarifaHotelLocalVacia()];
    });
    const guardandoHotelLocal = ref(false);

    const guardarHotelLocal = async () => {
        guardandoHotelLocal.value = true;
        try {
            const res = await opcionHotelService.crear(formHotelLocal.value);
            mostrarFormHotelLocal.value = false;
            matrizHotelActiva.value = {
                tarifas: (res.opcion_hotel.opciones_hotel_tarifas ?? []).map((t: OpcionHotelTarifa) => ({
                    id: t.id, tipo_habitacion: t.tipo_habitacion, precio: Number(t.precio_venta), precioVentaCamaAdicional: null,
                })),
                moneda: res.opcion_hotel.moneda,
                nombreProveedor: res.opcion_hotel.nombre_hotel,
                adhoc: true,
                hotelId: res.opcion_hotel.id,
                fotos: res.opcion_hotel.fotos ?? [],
                grupoAbierto: grupoHotelAbiertoDiaActivo.value,
            };
            formHotelLocal.value = {
                nombre_hotel: '', moneda: 'PEN', proveedor_id: null,
                tarifas: [tarifaHotelLocalVacia()],
            };
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar el hotel', 'error');
        } finally {
            guardandoHotelLocal.value = false;
        }
    };

    const agregarItemHotelAdhocLocal = async (
        opcionHotelTarifaId: number,
        cantidad: number,
        paxIncluidos: number[] | null = null,
        camasAdicionalesNino: number = 0,
    ) => {
        if (!alternativaActiva.value) return;
        try {
            const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
                opcion_hotel_tarifa_id: opcionHotelTarifaId,
                modo_precio: 'tarifa_fija',
                cantidad,
                dia_referencial: diaActivoParaAgregar.value,
                pax_incluidos: paxIncluidos,
                camas_adicionales_nino: camasAdicionalesNino,
                alternativa_destino_id: destinoActivoId.value,
            });
            await onServicioSueltoAgregado(res.alternativa_item, res.alternativa);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
        }
    };

    // «Comparar varias opciones»: un ítem por tarifa elegida, todos con el mismo grupo_opcion_id y
    // ninguno elegido; el vendedor resuelve el grupo desde el lienzo (aceptar la reserva lo exige).
    // Si ya hay una comparación abierta en el día, se suma a ese grupo en vez de arrancar otro.
    // `cantidad` son las noches que el vendedor eligió en el picker.
    const agregarGrupoHotel = async (campo: 'proveedor_tarifa_id' | 'opcion_hotel_tarifa_id', ids: number[], cantidad: number) => {
        if (!alternativaActiva.value) return;
        const grupoOpcionId = matrizHotelActiva.value?.grupoAbierto?.id ?? generarUuidGrupo();
        try {
            let ultimoItem: AlternativaItem | null = null;
            for (const id of ids) {
                const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
                    ...(campo === 'proveedor_tarifa_id' ? { proveedor_tarifa_id: id } : { opcion_hotel_tarifa_id: id }),
                    modo_precio: 'tarifa_fija',
                    cantidad,
                    dia_referencial: diaActivoParaAgregar.value,
                    alternativa_destino_id: destinoActivoId.value,
                    grupo_opcion_id: grupoOpcionId,
                });
                ultimoItem = res.alternativa_item;
            }
            if (ultimoItem) await onServicioSueltoAgregado(ultimoItem);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar el grupo', 'error');
        }
    };
    const agregarGrupoHotelAdhocLocal = (ids: number[], cantidad: number) => agregarGrupoHotel('opcion_hotel_tarifa_id', ids, cantidad);
    const agregarGrupoProveedorHotel = (ids: number[], cantidad: number) => agregarGrupoHotel('proveedor_tarifa_id', ids, cantidad);

    // Fotos del hotel no registrado (T-020), desde el drawer de la matriz. Mismas rutas que el picker
    // de mayorista; el backend devuelve las fotos ya resueltas.
    const subiendoFotoHotelAdhoc = ref(false);
    const onSubirFotoHotelAdhoc = async (event: Event, tipoFoto: 'fachada' | 'habitacion') => {
        const input = event.target as HTMLInputElement;
        const archivo = input.files?.[0];
        input.value = '';
        const hotelId = matrizHotelActiva.value?.hotelId;
        if (!archivo || !hotelId) return;
        subiendoFotoHotelAdhoc.value = true;
        try {
            const res = await opcionMayoristaService.agregarFotoHotel(hotelId, archivo, tipoFoto);
            if (matrizHotelActiva.value) matrizHotelActiva.value.fotos = res.opcion_hotel.fotos ?? [];
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar la foto', 'error');
        } finally {
            subiendoFotoHotelAdhoc.value = false;
        }
    };

    const onEliminarFotoHotelAdhoc = async (path: string) => {
        const hotelId = matrizHotelActiva.value?.hotelId;
        if (!hotelId) return;
        try {
            const res = await opcionMayoristaService.eliminarFotoHotel(hotelId, path);
            if (matrizHotelActiva.value) matrizHotelActiva.value.fotos = res.opcion_hotel.fotos ?? [];
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la foto', 'error');
        }
    };

    return {
        nochesHotelDefault, matrizHotelActiva, modoPrecioPendiente, grupoHotelAbiertoDiaActivo, clicBibliotecaItem,
        confirmandoModoPrecio, confirmarModoPrecio, agregarItemProveedorHotel, mostrarFormHotelLocal, tarifaHotelLocalVacia, formHotelLocal,
        guardandoHotelLocal, guardarHotelLocal, agregarItemHotelAdhocLocal, agregarGrupoHotelAdhocLocal,
        agregarGrupoProveedorHotel, subiendoFotoHotelAdhoc, onSubirFotoHotelAdhoc, onEliminarFotoHotelAdhoc,
    };
}
