<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice-dollar me-2 text-primary"></i>
                    Estado de Cuenta
                </h5>
                <small class="text-muted" v-if="resumen">{{ resumen.client_nombre }}</small>
            </div>
            <router-link :to="{ name: 'credit_receivables.index' }" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Volver
            </router-link>
        </div>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <div v-else-if="!resumen" class="alert alert-danger">
            No se pudo cargar el estado de cuenta.
        </div>

        <template v-else>
            <!-- ═══════════ Resumen ═══════════ -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Deuda total</small>
                            <span class="fw-bold">S/ {{ Number(resumen.deuda_total).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Mora acumulada</small>
                            <span class="fw-bold" :class="resumen.mora_acumulada_total > 0 ? 'text-danger' : ''">
                                S/ {{ Number(resumen.mora_acumulada_total).toFixed(2) }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Ventas abiertas</small>
                            <span class="fw-bold">{{ resumen.cantidad_ventas_abiertas }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100 bg-light">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Saldo a favor</small>
                            <span class="fw-bold text-success">S/ {{ Number(resumen.saldo_a_favor).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════ Ventas abiertas ═══════════ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-2"><i class="fas fa-receipt me-1 text-secondary"></i>Ventas con saldo pendiente</h6>
                    <p v-if="resumen.ventas.length === 0" class="text-muted small mb-0">
                        Este cliente no tiene ventas con saldo pendiente.
                    </p>
                    <div v-else class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Comprobante</th>
                                    <th>Fecha</th>
                                    <th class="text-end">Saldo</th>
                                    <th class="text-end">Mora</th>
                                    <th>Próxima cuota</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in resumen.ventas" :key="v.sale_id"
                                    :class="{ 'table-primary': v.sale_id === selectedSaleId }">
                                    <td>{{ v.n_operacion ?? `#${v.sale_id} (sin emitir)` }}</td>
                                    <td>{{ v.date }}</td>
                                    <td class="text-end fw-semibold">S/ {{ Number(v.saldo_pendiente).toFixed(2) }}</td>
                                    <td class="text-end">S/ {{ Number(v.mora_acumulada).toFixed(2) }}</td>
                                    <td>{{ v.proxima_cuota_vencimiento ?? '—' }}</td>
                                    <td><span class="badge" :class="estadoBadgeClass(v.estado)">{{ estadoLabel(v.estado) }}</span></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2"
                                            @click="seleccionarVentaEspecifica(v.sale_id)">
                                            Cobrar esta
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════ Registrar pago ═══════════ -->
            <div v-if="resumen.ventas.length > 0" class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="fas fa-hand-holding-usd me-1 text-secondary"></i>Registrar pago</h6>

                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Modo</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" id="modo-general" value="general"
                                    :checked="modo === 'general'" @click="modo = 'general'" autocomplete="off">
                                <label class="btn btn-outline-primary btn-sm" for="modo-general">General</label>
                                <input type="radio" class="btn-check" id="modo-especifico" value="especifico"
                                    :checked="modo === 'especifico'" @click="modo = 'especifico'" autocomplete="off">
                                <label class="btn btn-outline-primary btn-sm" for="modo-especifico">Específico</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-3" v-if="modo === 'especifico'">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Venta</label>
                            <select v-model.number="selectedSaleId" class="form-select form-select-sm">
                                <option v-for="v in resumen.ventas" :key="v.sale_id" :value="v.sale_id">
                                    {{ v.n_operacion ?? `#${v.sale_id}` }} — S/ {{ Number(v.saldo_pendiente).toFixed(2) }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Monto total</label>
                            <b-input-group size="sm">
                                <b-input-group-text>S/</b-input-group-text>
                                <b-form-input type="number" v-model.number="montoTotal" step="0.01" size="sm" />
                            </b-input-group>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Fecha de pago</label>
                            <b-form-input type="date" v-model="fechaPago" size="sm" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Método</label>
                            <select v-model="medioPago" class="form-select form-select-sm">
                                <option value="EFECTIVO">💵 Efectivo</option>
                                <option value="TRANSFERENCIA">🏦 Transferencia</option>
                                <option value="YAPE">📱 Yape</option>
                                <option value="PLIN">📱 Plin</option>
                                <option value="TARJETA DE CREDITO">💳 Tarjeta de Crédito</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">N° operación</label>
                            <b-form-input type="text" v-model="nroOperacion" size="sm" placeholder="Opcional" />
                        </div>
                        <div class="col-12 col-md-3 d-flex align-items-center">
                            <span v-if="loadingPreview" class="small text-muted">
                                <span class="spinner-border spinner-border-sm me-1"></span>Calculando reparto...
                            </span>
                        </div>
                    </div>

                    <!-- ═══ Caso trivial: 1 sola cuota, sin mora — sin tabla, directo a guardar ═══ -->
                    <div v-if="preview && esTrivial" class="border-top pt-3">
                        <div class="alert alert-light border py-2 px-3 mb-2 small">
                            Se aplicará <strong>S/ {{ Number(preview.total_aplicado).toFixed(2) }}</strong>
                            a {{ preview.aplicaciones[0].numero_cuota ? `la cuota ${preview.aplicaciones[0].numero_cuota} de ` : '' }}{{ ventaLabel(preview.aplicaciones[0].sale_id) }}.
                            <span v-if="preview.monto_no_aplicado > 0" class="text-success d-block mt-1">
                                Excedente (va a saldo a favor): <strong>S/ {{ Number(preview.monto_no_aplicado).toFixed(2) }}</strong>
                            </span>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-success fw-semibold" :disabled="loadingConfirm"
                                @click="confirmarPago">
                                <span v-if="loadingConfirm" class="spinner-border spinner-border-sm me-1"></span>
                                Guardar pago
                            </button>
                        </div>
                    </div>

                    <!-- ═══ Caso general: preview editable ═══ -->
                    <div v-else-if="preview" class="border-top pt-3">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-2">
                                <thead class="table-light">
                                    <tr class="small text-secondary text-uppercase">
                                        <th>Venta</th>
                                        <th>Cuota</th>
                                        <th class="text-end">Saldo antes</th>
                                        <th>Monto aplicado</th>
                                        <th class="text-end">Mora antes</th>
                                        <th>Mora aplicada</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(a, idx) in preview.aplicaciones" :key="idx">
                                        <td class="small">{{ ventaLabel(a.sale_id) }}</td>
                                        <td class="small">{{ a.numero_cuota ?? '—' }}</td>
                                        <td class="text-end small">S/ {{ Number(a.saldo_antes).toFixed(2) }}</td>
                                        <td>
                                            <b-input-group size="sm">
                                                <b-input-group-text>S/</b-input-group-text>
                                                <b-form-input type="number" v-model.number="a.monto_aplicado" step="0.01" size="sm" />
                                            </b-input-group>
                                        </td>
                                        <td class="text-end small">S/ {{ Number(a.mora_antes).toFixed(2) }}</td>
                                        <td>
                                            <b-input-group size="sm">
                                                <b-input-group-text>S/</b-input-group-text>
                                                <b-form-input type="number" v-model.number="a.monto_mora_aplicado" step="0.01" size="sm" />
                                            </b-input-group>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="small">
                                <span class="me-3">Total aplicado: <strong>S/ {{ Number(preview.total_aplicado).toFixed(2) }}</strong></span>
                                <span class="me-3">Mora cobrada: <strong>S/ {{ Number(preview.total_mora_aplicada).toFixed(2) }}</strong></span>
                                <span v-if="preview.monto_no_aplicado > 0" class="text-success">
                                    Excedente (va a saldo a favor): <strong>S/ {{ Number(preview.monto_no_aplicado).toFixed(2) }}</strong>
                                </span>
                            </div>
                            <button type="button" class="btn btn-success fw-semibold" :disabled="loadingConfirm"
                                @click="confirmarPago">
                                <span v-if="loadingConfirm" class="spinner-border spinner-border-sm me-1"></span>
                                Guardar pago
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════ Historial de pagos ═══════════ -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-2">
                        <i class="fas fa-history me-1 text-secondary"></i>Historial de pagos
                    </h6>

                    <div v-if="loadingRecibos" class="text-center py-3">
                        <span class="spinner-border spinner-border-sm text-primary"></span>
                    </div>
                    <p v-else-if="recibos.length === 0" class="text-muted small mb-0">
                        Este cliente aún no tiene pagos registrados.
                    </p>
                    <div v-else class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Origen</th>
                                    <th>N° Recibo</th>
                                    <th>Fecha pago</th>
                                    <th>Método</th>
                                    <th>N° operación</th>
                                    <th class="text-end">Monto total</th>
                                    <th class="text-end">Excedente</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in recibos" :key="`${r.origen}-${r.id}`">
                                    <td>
                                        <span class="badge"
                                            :class="r.origen === 'recibo' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary'"
                                            :title="r.origen === 'recibo' ? 'Cobrado desde Cuentas por Cobrar' : 'Pago inicial registrado en la venta — no genera recibo formal'">
                                            {{ r.origen === 'recibo' ? 'Recibo' : 'Pago de venta' }}
                                        </span>
                                    </td>
                                    <td class="fw-semibold">{{ r.numero_recibo ?? '—' }}</td>
                                    <td>{{ formatFechaPago(r.fecha_pago) }}</td>
                                    <td>{{ r.medio_pago }}</td>
                                    <td>{{ r.origen === 'recibo' ? (r.nro_operacion ?? '—') : (r.sale_n_operacion ?? '—') }}</td>
                                    <td class="text-end">S/ {{ Number(r.monto_total).toFixed(2) }}</td>
                                    <td class="text-end">
                                        <span v-if="r.monto_no_aplicado > 0" class="text-success">
                                            S/ {{ Number(r.monto_no_aplicado).toFixed(2) }}
                                        </span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="r.estado === 'anulado' ? 'bg-danger' : 'bg-success'">
                                            {{ r.estado === 'anulado' ? 'Anulado' : 'Activo' }}
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        <template v-if="r.origen === 'recibo'">
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1"
                                                @click="imprimirReciboPago(r.id)">
                                                <i class="fas fa-print"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                                                v-if="r.estado === 'activo' && puedeAnularPago"
                                                @click="anularPago(r)">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="recibos.length > 0" class="d-flex justify-content-between align-items-center px-1 pt-2 border-top mt-2">
                        <small class="text-muted">Mostrando {{ recibos.length }} de {{ totalRecibos }} pago(s)</small>
                        <b-pagination v-model="currentPageRecibos" :total-rows="totalRecibos" :per-page="25"
                            prev-text="Anterior" next-text="Siguiente" size="sm" class="mb-0" />
                    </div>
                </div>
            </div>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import { computed, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import Swal from "sweetalert2/dist/sweetalert2.js";
import httpClient from "@/helpers/http-client";
import { imprimirReciboPago } from "@/composables/usePrintComprobante";
import { useAuthStore } from "@/stores/auth";
import type {
    ClientCreditSummary,
    EstadoCuentaCredito,
    PaymentPreviewResponse,
    PaymentReceiptRow,
    PaymentReceiptsResponse,
    PaymentStoreResponse,
} from "@/types/credit";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const clientId = route.params.id as string;

const authStore = useAuthStore();
// Anular un pago mueve dinero real de vuelta a "pendiente" — mismo
// permiso Spatie que ya protege POST payment-receipts/{id}/anular en el
// backend (CreditPaymentController::anular()), configurable por rol
// desde Roles y Permisos (ver types/roles.ts).
const puedeAnularPago = computed(() => authStore.isPermitedRoute('anular-pago-credito'));

const loading = ref(true);
const resumen = ref<ClientCreditSummary | null>(null);

const modo = ref<"general" | "especifico">(route.query.sale_id ? "especifico" : "general");
const selectedSaleId = ref<number | null>(route.query.sale_id ? Number(route.query.sale_id) : null);

const montoTotal = ref<number>(0);
const fechaPago = ref<string>(new Date().toISOString().slice(0, 10));
const medioPago = ref<string>("EFECTIVO");
const nroOperacion = ref<string>("");

const preview = ref<PaymentPreviewResponse | null>(null);
const loadingPreview = ref(false);
const loadingConfirm = ref(false);

const recibos = ref<PaymentReceiptRow[]>([]);
const totalRecibos = ref(0);
const currentPageRecibos = ref(1);
const loadingRecibos = ref(false);

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

// fecha_pago llega como "YYYY-MM-DD" (fecha pura, sin hora real — se
// captura con un <input type="date">, ver formulario de "Registrar pago"
// más abajo) — solo se reordena a dd/mm/yyyy, no hay hora que mostrar.
function formatFechaPago(fecha: string): string {
    const [anio, mes, dia] = fecha.split("-");
    return `${dia}/${mes}/${anio}`;
}

function ventaLabel(saleId: number): string {
    const v = resumen.value?.ventas.find((x) => x.sale_id === saleId);
    return v?.n_operacion ?? `#${saleId}`;
}

// Caso trivial: el reparto tocó una sola cuota/venta y no hay mora de por
// medio — no hay nada que el cajero necesite ajustar a mano, así que la
// tabla editable no aporta (pedido del usuario: evitar el paso de más en
// el caso simple, sin perder la posibilidad de editar en el caso general).
const esTrivial = computed(() => {
    if (!preview.value || preview.value.aplicaciones.length !== 1) return false;
    const fila = preview.value.aplicaciones[0];
    return Number(fila.mora_antes) === 0 && Number(fila.monto_mora_aplicado) === 0;
});

function seleccionarVentaEspecifica(saleId: number) {
    modo.value = "especifico";
    selectedSaleId.value = saleId;
    const venta = resumen.value?.ventas.find((v) => v.sale_id === saleId);
    if (venta) montoTotal.value = Number(venta.saldo_pendiente);
    preview.value = null;
}

async function cargarResumen() {
    loading.value = true;
    try {
        const { data } = await httpClient.get(`clients/${clientId}/credit-summary`);
        resumen.value = data as ClientCreditSummary;

        if (selectedSaleId.value) {
            const venta = resumen.value.ventas.find((v) => v.sale_id === selectedSaleId.value);
            if (venta) montoTotal.value = Number(venta.saldo_pendiente);
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar el estado de cuenta.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

// ── Cálculo automático del reparto ──────────────────────────────────
// El cajero ya no clickea "Calcular" — apenas completa monto + fecha
// (y venta, si el modo es específico), el reparto se dispara solo con
// un pequeño debounce (evita pegarle al backend en cada tecla). Se
// mantiene como función aparte (no solo el watcher) porque
// seleccionarVentaEspecifica() también la necesita disparar.
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

watch([montoTotal, fechaPago, modo, selectedSaleId], () => {
    preview.value = null;
    if (debounceTimer) clearTimeout(debounceTimer);

    const listoParaCalcular = montoTotal.value > 0
        && !!fechaPago.value
        && (modo.value === "general" || !!selectedSaleId.value);

    if (!listoParaCalcular) return;

    debounceTimer = setTimeout(() => calcularReparto(), 500);
});

async function calcularReparto() {
    if (montoTotal.value <= 0) return;
    loadingPreview.value = true;
    preview.value = null;
    try {
        const { data }: { data: PaymentPreviewResponse } = await httpClient.post(`clients/${clientId}/payments/preview`, {
            monto_total: montoTotal.value,
            fecha_pago: fechaPago.value,
            medio_pago: medioPago.value,
            nro_operacion: nroOperacion.value || null,
            sale_id: modo.value === "especifico" ? selectedSaleId.value : null,
        });
        preview.value = data;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo calcular el reparto del pago.",
            "error"
        );
    } finally {
        loadingPreview.value = false;
    }
}

async function confirmarPago() {
    if (!preview.value) return;
    loadingConfirm.value = true;
    try {
        const { data }: { data: PaymentStoreResponse } = await httpClient.post(`clients/${clientId}/payments`, {
            monto_total: montoTotal.value,
            fecha_pago: fechaPago.value,
            medio_pago: medioPago.value,
            nro_operacion: nroOperacion.value || null,
            aplicaciones: preview.value.aplicaciones.map((a) => ({
                sale_id: a.sale_id,
                installment_id: a.installment_id,
                monto_aplicado: a.monto_aplicado,
                monto_mora_aplicado: a.monto_mora_aplicado,
            })),
        });

        await (Swal as TVueSwalInstance).fire(
            "¡Listo!",
            `Pago registrado — recibo ${data.numero_recibo}.`,
            "success"
        );

        preview.value = null;
        montoTotal.value = 0;
        nroOperacion.value = "";
        await Promise.all([cargarResumen(), cargarRecibos()]);
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo registrar el pago.",
            "error"
        );
    } finally {
        loadingConfirm.value = false;
    }
}

async function cargarRecibos() {
    loadingRecibos.value = true;
    try {
        const { data }: { data: PaymentReceiptsResponse } = await httpClient.get(
            `clients/${clientId}/payment-receipts`,
            { params: { page: currentPageRecibos.value } },
        );
        recibos.value = data.payment_receipts;
        totalRecibos.value = data.total;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar el historial de recibos.",
            "error"
        );
    } finally {
        loadingRecibos.value = false;
    }
}

// Anular un recibo de pago (§3.6) — revierte saldo_pendiente/installments/
// saldo_a_favor en el backend (CreditPaymentController::anular()), nunca
// DELETE. El motivo es siempre obligatorio (min 5 caracteres, mismo
// mínimo que exige el backend) para quedar en el historial de auditoría.
async function anularPago(receipt: PaymentReceiptRow) {
    const result = await Swal.fire({
        title: `¿Anular el recibo ${receipt.numero_recibo}?`,
        icon: "warning",
        html: `
            <p class="text-start small mb-2">
                Se revertirá el saldo pendiente de la(s) venta(s) afectada(s). Esta acción
                no se puede deshacer (el recibo queda marcado como anulado, con motivo).
            </p>
            <textarea id="motivo-anulacion-pago" class="swal2-textarea" rows="3"
                placeholder="Motivo de la anulación (mínimo 5 caracteres)" style="display:block;"></textarea>
        `,
        showCancelButton: true,
        confirmButtonText: "Anular recibo",
        confirmButtonColor: "#dc3545",
        cancelButtonText: "Cancelar",
        focusConfirm: false,
        preConfirm: () => {
            const textarea = document.querySelector<HTMLTextAreaElement>("#motivo-anulacion-pago");
            const motivo = textarea?.value.trim() ?? "";
            if (motivo.length < 5) {
                Swal.showValidationMessage("El motivo debe tener al menos 5 caracteres.");
                return false;
            }
            return motivo;
        },
    });

    if (!result.isConfirmed) return;

    try {
        await httpClient.post(`payment-receipts/${receipt.id}/anular`, {
            motivo_anulacion: result.value,
        });
        await (Swal as TVueSwalInstance).fire("Listo", "Recibo anulado correctamente.", "success");
        await Promise.all([cargarResumen(), cargarRecibos()]);
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo anular el recibo.",
            "error"
        );
    }
}

watch(currentPageRecibos, () => cargarRecibos());

onMounted(() => {
    cargarResumen();
    cargarRecibos();
});
</script>
