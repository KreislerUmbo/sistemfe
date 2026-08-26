<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice me-2 text-primary"></i>
                    Cotizaciones Comerciales
                </h5>
                <small class="text-muted">{{ totalRows }} registro(s) encontrado(s)</small>
            </div>
            <router-link :to="{ name: 'commercial-quotes.create' }" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i> Nueva cotización
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1">Buscar</label>
                        <input type="text" class="form-control form-control-sm" v-model="filtroBusqueda"
                            placeholder="Código o cliente..." @input="onFiltroInput">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Estado</label>
                        <select class="form-select form-select-sm" v-model="filtroStatus" @change="aplicarFiltros">
                            <option value="">Todos</option>
                            <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="filtroFechaDesde" @change="aplicarFiltros">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="filtroFechaHasta" @change="aplicarFiltros">
                    </div>
                    <div class="col-6 col-md-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" @click="limpiarFiltros">Limpiar</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Cliente</th>
                            <th>Estado</th>
                            <th class="text-end">Total</th>
                            <th>Válida hasta</th>
                            <th>Venta</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="7" class="text-center py-4">
                                <span class="spinner-border text-primary"></span>
                            </td>
                        </tr>
                        <tr v-else-if="cotizaciones.length === 0">
                            <td colspan="7" class="text-center text-muted py-4">Sin cotizaciones registradas.</td>
                        </tr>
                        <tr v-for="q in cotizaciones" :key="q.id">
                            <td>
                                <router-link :to="{ name: 'commercial-quotes.show', params: { id: q.id } }">
                                    {{ q.code }}
                                </router-link>
                            </td>
                            <td>{{ q.client?.full_name ?? q.client_name_free ?? '-' }}</td>
                            <td><span class="badge" :class="badgeClass(q.status)">{{ statusLabel(q.status) }}</span></td>
                            <td class="text-end">{{ moneda(q.currency) }} {{ Number(q.total).toFixed(2) }}</td>
                            <td>{{ q.valid_until ?? '-' }}</td>
                            <td>
                                <span v-if="q.converted_sale_id" class="badge bg-success">Venta #{{ q.converted_sale_id }}</span>
                                <span v-else class="text-muted small">—</span>
                            </td>
                            <td class="text-center">
                                <router-link :to="{ name: 'commercial-quotes.show', params: { id: q.id } }"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </router-link>
                                <router-link v-if="!q.converted_sale_id && ['borrador','enviada'].includes(q.status)"
                                    :to="{ name: 'commercial-quotes.edit', params: { id: q.id } }"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1" title="Editar">
                                    <i class="fas fa-pen"></i>
                                </router-link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <small class="text-muted">Mostrando {{ cotizaciones.length }} de {{ totalRows }} registro(s)</small>
                <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="25"
                    prev-text="Anterior" next-text="Siguiente" class="mb-0" />
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import { onMounted, ref, watch } from "vue";
import Swal from "sweetalert2/dist/sweetalert2.js";
import httpClient from "@/helpers/http-client";
import type { CommercialQuoteResumen, CommercialQuoteStatus, CommercialQuotesResponse } from "@/types/commercial-quotes";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const cotizaciones = ref<CommercialQuoteResumen[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const totalRows = ref(0);

const filtroBusqueda = ref("");
const filtroStatus = ref("");
const filtroFechaDesde = ref("");
const filtroFechaHasta = ref("");
let filtroBusquedaTimeout: ReturnType<typeof setTimeout> | undefined;

function onFiltroInput() {
    clearTimeout(filtroBusquedaTimeout);
    filtroBusquedaTimeout = setTimeout(() => aplicarFiltros(), 300);
}

function aplicarFiltros() {
    currentPage.value = 1;
    cargar();
}

function limpiarFiltros() {
    filtroBusqueda.value = "";
    filtroStatus.value = "";
    filtroFechaDesde.value = "";
    filtroFechaHasta.value = "";
    aplicarFiltros();
}

async function cargar() {
    loading.value = true;
    try {
        const { data }: { data: CommercialQuotesResponse } = await httpClient.get("commercial-quotes", {
            params: {
                page: currentPage.value,
                search: filtroBusqueda.value || undefined,
                status: filtroStatus.value || undefined,
                start_date: filtroFechaDesde.value || undefined,
                end_date: filtroFechaHasta.value || undefined,
            },
        });
        cotizaciones.value = data.commercial_quotes;
        totalRows.value = data.total;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar el listado de cotizaciones.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

function moneda(currency: string): string {
    return currency === "USD" ? "US$" : "S/";
}

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

watch(currentPage, () => cargar());
onMounted(() => cargar());
</script>
