<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-cash-register me-2 text-primary"></i>
                    Historial de Caja
                </h5>
                <small class="text-muted">{{ totalRows }} sesión(es) encontrada(s)</small>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="exportingPdf" @click="exportarPdfRango">
                    <span v-if="exportingPdf" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-file-pdf me-1"></i> Exportar PDF (rango)
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
                        <input type="date" class="form-control form-control-sm" v-model="filtros.dateFrom">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="filtros.dateTo">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Sede</label>
                        <select v-model="filtros.branchId" class="form-select form-select-sm">
                            <option value="">— Todas —</option>
                            <option v-for="b in sedesDisponibles" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Caja</label>
                        <select v-model="filtros.cashRegisterId" class="form-select form-select-sm">
                            <option value="">— Todas —</option>
                            <option v-for="r in cajasDisponibles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2" v-if="puedeVerTodas">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Cajero</label>
                        <select v-model="filtros.openedBy" class="form-select form-select-sm">
                            <option value="">— Todos —</option>
                            <option v-for="u in cajerosDisponibles" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Estado</label>
                        <select v-model="filtros.status" class="form-select form-select-sm">
                            <option value="">— Todos —</option>
                            <option value="open">Abierta</option>
                            <option value="closed">Cerrada</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="button" class="btn btn-primary btn-sm w-100" @click="recargar">
                            <i class="fas fa-filter me-1"></i>Filtrar
                        </button>
                    </div>
                </div>
                <small class="text-muted d-block mt-2" v-if="!puedeVerTodas">
                    Solo se muestra tu propio historial de sesiones.
                </small>
            </div>
        </div>

        <!-- ═══════ Tabla ═══════ -->
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Apertura</th>
                            <th>Cierre</th>
                            <th>Sede</th>
                            <th>Caja</th>
                            <th>Cajero</th>
                            <th class="text-end">Fondo</th>
                            <th class="text-end">Esperado</th>
                            <th class="text-end">Contado</th>
                            <th class="text-end">Diferencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="10" class="text-center py-4"><span class="spinner-border text-primary"></span></td>
                        </tr>
                        <tr v-else-if="sessions.length === 0">
                            <td colspan="10" class="text-center text-muted py-4">Sin sesiones en el rango/filtros seleccionados.</td>
                        </tr>
                        <tr v-for="s in sessions" :key="s.id" style="cursor:pointer" @click="abrirDetalle(s.id)">
                            <td>{{ s.opened_at }}</td>
                            <td>{{ s.closed_at ?? '—' }}</td>
                            <td>{{ s.cash_register.branch?.name ?? '-' }}</td>
                            <td>{{ s.cash_register.name }}</td>
                            <td>{{ s.opened_by_user.name }}</td>
                            <td class="text-end">S/ {{ Number(s.opening_amount).toFixed(2) }}</td>
                            <td class="text-end">{{ s.expected_cash !== null ? `S/ ${Number(s.expected_cash).toFixed(2)}` : '—' }}</td>
                            <td class="text-end">{{ s.counted_cash !== null ? `S/ ${Number(s.counted_cash).toFixed(2)}` : '—' }}</td>
                            <td class="text-end">{{ s.difference !== null ? `S/ ${Number(s.difference).toFixed(2)}` : '—' }}</td>
                            <td>
                                <span class="badge" :class="s.status === 'open' ? 'bg-warning text-dark' : 'bg-success'">
                                    {{ s.status === 'open' ? 'Abierta' : 'Cerrada' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <small class="text-muted">Mostrando {{ sessions.length }} de {{ totalRows }} registro(s)</small>
                <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="perPage"
                    prev-text="Anterior" next-text="Siguiente" class="mb-0" />
            </div>
        </div>

        <!-- ═══════ Detalle de solo lectura ═══════ -->
        <b-modal v-model="showDetailModal" title="Detalle de sesión" hide-footer centered size="lg">
            <div v-if="loadingDetail" class="text-center py-4"><span class="spinner-border"></span></div>
            <div v-else-if="detalle">
                <h6 class="fw-semibold mb-1">
                    {{ detalle.cash_register.name }}
                    <small class="text-muted">({{ detalle.cash_register.branch?.name }})</small>
                </h6>
                <small class="text-muted d-block mb-3">
                    Cajero: {{ detalle.opened_by_user.name }} —
                    Apertura: {{ detalle.opened_at }} —
                    Cierre: {{ detalle.closed_at ?? 'en curso' }}
                </small>

                <table class="table table-sm table-borderless mb-3">
                    <tbody>
                        <tr>
                            <td class="text-muted">Fondo inicial</td>
                            <td class="text-end fw-semibold">S/ {{ Number(detalle.opening_amount).toFixed(2) }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Esperado</td>
                            <td class="text-end fw-semibold">S/ {{ detalle.expected_cash_live.toFixed(2) }}</td>
                        </tr>
                        <tr v-if="detalle.status === 'closed'">
                            <td class="text-muted">Contado</td>
                            <td class="text-end fw-semibold">S/ {{ Number(detalle.counted_cash ?? 0).toFixed(2) }}</td>
                        </tr>
                        <tr v-if="detalle.status === 'closed'">
                            <td class="text-muted">Diferencia</td>
                            <td class="text-end fw-semibold">S/ {{ Number(detalle.difference ?? 0).toFixed(2) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="detalle.difference_reason" class="alert alert-warning py-2 mb-3">
                    <strong>Motivo de diferencia:</strong> {{ detalle.difference_reason }}
                </div>

                <h6 class="fw-semibold">Totales por método de pago</h6>
                <b-table-simple responsive small class="mb-3">
                    <b-thead class="table-light">
                        <b-tr><b-th>Método</b-th><b-th>Total</b-th></b-tr>
                    </b-thead>
                    <b-tbody>
                        <b-tr v-if="!detalle.totals_by_payment_method.length">
                            <b-td colspan="2" class="text-center text-muted">Sin movimientos</b-td>
                        </b-tr>
                        <b-tr v-for="t in detalle.totals_by_payment_method" :key="t.payment_method_id">
                            <b-td>{{ t.payment_method_name }}</b-td>
                            <b-td>S/ {{ t.total.toFixed(2) }}</b-td>
                        </b-tr>
                    </b-tbody>
                </b-table-simple>

                <h6 class="fw-semibold">Movimientos</h6>
                <b-table-simple responsive small class="mb-3">
                    <b-thead class="table-light">
                        <b-tr>
                            <b-th>Tipo</b-th><b-th>Método</b-th><b-th>Monto</b-th><b-th>Estado</b-th><b-th>Descripción</b-th>
                        </b-tr>
                    </b-thead>
                    <b-tbody>
                        <b-tr v-if="!detalle.movements.length">
                            <b-td colspan="5" class="text-center text-muted">Sin movimientos</b-td>
                        </b-tr>
                        <b-tr v-for="m in detalle.movements" :key="m.id">
                            <b-td>{{ m.type }}</b-td>
                            <b-td>{{ m.payment_method?.name ?? '-' }}</b-td>
                            <b-td>{{ m.direction === 'in' ? '+' : '-' }}{{ Number(m.amount).toFixed(2) }}</b-td>
                            <b-td>{{ m.status }}</b-td>
                            <b-td>{{ m.description ?? m.concept?.name ?? '-' }}</b-td>
                        </b-tr>
                    </b-tbody>
                </b-table-simple>

                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="downloadingPdf" @click="descargarPdfIndividual">
                    <span v-if="downloadingPdf" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-file-pdf me-1"></i>
                    {{ detalle.status === 'closed' ? 'Descargar PDF de cierre' : 'Vista previa (sesión abierta)' }}
                </button>
            </div>
        </b-modal>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import { ref, computed, watch, onMounted } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useAuthStore } from '@/stores/auth';
import type {
    CashSessionSummary,
    CashSessionsListResponse,
    CashSessionDetail,
    CashSessionDetailResponse,
    Branch,
    CashRegister,
    SessionUser,
} from '@/types/cash-session';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const authStore = useAuthStore();
const puedeVerTodas = computed(() => authStore.isPermitedRoute('cash.view_all'));

const loading = ref(false);
const sessions = ref<CashSessionSummary[]>([]);
const totalRows = ref(0);
const currentPage = ref(1);
const perPage = ref(25);

const filtros = ref({
    dateFrom: '',
    dateTo: '',
    branchId: '' as number | string,
    cashRegisterId: '' as number | string,
    openedBy: '' as number | string,
    status: '' as '' | 'open' | 'closed',
});

// Catálogos reales (BranchController/CashRegisterController, Fase 5 —
// corrección: antes se derivaban de las sesiones ya cargadas en pantalla,
// lo que dejaba invisibles sedes/cajas sin sesiones en la página actual
// ("no encuentro mi sede en el filtro"). cash-registers se re-filtra por
// sede cuando se elige una (?branch_id=), pero sin sede elegida trae todas.
const sedesDisponibles = ref<Branch[]>([]);
const cajasDisponibles = ref<CashRegister[]>([]);

async function cargarSedes() {
    try {
        const { data } = await httpClient.get('branches', { params: { active: 1 } });
        sedesDisponibles.value = data.branches;
    } catch (e) {
        console.error(e);
    }
}

async function cargarCajas() {
    try {
        const { data } = await httpClient.get('cash-registers', {
            params: { active: 1, branch_id: filtros.value.branchId || undefined },
        });
        cajasDisponibles.value = data.cash_registers;
    } catch (e) {
        console.error(e);
    }
}

watch(() => filtros.value.branchId, () => {
    // Si la caja seleccionada no pertenece a la sede recién elegida, se
    // limpia — evita mandar un filtro contradictorio (sede A, caja de sede B).
    filtros.value.cashRegisterId = '';
    cargarCajas();
});

// Sin endpoint propio de usuarios/cajeros todavía para este filtro — se
// mantiene derivado de las sesiones ya cargadas (solo visible con
// cash.view_all, alcance más acotado que sede/caja).
const cajerosDisponibles = computed<SessionUser[]>(() => {
    const vistos = new Map<number, SessionUser>();
    sessions.value.forEach((s) => vistos.set(s.opened_by_user.id, s.opened_by_user));
    return Array.from(vistos.values());
});

function paramsFiltros(pagina?: number) {
    return {
        date_from: filtros.value.dateFrom || undefined,
        date_to: filtros.value.dateTo || undefined,
        branch_id: filtros.value.branchId || undefined,
        cash_register_id: filtros.value.cashRegisterId || undefined,
        opened_by: puedeVerTodas.value ? (filtros.value.openedBy || undefined) : undefined,
        status: filtros.value.status || undefined,
        page: pagina,
    };
}

function recargar() {
    currentPage.value = 1;
    cargar();
}

async function cargar() {
    loading.value = true;
    try {
        const { data }: { data: CashSessionsListResponse } = await httpClient.get('cash/sessions', {
            params: paramsFiltros(currentPage.value),
        });
        sessions.value = data.sessions;
        totalRows.value = data.total;
        perPage.value = data.paginate;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo cargar el historial de caja.', 'error');
    } finally {
        loading.value = false;
    }
}

watch(currentPage, () => cargar());
onMounted(() => {
    cargar();
    cargarSedes();
    cargarCajas();
});

// ── Detalle de solo lectura ──────────────────────────────────────────
const showDetailModal = ref(false);
const loadingDetail = ref(false);
const detalle = ref<CashSessionDetail | null>(null);
const downloadingPdf = ref(false);

async function abrirDetalle(id: number) {
    showDetailModal.value = true;
    loadingDetail.value = true;
    detalle.value = null;
    try {
        const { data }: { data: CashSessionDetailResponse } = await httpClient.get(`cash/sessions/${id}`);
        detalle.value = data.session;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo cargar el detalle de la sesión.', 'error');
        showDetailModal.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

async function descargarPdfIndividual() {
    if (!detalle.value) return;
    downloadingPdf.value = true;
    try {
        const { data } = await httpClient.get(`cash/sessions/${detalle.value.id}/pdf-url`);
        window.open(data.url, '_blank');
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo generar el PDF.', 'error');
    } finally {
        downloadingPdf.value = false;
    }
}

// ── Exportar PDF de rango (máx. 1 mes) ───────────────────────────────
const exportingPdf = ref(false);

async function exportarPdfRango() {
    if (!filtros.value.dateFrom || !filtros.value.dateTo) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona fecha "Desde" y "Hasta" para exportar el rango.', 'error');
        return;
    }
    const dias = (new Date(filtros.value.dateTo).getTime() - new Date(filtros.value.dateFrom).getTime()) / 86400000;
    if (dias > 31) {
        (Swal as TVueSwalInstance).fire('Error', 'El rango máximo para este reporte es de un mes.', 'error');
        return;
    }

    exportingPdf.value = true;
    try {
        const { data } = await httpClient.get('cash/sessions/pdf-range-url', { params: paramsFiltros() });
        window.open(data.url, '_blank');
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo generar el PDF del rango.', 'error');
    } finally {
        exportingPdf.value = false;
    }
}

// ── Exportar Excel (sin límite de rango, respeta filtros en pantalla) ──
const exportingExcel = ref(false);

async function exportarExcel() {
    exportingExcel.value = true;
    try {
        const response = await httpClient.get('cash/movements/export', {
            params: paramsFiltros(),
            responseType: 'blob',
        });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.download = `movimientos_caja_${new Date().toISOString().slice(0, 10)}.xlsx`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', 'No se pudo generar el Excel.', 'error');
    } finally {
        exportingExcel.value = false;
    }
}
</script>
