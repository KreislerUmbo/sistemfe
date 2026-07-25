<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-hand-holding-usd me-2 text-primary"></i>
                    Adelantos de clientes
                </h5>
                <small class="text-muted">{{ totalRows }} registro(s) encontrado(s)</small>
            </div>
            <router-link :to="{ name: 'advances.create' }" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i> Nuevo adelanto
            </router-link>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Cliente</th>
                            <th>Comprobante</th>
                            <th class="text-end">Monto</th>
                            <th class="text-end">Aplicado</th>
                            <th class="text-end">Reembolsado</th>
                            <th class="text-end">Disponible</th>
                            <th>Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="8" class="text-center py-4">
                                <span class="spinner-border text-primary"></span>
                            </td>
                        </tr>
                        <tr v-else-if="advances.length === 0">
                            <td colspan="8" class="text-center text-muted py-4">Sin adelantos registrados.</td>
                        </tr>
                        <tr v-for="a in advances" :key="a.id">
                            <td>{{ a.client?.full_name }}</td>
                            <td>
                                <router-link :to="{ name: 'advances.show', params: { id: a.id } }">
                                    {{ a.sale?.n_operacion ?? ((a.sale?.serie ?? '') + '-pendiente') }}
                                </router-link>
                            </td>
                            <td class="text-end">{{ moneda(a) }} {{ Number(a.amount).toFixed(2) }}</td>
                            <td class="text-end">{{ moneda(a) }} {{ Number(a.applied_amount).toFixed(2) }}</td>
                            <td class="text-end">{{ moneda(a) }} {{ Number(a.refunded_amount).toFixed(2) }}</td>
                            <td class="text-end fw-semibold">{{ moneda(a) }} {{ disponible(a).toFixed(2) }}</td>
                            <td>
                                <span class="badge" :class="badgeClass(a.status)">{{ statusLabel(a.status) }}</span>
                            </td>
                            <td class="text-center">
                                <router-link :to="{ name: 'advances.show', params: { id: a.id } }"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </router-link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <small class="text-muted">Mostrando {{ advances.length }} de {{ totalRows }} registro(s)</small>
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
import type { Advance, AdvanceStatus, Advances } from "@/types/advances";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const advances = ref<Advance[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const totalRows = ref(0);

async function cargar() {
    loading.value = true;
    try {
        const { data }: { data: Advances } = await httpClient.get("advances", {
            params: { page: currentPage.value },
        });
        advances.value = data.advances;
        totalRows.value = data.total;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar el listado de adelantos.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

function disponible(a: Advance): number {
    return Number(a.amount) - Number(a.applied_amount) - Number(a.refunded_amount);
}

function moneda(a: Advance): string {
    return a.currency === "USD" ? "US$" : "S/";
}

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

watch(currentPage, () => cargar());
onMounted(() => cargar());
</script>
