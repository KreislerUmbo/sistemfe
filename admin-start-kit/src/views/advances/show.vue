<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-hand-holding-usd me-2 text-primary"></i>
                    Adelanto #{{ advanceId }}
                </h5>
                <small class="text-muted" v-if="advance">{{ advance.client?.full_name }}</small>
            </div>
            <router-link :to="{ name: 'advances.index' }" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Volver
            </router-link>
        </div>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <div v-else-if="!advance" class="alert alert-danger">
            No se pudo cargar el adelanto.
        </div>

        <template v-else>
            <!-- ═══════════ Comprobante del adelanto ═══════════ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h6 class="fw-bold mb-1">
                                {{ advance.sale?.n_operacion ?? `${advance.sale?.serie ?? ''}-pendiente` }}
                                <span class="badge ms-1" :class="badgeClass(advance.status)">
                                    {{ statusLabel(advance.status) }}
                                </span>
                                <!-- Tier 3 (2026-08-24): estado SUNAT persistente, separado
                                     del estado de aplicación — antes un rechazo solo se veía
                                     un instante en un popup, sin quedar nada visible acá. -->
                                <span class="badge ms-1" :class="badgeSunatClass">{{ labelSunat }}</span>
                            </h6>
                            <small class="text-muted d-block">
                                Monto: {{ moneda }} {{ Number(advance.amount).toFixed(2) }}
                                · Medio de pago: {{ advance.payment_method }}
                                <template v-if="referenciaPago"> · Ref: {{ referenciaPago }}</template>
                            </small>
                        </div>
                        <div class="d-flex gap-2">
                            <button v-if="!advance.sale?.xml" type="button" class="btn btn-sm btn-primary"
                                :disabled="enviandoSunat" @click="enviarComprobanteSunat">
                                <span v-if="enviandoSunat" class="spinner-border spinner-border-sm me-1"></span>
                                Enviar comprobante a SUNAT
                            </button>
                            <template v-else>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                    @click="imprimirComprobante(advance.sale_id)">
                                    <i class="fas fa-print me-1"></i> Imprimir
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning" @click="abrirModalCorregir">
                                    <i class="fas fa-pen me-1"></i> Corregir tratamiento tributario
                                </button>
                            </template>
                        </div>
                    </div>
                    <div v-if="!advance.sale?.xml" class="alert alert-warning mt-3 mb-0 py-2 px-3 small">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        El adelanto no puede aplicarse a una venta ni reembolsarse hasta que este
                        comprobante sea enviado y aceptado por SUNAT.
                    </div>
                    <!-- Tier 3 (2026-08-24): antes un rechazo de SUNAT solo se veía en
                         un Swal que desaparecía — sin quedar registrado en pantalla. -->
                    <div v-if="advance.sale?.sunat_error_message" class="alert alert-danger mt-3 mb-0 py-2 px-3 small">
                        <i class="fas fa-circle-xmark me-1"></i>
                        <strong>Rechazado por SUNAT:</strong> {{ advance.sale.sunat_error_message }}
                    </div>
                    <!-- Tier 2 (2026-08-24): trazabilidad de la corrección más
                         reciente — el comprobante anterior queda anulado (NC
                         motivo 01), no borrado, sigue accesible acá. -->
                    <div v-if="advance.corrected_from_sale_id" class="alert alert-secondary mt-3 mb-0 py-2 px-3 small">
                        <i class="fas fa-circle-info me-1"></i>
                        Este adelanto fue corregido el {{ formatFechaHora(advance.corrected_at) }}.
                        Comprobante anterior (anulado): {{ advance.correctedFromSale?.n_operacion ?? advance.correctedFromSale?.serie }}.
                        <template v-if="advance.correction_reason"> Motivo: {{ advance.correction_reason }}.</template>
                    </div>
                </div>
            </div>

            <!-- ═══════════ Resumen de saldo ═══════════ -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Monto original</small>
                            <span class="fw-bold">{{ moneda }} {{ Number(advance.amount).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Aplicado</small>
                            <span class="fw-bold">{{ moneda }} {{ Number(advance.applied_amount).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Reembolsado</small>
                            <span class="fw-bold">{{ moneda }} {{ Number(advance.refunded_amount).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100 bg-light">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Disponible</small>
                            <span class="fw-bold text-primary">{{ moneda }} {{ disponible.toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════ Historial de aplicaciones ═══════════ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-2"><i class="fas fa-receipt me-1 text-secondary"></i>Aplicado a ventas</h6>
                    <p v-if="!advance.applications || advance.applications.length === 0" class="text-muted small mb-0">
                        Este adelanto no se ha aplicado a ninguna venta todavía.
                    </p>
                    <table v-else class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Venta</th>
                                <th class="text-end">Monto aplicado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="app in advance.applications" :key="app.id">
                                <td>{{ app.sale?.n_operacion ?? `Venta #${app.sale_id}` }}</td>
                                <td class="text-end">{{ moneda }} {{ Number(app.amount_applied).toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ═══════════ Historial de reembolsos ═══════════ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-2"><i class="fas fa-undo me-1 text-secondary"></i>Reembolsos</h6>
                    <p v-if="!advance.refunds || advance.refunds.length === 0" class="text-muted small mb-0">
                        Sin reembolsos registrados.
                    </p>
                    <table v-else class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nota de Crédito</th>
                                <th class="text-end">Monto</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in advance.refunds" :key="r.id">
                                <td>{{ r.note?.n_operacion ?? `${r.note?.serie ?? ''}-pendiente` }}</td>
                                <td class="text-end">{{ moneda }} {{ Number(r.amount_refunded).toFixed(2) }}</td>
                                <td>
                                    <span class="badge" :class="{
                                        'bg-success': r.note?.status === 'aceptado',
                                        'bg-danger': r.note?.status === 'rechazado',
                                        'bg-secondary': r.note?.status === 'pendiente' || r.note?.status === 'enviando',
                                    }">{{ r.note?.status }}</span>
                                </td>
                                <td>
                                    <button v-if="r.note?.status === 'pendiente'" type="button"
                                        class="btn btn-sm btn-primary py-0 px-2" :disabled="enviandoNota === r.note?.id"
                                        @click="enviarNotaSunat(r)">
                                        <span v-if="enviandoNota === r.note?.id" class="spinner-border spinner-border-sm"></span>
                                        <span v-else>Enviar a SUNAT</span>
                                    </button>
                                    <button v-else-if="r.note?.status === 'aceptado'" type="button"
                                        class="btn btn-sm btn-outline-secondary py-0 px-2" @click="imprimirNota(r.note!.id)">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ═══════════ Reembolsar saldo ═══════════ -->
            <div v-if="disponible > 0 && advance.sale?.xml" class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="fas fa-hand-holding-usd me-1 text-secondary"></i>Reembolsar saldo</h6>
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold">Monto a reembolsar</label>
                            <input type="number" class="form-control" min="0.01" :max="disponible" step="0.01"
                                v-model.number="montoReembolso">
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label small fw-semibold">Motivo (opcional)</label>
                            <input type="text" class="form-control" v-model="motivoReembolso">
                        </div>
                        <div class="col-12 col-md-3">
                            <button type="button" class="btn btn-danger w-100" :disabled="!puedeReembolsar || loadingReembolso"
                                @click="confirmarReembolso">
                                <span v-if="loadingReembolso" class="spinner-border spinner-border-sm me-1"></span>
                                Reembolsar
                            </button>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">
                        Solo se puede reembolsar el 100% de un adelanto que aún no se aplicó a ninguna venta
                        (motivo 06 — Devolución total). El reembolso parcial de un adelanto ya aplicado en
                        parte requiere Nota de Crédito motivo 09, todavía no disponible.
                    </small>
                </div>
            </div>

            <!-- ═══════════ Corregir tratamiento tributario (Tier 2, 2026-08-24) ═══════════
                 Anula el comprobante (NC motivo 01) y reemite con el tratamiento correcto —
                 mismo Advance, no toca lo ya aplicado a ventas. -->
            <div v-if="mostrarFormCorregir" class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="fas fa-pen me-1 text-warning"></i>Corregir tratamiento tributario</h6>
                    <p class="small text-muted">
                        Anula el comprobante actual con una Nota de Crédito (motivo 01 — Anulación de la
                        operación) y emite uno nuevo con el tratamiento correcto. El comprobante anterior
                        queda anulado, no se borra. Ambos documentos deben enviarse a SUNAT por separado
                        después de confirmar acá.
                    </p>
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold">Tratamiento correcto</label>
                            <select class="form-select" v-model="tipAfeIgvCorreccion">
                                <option value="10">Gravado (IGV 18%)</option>
                                <option value="20">Exonerado</option>
                                <option value="30">Inafecto</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label small fw-semibold">Motivo de la corrección</label>
                            <input type="text" class="form-control" v-model="motivoCorreccion"
                                placeholder="Ej: el contador observó que debía salir exonerado por Ley 27037">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="mostrarFormCorregir = false">Cancelar</button>
                        <button type="button" class="btn btn-warning btn-sm" :disabled="!puedeCorregir || loadingCorregir"
                            @click="confirmarCorregir">
                            <span v-if="loadingCorregir" class="spinner-border spinner-border-sm me-1"></span>
                            Confirmar corrección
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import Swal from "sweetalert2/dist/sweetalert2.js";
import httpClient from "@/helpers/http-client";
import type { Advance, AdvanceRefundRecord, AdvanceStatus } from "@/types/advances";
import { imprimirComprobante, imprimirNota } from "@/composables/usePrintComprobante";
import { formatFechaHora } from "@/helpers/fecha";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const advanceId = route.params.id as string;

const loading = ref(true);
const advance = ref<Advance | null>(null);
const enviandoSunat = ref(false);
const enviandoNota = ref<number | null>(null);

const montoReembolso = ref<number>(0);
const motivoReembolso = ref<string>("");
const loadingReembolso = ref(false);

// Tier 2 (2026-08-24): corregir tratamiento tributario (anula NC motivo 01 + reemite).
const mostrarFormCorregir = ref(false);
const tipAfeIgvCorreccion = ref<"10" | "20" | "30">("10");
const motivoCorreccion = ref("");
const loadingCorregir = ref(false);
const puedeCorregir = computed(() => motivoCorreccion.value.trim().length >= 10);

const moneda = computed(() => (advance.value?.currency === "USD" ? "US$" : "S/"));

// Tier 3 (2026-08-24): estado SUNAT persistente + referencia de pago —
// mismo criterio que advances/index.vue.
const labelSunat = computed(() => {
    if (advance.value?.sale?.xml) return "Aceptado";
    if (advance.value?.sale?.sunat_error_message) return "Rechazado";
    return "Sin enviar";
});
const badgeSunatClass = computed(() => {
    if (advance.value?.sale?.xml) return "bg-success";
    if (advance.value?.sale?.sunat_error_message) return "bg-danger";
    return "bg-warning text-dark";
});
const referenciaPago = computed(() => advance.value?.sale?.sale_payments?.[0]?.comments || null);

const disponible = computed(() => {
    if (!advance.value) return 0;
    return Number(advance.value.amount) - Number(advance.value.applied_amount) - Number(advance.value.refunded_amount);
});

const puedeReembolsar = computed(() =>
    montoReembolso.value > 0 && montoReembolso.value <= disponible.value
);

const statusLabels: Record<AdvanceStatus, string> = {
    pending: "Pendiente",
    partially_applied: "Aplicado parcial",
    applied: "Aplicado",
    partially_refunded: "Reembolsado parcial",
    refunded: "Reembolsado",
};
function statusLabel(s: AdvanceStatus): string {
    return statusLabels[s] ?? s;
}

function badgeClass(s: AdvanceStatus): string {
    switch (s) {
        case "applied": return "bg-success";
        case "refunded": return "bg-secondary";
        case "partially_applied":
        case "partially_refunded": return "bg-info text-dark";
        default: return "bg-warning text-dark";
    }
}

async function cargar() {
    loading.value = true;
    try {
        const { data } = await httpClient.get(`advances/${advanceId}`);
        advance.value = data.advance;
        montoReembolso.value = disponible.value;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar el adelanto.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

async function enviarComprobanteSunat() {
    if (!advance.value) return;
    enviandoSunat.value = true;
    try {
        const { data } = await httpClient.post("enviarSunat", { sale_id: advance.value.sale_id });

        if (data.response?.error) {
            (Swal as TVueSwalInstance).fire(
                "Rechazado por SUNAT",
                data.response.error.message ?? "El comprobante fue rechazado.",
                "error"
            );
        } else {
            (Swal as TVueSwalInstance).fire("¡Enviado a SUNAT!", "El comprobante fue aceptado.", "success");
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "Error inesperado al enviar el comprobante.",
            "error"
        );
    } finally {
        enviandoSunat.value = false;
        await cargar();
    }
}

async function enviarNotaSunat(r: AdvanceRefundRecord) {
    if (!r.note) return;
    enviandoNota.value = r.note.id;
    try {
        const { data } = await httpClient.post("notas/enviar-sunat", { note_id: r.note.id });

        if (data.response?.error) {
            (Swal as TVueSwalInstance).fire(
                "Rechazado por SUNAT",
                data.response.error.message ?? "La Nota de Crédito fue rechazada.",
                "error"
            );
        } else {
            (Swal as TVueSwalInstance).fire("¡Enviado a SUNAT!", "La Nota de Crédito fue aceptada.", "success");
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "Error inesperado al enviar la Nota de Crédito.",
            "error"
        );
    } finally {
        enviandoNota.value = null;
        await cargar();
    }
}

function confirmarReembolso() {
    (Swal as TVueSwalInstance).fire({
        title: "¿Reembolsar este adelanto?",
        text: `Se creará una Nota de Crédito por ${moneda.value} ${montoReembolso.value.toFixed(2)}. ` +
            "Deberás enviarla a SUNAT desde esta misma página para completar el reembolso.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#dc2626",
        cancelButtonColor: "#6b7280",
        confirmButtonText: "Sí, reembolsar",
        cancelButtonText: "Cancelar",
    }).then((result: any) => {
        if (result.isConfirmed) {
            reembolsar();
        }
    });
}

async function reembolsar() {
    if (!advance.value) return;
    loadingReembolso.value = true;
    try {
        const { data } = await httpClient.post(`advances/${advance.value.id}/refund`, {
            amount: montoReembolso.value,
            reason: motivoReembolso.value || null,
        });

        (Swal as TVueSwalInstance).fire("¡Listo!", data.message, "success");
        motivoReembolso.value = "";
        await cargar();
    } catch (e: any) {
        if (e.response?.status === 501) {
            (Swal as TVueSwalInstance).fire(
                "Todavía no disponible",
                e.response.data?.message ?? "Este tipo de reembolso requiere una funcionalidad pendiente.",
                "info"
            );
        } else {
            (Swal as TVueSwalInstance).fire(
                "Error",
                e.response?.data?.message ?? "No se pudo procesar el reembolso.",
                "error"
            );
        }
    } finally {
        loadingReembolso.value = false;
    }
}

// Tier 2 (2026-08-24) — corregir tratamiento tributario.
function abrirModalCorregir() {
    // Precarga con la clasificación actual del comprobante (derivada de los
    // montos ya calculados por el backend, no hay un campo tip_afe_igv
    // directo en el Advance) — el usuario solo la cambia si corresponde.
    const sale = advance.value?.sale;
    if (sale && Number(sale.mto_oper_exoneradas) > 0) tipAfeIgvCorreccion.value = "20";
    else if (sale && Number(sale.mto_oper_inafectas) > 0) tipAfeIgvCorreccion.value = "30";
    else tipAfeIgvCorreccion.value = "10";
    motivoCorreccion.value = "";
    mostrarFormCorregir.value = true;
}

function confirmarCorregir() {
    (Swal as TVueSwalInstance).fire({
        title: "¿Corregir este adelanto?",
        text: "Se anulará el comprobante actual (Nota de Crédito motivo 01) y se emitirá uno nuevo " +
            "con el tratamiento elegido. Ambos deberán enviarse a SUNAT después.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d97706",
        cancelButtonColor: "#6b7280",
        confirmButtonText: "Sí, corregir",
        cancelButtonText: "Cancelar",
    }).then((result: any) => {
        if (result.isConfirmed) {
            corregir();
        }
    });
}

async function corregir() {
    if (!advance.value) return;
    loadingCorregir.value = true;
    try {
        const { data } = await httpClient.post(`advances/${advance.value.id}/corregir`, {
            tip_afe_igv: tipAfeIgvCorreccion.value,
            motivo_correccion: motivoCorreccion.value,
        });

        (Swal as TVueSwalInstance).fire("¡Listo!", data.message, "success");
        mostrarFormCorregir.value = false;
        await cargar();
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo corregir el adelanto.",
            "error"
        );
    } finally {
        loadingCorregir.value = false;
    }
}

onMounted(() => cargar());
</script>
