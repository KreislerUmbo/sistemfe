<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>
                    Reservas
                </h5>
                <small class="text-muted">{{ total }} registro(s) encontrado(s)</small>
            </div>
            <router-link to="/agencia-viajes/venta-directa" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-bolt me-2"></i>Venta Directa
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-12 col-md-5">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" placeholder="Buscar por código, destino, cliente o documento..." v-model="search" @keyup.enter="buscar">
                            <button class="btn btn-primary" @click="buscar"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select form-select-sm" v-model="estado" @change="buscar">
                            <option value="">Todos los estados</option>
                            <option value="activa">Activa</option>
                            <option value="cancelada">Cancelada</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select form-select-sm" v-model="estadoFacturacion" @change="buscar">
                            <option value="">Facturación: todas</option>
                            <option value="pendiente">Pendiente</option>
                            <option value="parcial">Parcial</option>
                            <option value="total">Facturado total</option>
                            <option value="facturacion_externa">Facturación externa</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select form-select-sm" v-model="vendedorId" @change="buscar">
                            <option value="">Todos los vendedores</option>
                            <option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <input type="date" class="form-control form-control-sm" title="Viaje desde" v-model="fechaDesde" @change="buscar">
                    </div>
                    <div class="col-6 col-md-1">
                        <input type="date" class="form-control form-control-sm" title="Viaje hasta" v-model="fechaHasta" @change="buscar">
                    </div>
                    <div class="col-6 col-md-1">
                        <button class="btn btn-outline-secondary btn-sm w-100" title="Limpiar filtros" @click="limpiarFiltros">
                            <i class="fas fa-eraser"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Cotización</th>
                                <th>Cliente</th>
                                <th>Destino</th>
                                <th>Fechas del viaje</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Facturación</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="7" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
                            </tr>
                            <tr v-else-if="reservas.length === 0">
                                <td colspan="7" class="text-center py-5 text-muted fst-italic">Sin reservas registradas.</td>
                            </tr>
                            <tr v-for="reserva in reservas" :key="reserva.id">
                                <td class="ps-3 fw-semibold">{{ reserva.codigo ?? reserva.alternativa?.cotizacion?.codigo }}</td>
                                <td>{{ reserva.alternativa?.cotizacion?.cliente?.full_name }}</td>
                                <td>{{ reserva.alternativa?.cotizacion?.destino }}</td>
                                <td class="small">
                                    <span v-if="reserva.fecha_viaje_desde">
                                        {{ formatFecha(reserva.fecha_viaje_desde) }} — {{ formatFecha(reserva.fecha_viaje_hasta) }}
                                    </span>
                                    <span v-else class="text-muted fst-italic">Sin definir</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge" :class="reserva.estado === 'activa' ? 'bg-success' : 'bg-danger'">
                                        {{ reserva.estado === 'activa' ? 'Activa' : 'Cancelada' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge" :class="badgeFacturacion(reserva.estado_facturacion).clase">
                                        {{ badgeFacturacion(reserva.estado_facturacion).texto }}
                                    </span>
                                </td>
                                <td class="text-center pe-3">
                                    <router-link :to="`/agencia-viajes/reservas/${reserva.id}`" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-arrow-right me-1"></i>Abrir
                                    </router-link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex align-items-center justify-content-between p-3 border-top flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">Mostrar</small>
                        <select class="form-select form-select-sm" style="width:auto" v-model.number="paginate" @change="buscar">
                            <option :value="15">15</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </div>
                    <div v-if="totalPages > 1" class="d-flex align-items-center gap-2">
                        <small class="text-muted">Página {{ page }} de {{ totalPages }}</small>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" :disabled="page <= 1 || loading" @click="irAPagina(page - 1)">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </button>
                            <button class="btn btn-outline-secondary" :disabled="page >= totalPages || loading" @click="irAPagina(page + 1)">
                                Siguiente <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { reservaService } from '@/services/admin/reservaService';
import type { Reserva, EstadoFacturacionReserva } from '@/types/agencia-viajes';
import { formatFecha } from '@/helpers/fecha';

const reservas = ref<Reserva[]>([]);
const search = ref<string>('');
const estado = ref<'' | 'activa' | 'cancelada'>('');
const estadoFacturacion = ref<'' | EstadoFacturacionReserva>('');
const vendedorId = ref<number | ''>('');
const fechaDesde = ref<string>('');
const fechaHasta = ref<string>('');
const vendedores = ref<Array<{ id: number; nombre: string }>>([]);
const total = ref<number>(0);
const paginate = ref<number>(15);
const page = ref<number>(1);
const loading = ref<boolean>(false);

// Backend pagina de a 15 (ReservaController::index()) pero no devolvía
// last_page/current_page — total + tamaño de página fijo alcanza para
// calcular la cantidad de páginas sin tocar el backend.
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / paginate.value)));

const list = async () => {
    loading.value = true;
    try {
        const res = await reservaService.listar({
            page: page.value,
            per_page: paginate.value,
            search: search.value || undefined,
            estado: (estado.value || undefined) as any,
            estado_facturacion: (estadoFacturacion.value || undefined) as any,
            vendedor_id: (vendedorId.value || undefined) as any,
            fecha_desde: fechaDesde.value || undefined,
            fecha_hasta: fechaHasta.value || undefined,
        });
        reservas.value = res.reservas;
        total.value = res.total;
        paginate.value = res.paginate;
        vendedores.value = res.vendedores ?? [];
    } finally {
        loading.value = false;
    }
};

// Un cambio de búsqueda/filtro vuelve siempre a la página 1 — quedarse en
// una página que puede no existir más para el nuevo filtro sería confuso.
const buscar = () => {
    page.value = 1;
    list();
};

const irAPagina = (nuevaPagina: number) => {
    if (nuevaPagina < 1 || nuevaPagina > totalPages.value) return;
    page.value = nuevaPagina;
    list();
};

const limpiarFiltros = () => {
    search.value = '';
    estado.value = '';
    estadoFacturacion.value = '';
    vendedorId.value = '';
    fechaDesde.value = '';
    fechaHasta.value = '';
    buscar();
};

// 2026-09-22 — badge de estado de facturación real (ver
// EstadoFacturacionReserva en types/agencia-viajes.ts). undefined solo
// puede pasar si el backend todavía no manda el campo (no debería, pero
// evita un badge roto en vez de romper la fila).
const badgeFacturacion = (estado?: EstadoFacturacionReserva): { texto: string; clase: string } => {
    switch (estado) {
        case 'total':
            return { texto: 'Facturado', clase: 'bg-success' };
        case 'parcial':
            return { texto: 'Parcial', clase: 'bg-warning text-dark' };
        case 'facturacion_externa':
            return { texto: 'Externa', clase: 'bg-info text-dark' };
        case 'pendiente':
            return { texto: 'Pendiente', clase: 'bg-secondary' };
        default:
            return { texto: '—', clase: 'bg-secondary' };
    }
};

onMounted(() => list());
</script>
