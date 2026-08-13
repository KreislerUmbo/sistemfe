<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-route me-2 text-primary"></i>
                    Salidas Operativas
                </h5>
                <small class="text-muted">{{ total }} salida(s) encontrada(s)</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="fechaDesde" @change="list">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="fechaHasta" @change="list">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Estado</label>
                        <select class="form-select form-select-sm" v-model="estado" @change="list">
                            <option value="">Todos</option>
                            <option value="activa">Activa</option>
                            <option value="cancelada">Cancelada</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="btn btn-outline-secondary btn-sm w-100" @click="reset">
                            <i class="fas fa-undo me-1"></i>Limpiar
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
                                <th class="ps-3">Fecha / Hora</th>
                                <th>Tour</th>
                                <th style="min-width:180px">Guía</th>
                                <th style="min-width:200px">Vehículo / Cupo</th>
                                <th class="text-center">Pax</th>
                                <th class="text-center">Reservas</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                                </td>
                            </tr>
                            <tr v-else-if="salidas.length === 0">
                                <td colspan="8" class="text-center py-5 text-muted fst-italic">
                                    <i class="fas fa-inbox opacity-50 fs-4 mb-2 d-block"></i>
                                    Sin salidas registradas en este rango.
                                </td>
                            </tr>
                            <tr v-for="s in salidas" :key="s.id" :class="{ 'table-warning': faltaGuia(s) }">
                                <td class="ps-3">
                                    <div class="fw-semibold">{{ formatFecha(s.fecha) }}</div>
                                    <small class="text-muted" v-if="s.hora">{{ s.hora }}</small>
                                </td>
                                <td>{{ s.tour_nombre ?? '—' }}</td>
                                <td>
                                    <div v-if="guiaEditando !== s.id" class="d-flex align-items-center justify-content-between border rounded px-2 py-1 bg-white"
                                        style="cursor:pointer;min-height:31px" @click="guiaEditando = s.id">
                                        <span class="small" :class="{ 'text-muted fst-italic': !s.guia }">{{ s.guia?.nombre ?? 'Sin asignar' }}</span>
                                        <i class="fas fa-pen text-muted small"></i>
                                    </div>
                                    <select v-else class="form-select form-select-sm" :value="s.guia_id ?? ''" autofocus
                                        @change="guardarGuia(s, ($event.target as HTMLSelectElement).value)" @blur="guiaEditando = null">
                                        <option value="">Sin asignar</option>
                                        <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}</option>
                                    </select>
                                    <div v-if="faltaGuia(s)" class="small text-warning-emphasis mt-1">
                                        <i class="fas fa-triangle-exclamation me-1"></i>Falta guía
                                    </div>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm mb-1" placeholder="Vehículo (ej. Van 14 pax)"
                                        v-model="s.vehiculo_descripcion" @blur="guardarCampos(s)">
                                    <input type="number" min="1" class="form-control form-control-sm" placeholder="Cupo máximo"
                                        v-model.number="s.cupo_maximo" @blur="guardarCampos(s)">
                                </td>
                                <td class="text-center fw-semibold">{{ s.total_pax ?? 0 }}</td>
                                <td class="text-center">{{ s.total_reservas ?? 0 }}</td>
                                <td class="text-center">
                                    <span class="badge" :class="s.estado === 'activa' ? 'bg-success' : 'bg-secondary'">
                                        {{ s.estado === 'activa' ? 'Activa' : 'Cancelada' }}
                                    </span>
                                </td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-primary me-1" title="Ver detalle" @click="abrirDetalle(s.id)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button v-if="s.estado === 'activa'" class="btn btn-sm btn-outline-danger" title="Cancelar salida" @click="confirmarCancelar(s)">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <nav v-if="total > perPageRows" class="mt-3 d-flex justify-content-end">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: currentPage === 1 }">
                    <button class="page-link" @click="currentPage > 1 && (currentPage--, list())">Anterior</button>
                </li>
                <li class="page-item disabled"><span class="page-link">Página {{ currentPage }}</span></li>
                <li class="page-item" :class="{ disabled: salidas.length < perPageRows }">
                    <button class="page-link" @click="currentPage++, list()">Siguiente</button>
                </li>
            </ul>
        </nav>

        <!-- Modal detalle -->
        <div class="modal fade" tabindex="-1" :class="{ show: !!detalle, 'd-block': !!detalle }"
            style="background:rgba(0,0,0,.5)" v-if="detalle">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">
                            Salida {{ formatFecha(detalle.fecha) }}{{ detalle.hora ? ' · ' + detalle.hora : '' }} — {{ detalle.tour_nombre ?? 'Sin tour' }}
                        </h6>
                        <button class="btn-close" @click="detalle = null"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3 small">
                            <div class="col-6"><strong>Guía:</strong> {{ detalle.guia?.nombre ?? 'Sin asignar' }}</div>
                            <div class="col-6"><strong>Estado:</strong> {{ detalle.estado === 'activa' ? 'Activa' : 'Cancelada' }}</div>
                            <div class="col-6"><strong>Vehículo:</strong> {{ detalle.vehiculo_descripcion ?? '—' }}</div>
                            <div class="col-6"><strong>Cupo máximo:</strong> {{ detalle.cupo_maximo ?? '—' }}</div>
                            <div class="col-6"><strong>Total pax:</strong> {{ detalle.total_pax ?? 0 }}</div>
                            <div class="col-6"><strong>Reservas enganchadas:</strong> {{ detalle.total_reservas ?? 0 }}</div>
                        </div>
                        <h6 class="small fw-semibold text-secondary text-uppercase">Reservas</h6>
                        <ul class="list-group">
                            <li v-for="r in detalle.reservas ?? []" :key="r.id" class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <div>
                                    <div class="fw-semibold small">{{ r.codigo ?? ('Reserva #' + r.id) }}</div>
                                    <small class="text-muted">{{ r.cliente ?? 'Sin cliente' }} · {{ r.total_pax }} pax</small>
                                </div>
                                <router-link :to="`/agencia-viajes/reservas/${r.id}`" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-arrow-right me-1"></i>Abrir
                                </router-link>
                            </li>
                            <li v-if="(detalle.reservas ?? []).length === 0" class="list-group-item text-muted small fst-italic text-center py-3">
                                Sin reservas enganchadas todavía.
                            </li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button v-if="detalle.estado === 'activa'" class="btn btn-outline-danger btn-sm" @click="confirmarCancelar(detalle)">
                            <i class="fas fa-ban me-1"></i>Cancelar salida
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="detalle = null">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { salidaOperativaService } from '@/services/admin/salidaOperativaService';
import { guiaService } from '@/services/admin/guiaService';
import { useToast } from '@/composables/useToast';
import { formatFecha } from '@/helpers/fecha';
import type { SalidaOperativa, Guia } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const toast = useToast();

const salidas = ref<SalidaOperativa[]>([]);
const guias = ref<Guia[]>([]);
const total = ref<number>(0);
const currentPage = ref<number>(1);
const perPageRows = ref<number>(20);
const loading = ref<boolean>(false);

const hoy = new Date();
const enSieteDias = new Date(hoy.getTime() + 7 * 24 * 60 * 60 * 1000);
const aIso = (d: Date) => d.toISOString().substring(0, 10);

const fechaDesde = ref<string>(aIso(hoy));
const fechaHasta = ref<string>(aIso(enSieteDias));
const estado = ref<'' | 'activa' | 'cancelada'>('');

const guiaEditando = ref<number | null>(null);
const detalle = ref<SalidaOperativa | null>(null);

const faltaGuia = (s: SalidaOperativa) => s.estado === 'activa' && !s.guia_id;

const list = async () => {
    loading.value = true;
    try {
        const res = await salidaOperativaService.listar({
            fecha_desde: fechaDesde.value || undefined,
            fecha_hasta: fechaHasta.value || undefined,
            estado: estado.value || undefined,
        });
        salidas.value = res.salidas;
        total.value = res.total;
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudieron cargar las salidas');
    } finally {
        loading.value = false;
    }
};

const reset = () => {
    fechaDesde.value = aIso(hoy);
    fechaHasta.value = aIso(enSieteDias);
    estado.value = '';
    currentPage.value = 1;
    list();
};

const guardarGuia = async (s: SalidaOperativa, valor: string) => {
    const guiaId = valor ? Number(valor) : null;
    try {
        await salidaOperativaService.actualizar(s.id, { guia_id: guiaId });
        s.guia_id = guiaId;
        s.guia = guiaId ? guias.value.find((g) => g.id === guiaId) ?? null : null;
        toast.success('Guía actualizado');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar el guía');
    } finally {
        guiaEditando.value = null;
    }
};

const guardarCampos = async (s: SalidaOperativa) => {
    try {
        await salidaOperativaService.actualizar(s.id, {
            vehiculo_descripcion: s.vehiculo_descripcion || null,
            cupo_maximo: s.cupo_maximo || null,
        });
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar la salida');
    }
};

const abrirDetalle = async (id: number) => {
    try {
        const res = await salidaOperativaService.ver(id);
        detalle.value = res;
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo cargar el detalle de la salida');
    }
};

const confirmarCancelar = async (s: SalidaOperativa) => {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: '¿Cancelar esta salida?',
        text: 'Esto NO cancela las reservas enganchadas — cada una se resuelve a mano, es plata de un cliente. Solo queda marcada como aviso visual.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar salida',
        cancelButtonText: 'Volver',
    });
    if (!confirmacion.isConfirmed) return;

    try {
        const res = await salidaOperativaService.cancelar(s.id);
        toast.success(res.message);
        if (detalle.value?.id === s.id) detalle.value = null;
        await list();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo cancelar la salida');
    }
};

onMounted(async () => {
    await list();
    const gs = await guiaService.listar({ page: 1 });
    guias.value = gs.guias ?? [];

    const idParam = route.params.id ? Number(route.params.id) : null;
    if (idParam) {
        await abrirDetalle(idParam);
    }
});
</script>
