<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice me-2 text-primary"></i>
                    {{ esEdicion ? 'Editar Cotización' : 'Nueva Cotización Comercial' }}
                </h5>
                <small class="text-muted">
                    Presupuesto sin efecto fiscal ni de stock — no descuenta inventario ni genera comprobante.
                </small>
            </div>
            <router-link :to="{ name: 'commercial-quotes.index' }" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <!-- Cliente -->
                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <label class="form-label fw-semibold mb-1">Cliente</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="clienteLibreSwitch"
                                v-model="clienteLibre" @change="clearClient">
                            <label class="form-check-label small" for="clienteLibreSwitch">Prospecto sin registrar</label>
                        </div>
                    </div>

                    <template v-if="!clienteLibre">
                        <div class="position-relative">
                            <div class="input-group">
                                <input type="text" class="form-control" v-model="clientSearchText"
                                    placeholder="Buscar por DNI, RUC o nombre..." @input="onClientSearchInput"
                                    :disabled="!!clientSelected" autocomplete="off" />
                                <button v-if="clientSelected" class="btn btn-outline-danger" type="button"
                                    @click="clearClient">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div v-if="clientSuggestions.length > 0 && !clientSelected"
                                class="list-group mt-1 position-absolute"
                                style="max-height:220px;overflow-y:auto;z-index:1050;width:100%;box-shadow:0 4px 8px rgba(0,0,0,.1)">
                                <button type="button" class="list-group-item list-group-item-action"
                                    v-for="c in clientSuggestions" :key="c.id" @mousedown.prevent="selectClient(c)">
                                    <div class="d-flex justify-content-between">
                                        <span>{{ c.full_name }}</span>
                                        <small class="text-muted">{{ c.n_document }}</small>
                                    </div>
                                </button>
                            </div>
                            <div v-if="clientSelected" class="border rounded px-2 py-2 mt-1 small">
                                <strong>{{ clientSelected.full_name }}</strong>
                                <span class="text-muted"> — {{ clientSelected.n_document }}</span>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" class="form-control" v-model="clientNameFree"
                                    placeholder="Nombre del prospecto">
                            </div>
                            <div class="col-md-6">
                                <input type="text" class="form-control" v-model="clientPhoneFree"
                                    placeholder="Teléfono (opcional)">
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Moneda / Vigencia -->
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Moneda</label>
                        <select class="form-select" v-model="currency">
                            <option value="PEN">Soles (PEN)</option>
                            <option value="USD">Dólares (USD)</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Válida hasta</label>
                        <input type="date" class="form-control" v-model="validUntil">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Observación</label>
                        <input type="text" class="form-control" v-model="observacion" placeholder="Opcional">
                    </div>
                </div>

                <!-- Productos -->
                <label class="form-label fw-semibold">Ítems</label>
                <div class="position-relative mb-2">
                    <input type="text" class="form-control" v-model="productSearchText"
                        placeholder="Buscar producto por nombre o SKU..." @input="onProductSearchInput" autocomplete="off">
                    <div v-if="productSuggestions.length > 0" class="list-group mt-1 position-absolute"
                        style="max-height:220px;overflow-y:auto;z-index:1050;width:100%;box-shadow:0 4px 8px rgba(0,0,0,.1)">
                        <button type="button" class="list-group-item list-group-item-action"
                            v-for="p in productSuggestions" :key="p.id" @mousedown.prevent="agregarProducto(p)">
                            <div class="d-flex justify-content-between">
                                <span>{{ p.title }}</span>
                                <small class="text-muted">S/ {{ Number(p.price_general).toFixed(2) }} · stock {{ p.stock }}</small>
                            </div>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-2" @click="agregarItemLibre">
                    <i class="fas fa-plus me-1"></i> Agregar línea libre (sin producto)
                </button>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Descripción</th>
                                <th style="width:100px;">Cant.</th>
                                <th style="width:120px;">P. Unit.</th>
                                <th style="width:90px;">Desc. %</th>
                                <th style="width:120px;" class="text-end">Subtotal</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="items.length === 0">
                                <td colspan="6" class="text-center text-muted py-3">Sin ítems agregados.</td>
                            </tr>
                            <tr v-for="(item, idx) in items" :key="idx">
                                <td>
                                    <span v-if="item.product_id">{{ item.description }}</span>
                                    <input v-else type="text" class="form-control form-control-sm" v-model="item.description"
                                        placeholder="Descripción del ítem">
                                </td>
                                <td><input type="number" min="0.01" step="0.01" class="form-control form-control-sm" v-model.number="item.quantity"></td>
                                <td><input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="item.unit_price"></td>
                                <td><input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" v-model.number="item.discount_percent"></td>
                                <td class="text-end">{{ subtotalLinea(item).toFixed(2) }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" @click="items.splice(idx, 1)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Totales -->
                <div class="row justify-content-end">
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Subtotal</span>
                            <span>{{ moneda }} {{ subtotal.toFixed(2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between small align-items-center mb-1">
                            <span>Descuento global</span>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end" style="width:120px;"
                                v-model.number="discountGlobal">
                        </div>
                        <div class="d-flex justify-content-between fw-bold border-top pt-2">
                            <span>Total</span>
                            <span>{{ moneda }} {{ total.toFixed(2) }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="button" class="btn btn-primary" :disabled="!puedeGuardar || loading" @click="guardar">
                        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                        {{ esEdicion ? 'Guardar cambios' : 'Crear cotización' }}
                    </button>
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
import type { Client } from "@/types/clients";
import type { CommercialQuoteDetalle, CommercialQuoteFormPayload } from "@/types/commercial-quotes";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;
type ProductoSuggestion = { id: number; title: string; price_general: number; stock: number };
type ItemForm = {
    product_id: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    discount_percent: number;
};

const route = useRoute();
const router = useRouter();

const esEdicion = computed(() => !!route.params.id);
const cotizacionId = computed(() => route.params.id as string | undefined);
const loading = ref(false);

// ── Cliente ──────────────────────────────────────────────────────────
const clienteLibre = ref(false);
const clientSearchText = ref("");
const clientSuggestions = ref<Client[]>([]);
const clientSelected = ref<Client | undefined>(undefined);
const clientNameFree = ref("");
const clientPhoneFree = ref("");
let clientSearchTimeout: ReturnType<typeof setTimeout> | undefined;

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

// ── Moneda / vigencia / observación ─────────────────────────────────
const currency = ref("PEN");
const validUntil = ref("");
const observacion = ref("");
const discountGlobal = ref(0);
const moneda = computed(() => (currency.value === "USD" ? "US$" : "S/"));

// ── Productos / ítems ────────────────────────────────────────────────
const productSearchText = ref("");
const productSuggestions = ref<ProductoSuggestion[]>([]);
let productSearchTimeout: ReturnType<typeof setTimeout> | undefined;
const items = ref<ItemForm[]>([]);

const onProductSearchInput = () => {
    clearTimeout(productSearchTimeout);
    const query = productSearchText.value.trim();
    if (query.length < 2) {
        productSuggestions.value = [];
        return;
    }
    productSearchTimeout = setTimeout(async () => {
        try {
            const { data } = await httpClient.get(`products?search=${encodeURIComponent(query)}&take=10`);
            productSuggestions.value = data.products.data;
        } catch {
            productSuggestions.value = [];
        }
    }, 300);
};

const agregarProducto = (p: ProductoSuggestion) => {
    items.value.push({
        product_id: p.id,
        description: p.title,
        quantity: 1,
        unit_price: Number(p.price_general) || 0,
        discount_percent: 0,
    });
    productSearchText.value = "";
    productSuggestions.value = [];
};

const agregarItemLibre = () => {
    items.value.push({ product_id: null, description: "", quantity: 1, unit_price: 0, discount_percent: 0 });
};

const subtotalLinea = (item: ItemForm): number => {
    const bruto = (item.quantity || 0) * (item.unit_price || 0);
    return bruto - (bruto * (item.discount_percent || 0) / 100);
};

const subtotal = computed(() => items.value.reduce((s, it) => s + subtotalLinea(it), 0));
const total = computed(() => Math.max(0, subtotal.value - (discountGlobal.value || 0)));

const puedeGuardar = computed(() => {
    const tieneCliente = clienteLibre.value ? !!clientNameFree.value.trim() : !!clientSelected.value;
    const itemsValidos = items.value.length > 0 && items.value.every(
        (it) => it.quantity > 0 && it.unit_price >= 0 && (it.product_id || it.description.trim())
    );
    return tieneCliente && itemsValidos;
});

const guardar = async () => {
    loading.value = true;
    try {
        const payload: CommercialQuoteFormPayload = {
            client_id: clienteLibre.value ? null : (clientSelected.value?.id ?? null),
            client_name_free: clienteLibre.value ? (clientNameFree.value || null) : null,
            client_phone_free: clienteLibre.value ? (clientPhoneFree.value || null) : null,
            currency: currency.value,
            discount_global: discountGlobal.value || 0,
            valid_until: validUntil.value || null,
            observacion: observacion.value || null,
            items: items.value.map((it) => ({
                product_id: it.product_id,
                description: it.product_id ? undefined : it.description,
                quantity: it.quantity,
                unit_price: it.unit_price,
                discount_percent: it.discount_percent || 0,
            })),
        };

        if (esEdicion.value && cotizacionId.value) {
            await httpClient.put(`commercial-quotes/${cotizacionId.value}`, payload);
            await (Swal as TVueSwalInstance).fire("¡Listo!", "Cotización actualizada exitosamente", "success");
            router.push({ name: "commercial-quotes.show", params: { id: cotizacionId.value } });
        } else {
            const { data } = await httpClient.post("commercial-quotes", payload);
            await (Swal as TVueSwalInstance).fire("¡Listo!", data.message, "success");
            router.push({ name: "commercial-quotes.show", params: { id: data.commercial_quote_id } });
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo guardar la cotización.",
            "error"
        );
    } finally {
        loading.value = false;
    }
};

const cargarParaEditar = async () => {
    if (!cotizacionId.value) return;
    loading.value = true;
    try {
        const { data }: { data: { commercial_quote: CommercialQuoteDetalle } } =
            await httpClient.get(`commercial-quotes/${cotizacionId.value}`);
        const q = data.commercial_quote;

        if (q.client) {
            clienteLibre.value = false;
            clientSelected.value = q.client as unknown as Client;
            clientSearchText.value = q.client.full_name;
        } else {
            clienteLibre.value = true;
            clientNameFree.value = q.client_name_free ?? "";
            clientPhoneFree.value = q.client_phone_free ?? "";
        }

        currency.value = q.currency;
        validUntil.value = q.valid_until ?? "";
        observacion.value = q.observacion ?? "";
        discountGlobal.value = Number(q.discount_global) || 0;
        items.value = q.items.map((it) => ({
            product_id: it.product_id,
            description: it.description,
            quantity: Number(it.quantity),
            unit_price: Number(it.unit_price),
            discount_percent: Number(it.discount_percent) || 0,
        }));
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar la cotización.",
            "error"
        );
        router.push({ name: "commercial-quotes.index" });
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    if (esEdicion.value) cargarParaEditar();
});
</script>
