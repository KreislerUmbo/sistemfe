<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-clipboard-list me-2 text-primary"></i>
                    Reporte Operativo
                    <span v-if="totalSinGuia > 0" class="badge bg-warning text-dark ms-2">{{ totalSinGuia }} sin asignar</span>
                    <span v-if="filas.length > 0" class="badge ms-2" :class="checkinsCompletados === filas.length ? 'bg-success' : 'bg-info-subtle text-info-emphasis border border-info-subtle'">
                        <i class="fas fa-check me-1"></i>{{ checkinsCompletados }}/{{ filas.length }} check-in
                    </span>
                </h5>
                <small class="text-muted">{{ filas.length }} registro(s) en el rango seleccionado</small>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="exportingPdf" @click="exportarPdf">
                    <span v-if="exportingPdf" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-file-pdf me-1"></i> Exportar PDF
                </button>
                <button type="button" class="btn btn-outline-success btn-sm" :disabled="exportingExcel" @click="exportarExcel">
                    <span v-if="exportingExcel" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-file-excel me-1"></i> Exportar Excel
                </button>
            </div>
        </div>

        <!-- ═══════ Filtros ═══════ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="filtros.fecha_desde">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="filtros.fecha_hasta">
                    </div>
                    <div class="col-auto">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary" @click="irAHoy">Hoy</button>
                            <button type="button" class="btn btn-outline-primary" @click="irAEstaSemana">Esta semana</button>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                        <select class="form-select form-select-sm" v-model="filtros.destino_atractivo_id" @change="cargar">
                            <option :value="null">Todos</option>
                            <option v-for="d in filtrosDisponibles.destinos" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tour</label>
                        <select class="form-select form-select-sm" v-model="filtros.tour_id" @change="cargar">
                            <option :value="null">Todos</option>
                            <option v-for="t in filtrosDisponibles.tours" :key="t.id" :value="t.id">{{ t.codigo ? t.codigo + ' — ' + t.nombre : t.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hotel</label>
                        <select class="form-select form-select-sm" v-model="filtros.hotel_proveedor_id" @change="cargar">
                            <option :value="null">Todos</option>
                            <option v-for="h in filtrosDisponibles.hoteles" :key="h.id" :value="h.id">{{ h.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Servicio</label>
                        <select class="form-select form-select-sm" v-model="filtros.servicio_id" @change="cargar">
                            <option :value="null">Todos</option>
                            <option v-for="s in filtrosDisponibles.servicios" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="pendienteAsignar" v-model="filtros.pendiente_asignar" @change="cargar">
                            <label class="form-check-label small" for="pendienteAsignar">Solo pendiente de asignar</label>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary btn-sm" :disabled="loading" @click="buscar">
                            <i class="fas fa-search me-1"></i>Buscar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════ Tabla, agrupada por fecha ═══════ -->
        <div v-if="loading" class="text-center py-5 text-muted">
            <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
        </div>
        <div v-else-if="filasPorFecha.length === 0" class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted fst-italic">Sin servicios programados en este rango de fechas.</div>
        </div>
        <div v-else class="card border-0 shadow-sm mb-3" v-for="grupo in filasPorFecha" :key="grupo.fecha">
            <div class="card-header bg-light fw-semibold">
                {{ formatFecha(grupo.fecha) }}
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Pasajero</th>
                                <th>Hora</th>
                                <th>Servicio</th>
                                <th>Destino</th>
                                <th>Hotel</th>
                                <th>Asignación</th>
                                <th>Alimentación / discapacidad</th>
                                <th title="Vuelo que el pasajero compró por su cuenta, ajeno a la agencia">Vuelo ida (propio)</th>
                                <th title="Vuelo que el pasajero compró por su cuenta, ajeno a la agencia">Vuelo vuelta (propio)</th>
                                <th title="Vuelo vendido por la agencia — solo en la fila del pasaje aéreo cotizado">Vuelo ida (agencia)</th>
                                <th title="Vuelo vendido por la agencia — solo en la fila del pasaje aéreo cotizado">Vuelo vuelta (agencia)</th>
                                <th class="text-center pe-3">Check-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="cluster in grupo.pasajeros" :key="cluster.pasajero.id">
                                <tr v-for="(fila, i) in cluster.filas" :key="fila.reserva_item_id + '-' + fila.pasajero.id">
                                    <td v-if="i === 0" class="ps-3" :rowspan="cluster.filas.length">
                                        <router-link :to="`/agencia-viajes/reservas/${fila.reserva_id}`" class="text-decoration-none">
                                            {{ fila.pasajero.nombre || ('Pasajero ' + fila.pasajero.id) }}
                                        </router-link>
                                        <div v-if="fila.codigo_reserva" class="small text-muted">{{ fila.codigo_reserva }}</div>
                                    </td>
                                    <td>{{ fila.hora }}</td>
                                    <td>{{ fila.servicio }}</td>
                                    <td>{{ fila.destino }}</td>
                                    <td>{{ fila.hotel ?? '—' }}</td>
                                    <td style="min-width:220px">
                                        <!-- Caso guía enganchado a una Salida Operativa: el guía real es el de la
                                             salida (compartido con otras reservas) — solo lectura + link, mismo
                                             criterio que reservas/detalle.vue. Editarlo acá crearía un guía
                                             duplicado y desincronizado del de la salida. -->
                                        <div v-if="fila.origen_tipo === 'guia' && fila.salida_operativa_id" class="small">
                                            <span :class="fila.guia ? '' : 'text-muted fst-italic'">{{ fila.guia?.nombre ?? 'Sin asignar' }}</span>
                                            <div v-if="fila.salida_vehiculo" class="text-muted" style="font-size:11px">{{ fila.salida_vehiculo }}</div>
                                            <router-link :to="`/agencia-viajes/salidas/${fila.salida_operativa_id}`" class="d-block text-decoration-none" style="font-size:11px">
                                                <i class="fas fa-link me-1"></i>Vía salida operativa
                                            </router-link>
                                        </div>
                                        <!-- Caso guía (origen_tipo='guia') sin salida — poco frecuente en la práctica. -->
                                        <select v-else-if="fila.origen_tipo === 'guia'" class="form-select form-select-sm"
                                            :value="fila.guia?.id ?? ''"
                                            @change="guardarGuia(fila, valorSelect($event))">
                                            <option value="">Sin asignar</option>
                                            <option v-for="g in guias" :key="g.id" :value="g.id">
                                                {{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}
                                            </option>
                                        </select>
                                        <!-- Caso proveedor (origen_tipo='proveedor') — el caso real más común. Mismo
                                             buscador inline que reservas/detalle.vue, no un <select> nativo porque la
                                             biblioteca de tarifas puede ser larga. -->
                                        <template v-else-if="fila.origen_tipo === 'proveedor'">
                                            <div v-if="proveedorBuscando !== fila.reserva_item_id"
                                                class="d-flex align-items-center justify-content-between border rounded px-2 py-1 bg-white"
                                                style="cursor:pointer;min-height:31px" @click="abrirBusquedaProveedor(fila)">
                                                <span class="small" :class="{ 'text-warning fw-semibold': !fila.proveedor }">{{ fila.proveedor ?? 'Sin asignar' }}</span>
                                                <i class="fas fa-pen text-muted small"></i>
                                            </div>
                                            <div v-else class="position-relative">
                                                <input type="text" class="form-control form-control-sm" placeholder="Buscar proveedor..."
                                                    v-model="proveedorSearch[fila.reserva_item_id]"
                                                    @blur="cerrarBusquedaProveedor(fila.reserva_item_id)" autofocus>
                                                <div class="list-group position-absolute w-100 shadow-sm" style="z-index:10;max-height:220px;overflow-y:auto">
                                                    <button type="button" class="list-group-item list-group-item-action py-1 small text-muted" @mousedown.prevent="elegirProveedor(fila, null)">
                                                        Sin asignar
                                                    </button>
                                                    <button type="button" class="list-group-item list-group-item-action py-1 small" v-for="t in proveedoresFiltrados(fila)" :key="t.id" @mousedown.prevent="elegirProveedor(fila, t.id)">
                                                        {{ t.proveedor_servicio?.proveedor?.nombre_comercial || t.proveedor_servicio?.proveedor?.razon_social || ('Tarifa #' + t.id) }}{{ t.proveedor_servicio?.proveedor?.es_referencial ? ' (Referencial)' : '' }}
                                                        <span class="text-muted">— {{ t.tipo_tarifa }} · {{ t.modalidad }} · {{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(0) }}</span>
                                                    </button>
                                                    <div v-if="proveedoresFiltrados(fila).length === 0" class="list-group-item small text-muted py-1">Sin resultados</div>
                                                </div>
                                            </div>
                                        </template>
                                        <span v-else class="text-muted small">—</span>
                                    </td>
                                    <td v-if="i === 0" :rowspan="cluster.filas.length" class="small">
                                        {{ fila.pasajero.alimentacion_especial || '—' }}
                                        <div v-if="fila.pasajero.discapacidad" class="text-warning">{{ fila.pasajero.discapacidad }}</div>
                                    </td>
                                    <td v-if="i === 0" :rowspan="cluster.filas.length" class="small">
                                        <span v-if="fila.vuelo_ida">{{ fila.vuelo_ida.aerolinea }}<br>{{ fila.vuelo_ida.fecha }} {{ fila.vuelo_ida.hora }}</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td v-if="i === 0" :rowspan="cluster.filas.length" class="small">
                                        <span v-if="fila.vuelo_vuelta">{{ fila.vuelo_vuelta.aerolinea }}<br>{{ fila.vuelo_vuelta.fecha }} {{ fila.vuelo_vuelta.hora }}</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="small">
                                        <span v-if="fila.vuelo_agencia_ida">{{ fila.vuelo_agencia_ida.numero }} · {{ fila.vuelo_agencia_ida.aerolinea }}<br>{{ fila.vuelo_agencia_ida.fecha }} {{ fila.vuelo_agencia_ida.hora }}</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="small">
                                        <span v-if="fila.vuelo_agencia_vuelta">{{ fila.vuelo_agencia_vuelta.numero }} · {{ fila.vuelo_agencia_vuelta.aerolinea }}<br>{{ fila.vuelo_agencia_vuelta.fecha }} {{ fila.vuelo_agencia_vuelta.hora }}</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="text-center pe-3">
                                        <input type="checkbox" class="form-check-input" :checked="fila.checkin_realizado"
                                            @change="toggleCheckin(fila, valorCheckbox($event))">
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { reporteOperativoService } from '@/services/admin/reporteOperativoService';
import { reservaItemService } from '@/services/admin/reservaItemService';
import { guiaService } from '@/services/admin/guiaService';
import { proveedorService } from '@/services/admin/proveedorService';
import { useToast } from '@/composables/useToast';
import { formatFecha } from '@/helpers/fecha';
import type { Guia, ProveedorTarifa, ReporteOperativoFila, ReporteOperativoFiltrosDisponibles } from '@/types/agencia-viajes';

const toast = useToast();

const filtros = ref<{
    fecha_desde: string; fecha_hasta: string; pendiente_asignar: boolean;
    destino_atractivo_id: number | null; tour_id: number | null;
    hotel_proveedor_id: number | null; servicio_id: number | null;
}>({
    fecha_desde: hoyISO(),
    fecha_hasta: hoyISO(),
    pendiente_asignar: false,
    destino_atractivo_id: null,
    tour_id: null,
    hotel_proveedor_id: null,
    servicio_id: null,
});

const filas = ref<ReporteOperativoFila[]>([]);
const totalSinGuia = ref(0);
const loading = ref(false);
const guias = ref<Guia[]>([]);
const filtrosDisponibles = ref<Omit<ReporteOperativoFiltrosDisponibles, 'code'>>({
    destinos: [], servicios: [], tours: [], hoteles: [],
});

// ── Reasignar proveedor inline (origen_tipo='proveedor') ────────────────────
// Mismo patrón que reservas/detalle.vue (buscador con biblioteca completa cargada
// una vez, filtrada en cliente por servicio_id + texto) — es el caso REAL más común
// en el reporte (a diferencia del select de guía de arriba, casi no usado en la
// práctica: los datos reales de agencia-demo son casi todos origen_tipo='proveedor').
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const proveedorBuscando = ref<number | null>(null); // reserva_item_id en modo búsqueda
const proveedorSearch = ref<Record<number, string>>({});

function hoyISO(): string {
    return new Date().toISOString().slice(0, 10);
}

function sumarDias(fechaISO: string, dias: number): string {
    const d = new Date(fechaISO + 'T00:00:00');
    d.setDate(d.getDate() + dias);
    return d.toISOString().slice(0, 10);
}

// Saltar de rango (Hoy/Esta semana) limpia los 4 filtros de dimensión: sus opciones
// están acotadas al rango de fecha (filtrosDisponibles()), una selección del rango
// anterior podría no existir más en el nuevo y dejar el reporte vacío en silencio.
const limpiarFiltrosDimension = () => {
    filtros.value.destino_atractivo_id = null;
    filtros.value.tour_id = null;
    filtros.value.hotel_proveedor_id = null;
    filtros.value.servicio_id = null;
};

const irAHoy = () => {
    filtros.value.fecha_desde = hoyISO();
    filtros.value.fecha_hasta = hoyISO();
    limpiarFiltrosDimension();
    buscar();
};

const irAEstaSemana = () => {
    filtros.value.fecha_desde = hoyISO();
    filtros.value.fecha_hasta = sumarDias(hoyISO(), 6);
    limpiarFiltrosDimension();
    buscar();
};

// Resumen de progreso del check-in del rango cargado — el dato que más le importa a
// un coordinador el día del tour ("¿cuánta gente falta confirmar?"), antes solo
// visible fila por fila.
const checkinsCompletados = computed(() => filas.value.filter((f) => f.checkin_realizado).length);

// Un solo reporte con selector de rango (plan-modulo-cotizaciones-reservas.md §8) —
// no dos pantallas separadas para uso diario vs. planificación.
//
// Pedido del usuario tras ver el reporte en vivo desordenado: antes la tabla era
// plana ordenada solo por hora, así que las filas de un mismo pasajero (sus
// distintos servicios ese día) quedaban intercaladas con las de otros pasajeros.
// Se agrupa un nivel más: fecha → pasajero (con sus filas juntas, ordenadas por
// hora) — mismo criterio de agrupación que ya se usa en el PDF/Excel
// (armarVistaAgrupada() en el backend), pero sin la capa de Tour/"Servicios
// sueltos": esta pantalla sigue siendo la vista de trabajo diaria con los 4
// filtros de dimensión, no el documento para repartir en campo.
type PasajeroCluster = { pasajero: ReporteOperativoFila['pasajero']; filas: ReporteOperativoFila[] };

const filasPorFecha = computed(() => {
    const grupos: { fecha: string; pasajeros: PasajeroCluster[] }[] = [];
    const indiceFecha = new Map<string, number>();
    const indicePasajeroPorFecha = new Map<string, Map<number, number>>();

    for (const fila of filas.value) {
        const keyFecha = fila.fecha ?? 'sin-fecha';
        if (!indiceFecha.has(keyFecha)) {
            indiceFecha.set(keyFecha, grupos.length);
            grupos.push({ fecha: keyFecha, pasajeros: [] });
            indicePasajeroPorFecha.set(keyFecha, new Map());
        }
        const grupo = grupos[indiceFecha.get(keyFecha)!];
        const indicePasajero = indicePasajeroPorFecha.get(keyFecha)!;

        if (!indicePasajero.has(fila.pasajero.id)) {
            indicePasajero.set(fila.pasajero.id, grupo.pasajeros.length);
            grupo.pasajeros.push({ pasajero: fila.pasajero, filas: [] });
        }
        grupo.pasajeros[indicePasajero.get(fila.pasajero.id)!].filas.push(fila);
    }

    for (const grupo of grupos) {
        grupo.pasajeros.sort((a, b) => (a.pasajero.nombre ?? '').localeCompare(b.pasajero.nombre ?? ''));
        for (const cluster of grupo.pasajeros) {
            cluster.filas.sort((a, b) => (a.hora ?? '').localeCompare(b.hora ?? ''));
        }
    }

    return grupos;
});

const filtrosParaApi = () => ({
    fecha_desde: filtros.value.fecha_desde,
    fecha_hasta: filtros.value.fecha_hasta,
    pendiente_asignar: filtros.value.pendiente_asignar || undefined,
    destino_atractivo_id: filtros.value.destino_atractivo_id ?? undefined,
    tour_id: filtros.value.tour_id ?? undefined,
    hotel_proveedor_id: filtros.value.hotel_proveedor_id ?? undefined,
    servicio_id: filtros.value.servicio_id ?? undefined,
});

const cargar = async () => {
    loading.value = true;
    try {
        const res = await reporteOperativoService.obtener(filtrosParaApi());
        filas.value = res.filas;
        totalSinGuia.value = res.total_sin_guia;
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'No se pudo cargar el reporte.');
    } finally {
        loading.value = false;
    }
};

const cargarFiltrosDisponibles = async () => {
    const res = await reporteOperativoService.filtrosDisponibles({
        fecha_desde: filtros.value.fecha_desde,
        fecha_hasta: filtros.value.fecha_hasta,
    });
    filtrosDisponibles.value = { destinos: res.destinos, servicios: res.servicios, tours: res.tours, hoteles: res.hoteles };
};

// Botón "Buscar" (o Hoy/Esta semana): el rango de fecha pudo haber cambiado, así que
// las opciones de los 4 selects también se refrescan — independiente de cargar(), van
// en paralelo (mismo criterio que el fix de awaits secuenciales de paquetes/detalle.vue).
const buscar = () => {
    Promise.all([cargarFiltrosDisponibles(), cargar()]);
};

const cargarGuias = async () => {
    const res = await guiaService.listar({ page: 1 });
    guias.value = res.guias ?? [];
};

// ── Reasignar guía inline (solo para ítems origen_tipo='guia') ──────────────
// El select vive por fila (pasajero), pero el guía pertenece al reserva_item —
// se actualiza en TODAS las filas que comparten ese reserva_item_id para que no
// quede una vista inconsistente entre pasajeros del mismo servicio.
const guardarGuia = async (fila: ReporteOperativoFila, nuevoGuiaId: number | null) => {
    try {
        await reservaItemService.actualizar(fila.reserva_item_id, { guia_id: nuevoGuiaId });
        const nuevoGuia = nuevoGuiaId ? guias.value.find((g) => g.id === nuevoGuiaId) ?? null : null;
        const sinGuia = !nuevoGuia || !!nuevoGuia.es_referencial;
        for (const f of filas.value) {
            if (f.reserva_item_id === fila.reserva_item_id) {
                f.guia = nuevoGuia ? { id: nuevoGuia.id, nombre: nuevoGuia.nombre, es_referencial: !!nuevoGuia.es_referencial } : null;
                f.sin_guia = sinGuia;
            }
        }
        totalSinGuia.value = filas.value.filter((f) => f.sin_guia).length;
        toast.success('Guía actualizado');
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'No se pudo actualizar el guía');
    }
};

const valorSelect = (event: Event): number | null => {
    const valor = (event.target as HTMLSelectElement).value;
    return valor ? Number(valor) : null;
};

// ── Reasignar proveedor inline (origen_tipo='proveedor') ────────────────────
// La búsqueda matchea por nombre comercial O razón social (el usuario puede
// conocer al proveedor por cualquiera de los dos) — la lista de resultados
// solo MUESTRA nombre comercial (con fallback a razón social), mismo criterio
// que ya se usa en el resto del reporte.
const proveedoresFiltrados = (fila: ReporteOperativoFila) => {
    const q = (proveedorSearch.value[fila.reserva_item_id] ?? '').trim().toLowerCase();
    return bibliotecaTarifas.value
        .filter((t) => !fila.servicio_id || t.proveedor_servicio?.destino_servicio?.servicio_id === fila.servicio_id)
        .filter((t) => {
            if (!q) return true;
            const proveedor = t.proveedor_servicio?.proveedor;
            return (proveedor?.nombre_comercial ?? '').toLowerCase().includes(q)
                || (proveedor?.razon_social ?? '').toLowerCase().includes(q);
        })
        .slice(0, 30);
};

const abrirBusquedaProveedor = (fila: ReporteOperativoFila) => {
    proveedorBuscando.value = fila.reserva_item_id;
    proveedorSearch.value[fila.reserva_item_id] = '';
};

// mismo truco que detalle.vue: @mousedown.prevent en los botones de la lista
// dispara la selección ANTES de este blur — sin el delay, el mousedown nunca
// llegaría a disparar (el blur gana la carrera).
const cerrarBusquedaProveedor = (reservaItemId: number) => {
    setTimeout(() => { if (proveedorBuscando.value === reservaItemId) proveedorBuscando.value = null; }, 200);
};

// A diferencia de guardarGuia() (que solo toca guia_id/sin_guia y puede parchear el
// estado local), reasignar el proveedor puede cambiar destino/servicio/hotel de la
// fila (un proveedor del mismo servicio_id puede pertenecer a otro destino_servicio)
// — recalcularlo bien acá duplicaría la lógica de armarFila() del backend. Más simple
// y correcto: refrescar el reporte completo.
const elegirProveedor = async (fila: ReporteOperativoFila, proveedorTarifaId: number | null) => {
    proveedorBuscando.value = null;
    try {
        await reservaItemService.actualizar(fila.reserva_item_id, { proveedor_tarifa_id: proveedorTarifaId });
        toast.success('Proveedor actualizado');
        await cargar();
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'No se pudo actualizar el proveedor');
    }
};

// ── Check-in inline ───────────────────────────────────────────────────────
const toggleCheckin = async (fila: ReporteOperativoFila, marcado: boolean) => {
    try {
        await reporteOperativoService.checkin(fila.reserva_item_id, fila.pasajero.id, marcado);
        fila.checkin_realizado = marcado;
        fila.checkin_hora = marcado ? new Date().toISOString() : null;
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'No se pudo actualizar el check-in');
    }
};

const valorCheckbox = (event: Event): boolean => (event.target as HTMLInputElement).checked;

// ── Exportar PDF (URL firmada) / Excel (descarga directa) ───────────────────
const exportingPdf = ref(false);
const exportarPdf = async () => {
    exportingPdf.value = true;
    try {
        const { url } = await reporteOperativoService.pdfUrl(filtrosParaApi());
        window.open(url, '_blank');
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'No se pudo generar el PDF.');
    } finally {
        exportingPdf.value = false;
    }
};

const exportingExcel = ref(false);
const exportarExcel = async () => {
    exportingExcel.value = true;
    try {
        const blob = await reporteOperativoService.exportarExcel(filtrosParaApi());
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = nombreArchivo('xlsx');
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (e: any) {
        toast.error('No se pudo generar el Excel.');
    } finally {
        exportingExcel.value = false;
    }
};

// Mismo formato que ReporteOperativoController::nombreArchivo() en el backend — el
// nombre del blob lo pone el navegador (el header de la respuesta no alcanza), así que
// hay que rearmarlo igual acá para que coincida.
const nombreArchivo = (extension: string): string => {
    const formatear = (iso: string) => {
        const [anio, mes, dia] = iso.split('-');
        return `${dia}-${mes}-${anio}`;
    };
    return `reporte_operativo_del_${formatear(filtros.value.fecha_desde)}_al_${formatear(filtros.value.fecha_hasta)}.${extension}`;
};

const cargarBibliotecaTarifas = async () => {
    const res = await proveedorService.biblioteca();
    bibliotecaTarifas.value = res.proveedor_tarifas ?? [];
};

// Independientes entre sí — Promise.all en vez de awaits secuenciales
// (mismo criterio que el fix de paquetes/detalle.vue y cotizador/editar.vue).
onMounted(() => {
    Promise.all([cargarGuias(), cargarBibliotecaTarifas(), cargarFiltrosDisponibles(), cargar()]);
});
</script>
