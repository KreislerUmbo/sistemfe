<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice me-2 text-primary"></i>
                    {{ cotizacion?.code ?? `Cotización #${route.params.id}` }}
                    <span v-if="cotizacion" class="badge ms-1" :class="badgeClass(cotizacion.status)">
                        {{ statusLabel(cotizacion.status) }}
                    </span>
                </h5>
                <small class="text-muted" v-if="cotizacion">
                    {{ cotizacion.client?.full_name ?? cotizacion.client_name_free ?? 'Sin cliente' }}
                </small>
            </div>
            <router-link :to="{ name: 'commercial-quotes.index' }" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Volver
            </router-link>
        </div>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <div v-else-if="!cotizacion" class="alert alert-danger">
            No se pudo cargar la cotización.
        </div>

        <template v-else>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div class="small">
                            <div><strong>Vendedor:</strong> {{ cotizacion.registrado_por ?? '-' }}</div>
                            <div><strong>Válida hasta:</strong> {{ cotizacion.valid_until ?? '-' }}</div>
                            <div v-if="cotizacion.observacion"><strong>Observación:</strong> {{ cotizacion.observacion }}</div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="verPdf">
                                <i class="fas fa-file-pdf me-1"></i> PDF
                            </button>
                            <router-link v-if="puedeEditar" :to="{ name: 'commercial-quotes.edit', params: { id: cotizacion.id } }"
                                class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-pen me-1"></i> Editar
                            </router-link>
                            <button v-if="cotizacion.status === 'borrador'" type="button" class="btn btn-sm btn-info"
                                :disabled="cambiandoEstado" @click="cambiarEstado('enviada')">
                                Marcar enviada
                            </button>
                            <button v-if="cotizacion.status === 'enviada'" type="button" class="btn btn-sm btn-success"
                                :disabled="cambiandoEstado" @click="cambiarEstado('aceptada')">
                                Marcar aceptada
                            </button>
                            <button v-if="cotizacion.status === 'enviada'" type="button" class="btn btn-sm btn-outline-danger"
                                :disabled="cambiandoEstado" @click="cambiarEstado('rechazada')">
                                Marcar rechazada
                            </button>
                            <button v-if="cotizacion.status === 'enviada'" type="button" class="btn btn-sm btn-outline-warning"
                                :disabled="cambiandoEstado" @click="cambiarEstado('vencida')">
                                Marcar vencida
                            </button>
                            <button v-if="puedeAnular" type="button" class="btn btn-sm btn-outline-secondary"
                                :disabled="cambiandoEstado" @click="cambiarEstado('anulada')">
                                Anular
                            </button>
                            <button v-if="puedeCobrarAnticipo" type="button" class="btn btn-sm btn-outline-success"
                                @click="abrirModalAnticipo">
                                <i class="fas fa-hand-holding-usd me-1"></i> Cobrar anticipo
                            </button>
                            <button v-if="puedeConvertir" type="button" class="btn btn-sm btn-primary" @click="convertirEnVenta">
                                <i class="fas fa-cart-plus me-1"></i> Convertir en venta
                            </button>
                        </div>
                    </div>

                    <!-- Anticipos recibidos — mismo patrón que
                         agencia-viajes/reservas/detalle.vue: el comprobante
                         propio de cada anticipo se maneja desde el módulo de
                         Adelantos, acá solo se ve el resumen y se puede
                         desasociar mientras no se haya aplicado a ninguna venta. -->
                    <div v-if="cotizacion.anticipos.length > 0" class="card border-0 bg-light mb-3">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-semibold mb-0"><i class="fas fa-hand-holding-usd me-1 text-success"></i>Anticipos recibidos</h6>
                                <span class="small text-muted">Disponible: {{ moneda }} {{ totalAnticiposDisponibles.toFixed(2) }}</span>
                            </div>
                            <table class="table table-sm mb-0 bg-white">
                                <thead class="table-light">
                                    <tr>
                                        <th>Fecha</th>
                                        <th class="text-end">Monto</th>
                                        <th class="text-end">Disponible</th>
                                        <th>Estado SUNAT</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="a in cotizacion.anticipos" :key="a.id">
                                        <td>{{ formatFecha(a.fecha_asignacion) }}</td>
                                        <td class="text-end">{{ moneda }} {{ Number(a.monto_asignado).toFixed(2) }}</td>
                                        <td class="text-end">{{ moneda }} {{ Number(a.disponible).toFixed(2) }}</td>
                                        <td>
                                            <span class="badge" :class="a.sunat_enviado ? 'bg-success' : 'bg-warning text-dark'">
                                                {{ a.sunat_enviado ? 'Enviado a SUNAT' : 'Registrado, sin enviar' }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <button v-if="a.disponible === a.monto_asignado" type="button"
                                                class="btn btn-sm btn-link text-danger p-0" title="Quitar de esta cotización"
                                                @click="quitarAnticipo(a)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="cotizacion.converted_sale_id" class="alert alert-success py-2 px-3 small mb-3">
                        <i class="fas fa-check-circle me-1"></i>
                        Convertida en la venta
                        <router-link :to="{ name: 'sale.edit', params: { id: cotizacion.converted_sale_id } }">
                            #{{ cotizacion.converted_sale_id }}
                        </router-link>
                        el {{ formatFechaHora(cotizacion.converted_at) }}.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Descripción</th>
                                    <th class="text-end">Cant.</th>
                                    <th class="text-end">P. Unit.</th>
                                    <th class="text-end">Desc. %</th>
                                    <th class="text-end">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in cotizacion.items" :key="item.id">
                                    <td>{{ item.product?.title ?? item.description }}</td>
                                    <td class="text-end">{{ item.quantity }}</td>
                                    <td class="text-end">{{ Number(item.unit_price).toFixed(2) }}</td>
                                    <td class="text-end">{{ Number(item.discount_percent).toFixed(2) }}</td>
                                    <td class="text-end">{{ Number(item.subtotal).toFixed(2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="row justify-content-end mt-2">
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Subtotal</span>
                                <span>{{ moneda }} {{ Number(cotizacion.subtotal).toFixed(2) }}</span>
                            </div>
                            <div v-if="cotizacion.discount_global > 0" class="d-flex justify-content-between small mb-1">
                                <span>Descuento</span>
                                <span>-{{ moneda }} {{ Number(cotizacion.discount_global).toFixed(2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold border-top pt-2">
                                <span>Total</span>
                                <span>{{ moneda }} {{ Number(cotizacion.total).toFixed(2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Cobrar anticipo — cliente y moneda fijos (los de la cotización),
             no editables acá: evita de raíz un anticipo con moneda/cliente
             distinto al de la cotización (mismo criterio que reservas). -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalAnticipo, 'd-block': mostrarModalAnticipo }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalAnticipo">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Cobrar anticipo</h6>
                        <button class="btn-close" @click="mostrarModalAnticipo = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            Genera su propio comprobante SUNAT a nombre de {{ cotizacion?.client?.full_name }}
                            ({{ moneda }}) — enviarlo a SUNAT se hace después, desde el módulo de Adelantos.
                        </p>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Monto recibido</label>
                            <input type="number" class="form-control form-control-sm" min="0.01" step="0.01"
                                v-model.number="formAnticipo.monto">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Medio de pago</label>
                            <select class="form-select form-select-sm" v-model="formAnticipo.medio_pago">
                                <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.code">{{ pm.name }}</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Tratamiento tributario</label>
                            <select class="form-select form-select-sm" v-model="formAnticipo.tip_afe_igv">
                                <option value="10">Gravado (IGV 18%)</option>
                                <option value="20">Exonerado</option>
                                <option value="30">Inafecto</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-semibold">Notas (opcional)</label>
                            <textarea class="form-control form-control-sm" rows="2" v-model="formAnticipo.notas"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalAnticipo = false">Cerrar</button>
                        <button class="btn btn-primary btn-sm"
                            :disabled="guardandoAnticipo || formAnticipo.monto <= 0 || !formAnticipo.medio_pago"
                            @click="guardarAnticipo">
                            <span v-if="guardandoAnticipo" class="spinner-border spinner-border-sm me-1"></span>Registrar anticipo
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import Swal from "sweetalert2/dist/sweetalert2.js";
import httpClient from "@/helpers/http-client";
import { formatFecha, formatFechaHora } from "@/helpers/fecha";
import type { CommercialQuoteAnticipo, CommercialQuoteDetalle, CommercialQuoteStatus } from "@/types/commercial-quotes";
import type { PaymentMethod, PaymentMethods } from "@/types/cash";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();

const cotizacion = ref<CommercialQuoteDetalle | null>(null);
const loading = ref(false);
const cambiandoEstado = ref(false);

const moneda = computed(() => (cotizacion.value?.currency === "USD" ? "US$" : "S/"));

const puedeEditar = computed(() =>
    !!cotizacion.value &&
    !cotizacion.value.converted_sale_id &&
    ["borrador", "enviada"].includes(cotizacion.value.status)
);

const puedeAnular = computed(() =>
    !!cotizacion.value &&
    !cotizacion.value.converted_sale_id &&
    !["anulada"].includes(cotizacion.value.status)
);

const puedeConvertir = computed(() =>
    !!cotizacion.value &&
    !cotizacion.value.converted_sale_id &&
    !["anulada", "rechazada", "vencida"].includes(cotizacion.value.status)
);

// Cobrar anticipo requiere además un cliente registrado — el Advance exige
// un Client real para su propio comprobante SUNAT (ver
// CommercialQuoteAnticipoController::store()).
const puedeCobrarAnticipo = computed(() =>
    puedeConvertir.value && !!cotizacion.value?.client?.id
);

const totalAnticiposDisponibles = computed(() =>
    (cotizacion.value?.anticipos ?? []).reduce((s, a) => s + a.disponible, 0)
);

const statusLabels: Record<CommercialQuoteStatus, string> = {
    borrador: "Borrador",
    enviada: "Enviada",
    aceptada: "Aceptada",
    rechazada: "Rechazada",
    vencida: "Vencida",
    anulada: "Anulada",
};
function statusLabel(s: CommercialQuoteStatus): string {
    return statusLabels[s] ?? s;
}

function badgeClass(s: CommercialQuoteStatus): string {
    switch (s) {
        case "aceptada": return "bg-success";
        case "rechazada":
        case "anulada": return "bg-secondary";
        case "vencida": return "bg-danger";
        case "enviada": return "bg-info text-dark";
        default: return "bg-warning text-dark";
    }
}

async function cargar() {
    loading.value = true;
    try {
        const { data } = await httpClient.get(`commercial-quotes/${route.params.id}`);
        cotizacion.value = data.commercial_quote;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar la cotización.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

async function cambiarEstado(nuevo: CommercialQuoteStatus) {
    if (!cotizacion.value) return;
    cambiandoEstado.value = true;
    try {
        await httpClient.put(`commercial-quotes/${cotizacion.value.id}`, { status: nuevo });
        await cargar();
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cambiar el estado.",
            "error"
        );
    } finally {
        cambiandoEstado.value = false;
    }
}

async function verPdf() {
    if (!cotizacion.value) return;
    try {
        const { data } = await httpClient.get(`commercial-quotes-pdf-url/${cotizacion.value.id}`);
        window.open(data.url, "_blank");
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo generar el PDF.",
            "error"
        );
    }
}

function convertirEnVenta() {
    if (!cotizacion.value) return;
    router.push({ name: "sale.register", query: { from_quote: String(cotizacion.value.id) } });
}

// ── Anticipos — mismo patrón que reservas/detalle.vue ────────────────
const mostrarModalAnticipo = ref(false);
const guardandoAnticipo = ref(false);
const paymentMethods = ref<PaymentMethod[]>([]);
const formAnticipo = ref<{ monto: number; medio_pago: string; tip_afe_igv: "10" | "20" | "30"; notas: string }>({
    monto: 0, medio_pago: "", tip_afe_igv: "10", notas: "",
});

async function cargarPaymentMethods() {
    try {
        const res: { data: PaymentMethods } = await httpClient.get("payment-methods?active=1");
        paymentMethods.value = res.data.payment_methods;
    } catch {
        // Silencioso — un fallo acá no debe bloquear la carga de la cotización.
    }
}

function abrirModalAnticipo() {
    formAnticipo.value = { monto: 0, medio_pago: paymentMethods.value[0]?.code ?? "", tip_afe_igv: "10", notas: "" };
    mostrarModalAnticipo.value = true;
}

async function guardarAnticipo() {
    if (!cotizacion.value) return;
    guardandoAnticipo.value = true;
    try {
        const { data } = await httpClient.post(`commercial-quotes/${cotizacion.value.id}/anticipos`, {
            monto: formAnticipo.value.monto,
            medio_pago: formAnticipo.value.medio_pago,
            tip_afe_igv: formAnticipo.value.tip_afe_igv,
            notas: formAnticipo.value.notas || null,
        });
        await (Swal as TVueSwalInstance).fire("¡Listo!", data.message, "success");
        mostrarModalAnticipo.value = false;
        await cargar();
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo registrar el anticipo.",
            "error"
        );
    } finally {
        guardandoAnticipo.value = false;
    }
}

async function quitarAnticipo(anticipo: CommercialQuoteAnticipo) {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        icon: "warning",
        title: "¿Quitar este anticipo?",
        text: "El dinero no se toca, solo deja de estar asociado a esta cotización.",
        showCancelButton: true,
        confirmButtonText: "Quitar",
        cancelButtonText: "Cancelar",
    });
    if (!confirmacion.isConfirmed) return;

    try {
        const { data } = await httpClient.delete(`commercial-quote-anticipos/${anticipo.id}`);
        await (Swal as TVueSwalInstance).fire("¡Listo!", data.message, "success");
        await cargar();
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo quitar el anticipo.",
            "error"
        );
    }
}

onMounted(() => {
    cargar();
    cargarPaymentMethods();
});
</script>
