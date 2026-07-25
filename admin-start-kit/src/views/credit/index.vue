<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice-dollar me-2 text-primary"></i>
                    Cuentas por Cobrar
                </h5>
                <small class="text-muted">{{ totalRows }} registro(s) encontrado(s)</small>
            </div>
            <div class="btn-group shadow-sm" role="group">
                <input type="radio" class="btn-check" name="vista" id="vista-cliente" value="cliente"
                    :checked="vista === 'cliente'" @click="cambiarVista('cliente')" autocomplete="off">
                <label class="btn btn-outline-primary btn-sm px-3" for="vista-cliente">
                    <i class="fas fa-users me-1"></i>Por Cliente
                </label>
                <input type="radio" class="btn-check" name="vista" id="vista-venta" value="venta"
                    :checked="vista === 'venta'" @click="cambiarVista('venta')" autocomplete="off">
                <label class="btn btn-outline-primary btn-sm px-3" for="vista-venta">
                    <i class="fas fa-receipt me-1"></i>Por Venta
                </label>
            </div>
        </div>

        <!-- ═══════ Filtros ═══════ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4" v-if="vista === 'cliente'">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Buscar</label>
                        <input type="text" class="form-control form-control-sm" v-model="search"
                            placeholder="Nombre o documento..." @keyup.enter="recargar">
                    </div>
                    <template v-else>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Estado</label>
                            <select v-model="estado" class="form-select form-select-sm">
                                <option value="">— Todos —</option>
                                <option value="al_dia">Al día</option>
                                <option value="por_vencer">Por vencer</option>
                                <option value="vencida">Vencida</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Desde</label>
                            <input type="date" class="form-control form-control-sm" v-model="fechaDesde">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Hasta</label>
                            <input type="date" class="form-control form-control-sm" v-model="fechaHasta">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Orden</label>
                            <select v-model="orderBy" class="form-select form-select-sm">
                                <option value="antiguedad">Antigüedad</option>
                                <option value="monto">Monto</option>
                            </select>
                        </div>
                    </template>
                    <div class="col-6 col-md-2">
                        <button type="button" class="btn btn-primary btn-sm w-100" @click="recargar">
                            <i class="fas fa-filter me-1"></i>Filtrar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════ Vista A — por cliente ═══════ -->
        <div v-if="vista === 'cliente'" class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Cliente</th>
                            <th>Documento</th>
                            <th class="text-end">Deuda total</th>
                            <th class="text-center">Ventas abiertas</th>
                            <th class="text-center">Cuotas vencidas</th>
                            <th class="text-end">Saldo a favor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="6" class="text-center py-4"><span class="spinner-border text-primary"></span></td>
                        </tr>
                        <tr v-else-if="clientes.length === 0">
                            <td colspan="6" class="text-center text-muted py-4">Sin clientes con deuda activa.</td>
                        </tr>
                        <tr v-for="c in clientes" :key="c.client_id" style="cursor:pointer"
                            @click="$router.push({ name: 'credit_receivables.client', params: { id: c.client_id } })">
                            <td class="fw-semibold">{{ c.client_nombre }}</td>
                            <td>{{ c.n_document }}</td>
                            <td class="text-end fw-bold">S/ {{ Number(c.deuda_total).toFixed(2) }}</td>
                            <td class="text-center">{{ c.cantidad_ventas_abiertas }}</td>
                            <td class="text-center">
                                <span v-if="c.cantidad_cuotas_vencidas > 0" class="badge bg-danger">{{ c.cantidad_cuotas_vencidas }}</span>
                                <span v-else class="text-muted">0</span>
                            </td>
                            <td class="text-end">
                                <span v-if="c.saldo_a_favor > 0" class="text-success fw-semibold">S/ {{ Number(c.saldo_a_favor).toFixed(2) }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <small class="text-muted">Mostrando {{ clientes.length }} de {{ totalRows }} registro(s)</small>
                <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="25"
                    prev-text="Anterior" next-text="Siguiente" class="mb-0" />
            </div>
        </div>

        <!-- ═══════ Vista B — por venta ═══════ -->
        <div v-else class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Comprobante</th>
                            <th>Cliente</th>
                            <th>Fecha</th>
                            <th class="text-end">Saldo pendiente</th>
                            <th>Próxima cuota</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="6" class="text-center py-4"><span class="spinner-border text-primary"></span></td>
                        </tr>
                        <tr v-else-if="ventas.length === 0">
                            <td colspan="6" class="text-center text-muted py-4">Sin ventas con saldo pendiente.</td>
                        </tr>
                        <tr v-for="v in ventas" :key="v.sale_id" style="cursor:pointer"
                            @click="$router.push({ name: 'credit_receivables.client', params: { id: v.client_id }, query: { sale_id: v.sale_id } })">
                            <td>{{ v.n_operacion ?? `#${v.sale_id} (sin emitir)` }}</td>
                            <td>{{ v.client_nombre }}</td>
                            <td>{{ v.date }}</td>
                            <td class="text-end fw-bold">S/ {{ Number(v.saldo_pendiente).toFixed(2) }}</td>
                            <td>{{ v.proxima_cuota_vencimiento ?? '—' }}</td>
                            <td>
                                <span class="badge" :class="estadoBadgeClass(v.estado)">{{ estadoLabel(v.estado) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <small class="text-muted">Mostrando {{ ventas.length }} de {{ totalRows }} registro(s)</small>
                <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="25"
                    prev-text="Anterior" next-text="Siguiente" class="mb-0" />
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import { ref, watch, onMounted } from "vue";
import Swal from "sweetalert2/dist/sweetalert2.js";
import httpClient from "@/helpers/http-client";
import type {
    ClientCreditSummaryRow,
    CreditSaleRow,
    CreditSalesResponse,
    CreditSummaryListResponse,
    EstadoCuentaCredito,
} from "@/types/credit";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const vista = ref<"cliente" | "venta">("cliente");
const loading = ref(false);
const currentPage = ref(1);
const totalRows = ref(0);

const clientes = ref<ClientCreditSummaryRow[]>([]);
const ventas = ref<CreditSaleRow[]>([]);

const search = ref("");
const estado = ref("");
const fechaDesde = ref("");
const fechaHasta = ref("");
const orderBy = ref("antiguedad");

const estadoLabels: Record<EstadoCuentaCredito, string> = {
    al_dia: "Al día",
    por_vencer: "Por vencer",
    vencida: "Vencida",
};
function estadoLabel(e: EstadoCuentaCredito): string {
    return estadoLabels[e] ?? e;
}
function estadoBadgeClass(e: EstadoCuentaCredito): string {
    switch (e) {
        case "vencida": return "bg-danger";
        case "por_vencer": return "bg-warning text-dark";
        default: return "bg-success";
    }
}

function cambiarVista(v: "cliente" | "venta") {
    vista.value = v;
    currentPage.value = 1;
    cargar();
}

function recargar() {
    currentPage.value = 1;
    cargar();
}

async function cargar() {
    loading.value = true;
    try {
        if (vista.value === "cliente") {
            const { data }: { data: CreditSummaryListResponse } = await httpClient.get("clients/credit-summary-list", {
                params: { search: search.value || undefined, page: currentPage.value },
            });
            clientes.value = data.clients;
            totalRows.value = data.total;
        } else {
            const { data }: { data: CreditSalesResponse } = await httpClient.get("credit-sales", {
                params: {
                    estado: estado.value || undefined,
                    fecha_desde: fechaDesde.value || undefined,
                    fecha_hasta: fechaHasta.value || undefined,
                    order_by: orderBy.value,
                    page: currentPage.value,
                },
            });
            ventas.value = data.sales;
            totalRows.value = data.total;
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar Cuentas por Cobrar.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

watch(currentPage, () => cargar());
onMounted(() => cargar());
</script>
