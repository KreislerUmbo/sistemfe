<template>
    <DefaultLayout>
        <div v-if="reserva && cabecera" class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-0">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>Reserva {{ cabecera.codigo_cotizacion }}
                    <span class="badge ms-2" :class="reserva.estado === 'activa' ? 'bg-success' : 'bg-danger'">
                        {{ reserva.estado === 'activa' ? 'Activa' : 'Cancelada' }}
                    </span>
                </h5>
                <small class="text-muted">
                    {{ cabecera.cliente?.full_name }} · {{ cabecera.destino }} ·
                    {{ formatFecha(cabecera.fecha_viaje_desde) }} — {{ formatFecha(cabecera.fecha_viaje_hasta) }}
                </small>
            </div>
            <div class="d-flex gap-2">
                <router-link to="/agencia-viajes/reservas" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </router-link>
                <button v-if="reserva.estado === 'activa'" class="btn btn-outline-danger btn-sm" @click="mostrarModalCancelar = true">
                    <i class="fas fa-ban me-1"></i>Cancelar reserva
                </button>
            </div>
        </div>

        <div v-if="reserva" class="row g-3">
            <div class="col-12 col-lg-8">
                <ul class="nav nav-pills mb-3">
                    <li class="nav-item">
                        <button class="nav-link" :class="{ active: tab === 'pax' }" @click="tab = 'pax'">
                            Pasajeros <span class="badge bg-warning text-dark ms-1">{{ pasajerosIncompletos }}</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" :class="{ active: tab === 'items' }" @click="tab = 'items'">
                            Ítems <span class="badge bg-warning text-dark ms-1">{{ itemsSinAsignar }}</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" :class="{ active: tab === 'asignacion' }" @click="cambiarAAsignacion">
                            Asignación pasajero↔ítem
                        </button>
                    </li>
                </ul>

                <!-- TAB PASAJEROS -->
                <div v-if="tab === 'pax'" class="d-flex flex-column gap-2">
                    <div v-for="p in reserva.pasajeros" :key="p.id" class="card border-0 shadow-sm"
                        :style="{ borderLeft: '4px solid ' + (esPasajeroCompleto(p) ? '#22c55e' : '#f59e0b') }">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleFormPax(p.id)">
                                <span class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold"
                                        style="width:34px;height:34px;background:#e0e7ff;color:#4338ca;font-size:13px;flex-shrink:0">
                                        {{ iniciales(p.nombre) }}
                                    </span>
                                    <span>
                                        <span class="badge bg-light text-dark border me-2">{{ etiquetaTipoPax(p.tipo_pax) }}</span>
                                        {{ p.nombre || '—' }}
                                        <span v-if="!p.nombre" class="text-muted fst-italic">Sin datos todavía</span>
                                    </span>
                                </span>
                                <span class="badge" :class="esPasajeroCompleto(p) ? 'bg-success' : 'bg-warning text-dark'">
                                    {{ esPasajeroCompleto(p) ? 'Completo' : 'Incompleto' }}
                                </span>
                            </div>

                            <div v-if="formPaxAbierto === p.id" class="mt-3 border-top pt-3">
                                <div class="row g-2 mb-2 position-relative">
                                    <div class="col-md-6">
                                        <label class="form-label small text-secondary mb-1">Buscar pasajero ya cargado</label>
                                        <input type="text" class="form-control form-control-sm" placeholder="Buscar por DNI o nombre..."
                                            v-model="catalogoSearch[p.id]" @input="onBuscarCatalogo(p.id)" autocomplete="off">
                                        <div v-if="(catalogoResultados[p.id]?.length ?? 0) > 0"
                                            class="list-group position-absolute w-100 shadow-sm" style="z-index:10;max-width:60%">
                                            <button type="button" class="list-group-item list-group-item-action py-2 text-start"
                                                v-for="c in catalogoResultados[p.id]" :key="c.id" @click="autocompletarDesdeCatalogo(p, c)">
                                                <div class="small fw-semibold">{{ c.nombre }}</div>
                                                <div class="small text-muted">{{ c.documentos?.[0]?.numero_documento ?? 'sin documento' }}</div>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Nombre completo</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.nombre">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Documento</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.documento">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Nacionalidad</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.nacionalidad">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-secondary mb-1">Alimentación especial</label>
                                        <input type="text" class="form-control form-control-sm" placeholder="Ninguna" v-model="p.alimentacion_especial">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-secondary mb-1">Discapacidad (detalle)</label>
                                        <input type="text" class="form-control form-control-sm" placeholder="Ninguna" v-model="p.discapacidad">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-secondary mb-1">Vuelo ida — aerolínea</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.vuelo_aerolinea_ida">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-secondary mb-1">Vuelo ida — hora</label>
                                        <input type="time" class="form-control form-control-sm" v-model="p.vuelo_hora_ida">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-secondary mb-1">Vuelo vuelta — aerolínea</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.vuelo_aerolinea_vuelta">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-secondary mb-1">Vuelo vuelta — hora</label>
                                        <input type="time" class="form-control form-control-sm" v-model="p.vuelo_hora_vuelta">
                                    </div>
                                </div>
                                <button class="btn btn-primary btn-sm mt-2" :disabled="guardandoPax === p.id" @click="guardarPax(p)">
                                    <span v-if="guardandoPax === p.id" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-check me-1"></i>Guardar
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-if="(reserva.pasajeros?.length ?? 0) === 0" class="text-muted text-center py-4">Sin pasajeros.</div>
                </div>

                <!-- TAB ITEMS -->
                <div v-if="tab === 'items'" class="d-flex flex-column gap-2">
                    <div v-for="it in reserva.items" :key="it.id" class="card border-0 shadow-sm">
                        <div class="card-body py-2">
                            <div class="mb-2"><strong>{{ nombreItem(it) }}</strong></div>
                            <div class="row g-2">
                                <div class="col-md-3" v-if="it.alternativa_item?.origen_tipo === 'proveedor'">
                                    <label class="form-label small text-secondary mb-1">Proveedor</label>
                                    <select class="form-select form-select-sm" v-model="it.proveedor_tarifa_id" @change="guardarItem(it)">
                                        <option :value="null">Sin asignar</option>
                                        <option v-for="t in bibliotecaTarifas" :key="t.id" :value="t.id">
                                            {{ t.proveedor_servicio?.proveedor?.razon_social ?? ('Tarifa #' + t.id) }}{{ t.proveedor_servicio?.proveedor?.es_referencial ? ' (Referencial)' : '' }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-3" v-if="it.alternativa_item?.origen_tipo === 'guia'">
                                    <label class="form-label small text-secondary mb-1">Guía</label>
                                    <select class="form-select form-select-sm" v-model="it.guia_id" @change="guardarItem(it)">
                                        <option :value="null">Sin asignar</option>
                                        <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}</option>
                                    </select>
                                </div>
                                <div :class="tieneAsignacionAplicable(it) ? 'col-md-3' : 'col-md-6'">
                                    <label class="form-label small text-secondary mb-1">Fecha del servicio</label>
                                    <input type="date" class="form-control form-control-sm" v-model="it.fecha" @change="guardarItem(it)">
                                </div>
                                <div :class="tieneAsignacionAplicable(it) ? 'col-md-3' : 'col-md-6'">
                                    <label class="form-label small text-secondary mb-1">Hora</label>
                                    <input type="time" class="form-control form-control-sm" v-model="it.hora" @change="guardarItem(it)">
                                </div>
                            </div>
                            <p v-if="tieneAsignacionAplicable(it) && !it.proveedor_tarifa_id && !it.guia_id" class="small mt-2 mb-0" style="color:#adb5bd;font-style:italic">
                                <i class="fas fa-triangle-exclamation me-1"></i>Sin asignar todavía — no bloquea el resto de la reserva
                            </p>
                        </div>
                    </div>
                    <div v-if="(reserva.items?.length ?? 0) === 0" class="text-muted text-center py-4">Sin ítems.</div>
                </div>

                <!-- TAB ASIGNACION -->
                <div v-if="tab === 'asignacion'" class="d-flex flex-column gap-3">
                    <div v-for="it in reserva.items" :key="it.id" class="card border-0 shadow-sm">
                        <div class="card-body py-2">
                            <p class="fw-semibold mb-2">{{ nombreItem(it) }}</p>
                            <div class="d-flex flex-wrap gap-3">
                                <label v-for="p in reserva.pasajeros" :key="p.id" class="small d-flex align-items-center gap-1" style="cursor:pointer">
                                    <input type="checkbox" :checked="estaAsignado(it.id, p.id)" @change="toggleAsignacion(it, p)">
                                    {{ p.nombre || ('Pasajero ' + p.id) }} <span class="text-muted">({{ etiquetaTipoPax(p.tipo_pax) }})</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div v-if="(reserva.items?.length ?? 0) === 0" class="text-muted text-center py-4">Sin ítems.</div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm" style="position:sticky;top:1rem">
                    <div class="card-body">
                        <p class="fw-bold mb-0">Resumen de la reserva</p>
                        <p class="small text-muted mb-3">
                            {{ reserva.pasajeros?.length ?? 0 }} pasajero(s) ·
                            {{ formatFecha(cabecera?.fecha_viaje_desde) }} — {{ formatFecha(cabecera?.fecha_viaje_hasta) }}
                        </p>

                        <div class="d-flex flex-column gap-2 small mb-3">
                            <div v-for="r in resumen" :key="r.reserva_item_id" class="d-flex justify-content-between">
                                <span>{{ r.nombre }}</span>
                                <span class="fw-semibold">{{ moneda }} {{ Number(r.total_convertido).toFixed(2) }}</span>
                            </div>
                        </div>

                        <hr>
                        <div class="d-flex justify-content-between align-items-baseline mb-3">
                            <span class="fw-semibold">TOTAL</span>
                            <span class="fs-4 fw-semibold">{{ moneda }} {{ Number(total).toFixed(2) }}</span>
                        </div>

                        <hr>
                        <p class="small fw-semibold text-secondary mb-2">Progreso operativo</p>
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span><i class="fas fa-user-check me-1 text-muted"></i>Pasajeros completos</span>
                            <span class="fw-semibold">{{ pasajerosCompletos }} / {{ reserva.pasajeros?.length ?? 0 }}</span>
                        </div>
                        <div class="progress mb-2" style="height:6px">
                            <div class="progress-bar bg-success" :style="{ width: pctPax + '%' }"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span><i class="fas fa-clipboard-check me-1 text-muted"></i>Ítems con proveedor/guía</span>
                            <span class="fw-semibold">{{ itemsAsignados }} / {{ itemsAsignables.length }}</span>
                        </div>
                        <div class="progress" style="height:6px">
                            <div class="progress-bar bg-info" :style="{ width: pctItems + '%' }"></div>
                        </div>

                        <p class="small text-muted mt-3 mb-0">
                            <i class="fas fa-circle-info me-1"></i>Saldo pendiente de cobro no entra en esta pantalla — el total es el cerrado al aceptar la alternativa, no se recalcula acá.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="text-center text-muted py-5">Cargando reserva...</div>

        <!-- Modal cancelar -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalCancelar, 'd-block': mostrarModalCancelar }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalCancelar">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Cancelar reserva</h6>
                        <button class="btn-close" @click="mostrarModalCancelar = false"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small fw-semibold text-secondary">Motivo</label>
                        <select class="form-select form-select-sm" v-model="motivoCancelacion">
                            <option value="voluntaria">Voluntaria</option>
                            <option value="fuerza_mayor">Fuerza mayor</option>
                            <option value="clima">Clima</option>
                            <option value="falta_pago_cuotas">Falta de pago de cuotas</option>
                        </select>
                        <p class="small text-muted mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>El cálculo de reembolso no entra en esta pantalla todavía.</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalCancelar = false">Volver</button>
                        <button class="btn btn-danger btn-sm" :disabled="cancelando" @click="confirmarCancelacion">Confirmar cancelación</button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { reservaService } from '@/services/admin/reservaService';
import { reservaPasajeroService } from '@/services/admin/reservaPasajeroService';
import { reservaItemService } from '@/services/admin/reservaItemService';
import { proveedorService } from '@/services/admin/proveedorService';
import { guiaService } from '@/services/admin/guiaService';
import { useToast } from '@/composables/useToast';
import { formatFecha } from '@/helpers/fecha';
import type {
    Reserva, ReservaPasajero, ReservaItem, ReservaResumenItem, ReservaCabecera,
    PasajeroCatalogo, ProveedorTarifa, Guia, MotivoCancelacion,
} from '@/types/agencia-viajes';

const route = useRoute();
const reservaId = Number(route.params.id);
const toast = useToast();

const reserva = ref<Reserva | null>(null);
const resumen = ref<ReservaResumenItem[]>([]);
const total = ref<number>(0);
const moneda = ref<'PEN' | 'USD'>('PEN');
const cabecera = ref<ReservaCabecera | null>(null);

const tab = ref<'pax' | 'items' | 'asignacion'>('pax');

const cargarReserva = async () => {
    const res = await reservaService.obtener(reservaId);
    // El backend devuelve fecha como timestamp ISO completo (cast 'date' de
    // Eloquent, mismo patrón ya documentado para fecha_viaje_desde/hasta) —
    // <input type="date"> exige exactamente YYYY-MM-DD o queda vacío en
    // pantalla aunque el modelo sí tenga el valor. Antes de esta sesión
    // 'fecha' siempre nacía null y se tipeaba a mano (nunca disparaba el
    // problema); ahora que ReservaController la auto-completa, truncar acá
    // es necesario para que se vea.
    res.reserva.items?.forEach((it) => {
        if (it.fecha) it.fecha = it.fecha.substring(0, 10);
    });
    reserva.value = res.reserva;
    resumen.value = res.resumen;
    total.value = res.total;
    moneda.value = res.moneda;
    cabecera.value = res.cabecera;
};

const iniciales = (nombre?: string | null) => (nombre || '?').split(' ').map((x) => x[0]).slice(0, 2).join('').toUpperCase();
const etiquetaTipoPax = (t?: string | null) => t === 'adulto' ? 'Adulto' : t === 'nino' ? 'Niño' : t === 'infante' ? 'Infante' : '—';

// ── Pasajeros ────────────────────────────────────────────────────────
const esPasajeroCompleto = (p: ReservaPasajero) => !!(p.nombre && p.documento);
const pasajerosCompletos = computed(() => (reserva.value?.pasajeros ?? []).filter(esPasajeroCompleto).length);
const pasajerosIncompletos = computed(() => (reserva.value?.pasajeros?.length ?? 0) - pasajerosCompletos.value);
const pctPax = computed(() => {
    const totalPax = reserva.value?.pasajeros?.length ?? 0;
    return totalPax ? Math.round((pasajerosCompletos.value / totalPax) * 100) : 0;
});

const formPaxAbierto = ref<number | null>(null);
const toggleFormPax = (id: number) => { formPaxAbierto.value = formPaxAbierto.value === id ? null : id; };

const catalogoSearch = ref<Record<number, string>>({});
const catalogoResultados = ref<Record<number, PasajeroCatalogo[]>>({});
let catalogoTimeout: any = null;

const onBuscarCatalogo = (paxId: number) => {
    clearTimeout(catalogoTimeout);
    const texto = catalogoSearch.value[paxId] ?? '';
    if (texto.trim().length < 2) { catalogoResultados.value[paxId] = []; return; }
    catalogoTimeout = setTimeout(async () => {
        const res = await reservaPasajeroService.buscarCatalogo(texto);
        catalogoResultados.value[paxId] = res.pasajeros_catalogo;
    }, 250); // debounce, mismo criterio que el buscador de cliente en Ventas
};

const autocompletarDesdeCatalogo = (p: ReservaPasajero, c: PasajeroCatalogo) => {
    p.nombre = c.nombre;
    p.nacionalidad = c.nacionalidad ?? p.nacionalidad;
    p.documento = c.documentos?.[0]?.numero_documento ?? p.documento;
    p.pasajero_catalogo_id = c.id;
    catalogoResultados.value[p.id] = [];
    catalogoSearch.value[p.id] = '';
    toast.success(`Datos de ${c.nombre} autocompletados desde su perfil`);
};

const guardandoPax = ref<number | null>(null);
const guardarPax = async (p: ReservaPasajero) => {
    guardandoPax.value = p.id;
    try {
        const res = await reservaPasajeroService.actualizar(p.id, {
            nombre: p.nombre, documento: p.documento, nacionalidad: p.nacionalidad,
            alimentacion_especial: p.alimentacion_especial, discapacidad: p.discapacidad,
            vuelo_aerolinea_ida: p.vuelo_aerolinea_ida, vuelo_hora_ida: p.vuelo_hora_ida,
            vuelo_aerolinea_vuelta: p.vuelo_aerolinea_vuelta, vuelo_hora_vuelta: p.vuelo_hora_vuelta,
            pasajero_catalogo_id: p.pasajero_catalogo_id,
        });
        Object.assign(p, res.reserva_pasajero);
        toast.success(esPasajeroCompleto(p) ? `${p.nombre} guardado — datos completos` : 'Guardado, pero faltan datos obligatorios');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo guardar el pasajero');
    } finally {
        guardandoPax.value = null;
    }
};

// ── Ítems ────────────────────────────────────────────────────────────
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const guias = ref<Guia[]>([]);

const nombreItem = (it: ReservaItem) => {
    const item = it.alternativa_item;
    if (!item) return 'Servicio';
    if (item.origen_tipo === 'manual') return item.descripcion_manual ?? 'Ítem manual';
    if (item.origen_tipo === 'pasaje_aereo') return item.cotizacion_pasaje_aereo?.aerolinea ?? 'Pasaje aéreo';
    if (item.origen_tipo === 'mayorista') return item.opcion_mayorista?.proveedor?.razon_social ?? 'Paquete mayorista';
    if (item.proveedor_tarifa?.tipo_habitacion) {
        const proveedor = item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social ?? 'Hotel';
        return `${proveedor} · ${item.proveedor_tarifa.tipo_habitacion}`;
    }
    return item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'Servicio';
};

// Sesión pendiente-11e-groundwork — Proveedor solo aplica a origen_tipo
// 'proveedor' (servicios normales + hoteles), Guía solo a 'guia' (el
// guía ya es un ítem real con costo propio, no un campo suelto).
// Manual/pasaje_aereo/mayorista no tienen ningún campo de asignación
// operativa hoy — el layout de Fecha/Hora se ajusta cuando no aplica
// ninguno de los dos.
const tieneAsignacionAplicable = (it: ReservaItem) =>
    it.alternativa_item?.origen_tipo === 'proveedor' || it.alternativa_item?.origen_tipo === 'guia';

const itemsAsignables = computed(() => (reserva.value?.items ?? []).filter(tieneAsignacionAplicable));
const itemsAsignados = computed(() => itemsAsignables.value.filter((it) =>
    it.alternativa_item?.origen_tipo === 'guia' ? !!it.guia_id : !!it.proveedor_tarifa_id
).length);
const itemsSinAsignar = computed(() => itemsAsignables.value.length - itemsAsignados.value);
const pctItems = computed(() => {
    const total = itemsAsignables.value.length;
    return total ? Math.round((itemsAsignados.value / total) * 100) : 0;
});

const guardarItem = async (it: ReservaItem) => {
    try {
        await reservaItemService.actualizar(it.id, {
            guia_id: it.guia_id ?? null,
            proveedor_tarifa_id: it.proveedor_tarifa_id ?? null,
            fecha: it.fecha ?? null,
            hora: it.hora ?? null,
        });
        toast.success('Ítem actualizado');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar el ítem');
    }
};

// ── Asignación pasajero↔ítem ────────────────────────────────────────
const asignacionesPorItem = ref<Record<number, Array<{ id: number; reserva_pasajero_id: number }>>>({});

const cambiarAAsignacion = async () => {
    tab.value = 'asignacion';
    for (const it of reserva.value?.items ?? []) {
        const res = await reservaItemService.listarPasajerosAsignados(it.id);
        asignacionesPorItem.value[it.id] = res.reserva_item_pasajeros.map((a) => ({ id: a.id, reserva_pasajero_id: a.reserva_pasajero_id }));
    }
};

const estaAsignado = (itemId: number, pasajeroId: number) => (asignacionesPorItem.value[itemId] ?? []).some((a) => a.reserva_pasajero_id === pasajeroId);

const toggleAsignacion = async (it: ReservaItem, p: ReservaPasajero) => {
    const asignacion = (asignacionesPorItem.value[it.id] ?? []).find((a) => a.reserva_pasajero_id === p.id);
    try {
        if (asignacion) {
            await reservaItemService.quitarPasajero(asignacion.id);
        } else {
            await reservaItemService.asignarPasajero(it.id, p.id);
        }
        const res = await reservaItemService.listarPasajerosAsignados(it.id);
        asignacionesPorItem.value[it.id] = res.reserva_item_pasajeros.map((a) => ({ id: a.id, reserva_pasajero_id: a.reserva_pasajero_id }));
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar la asignación');
    }
};

// ── Cancelación ──────────────────────────────────────────────────────
const mostrarModalCancelar = ref(false);
const motivoCancelacion = ref<MotivoCancelacion>('voluntaria');
const cancelando = ref(false);

const confirmarCancelacion = async () => {
    if (!reserva.value) return;
    cancelando.value = true;
    try {
        await reservaService.cancelar(reserva.value.id, motivoCancelacion.value);
        mostrarModalCancelar.value = false;
        await cargarReserva();
        toast.success('Reserva cancelada correctamente');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo cancelar la reserva');
    } finally {
        cancelando.value = false;
    }
};

onMounted(async () => {
    await cargarReserva();
    const [bib, gs] = await Promise.all([proveedorService.biblioteca(), guiaService.listar({ page: 1 })]);
    bibliotecaTarifas.value = bib.proveedor_tarifas;
    guias.value = gs.guias ?? [];
});
</script>
