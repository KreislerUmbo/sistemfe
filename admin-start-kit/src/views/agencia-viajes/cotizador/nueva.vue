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
                        <div class="input-group input-group-sm" :class="{ 'has-validation': errores.cliente_id }">
                            <input type="text" class="form-control" :class="{ 'is-invalid': errores.cliente_id }" v-model="clientSearchText"
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
                            <div v-if="errores.cliente_id" class="invalid-feedback">{{ errores.cliente_id }}</div>
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
                        <div v-if="errores.destino" class="invalid-feedback d-block">{{ errores.destino }}</div>
                    </div>

                    <div class="col-12 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha desde</label>
                        <CampoFecha v-model="fechaViajeDesde" :disabled="sinFechaExacta" />
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha hasta</label>
                        <CampoFecha v-model="fechaViajeHasta" :disabled="sinFechaExacta" />
                        <div v-if="errores.fecha_viaje_hasta" class="invalid-feedback d-block">{{ errores.fecha_viaje_hasta }}</div>
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
                <div class="d-flex align-items-center gap-2 mb-3">
                    <input type="number" class="form-control form-control-sm" style="max-width:70px" v-model.number="cantidadAAgregar" min="1" max="30">
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(30, cantidadAAgregar)"><i class="fas fa-plus me-1"></i>Adulto(s)</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(8, cantidadAAgregar)"><i class="fas fa-plus me-1"></i>Niño(s)</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(1, cantidadAAgregar)"><i class="fas fa-plus me-1"></i>Infante(s)</button>
                </div>
                <div v-if="errores.pasajeros" class="invalid-feedback d-block mb-2">{{ errores.pasajeros }}</div>
                <div v-else-if="pasajeros.length === 0" class="text-muted small fst-italic mb-2">Agregá al menos un pasajero.</div>
                <div class="d-flex flex-column gap-2">
                    <div v-for="(pax, idx) in pasajeros" :key="idx" class="d-flex align-items-center gap-2 border rounded p-2 small">
                        <span class="badge" :class="tipoPorEdad(pax.edad).clase" style="min-width:70px">{{ tipoPorEdad(pax.edad).label }}</span>
                        <label class="text-muted mb-0">Edad</label>
                        <input type="number" class="form-control form-control-sm" style="max-width:80px" v-model.number="pax.edad" min="0" max="99">
                        <!-- Fila 13, fase 1b (§3.3): residencia, solo para el precio del
                             tour (un extranjero residente paga como nacional). -->
                        <select class="form-select form-select-sm" style="max-width:130px" v-model="pax.residencia" title="Residencia (precio del tour)">
                            <option value="nacional">Nacional</option>
                            <option value="extranjero">Extranjero</option>
                        </select>
                        <i class="fas fa-times text-danger ms-auto" style="cursor:pointer" @click="pasajeros.splice(idx, 1)"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- T-033: la primera alternativa nace con la cotización. El nombre es el título que ve
             el cliente; el tipo de cambio sale solo y se pide acá solo si no hay ninguno. -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">3</span>
                <span class="fw-semibold text-dark">Propuesta</span>
                <small class="text-muted">(después podés agregar otras alternativas)</small>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre</label>
                        <input type="text" class="form-control form-control-sm" :class="{ 'is-invalid': errores.alternativa_nombre }"
                            v-model="alternativaNombre" maxlength="100" placeholder="Alternativa A">
                        <div v-if="errores.alternativa_nombre" class="invalid-feedback">{{ errores.alternativa_nombre }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                        <select class="form-select form-select-sm" :class="{ 'is-invalid': errores.moneda_cotizacion }" v-model="monedaCotizacion">
                            <option value="PEN">Soles (PEN)</option>
                            <option value="USD">Dólares (USD)</option>
                        </select>
                        <div v-if="errores.moneda_cotizacion" class="invalid-feedback">{{ errores.moneda_cotizacion }}</div>
                    </div>
                    <div v-if="pedirTipoCambio" class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de cambio del día</label>
                        <input type="number" step="0.0001" min="0" class="form-control form-control-sm" :class="{ 'is-invalid': errores.tipo_cambio_valor }"
                            v-model.number="tipoCambioValor" placeholder="Ej. 3.75" @input="tipoCambioConfirmado = false">
                        <div v-if="errores.tipo_cambio_valor" class="invalid-feedback">{{ errores.tipo_cambio_valor }}</div>
                        <div v-if="tipoCambioRequiereConfirmacion" class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="tc-confirmado" v-model="tipoCambioConfirmado">
                            <label class="form-check-label small" for="tc-confirmado">El valor es correcto</label>
                        </div>
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
        <div v-if="errores.general" class="alert alert-danger py-2 small">{{ errores.general }}</div>

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
import CampoFecha from '@/components/CampoFecha.vue';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { cotizacionService } from '@/services/admin/cotizacionService';
import { configuracionAgenciaService } from '@/services/admin/configuracionAgenciaService';
import type { Client } from '@/types/clients';

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

const pasajeros = ref<Array<{ edad: number; residencia: 'nacional' | 'extranjero' }>>([]);
const creando = ref<boolean>(false);
const cantidadAAgregar = ref<number>(1);

// T-033 — primera alternativa ("Propuesta") y errores junto a cada campo (R7).
const alternativaNombre = ref<string>('Alternativa A');
const monedaCotizacion = ref<'PEN' | 'USD'>('PEN');
const pedirTipoCambio = ref<boolean>(false);
const tipoCambioValor = ref<number | null>(null);
const tipoCambioRequiereConfirmacion = ref<boolean>(false);
const tipoCambioConfirmado = ref<boolean>(false);
const errores = ref<Record<string, string>>({});

onMounted(async () => {
    try {
        const res = await configuracionAgenciaService.obtener();
        monedaCotizacion.value = res.configuracion_agencia.moneda_cotizacion_default ?? 'PEN';
    } catch {
        // Sin configuración se queda en PEN: igual se puede elegir a mano.
    }
});

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

const agregarPax = (edadSugerida: number, cantidad: number = 1) => {
    const veces = Math.max(1, Math.min(30, cantidad || 1));
    for (let i = 0; i < veces; i++) {
        pasajeros.value.push({ edad: edadSugerida, residencia: 'nacional' });
    }
};

const crear = async () => {
    const faltan: Record<string, string> = {};
    if (!clienteSeleccionado.value) faltan.cliente_id = 'Seleccioná un cliente.';
    if (!destinoTexto.value.trim()) faltan.destino = 'Seleccioná un destino.';
    if (pasajeros.value.length === 0) faltan.pasajeros = 'Agregá al menos un pasajero.';
    if (pedirTipoCambio.value && !(Number(tipoCambioValor.value) > 0)) faltan.tipo_cambio_valor = 'Escribí el tipo de cambio del día.';
    errores.value = faltan;
    if (Object.keys(faltan).length > 0 || !clienteSeleccionado.value) return;

    creando.value = true;
    try {
        const res = await cotizacionService.crear({
            cliente_id: clienteSeleccionado.value.id,
            destino: destinoTexto.value,
            fecha_viaje_desde: sinFechaExacta.value ? null : (fechaViajeDesde.value || null),
            fecha_viaje_hasta: sinFechaExacta.value ? null : (fechaViajeHasta.value || null),
            pasajeros: pasajeros.value,
            alternativa_nombre: alternativaNombre.value.trim() || null,
            moneda_cotizacion: monedaCotizacion.value,
            tipo_cambio_valor: pedirTipoCambio.value ? tipoCambioValor.value : null,
            tipo_cambio_confirmado: pedirTipoCambio.value && tipoCambioConfirmado.value,
        });

        router.push(`/agencia-viajes/cotizador/${res.cotizacion.id}`);
    } catch (error: any) {
        const data = error.response?.data ?? {};
        if (data.requiere_tipo_cambio) {
            // Sin tipo de cambio registrado ni de SUNAT (o fuera de rango): se pide acá.
            pedirTipoCambio.value = true;
            tipoCambioRequiereConfirmacion.value = !!data.requiere_confirmacion;
            errores.value = { tipo_cambio_valor: data.message };
        } else if (data.errors) {
            // 'pasajeros.0.edad' → se muestra en el bloque de pasajeros.
            errores.value = Object.fromEntries(
                Object.entries(data.errors as Record<string, string[]>).map(([campo, msgs]) => [campo.split('.')[0], msgs[0]])
            );
        } else {
            errores.value = { general: data.message ?? 'No se pudo crear la cotización' };
        }
    } finally {
        creando.value = false;
    }
};
</script>
