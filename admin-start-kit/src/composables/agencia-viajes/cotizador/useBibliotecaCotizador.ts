import { computed, nextTick, ref, watch, type ComputedRef, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { bibliotecaCotizadorService, type BibliotecaTipo } from '@/services/admin/bibliotecaCotizadorService';
import { escaparHtml } from '@/helpers/escaparHtml';
import { useToast } from '@/composables/useToast';
import type {
    Alternativa, AlternativaDestino, AlternativaItem, BibliotecaResultado, ConfiguracionAgencia, ProveedorTarifa, ProveedorTipo, Servicio,
} from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que la biblioteca necesita del núcleo, del lienzo, del drawer y de los hoteles. */
export interface DependenciasBiblioteca {
    alternativaActiva: Readonly<Ref<Alternativa | null>> | ComputedRef<Alternativa | null>;
    itemsSinAjuste: ComputedRef<AlternativaItem[]>;
    configAgencia: Ref<ConfiguracionAgencia | null>;
    cargarCotizacion: () => Promise<void>;
    alternativaDestinos: ComputedRef<AlternativaDestino[]>;
    destinoActivoId: Ref<number | null>;
    diaActivoParaAgregar: ComputedRef<number>;
    diasCreados: Ref<number[]>;
    diasDesdeCombo: Ref<Set<number>>;
    drawerBibliotecaAbierto: Ref<boolean>;
    cerrarDrawerBiblioteca: () => void;
    /** Clic en una tarifa de proveedor (hotel → matriz; otro servicio → modo de precio). */
    clicBibliotecaItem: (tarifa: ProveedorTarifa) => Promise<void>;
}

/** Un chip de la biblioteca: los fijos no tienen tipo de proveedor; los de proveedor, siempre. */
export type ChipBiblioteca =
    | { tipo: Exclude<BibliotecaTipo, 'proveedor'>; proveedorTipoId: null; nombre: string; icono: string }
    | { tipo: 'proveedor'; proveedorTipoId: number; nombre: string; icono: string };

type PlantillaBiblioteca = Extract<BibliotecaResultado, { tipo_resultado: 'tour' | 'paquete' }>;
type BibliotecaFila = ProveedorTarifa & { _rangoHabitaciones?: boolean };
export type FilaBiblioteca = { kind: 'proveedor'; data: BibliotecaFila } | { kind: 'plantilla'; data: PlantillaBiblioteca };
export type VistaPreviaCombo = Array<{ tourOrigenId: number; dia: number; nombre: string; total: number }>;

// Los cuatro tipos de proveedor de más uso tienen chip fijo; se identifican por slug porque el id
// cambia por tenant. El resto del catálogo (y cualquier tipo nuevo) cae en «Más ▾».
const SLUGS_CHIPS_FIJOS_PROVEEDOR: Array<{ slug: string; nombre: string; icono: string }> = [
    { slug: 'alojamiento-hoteles', nombre: 'Alojamiento', icono: 'fa-bed' },
    { slug: 'transporte', nombre: 'Transporte', icono: 'fa-bus' },
    { slug: 'alimentacion', nombre: 'Alimentación', icono: 'fa-utensils' },
    { slug: 'actividades', nombre: 'Actividades', icono: 'fa-hiking' },
];

const CHIP_TODOS: ChipBiblioteca = { tipo: 'todos', proveedorTipoId: null, nombre: 'Todos', icono: 'fa-th' };
const BIBLIOTECA_POR_PAGINA = 12; // grilla de 3 columnas × 4 filas

/**
 * Biblioteca del drawer: un solo endpoint (BibliotecaCotizadorController) decide contra qué tablas
 * busca según el chip activo. Chips, filtros, búsqueda («la última petición gana»), grilla unificada
 * paginada en el cliente, y carga de un tour o paquete con la vista previa del combo.
 */
export function useBibliotecaCotizador(deps: DependenciasBiblioteca) {
    const {
        alternativaActiva, itemsSinAjuste, configAgencia, cargarCotizacion, alternativaDestinos, destinoActivoId,
        diaActivoParaAgregar, diasCreados, diasDesdeCombo, drawerBibliotecaAbierto, cerrarDrawerBiblioteca, clicBibliotecaItem,
    } = deps;
    const toast = useToast();

    // Catálogo real de proveedor_tipos (cambia por tenant); lo carga la página al montar.
    const proveedorTipos = ref<ProveedorTipo[]>([]);

    const chipsFijos = computed<ChipBiblioteca[]>(() => [
        CHIP_TODOS,
        { tipo: 'tour', proveedorTipoId: null, nombre: 'Tours', icono: 'fa-route' },
        { tipo: 'paquete', proveedorTipoId: null, nombre: 'Paquetes', icono: 'fa-suitcase-rolling' },
        ...SLUGS_CHIPS_FIJOS_PROVEEDOR.flatMap((def): ChipBiblioteca[] => {
            const tipo = proveedorTipos.value.find((t) => t.slug === def.slug);
            return tipo ? [{ tipo: 'proveedor', proveedorTipoId: tipo.id, nombre: def.nombre, icono: def.icono }] : [];
        }),
    ]);

    // «Más ▾»: todo tipo de proveedor que no tenga chip fijo.
    const chipsProveedores = computed<ChipBiblioteca[]>(() => {
        const slugsFijos = new Set(SLUGS_CHIPS_FIJOS_PROVEEDOR.map((d) => d.slug));
        return proveedorTipos.value
            .filter((t) => !slugsFijos.has(t.slug))
            .map((t): ChipBiblioteca => ({ tipo: 'proveedor', proveedorTipoId: t.id, nombre: t.nombre, icono: 'fa-ellipsis-h' }));
    });

    const mostrarMasChips = ref(false);
    const chipActivoState = ref<ChipBiblioteca>(CHIP_TODOS);
    const chipActivo = (chip: ChipBiblioteca) => chip.tipo === chipActivoState.value.tipo && chip.proveedorTipoId === chipActivoState.value.proveedorTipoId;
    const chipMasActivo = computed(() => chipsProveedores.value.find((c) => chipActivo(c)) ?? null);

    const seleccionarChip = (chip: ChipBiblioteca) => {
        chipActivoState.value = chip;
        cargarBiblioteca();
    };

    const bibliotecaSearch = ref('');
    const bibliotecaResultados = ref<BibliotecaResultado[]>([]);
    let bibliotecaTimeout: ReturnType<typeof setTimeout> | undefined;

    // Filtros combinables. servicio_id/proveedor_id dejan fuera los tours y paquetes (no aplican).
    const filtroDestinoId = ref<number | null>(null);
    const filtroDestinoTexto = ref('');
    const filtroServicioId = ref<number | null>(null);
    const filtroProveedorId = ref<number | null>(null);
    const serviciosFiltro = ref<Servicio[]>([]); // lo carga la página al montar

    // T-032 (R4): mientras llega la respuesta la lista se atenúa y no se puede hacer clic en ella.
    // Solo cuenta la última petición: una respuesta vieja que llega tarde se descarta.
    const cargandoBiblioteca = ref(false);
    let bibliotecaPeticion = 0;
    const cargarBiblioteca = async () => {
        const miPeticion = ++bibliotecaPeticion;
        cargandoBiblioteca.value = true;
        try {
            const res = await bibliotecaCotizadorService.buscar({
                tipo: chipActivoState.value.tipo,
                proveedor_tipo_id: chipActivoState.value.proveedorTipoId ?? undefined,
                search: bibliotecaSearch.value || undefined,
                destino_atractivo_id: filtroDestinoId.value ?? undefined,
                servicio_id: filtroServicioId.value ?? undefined,
                proveedor_id: filtroProveedorId.value ?? undefined,
            });
            if (miPeticion !== bibliotecaPeticion) return;
            bibliotecaResultados.value = res.resultados;
            bibliotecaPaginaActual.value = 1;
        } finally {
            if (miPeticion === bibliotecaPeticion) cargandoBiblioteca.value = false;
        }
    };

    const onBibliotecaSearch = () => {
        clearTimeout(bibliotecaTimeout);
        bibliotecaTimeout = setTimeout(cargarBiblioteca, 300);
    };

    watch([filtroDestinoId, filtroServicioId, filtroProveedorId], onBibliotecaSearch);

    // T-035 (R3): cada vez que se abre el drawer, el filtro vuelve al destino activo de la alternativa
    // (sin catálogo → sin filtro). Mientras siga abierto se respeta lo que cambie el vendedor.
    watch(drawerBibliotecaAbierto, async (abierto) => {
        if (!abierto) return;
        const destino = alternativaDestinos.value.find((d) => d.id === destinoActivoId.value);
        const nuevoId = destino?.destino_atractivo_id ?? null;
        filtroDestinoTexto.value = nuevoId ? (destino?.destino_texto ?? '') : '';
        filtroDestinoId.value = nuevoId;
        // El cambio de filtro agenda una recarga con 300 ms de espera (pensada para cuando se escribe).
        // Al abrir no hay nada que esperar: tras nextTick se cancela esa recarga y se carga ya, para
        // que la banda y la lista no queden desfasadas.
        await nextTick();
        clearTimeout(bibliotecaTimeout);
        cargarBiblioteca();
    });

    const etiquetaCategoriaPaquete = (c: string) => ({ local: 'Local', nacional: 'Nacional', internacional: 'Internacional' } as Record<string, string>)[c] ?? c;

    const bibliotecaToursPaquetes = computed(() =>
        bibliotecaResultados.value.filter((r) => r.tipo_resultado === 'tour' || r.tipo_resultado === 'paquete') as PlantillaBiblioteca[],
    );

    // Un hotel con varios tipos de habitación sale una sola vez: la tarifa de menor precio_venta_adulto
    // lo representa (clicBibliotecaItem pide después la matriz completa). Las tarifas sin
    // tipo_habitacion (tour, transporte, restaurante…) se listan una por una.
    const bibliotecaProveedorAgrupada = computed<BibliotecaFila[]>(() => {
        const tarifas = bibliotecaResultados.value.filter((r) => r.tipo_resultado === 'proveedor_tarifa') as ProveedorTarifa[];

        const gruposPorServicio = new Map<number, ProveedorTarifa[]>();
        for (const t of tarifas) {
            if (!t.tipo_habitacion) continue;
            const grupo = gruposPorServicio.get(t.proveedor_servicio_id) ?? [];
            grupo.push(t);
            gruposPorServicio.set(t.proveedor_servicio_id, grupo);
        }

        const vistos = new Set<number>();
        const filas: BibliotecaFila[] = [];
        for (const t of tarifas) {
            if (!t.tipo_habitacion) {
                filas.push(t);
                continue;
            }
            if (vistos.has(t.proveedor_servicio_id)) continue;
            vistos.add(t.proveedor_servicio_id);

            const grupo = gruposPorServicio.get(t.proveedor_servicio_id)!;
            const representativa = grupo.reduce((min, cur) =>
                Number(cur.precio_venta_adulto) < Number(min.precio_venta_adulto) ? cur : min,
            );
            filas.push({ ...representativa, _rangoHabitaciones: grupo.length > 1 });
        }
        return filas;
    });

    // Grilla unificada (tarifas + tours/paquetes) paginada en el cliente: el endpoint trae como mucho
    // ~150 filas, así que no hace falta paginar en el backend.
    const filasBibliotecaUnificadas = computed<FilaBiblioteca[]>(() => [
        ...bibliotecaProveedorAgrupada.value.map((data): FilaBiblioteca => ({ kind: 'proveedor', data })),
        ...bibliotecaToursPaquetes.value.map((data): FilaBiblioteca => ({ kind: 'plantilla', data })),
    ]);

    const bibliotecaPaginaActual = ref(1);
    const bibliotecaTotalPaginas = computed(() => Math.max(1, Math.ceil(filasBibliotecaUnificadas.value.length / BIBLIOTECA_POR_PAGINA)));
    const filasBibliotecaPagina = computed(() => {
        const inicio = (bibliotecaPaginaActual.value - 1) * BIBLIOTECA_POR_PAGINA;
        return filasBibliotecaUnificadas.value.slice(inicio, inicio + BIBLIOTECA_POR_PAGINA);
    });

    const cargandoFilaBibliotecaId = ref<string | null>(null);
    const onClicFilaBiblioteca = async (fila: FilaBiblioteca) => {
        cargandoFilaBibliotecaId.value = `${fila.kind}-${fila.data.id}`;
        try {
            if (fila.kind === 'proveedor') await clicBibliotecaItem(fila.data);
            else await clicResultadoPlantilla(fila.data);
        } finally {
            cargandoFilaBibliotecaId.value = null;
        }
    };

    // Franja de vista previa cuando el tour o paquete recién cargado ocupa más de un día (solo un
    // paquete_combo puede: un tour_simple cae entero en el día activo).
    const vistaPreviaCombo = ref<VistaPreviaCombo | null>(null);
    const modoToursNuevo = computed(() => configAgencia.value?.modo_tours === 'nuevo');

    // Clic en un tour o paquete: carga todos sus ítems en la alternativa activa, sin confirmación (la
    // tarjeta ya muestra cuántos ítems trae). A diferencia de un servicio suelto, esto sí cierra el
    // drawer: el vendedor tiene que ver el resultado en el lienzo.
    const clicResultadoPlantilla = async (p: PlantillaBiblioteca) => {
        if (!alternativaActiva.value) return;
        // En modo nuevo un tour solo de itinerario no se carga (el backend también lo rechaza con 422).
        if (modoToursNuevo.value && p.tipo_resultado === 'tour' && p.uso === 'solo_itinerario') {
            toast.warning('Es un tour solo de itinerario (de mayorista): no se carga en la cotización.');
            return;
        }
        const diaInicioCarga = diaActivoParaAgregar.value;
        try {
            const res = await alternativaItemService.cargarDesdePlantilla(alternativaActiva.value.id, {
                paquete_plantilla_id: p.id,
                dia_referencial: diaInicioCarga,
                alternativa_destino_id: destinoActivoId.value,
            });
            if (res.guias_pendientes.length) {
                const lista = res.guias_pendientes
                    .map((g) => `<li>${escaparHtml(g.guia_nombre ?? 'Guía')} — ${escaparHtml(g.tour_origen_nombre)}${g.destino_nombre ? ` (${escaparHtml(g.destino_nombre)})` : ''}</li>`)
                    .join('');
                await (Swal as TVueSwalInstance).fire({
                    title: 'Guías pendientes de asignar',
                    html: `<p class="text-start mb-1">El costo ya se sumó al total — falta confirmar cuál guía puntual queda asignado (eso se hace recién al reservar):</p><ul class="text-start">${lista}</ul>`,
                    icon: 'info',
                });
            }

            // Avisos del modo nuevo: sin fecha (precio base), tour sin costo estimado, tour del combo desactivado.
            if (res.avisos?.length) {
                res.avisos.forEach((aviso) => toast.warning(aviso));
            }

            const idsTourOrigen = new Set(res.items_agregados.map((i) => i.tour_origen_id).filter((id): id is number => id != null));

            await cargarCotizacion();
            cerrarDrawerBiblioteca();

            // Al recargar, los días salen solo de los ítems reales: un tour hijo sin Incluye no genera
            // ningún ítem y su día quedaría sin pestaña.
            if (res.dia_final_combo > diasCreados.value.length) {
                diasCreados.value = Array.from({ length: res.dia_final_combo }, (_, i) => i + 1);
            }
            for (let d = diaInicioCarga; d <= res.dia_final_combo; d++) {
                diasDesdeCombo.value.add(d);
            }

            vistaPreviaCombo.value = calcularVistaPreviaCombo(idsTourOrigen);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar la plantilla', 'error');
        }
    };

    // Un renglón por tour del combo con su día y su total; null si el combo no ocupa más de un día.
    const calcularVistaPreviaCombo = (idsTourOrigen: Set<number>): VistaPreviaCombo | null => {
        if (idsTourOrigen.size < 2) return null;

        const grupos = new Map<number, VistaPreviaCombo[number]>();
        for (const item of itemsSinAjuste.value) {
            if (!item.tour_origen_id || !idsTourOrigen.has(item.tour_origen_id)) continue;
            const existente = grupos.get(item.tour_origen_id);
            if (existente) {
                existente.total += Number(item.total_convertido);
            } else {
                grupos.set(item.tour_origen_id, {
                    tourOrigenId: item.tour_origen_id,
                    dia: item.dia_referencial ?? 0,
                    nombre: item.tour_origen?.nombre ?? 'Tour',
                    total: Number(item.total_convertido),
                });
            }
        }

        const dias = new Set([...grupos.values()].map((g) => g.dia));
        return dias.size > 1 ? [...grupos.values()].sort((a, b) => a.dia - b.dia) : null;
    };

    return {
        proveedorTipos, chipsFijos, chipsProveedores, mostrarMasChips, chipMasActivo, chipActivo, seleccionarChip,
        bibliotecaSearch, filtroDestinoId, filtroDestinoTexto, filtroServicioId, filtroProveedorId, serviciosFiltro,
        cargandoBiblioteca, cargarBiblioteca, onBibliotecaSearch, etiquetaCategoriaPaquete, bibliotecaPaginaActual,
        bibliotecaTotalPaginas, filasBibliotecaPagina, cargandoFilaBibliotecaId,
        onClicFilaBiblioteca, vistaPreviaCombo, modoToursNuevo,
    };
}
