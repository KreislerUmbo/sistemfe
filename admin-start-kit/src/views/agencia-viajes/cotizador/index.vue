<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-route me-2 text-primary"></i>
                    Cotizaciones
                </h5>
                <small class="text-muted">{{ total }} registro(s) encontrado(s)</small>
            </div>
            <router-link to="/agencia-viajes/cotizador/nueva" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-plus me-2"></i>Nueva Cotización
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-12 col-md-5">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" placeholder="Buscar por código, destino o cliente..." v-model="search" @keyup.enter="buscar">
                            <button class="btn btn-primary" @click="buscar"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select form-select-sm" v-model="estadoResumen" @change="buscar">
                            <option value="">Todos los estados</option>
                            <option value="borrador">Borrador</option>
                            <option value="enviada">Enviada</option>
                            <option value="reservada">Reservada</option>
                            <option value="anulada">Anulada</option>
                            <option value="vencida">Vencida</option>
                            <option value="descartada">Descartada</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <input type="date" class="form-control form-control-sm" title="Viaje desde" v-model="fechaDesde" @change="buscar">
                    </div>
                    <div class="col-6 col-md-2">
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
                                <th class="ps-3">Código</th>
                                <th>Cliente</th>
                                <th>Destino</th>
                                <th>Fechas del viaje</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Alternativas</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="7" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
                            </tr>
                            <tr v-else-if="cotizaciones.length === 0">
                                <td colspan="7" class="text-center py-5 text-muted fst-italic">Sin cotizaciones registradas.</td>
                            </tr>
                            <tr v-for="cotizacion in cotizaciones" :key="cotizacion.id">
                                <td class="ps-3 fw-semibold">{{ cotizacion.codigo }}</td>
                                <td>{{ cotizacion.cliente?.full_name }}</td>
                                <td>{{ cotizacion.destino }}</td>
                                <td class="small">
                                    <span v-if="cotizacion.fecha_viaje_desde">
                                        {{ formatFecha(cotizacion.fecha_viaje_desde) }} — {{ formatFecha(cotizacion.fecha_viaje_hasta) }}
                                    </span>
                                    <span v-else class="text-muted fst-italic">Sin definir</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge rounded-pill" :class="badgeEstado(cotizacion.estado_resumen).clase">
                                        {{ badgeEstado(cotizacion.estado_resumen).texto }}
                                    </span>
                                </td>
                                <td class="text-center">{{ cotizacion.alternativas_count ?? 0 }}</td>
                                <td class="text-center pe-3">
                                    <router-link :to="`/agencia-viajes/cotizador/${cotizacion.id}`" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-arrow-right me-1"></i>Abrir
                                    </router-link>
                                    <button class="btn btn-sm btn-outline-danger ms-1" title="Eliminar cotización" @click="eliminarCotizacion(cotizacion)">
                                        <i class="fas fa-trash"></i>
                                    </button>
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
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { cotizacionService } from '@/services/admin/cotizacionService';
import type { Cotizacion } from '@/types/agencia-viajes';
import { formatFecha } from '@/helpers/fecha';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// Taxonomía de estados del listado (09-sep-2026) — estado_resumen es un
// campo CALCULADO por Cotizacion::estadoResumen() en el backend, no una
// columna real (Cotizacion no tiene 'estado' propio).
const badgeEstado = (estado?: Cotizacion['estado_resumen']) => {
    switch (estado) {
        case 'reservada': return { texto: 'Reservada', clase: 'bg-success' };
        case 'anulada': return { texto: 'Anulada', clase: 'bg-danger' };
        case 'vencida': return { texto: 'Vencida', clase: 'bg-warning text-dark' };
        case 'enviada': return { texto: 'Enviada', clase: 'bg-info text-dark' };
        case 'descartada': return { texto: 'Descartada', clase: 'bg-dark' };
        default: return { texto: 'Borrador', clase: 'bg-secondary' };
    }
};

const cotizaciones = ref<Cotizacion[]>([]);
const search = ref<string>('');
const estadoResumen = ref<string>('');
const fechaDesde = ref<string>('');
const fechaHasta = ref<string>('');
const total = ref<number>(0);
const paginate = ref<number>(15);
const page = ref<number>(1);
const loading = ref<boolean>(false);

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / paginate.value)));

const list = async () => {
    loading.value = true;
    try {
        const res = await cotizacionService.listar({
            page: page.value,
            per_page: paginate.value,
            search: search.value || undefined,
            estado_resumen: estadoResumen.value || undefined,
            fecha_desde: fechaDesde.value || undefined,
            fecha_hasta: fechaHasta.value || undefined,
        });
        cotizaciones.value = res.cotizaciones;
        total.value = res.total;
        paginate.value = res.paginate;
    } finally {
        loading.value = false;
    }
};

// Cambiar cualquier filtro vuelve siempre a la página 1 (mismo criterio que
// Reservas·Listado) — quedarse en una página que puede no existir más para
// el filtro nuevo sería confuso.
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
    estadoResumen.value = '';
    fechaDesde.value = '';
    fechaHasta.value = '';
    buscar();
};

// El endpoint de listado (CotizacionController::index()) solo trae
// alternativas_count, sin desglose por estado — no hay forma barata de
// saber acá si alguna alternativa ya generó una reserva. El botón queda
// siempre visible; el 422 real de CotizacionController::destroy() (ya
// generó una reserva) es el guard, mostrado con el mismo error.
const eliminarCotizacion = async (cotizacion: Cotizacion) => {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: `¿Eliminar la cotización ${cotizacion.codigo}?`,
        text: (cotizacion.alternativas_count ?? 0) > 0
            ? `Se eliminarán sus ${cotizacion.alternativas_count} alternativa(s) con todos sus ítems.`
            : 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirmacion.isConfirmed) return;

    try {
        await cotizacionService.eliminar(cotizacion.id);
        await list();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la cotización', 'error');
    }
};

onMounted(() => list());
</script>
