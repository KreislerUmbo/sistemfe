<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice me-2 text-primary"></i>
                    Notas de Crédito / Débito
                </h5>
                <small class="text-muted">{{ totalRows }} registro(s) encontrado(s)</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body d-flex gap-3 flex-wrap">
                <div>
                    <label class="form-label small mb-1">Estado</label>
                    <select class="form-select form-select-sm" v-model="filtroStatus" @change="reset">
                        <option value="">Todos</option>
                        <option value="pendiente">Pendiente</option>
                        <option value="enviando">Enviando</option>
                        <option value="aceptado">Aceptado</option>
                        <option value="rechazado">Rechazado</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-1">Tipo</label>
                    <select class="form-select form-select-sm" v-model="filtroTipoDoc" @change="reset">
                        <option value="">Todos</option>
                        <option value="07">Nota de Crédito</option>
                        <option value="08">Nota de Débito</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tipo</th>
                            <th>N° operación</th>
                            <th>Venta afectada</th>
                            <th>Cliente</th>
                            <th>Motivo</th>
                            <th class="text-end">Total</th>
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
                        <tr v-else-if="notas.length === 0">
                            <td colspan="8" class="text-center text-muted py-4">Sin notas registradas.</td>
                        </tr>
                        <tr v-for="n in notas" :key="n.id">
                            <td>{{ n.tipo_doc === '07' ? 'NC' : 'ND' }}</td>
                            <td>{{ n.n_operacion ?? (n.serie + '-pendiente') }}</td>
                            <td>
                                <router-link v-if="n.sale_id" :to="{ name: 'sale.nota', params: { id: n.sale_id } }">
                                    {{ n.sale?.serie }}-{{ String(n.sale?.correlativo).padStart(8, '0') }}
                                </router-link>
                            </td>
                            <td>{{ n.client?.full_name }}</td>
                            <td>{{ n.cod_motivo }} - {{ n.des_motivo }}</td>
                            <td class="text-end">{{ n.currency === 'USD' ? 'US$' : 'S/' }} {{ Number(n.mto_imp_venta).toFixed(2) }}</td>
                            <td>
                                <span class="badge" :class="{
                                    'bg-success': n.status === 'aceptado',
                                    'bg-danger': n.status === 'rechazado',
                                    'bg-secondary': n.status === 'pendiente' || n.status === 'enviando',
                                }">{{ n.status }}</span>
                                <div v-if="n.status === 'rechazado' && n.sunat_error_message" class="text-danger small">
                                    {{ n.sunat_error_message }}
                                </div>
                            </td>
                            <td class="text-center">
                                <button v-if="n.status === 'aceptado'" type="button"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2" @click="imprimirNota(n.id)"
                                    title="Imprimir">
                                    <i class="fas fa-print"></i>
                                </button>
                                <button v-else-if="n.status === 'pendiente'" type="button"
                                    class="btn btn-sm btn-primary py-0 px-2" :disabled="enviando === n.id"
                                    @click="enviar(n)" title="Enviar a SUNAT">
                                    <span v-if="enviando === n.id" class="spinner-border spinner-border-sm"></span>
                                    <span v-else>Enviar</span>
                                </button>
                                <router-link v-else-if="n.status === 'rechazado' && n.sale_id"
                                    :to="{ name: 'sale.nota', params: { id: n.sale_id } }"
                                    class="btn btn-sm btn-outline-danger py-0 px-2">
                                    Corregir
                                </router-link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <small class="text-muted">Mostrando {{ notas.length }} de {{ totalRows }} registro(s)</small>
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
import { imprimirNota } from "@/composables/usePrintComprobante";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

type NotaListItem = {
    id: number;
    sale_id: number;
    tipo_doc: "07" | "08";
    serie: string;
    correlativo?: number | null;
    n_operacion?: string | null;
    cod_motivo: string;
    des_motivo: string;
    status: "pendiente" | "enviando" | "aceptado" | "rechazado";
    sunat_error_message?: string | null;
    currency: string;
    mto_imp_venta: number;
    sale?: { serie: string; correlativo: number };
    client?: { full_name: string };
};

const notas = ref<NotaListItem[]>([]);
const loading = ref(false);
const currentPage = ref(1);
const totalRows = ref(0);
const enviando = ref<number | null>(null);

const filtroStatus = ref("");
const filtroTipoDoc = ref("");

async function cargar() {
    loading.value = true;
    try {
        const { data } = await httpClient.get("notas", {
            params: {
                page: currentPage.value,
                status: filtroStatus.value || undefined,
                tipo_doc: filtroTipoDoc.value || undefined,
            },
        });
        notas.value = data.notes;
        totalRows.value = data.total;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar el listado de notas.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

function reset() {
    currentPage.value = 1;
    cargar();
}

async function enviar(n: NotaListItem) {
    enviando.value = n.id;
    try {
        const { data } = await httpClient.post("notas/enviar-sunat", { note_id: n.id });

        if (data.response?.error) {
            (Swal as TVueSwalInstance).fire(
                "Rechazado por SUNAT",
                data.response.error.message ?? "La nota fue rechazada.",
                "error"
            );
        } else {
            (Swal as TVueSwalInstance).fire("¡Enviado a SUNAT!", "La nota fue aceptada.", "success");
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "Error inesperado al enviar la nota.",
            "error"
        );
    } finally {
        enviando.value = null;
        await cargar();
    }
}

watch(currentPage, () => cargar());

onMounted(() => cargar());
</script>
