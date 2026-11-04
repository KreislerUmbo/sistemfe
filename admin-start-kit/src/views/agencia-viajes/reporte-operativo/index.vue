<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-clipboard-list me-2 text-primary"></i>
                    Reporte Operativo
                    <span v-if="totalSinGuia > 0" class="badge bg-warning text-dark ms-2">{{ totalSinGuia }} sin asignar</span>
                    <span v-if="totalPasajeros > 0" class="badge ms-2" :class="pasajerosConCheckin === totalPasajeros ? 'bg-success' : 'bg-info-subtle text-info-emphasis border border-info-subtle'">
                        <i class="fas fa-check me-1"></i>{{ pasajerosConCheckin }}/{{ totalPasajeros }} pasajeros con check-in
                    </span>
                </h5>
                <small class="text-muted">{{ filasReales.length }} registro(s) en el rango seleccionado</small>
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
                        <CampoFecha v-model="filtros.fecha_desde" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hasta</label>
                        <CampoFecha v-model="filtros.fecha_hasta" :min="filtros.fecha_desde || undefined" :max="hastaMaximo" />
                    </div>
                    <div class="col-auto">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary" @click="irAHoy">Hoy</button>
                            <button type="button" class="btn btn-outline-primary" @click="irAManana">Mañana</button>
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
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Buscar</label>
                        <input id="busquedaReporte" type="search" class="form-control form-control-sm" v-model="filtros.q" maxlength="100"
                            placeholder="Pasajero, documento o código de reserva" @input="buscarConPausa" @keyup.enter="buscar">
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
                <!-- T-058: un botón por servicio del día; marca a todos sus pasajeros de una vez. -->
                <div v-if="grupo.servicios.length" class="d-flex flex-wrap gap-2 mt-2">
                    <template v-for="serv in grupo.servicios" :key="serv.key">
                        <button v-if="serv.pendientes.length" type="button" class="btn btn-outline-success btn-sm fw-normal"
                            :disabled="checkinMasivoEnCurso === serv.key" @click="checkinATodos(serv)">
                            <span v-if="checkinMasivoEnCurso === serv.key" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-check-double me-1"></i>
                            Check-in a todos · {{ serv.nombre }} ({{ serv.pendientes.length }})
                        </button>
                        <span v-else class="badge bg-success-subtle text-success-emphasis border border-success-subtle fw-normal align-self-center">
                            <i class="fas fa-check me-1"></i>{{ serv.nombre }}: todos con check-in
                        </span>
                    </template>
                </div>
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
                                            <div v-if="fila.motivo_pendiente" class="text-warning" style="font-size:11px"><i class="fas fa-exclamation-triangle me-1"></i>{{ fila.motivo_pendiente }}</div>
                                        </div>
                                        <!-- Caso guía (origen_tipo='guia') sin salida — poco frecuente en la práctica. -->
                                        <!-- En un <template> para no cortar la cadena v-if/v-else-if con el aviso. -->
                                        <template v-else-if="fila.origen_tipo === 'guia'">
                                            <GuiaBuscador :key="`${fila.reserva_item_id}-${rearmarBuscadorGuia}`"
                                                :model-value="fila.guia?.id ?? null" :nombre-actual="fila.guia?.nombre ?? null"
                                                placeholder="Sin asignar" texto-quitar="Sin asignar" @elegido="(g) => guardarGuia(fila, g)" />
                                            <div v-if="fila.motivo_pendiente" class="text-warning" style="font-size:11px"><i class="fas fa-exclamation-triangle me-1"></i>{{ fila.motivo_pendiente }}</div>
                                        </template>
                                        <!-- Fila 13, fase 1b — tour cargado como una sola línea (modo nuevo):
                                             no tiene proveedor ni guía por servicio; su operación (vehículo,
                                             guía, proveedores) será la de su salida, en la fase 2. -->
                                        <div v-else-if="fila.origen_tipo === 'tour'" class="small">
                                            <div class="text-muted"><i class="fas fa-route me-1"></i>Tour completo</div>
                                            <div :class="fila.guia ? '' : 'text-muted fst-italic'"><i class="fas fa-user me-1 text-muted"></i>{{ fila.guia?.nombre ?? 'Sin guía' }}</div>
                                            <div v-if="fila.salida_vehiculo" class="text-muted" style="font-size:11px"><i class="fas fa-bus me-1"></i>{{ fila.salida_vehiculo }}</div>
                                            <router-link v-if="fila.salida_operativa_id" :to="`/agencia-viajes/salidas/${fila.salida_operativa_id}`" class="d-block text-decoration-none" style="font-size:11px">
                                                <i class="fas fa-link me-1"></i>Ver salida
                                            </router-link>
                                            <div v-if="fila.motivo_pendiente" class="text-warning" style="font-size:11px"><i class="fas fa-exclamation-triangle me-1"></i>{{ fila.motivo_pendiente }}</div>
                                        </div>
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
                                                    v-model="proveedorSearch[fila.reserva_item_id]" @input="buscarTarifasConPausa(fila)"
                                                    @blur="cerrarBusquedaProveedor(fila.reserva_item_id)" autofocus>
                                                <div class="list-group position-absolute w-100 shadow-sm" style="z-index:10;max-height:220px;overflow-y:auto">
                                                    <button type="button" class="list-group-item list-group-item-action py-1 small text-muted" @mousedown.prevent="elegirProveedor(fila, null)">
                                                        Sin asignar
                                                    </button>
                                                    <button type="button" class="list-group-item list-group-item-action py-1 small" v-for="t in tarifasProveedor" :key="t.id" @mousedown.prevent="elegirProveedor(fila, t.id)">
                                                        {{ t.proveedor_servicio?.proveedor?.nombre_comercial || t.proveedor_servicio?.proveedor?.razon_social || ('Tarifa #' + t.id) }}{{ t.proveedor_servicio?.proveedor?.es_referencial ? ' (Referencial)' : '' }}
                                                        <span class="text-muted">— {{ t.tipo_tarifa }} · {{ t.modalidad }} · {{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(0) }}</span>
                                                    </button>
                                                    <div v-if="buscandoTarifas" class="list-group-item small text-muted py-1">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>Buscando…
                                                    </div>
                                                    <div v-else-if="tarifasProveedor.length === 0" class="list-group-item small text-muted py-1">Sin resultados</div>
                                                </div>
                                            </div>
                                            <div v-if="fila.motivo_pendiente" class="text-warning" style="font-size:11px"><i class="fas fa-exclamation-triangle me-1"></i>{{ fila.motivo_pendiente }}</div>
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
                                        <input v-if="!fila.es_noche_hotel" type="checkbox" class="form-check-input" :checked="fila.checkin_realizado"
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
import CampoFecha from '@/components/CampoFecha.vue';
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { reporteOperativoService } from '@/services/admin/reporteOperativoService';
import { reservaItemService } from '@/services/admin/reservaItemService';
import GuiaBuscador from '@/components/AgenciaViajes/GuiaBuscador.vue';
import { proveedorService } from '@/services/admin/proveedorService';
import { useToast } from '@/composables/useToast';
import { formatFecha, hoyPeru, sumarDiasISO } from '@/helpers/fecha';
import type { Guia, ProveedorTarifa, ReporteOperativoFila, ReporteOperativoFiltrosDisponibles } from '@/types/agencia-viajes';

const toast = useToast();
const route = useRoute();
const router = useRouter();

// T-058: los filtros viven en la URL (query de la ruta): al ir a una reserva y volver, la
// pantalla se arma con lo mismo que se estaba viendo.
const textoQuery = (clave: string): string => {
    const v = route.query[clave];
    return typeof v === 'string' ? v : '';
};
const fechaQuery = (clave: string): string => {
    const v = textoQuery(clave);
    return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : '';
};
const idQuery = (clave: string): number | null => {
    const n = Number(textoQuery(clave));
    return Number.isInteger(n) && n > 0 ? n : null;
};

const filtros = ref<{
    fecha_desde: string; fecha_hasta: string; pendiente_asignar: boolean; q: string;
    destino_atractivo_id: number | null; tour_id: number | null;
    hotel_proveedor_id: number | null; servicio_id: number | null;
}>({
    fecha_desde: fechaQuery('fecha_desde') || hoyISO(),
    fecha_hasta: fechaQuery('fecha_hasta') || fechaQuery('fecha_desde') || hoyISO(),
    pendiente_asignar: textoQuery('pendiente_asignar') === '1',
    q: textoQuery('q').slice(0, 100),
    destino_atractivo_id: idQuery('destino_atractivo_id'),
    tour_id: idQuery('tour_id'),
    hotel_proveedor_id: idQuery('hotel_proveedor_id'),
    servicio_id: idQuery('servicio_id'),
});

// "Hasta" nunca antes de "Desde" ni más de 30 días después (rango máximo de 31 días del servidor).
const hastaMaximo = computed(() => (filtros.value.fecha_desde ? sumarDiasISO(filtros.value.fecha_desde, 30) : undefined));
watch(() => filtros.value.fecha_desde, (desde) => {
    if (!desde) return;
    if (!filtros.value.fecha_hasta || filtros.value.fecha_hasta < desde) filtros.value.fecha_hasta = desde;
    else if (filtros.value.fecha_hasta > sumarDiasISO(desde, 30)) filtros.value.fecha_hasta = sumarDiasISO(desde, 30);
});

const filas = ref<ReporteOperativoFila[]>([]);
const totalSinGuia = ref(0);
const loading = ref(false);
const filtrosDisponibles = ref<Omit<ReporteOperativoFiltrosDisponibles, 'code'>>({
    destinos: [], servicios: [], tours: [], hoteles: [],
});

// ── Reasignar proveedor inline (origen_tipo='proveedor') ────────────────────
// Es el caso REAL más común en el reporte (a diferencia del guía de arriba, casi no
// usado en la práctica: los datos reales de agencia-demo son casi todos
// origen_tipo='proveedor'). T-039: antes cargaba la biblioteca una vez (solo las
// primeras 30 tarifas) y filtraba en el navegador; ahora consulta al servidor por
// el servicio de la fila y el texto escrito.
const tarifasProveedor = ref<ProveedorTarifa[]>([]);
const buscandoTarifas = ref(false);
const proveedorBuscando = ref<number | null>(null); // reserva_item_id en modo búsqueda
const proveedorSearch = ref<Record<number, string>>({});

function hoyISO(): string {
    return hoyPeru();
}

function sumarDias(fechaISO: string, dias: number): string {
    return sumarDiasISO(fechaISO, dias);
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

const irAManana = () => {
    const manana = sumarDias(hoyISO(), 1);
    filtros.value.fecha_desde = manana;
    filtros.value.fecha_hasta = manana;
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
// T-055: las filas de noche/salida de hotel no son servicios: no cuentan ni tienen check-in.
const filasReales = computed(() => filas.value.filter((f) => !f.es_noche_hotel));
// T-058: el contador es por pasajero distinto, no por fila: con 2 pasajeros y 3 servicios
// cada uno, "1/2" es que uno de ellos ya tiene check-in (en alguno de sus servicios).
// El id del pasajero es por reserva, así que no se mezclan entre reservas.
const totalPasajeros = computed(() => new Set(filasReales.value.map((f) => f.pasajero.id)).size);
const pasajerosConCheckin = computed(() => new Set(filasReales.value.filter((f) => f.checkin_realizado).map((f) => f.pasajero.id)).size);

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
// T-058: servicio del día para "Check-in a todos"; `pendientes` = filas aún sin check-in.
type ServicioDelDia = { key: string; nombre: string; pendientes: ReporteOperativoFila[] };

const filasPorFecha = computed(() => {
    const grupos: { fecha: string; pasajeros: PasajeroCluster[]; servicios: ServicioDelDia[] }[] = [];
    const indiceFecha = new Map<string, number>();
    const indicePasajeroPorFecha = new Map<string, Map<number, number>>();

    for (const fila of filas.value) {
        const keyFecha = fila.fecha ?? 'sin-fecha';
        if (!indiceFecha.has(keyFecha)) {
            indiceFecha.set(keyFecha, grupos.length);
            grupos.push({ fecha: keyFecha, pasajeros: [], servicios: [] });
            indicePasajeroPorFecha.set(keyFecha, new Map());
        }
        const grupo = grupos[indiceFecha.get(keyFecha)!];
        const indicePasajero = indicePasajeroPorFecha.get(keyFecha)!;

        if (!indicePasajero.has(fila.pasajero.id)) {
            indicePasajero.set(fila.pasajero.id, grupo.pasajeros.length);
            grupo.pasajeros.push({ pasajero: fila.pasajero, filas: [] });
        }
        grupo.pasajeros[indicePasajero.get(fila.pasajero.id)!].filas.push(fila);

        // Noches y salida de hotel no son servicios: sin check-in, sin botón.
        if (!fila.es_noche_hotel) {
            const key = fila.tour_origen_id ? `tour-${fila.tour_origen_id}` : `servicio-${fila.servicio ?? 'sin-nombre'}`;
            let servicio = grupo.servicios.find((s) => s.key === key);
            if (!servicio) {
                servicio = { key, nombre: fila.tour_origen_nombre ?? fila.servicio ?? 'Servicio', pendientes: [] };
                grupo.servicios.push(servicio);
            }
            if (!fila.checkin_realizado) servicio.pendientes.push(fila);
        }
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
    q: filtros.value.q.trim() || undefined,
    destino_atractivo_id: filtros.value.destino_atractivo_id ?? undefined,
    tour_id: filtros.value.tour_id ?? undefined,
    hotel_proveedor_id: filtros.value.hotel_proveedor_id ?? undefined,
    servicio_id: filtros.value.servicio_id ?? undefined,
});

// Deja los filtros vigentes en la URL (sin sumar entradas al historial).
const recordarFiltrosEnUrl = () => {
    const query: Record<string, string> = {};
    for (const [clave, valor] of Object.entries(filtrosParaApi())) {
        if (valor !== undefined && valor !== null && valor !== '') query[clave] = valor === true ? '1' : String(valor);
    }
    router.replace({ query });
};

// Mensaje del 422 (rango inválido, búsqueda larga…) o uno genérico; el Excel llega como Blob.
const mensajeDeError = async (e: any, porDefecto: string): Promise<string> => {
    let data = e?.response?.data;
    if (data instanceof Blob) {
        try { data = JSON.parse(await data.text()); } catch { data = null; }
    }
    return data?.message ?? porDefecto;
};

const cargar = async () => {
    loading.value = true;
    recordarFiltrosEnUrl();
    try {
        const res = await reporteOperativoService.obtener(filtrosParaApi());
        filas.value = res.filas;
        totalSinGuia.value = res.total_sin_guia;
    } catch (e: any) {
        toast.error(await mensajeDeError(e, 'No se pudo cargar el reporte.'));
    } finally {
        loading.value = false;
    }
};

const cargarFiltrosDisponibles = async () => {
    try {
        const res = await reporteOperativoService.filtrosDisponibles({
            fecha_desde: filtros.value.fecha_desde,
            fecha_hasta: filtros.value.fecha_hasta,
        });
        filtrosDisponibles.value = { destinos: res.destinos, servicios: res.servicios, tours: res.tours, hoteles: res.hoteles };
    } catch {
        // Mismo rango que cargar(), que ya avisa el motivo: no repetir el aviso.
    }
};

// Búsqueda mientras se escribe, con pausa para no pedir en cada tecla.
let pausaBusqueda: ReturnType<typeof setTimeout> | null = null;
const buscarConPausa = () => {
    if (pausaBusqueda) clearTimeout(pausaBusqueda);
    pausaBusqueda = setTimeout(cargar, 350);
};

// Botón "Buscar" (o Hoy/Esta semana): el rango de fecha pudo haber cambiado, así que
// las opciones de los 4 selects también se refrescan — independiente de cargar(), van
// en paralelo (mismo criterio que el fix de awaits secuenciales de paquetes/detalle.vue).
const buscar = () => {
    Promise.all([cargarFiltrosDisponibles(), cargar()]);
};

// ── Reasignar guía inline (solo para ítems origen_tipo='guia') ──────────────
// El buscador vive por fila (pasajero), pero el guía pertenece al reserva_item —
// se actualiza en TODAS las filas que comparten ese reserva_item_id para que no
// quede una vista inconsistente entre pasajeros del mismo servicio.
const rearmarBuscadorGuia = ref(0);
const guardarGuia = async (fila: ReporteOperativoFila, nuevoGuia: Guia | null) => {
    try {
        await reservaItemService.actualizar(fila.reserva_item_id, { guia_id: nuevoGuia?.id ?? null });
        const sinGuia = !nuevoGuia || !!nuevoGuia.es_referencial;
        for (const f of filas.value) {
            if (f.reserva_item_id === fila.reserva_item_id) {
                f.guia = nuevoGuia ? { id: nuevoGuia.id, nombre: nuevoGuia.nombre, es_referencial: !!nuevoGuia.es_referencial } : null;
                f.sin_guia = sinGuia;
                f.motivo_pendiente = !nuevoGuia ? 'Sin guía' : (sinGuia ? 'Guía referencial' : null);
            }
        }
        totalSinGuia.value = filas.value.filter((f) => f.sin_guia).length;
        toast.success('Guía actualizado');
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'No se pudo actualizar el guía');
        // El buscador ya muestra el guía elegido: se rearma para volver al guardado.
        rearmarBuscadorGuia.value++;
    }
};

// ── Reasignar proveedor inline (origen_tipo='proveedor') ────────────────────
// La búsqueda matchea por nombre comercial O razón social (el usuario puede
// conocer al proveedor por cualquiera de los dos) — la lista de resultados
// solo MUESTRA nombre comercial (con fallback a razón social), mismo criterio
// que ya se usa en el resto del reporte.
let pausaTarifas: ReturnType<typeof setTimeout> | null = null;
const buscarTarifas = async (fila: ReporteOperativoFila) => {
    buscandoTarifas.value = true;
    try {
        const res = await proveedorService.biblioteca({
            search: (proveedorSearch.value[fila.reserva_item_id] ?? '').trim() || undefined,
            servicio_id: fila.servicio_id ?? undefined,
            per_page: 30,
        });
        tarifasProveedor.value = res.proveedor_tarifas ?? [];
    } finally {
        buscandoTarifas.value = false;
    }
};
const buscarTarifasConPausa = (fila: ReporteOperativoFila) => {
    if (pausaTarifas) clearTimeout(pausaTarifas);
    pausaTarifas = setTimeout(() => buscarTarifas(fila), 300);
};

const abrirBusquedaProveedor = (fila: ReporteOperativoFila) => {
    proveedorBuscando.value = fila.reserva_item_id;
    proveedorSearch.value[fila.reserva_item_id] = '';
    tarifasProveedor.value = [];
    buscarTarifas(fila);
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

// T-058: marca a todos los pasajeros pendientes de un servicio y día en una sola petición.
const checkinMasivoEnCurso = ref<string | null>(null);
const checkinATodos = async (servicio: ServicioDelDia) => {
    const filasAMarcar = [...servicio.pendientes];
    checkinMasivoEnCurso.value = servicio.key;
    try {
        const res = await reporteOperativoService.checkinMasivo(
            filasAMarcar.map((f) => ({ reserva_item_id: f.reserva_item_id, reserva_pasajero_id: f.pasajero.id }))
        );
        if (res.omitidos > 0) {
            // Alguno no corresponde (reserva ya no activa…): se vuelve a leer para mostrar lo real.
            toast.warning(`${res.marcados} marcado(s); ${res.omitidos} no se pudieron marcar.`);
            await cargar();
        } else {
            const ahora = new Date().toISOString();
            for (const f of filasAMarcar) {
                f.checkin_realizado = true;
                f.checkin_hora = ahora;
            }
        }
    } catch (e: any) {
        toast.error(await mensajeDeError(e, 'No se pudo marcar el check-in.'));
    } finally {
        checkinMasivoEnCurso.value = null;
    }
};

const valorCheckbox =(event: Event): boolean => (event.target as HTMLInputElement).checked;

// ── Exportar PDF (URL firmada) / Excel (descarga directa) ───────────────────
const exportingPdf = ref(false);
const exportarPdf = async () => {
    exportingPdf.value = true;
    try {
        const { url } = await reporteOperativoService.pdfUrl(filtrosParaApi());
        window.open(url, '_blank');
    } catch (e: any) {
        toast.error(await mensajeDeError(e, 'No se pudo generar el PDF.'));
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
        toast.error(await mensajeDeError(e, 'No se pudo generar el Excel.'));
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

// Independientes entre sí — Promise.all en vez de awaits secuenciales
// (mismo criterio que el fix de paquetes/detalle.vue y cotizador/editar.vue).
onMounted(() => {
    Promise.all([cargarFiltrosDisponibles(), cargar()]);
});
</script>
