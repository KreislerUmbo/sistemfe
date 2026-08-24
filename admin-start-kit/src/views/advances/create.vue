<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-hand-holding-usd me-2 text-primary"></i>
                    Nuevo adelanto
                </h5>
                <small class="text-muted">
                    Registra un pago anticipado de cliente — genera su propio comprobante SUNAT.
                </small>
            </div>
            <router-link :to="{ name: 'advances.index' }" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <!-- Cliente -->
                <div class="mb-3 position-relative">
                    <label class="form-label fw-semibold">Cliente</label>
                    <div class="input-group">
                        <input type="text" class="form-control" v-model="clientSearchText"
                            placeholder="Buscar por DNI, RUC o nombre..." @input="onClientSearchInput"
                            :disabled="!!clientSelected" autocomplete="off" />
                        <button v-if="clientSelected" class="btn btn-outline-danger" type="button"
                            @click="clearClient">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div v-if="clientSuggestions.length > 0 && !clientSelected" class="list-group mt-1 position-absolute"
                        style="max-height:220px;overflow-y:auto;z-index:1050;width:100%;box-shadow:0 4px 8px rgba(0,0,0,.1)">
                        <button type="button" class="list-group-item list-group-item-action"
                            v-for="c in clientSuggestions" :key="c.id" @mousedown.prevent="selectClient(c)">
                            <div class="d-flex justify-content-between">
                                <span>{{ c.full_name }}</span>
                                <small class="text-muted">{{ c.n_document }}</small>
                            </div>
                        </button>
                    </div>
                    <small v-if="clientSelected" class="text-muted">
                        {{ clientSelected.cod_tipo_doc_sunat === '6' ? 'RUC → se emitirá Factura' : 'Se emitirá Boleta' }}
                    </small>
                </div>

                <!-- Monto / Moneda / Medio de pago -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Monto recibido</label>
                        <input type="number" class="form-control" min="0.01" step="0.01" v-model.number="amount">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label fw-semibold">Moneda</label>
                        <select class="form-select" v-model="currency">
                            <option value="PEN">Soles (PEN)</option>
                            <option value="USD">Dólares (USD)</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label fw-semibold">Medio de pago</label>
                        <select class="form-select" v-model="paymentMethod">
                            <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.code">{{ pm.name }}</option>
                        </select>
                    </div>
                </div>

                <!-- Tier 1 (2026-08-24): antes salía gravado 18% siempre, sin
                     mostrarlo — un adelanto de cliente Amazonía o de un
                     servicio exonerado no tenía forma de salir bien
                     clasificado. -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Tratamiento tributario</label>
                    <select class="form-select" v-model="tipAfeIgv">
                        <option value="10">Gravado (IGV 18%)</option>
                        <option value="20">Exonerado</option>
                        <option value="30">Inafecto</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Notas (opcional)</label>
                    <textarea class="form-control" rows="2" v-model="notes"></textarea>
                </div>

                <button type="button" class="btn btn-primary" :disabled="!puedeCrear || loading" @click="crear">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                    Registrar adelanto
                </button>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import { computed, ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import Swal from "sweetalert2/dist/sweetalert2.js";
import httpClient from "@/helpers/http-client";
import type { AxiosResponse } from "axios";
import type { Client } from "@/types/clients";
import type { AdvanceResponse } from "@/types/advances";
import type { PaymentMethod, PaymentMethods } from "@/types/cash";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const router = useRouter();

const clientSearchText = ref("");
const clientSuggestions = ref<Client[]>([]);
const clientSelected = ref<Client | undefined>(undefined);
let clientSearchTimeout: ReturnType<typeof setTimeout> | undefined;

const amount = ref<number>(0);
const currency = ref<string>("PEN");
// Módulo Caja — Fase 6: antes hardcodeado ("TARJETA", "YAPE" fusionado con
// Plin) — ahora consume el mismo catálogo que register.vue desde Fase 0
// (payment-methods?active=1), mismos code exactos que payment_methods.
const paymentMethods = ref<PaymentMethod[]>([]);
const paymentMethod = ref<string>("");
// Tier 1 (2026-08-24): gravado por default — mismo comportamiento de hoy
// si el usuario no lo toca.
const tipAfeIgv = ref<"10" | "20" | "30">("10");
const notes = ref<string>("");
const loading = ref(false);

const loadPaymentMethods = async () => {
    try {
        const res: AxiosResponse<PaymentMethods> = await httpClient.get("payment-methods?active=1");
        paymentMethods.value = res.data.payment_methods;
        if (!paymentMethod.value && paymentMethods.value.length > 0) {
            paymentMethod.value = paymentMethods.value[0].code;
        }
    } catch (error) {
        console.error(error);
    }
};

onMounted(() => {
    loadPaymentMethods();
});

const onClientSearchInput = () => {
    clearTimeout(clientSearchTimeout);
    const query = clientSearchText.value.trim();
    if (query.length < 2) {
        clientSuggestions.value = [];
        return;
    }
    clientSearchTimeout = setTimeout(async () => {
        try {
            const { data } = await httpClient.get(`clients?search=${encodeURIComponent(query)}&take=10`);
            clientSuggestions.value = data.clients.data;
        } catch {
            clientSuggestions.value = [];
        }
    }, 300);
};

const selectClient = (c: Client) => {
    clientSelected.value = c;
    clientSearchText.value = c.full_name;
    clientSuggestions.value = [];
};

const clearClient = () => {
    clientSelected.value = undefined;
    clientSearchText.value = "";
};

const puedeCrear = computed(() => !!clientSelected.value && amount.value > 0 && !!paymentMethod.value);

const crear = async () => {
    if (!clientSelected.value) return;
    loading.value = true;
    try {
        const { data }: { data: AdvanceResponse } = await httpClient.post("advances", {
            client_id: clientSelected.value.id,
            amount: amount.value,
            currency: currency.value,
            payment_method: paymentMethod.value,
            tip_afe_igv: tipAfeIgv.value,
            notes: notes.value || null,
        });

        await (Swal as TVueSwalInstance).fire("¡Listo!", data.message, "success");
        router.push({ name: "advances.show", params: { id: data.advance_id } });
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo crear el adelanto.",
            "error"
        );
    } finally {
        loading.value = false;
    }
};
</script>
