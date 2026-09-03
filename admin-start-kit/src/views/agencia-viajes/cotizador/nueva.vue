<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-route me-2 text-primary"></i>
                    Nueva cotización
                </h5>
                <small class="text-muted">Paso 0 — se completa una sola vez, no por alternativa</small>
            </div>
            <router-link to="/agencia-viajes/cotizador" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">1</span>
                <span class="fw-semibold text-dark">Cliente, destino y fecha</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-12 col-md-6 position-relative">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Cliente</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" v-model="clientSearchText"
                                placeholder="Buscar por DNI, RUC o nombre..." @input="onClientSearchInput"
                                @focus="showClientSuggestions = true" @blur="onClientSearchBlur" autocomplete="off">
                            <button v-if="clienteSeleccionado" class="btn btn-outline-primary" type="button" title="Editar datos del cliente" @click="abrirEdicionCliente">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button v-if="clienteSeleccionado" class="btn btn-outline-danger" type="button" @click="limpiarCliente">
                                <i class="fas fa-times"></i>
                            </button>
                            <button class="btn btn-outline-primary" type="button" @click="abrirNuevoCliente">
                                <i class="fas fa-user-plus"></i>
                            </button>
                        </div>
                        <div v-if="showClientSuggestions && clientSuggestions.length > 0 && !clienteSeleccionado"
                            class="list-group mt-1 position-absolute"
                            style="max-height:220px;overflow-y:auto;z-index:1050;width:calc(100% - 2px);box-shadow:0 4px 8px rgba(0,0,0,.1)">
                            <button type="button" class="list-group-item list-group-item-action" v-for="c in clientSuggestions" :key="c.id"
                                @mousedown.prevent="seleccionarCliente(c)">
                                <div class="d-flex justify-content-between">
                                    <span>{{ c.full_name }}</span>
                                    <small class="text-muted">{{ c.n_document }}</small>
                                </div>
                            </button>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                        <DestinoTreeSelect v-model="destinoId" @update:label="destinoTexto = $event" />
                    </div>

                    <div class="col-12 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="fechaViajeDesde" :disabled="sinFechaExacta">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="fechaViajeHasta" :disabled="sinFechaExacta">
                    </div>
                    <div class="col-12 col-md-8 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="sin-fecha-exacta" v-model="sinFechaExacta">
                            <label class="form-check-label small text-muted" for="sin-fecha-exacta">Todavía no tiene fecha exacta</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">2</span>
                <span class="fw-semibold text-dark">Pasajeros</span>
                <small class="text-muted">(la edad decide el precio adulto/niño/infante de cada proveedor)</small>
            </div>
            <div class="card-body py-3">
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(30)"><i class="fas fa-plus me-1"></i>Adulto</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(8)"><i class="fas fa-plus me-1"></i>Niño</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(1)"><i class="fas fa-plus me-1"></i>Infante</button>
                </div>
                <div v-if="pasajeros.length === 0" class="text-muted small fst-italic mb-2">Agregá al menos un pasajero.</div>
                <div class="d-flex flex-column gap-2">
                    <div v-for="(pax, idx) in pasajeros" :key="idx" class="d-flex align-items-center gap-2 border rounded p-2 small">
                        <span class="badge" :class="tipoPorEdad(pax.edad).clase" style="min-width:70px">{{ tipoPorEdad(pax.edad).label }}</span>
                        <label class="text-muted mb-0">Edad</label>
                        <input type="number" class="form-control form-control-sm" style="max-width:80px" v-model.number="pax.edad" min="0" max="99">
                        <i class="fas fa-times text-danger ms-auto" style="cursor:pointer" @click="pasajeros.splice(idx, 1)"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <button class="btn btn-primary fw-semibold" @click="crear" :disabled="creando">
                <span v-if="creando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-check me-2"></i>
                Crear cotización y empezar a armar
            </button>
        </div>

        <div class="modal fade" tabindex="-1" :class="{ show: showQuickClientModal, 'd-block': showQuickClientModal }"
            style="background:rgba(0,0,0,.5)" v-if="showQuickClientModal">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ modoEdicionCliente ? 'Editar Cliente' : 'Registrar Cliente Rápido' }}</h6>
                        <button class="btn-close" @click="showQuickClientModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <ClientFormQuick :initial-data="modoEdicionCliente ? clienteSeleccionado : null"
                            :cliente-id="modoEdicionCliente ? clienteSeleccionado?.id ?? null : null"
                            @saved="onClientCreated" @cancel="showQuickClientModal = false" />
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { cotizacionService } from '@/services/admin/cotizacionService';
import type { Client } from '@/types/clients';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const router = useRouter();

const clientSearchText = ref<string>('');
const clientSuggestions = ref<Client[]>([]);
const clienteSeleccionado = ref<Client | null>(null);
const showClientSuggestions = ref<boolean>(false);
const showQuickClientModal = ref<boolean>(false);
const modoEdicionCliente = ref<boolean>(false);
let clientSearchTimeout: any = null;

const destinoId = ref<number | null>(null);
const destinoTexto = ref<string>('');

const fechaViajeDesde = ref<string>('');
const fechaViajeHasta = ref<string>('');
const sinFechaExacta = ref<boolean>(false);

const pasajeros = ref<Array<{ edad: number }>>([]);
const creando = ref<boolean>(false);

const onClientSearchInput = () => {
    clearTimeout(clientSearchTimeout);
    clientSearchTimeout = setTimeout(buscarClientes, 300);
};

const buscarClientes = async () => {
    if (clientSearchText.value.trim().length < 2) {
        clientSuggestions.value = [];
        return;
    }
    try {
        const res = await httpClient.get('/clients', { params: { search: clientSearchText.value } });
        // ClientController::index() envuelve el listado en ClientCollection
        // ({ data: [...] }) — clients.data, no clients directo (mismo acceso
        // que ya usa sale/register.vue).
        clientSuggestions.value = res.data.clients?.data ?? [];
    } catch (error) {
        console.log(error);
    }
};

const seleccionarCliente = (c: Client) => {
    clienteSeleccionado.value = c;
    clientSearchText.value = c.full_name;
    showClientSuggestions.value = false;
};

const limpiarCliente = () => {
    clienteSeleccionado.value = null;
    clientSearchText.value = '';
};

const abrirNuevoCliente = () => {
    modoEdicionCliente.value = false;
    showQuickClientModal.value = true;
};

const abrirEdicionCliente = () => {
    if (!clienteSeleccionado.value) return;
    modoEdicionCliente.value = true;
    showQuickClientModal.value = true;
};

const onClientSearchBlur = () => {
    setTimeout(() => { showClientSuggestions.value = false; }, 150);
};

const onClientCreated = (client: Client) => {
    seleccionarCliente(client);
    showQuickClientModal.value = false;
};

const tipoPorEdad = (edad: number) => {
    if (edad <= 2) return { label: 'Infante', clase: 'bg-info-subtle text-info' };
    if (edad <= 12) return { label: 'Niño', clase: 'bg-warning-subtle text-warning' };
    return { label: 'Adulto', clase: 'bg-success-subtle text-success' };
};

const agregarPax = (edadSugerida: number) => {
    pasajeros.value.push({ edad: edadSugerida });
};

const crear = async () => {
    if (!clienteSeleccionado.value) {
        (Swal as TVueSwalInstance).fire('Error', 'Seleccioná un cliente.', 'error');
        return;
    }
    if (!destinoTexto.value.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'Seleccioná un destino.', 'error');
        return;
    }
    if (pasajeros.value.length === 0) {
        (Swal as TVueSwalInstance).fire('Error', 'Agregá al menos un pasajero.', 'error');
        return;
    }

    creando.value = true;
    try {
        const res = await cotizacionService.crear({
            cliente_id: clienteSeleccionado.value.id,
            destino: destinoTexto.value,
            fecha_viaje_desde: sinFechaExacta.value ? null : (fechaViajeDesde.value || null),
            fecha_viaje_hasta: sinFechaExacta.value ? null : (fechaViajeHasta.value || null),
            pasajeros: pasajeros.value,
        });

        router.push(`/agencia-viajes/cotizador/${res.cotizacion.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear la cotización', 'error');
    } finally {
        creando.value = false;
    }
};
</script>
