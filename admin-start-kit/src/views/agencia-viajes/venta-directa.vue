<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-bolt me-2 text-primary"></i>
                    Venta directa
                </h5>
                <small class="text-muted">Atajo para un solo servicio suelto — arma la reserva en un solo paso, sin pasar por el cotizador completo</small>
            </div>
            <router-link to="/agencia-viajes/reservas" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-12 col-md-6 position-relative">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Cliente</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" v-model="clientSearchText"
                                placeholder="Buscar por DNI, RUC o nombre..." @input="onClientSearchInput"
                                @focus="showClientSuggestions = true" @blur="onClientSearchBlur" autocomplete="off">
                            <button v-if="clienteSeleccionado" class="btn btn-outline-danger" type="button" @click="limpiarCliente">
                                <i class="fas fa-times"></i>
                            </button>
                            <button v-else class="btn btn-success" type="button" @click="showQuickClientModal = true" title="Registrar cliente nuevo">
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
                        <div v-if="clienteSeleccionado" class="small text-success mt-1">
                            <i class="fas fa-circle-check me-1"></i>{{ clienteSeleccionado.full_name }} ({{ clienteSeleccionado.n_document }})
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                        <input type="text" class="form-control form-control-sm" v-model="destino" placeholder="Ej. Alto Mayo">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha del servicio</label>
                        <input type="date" class="form-control form-control-sm" v-model="fechaServicio">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark">Pasajeros</span>
            </div>
            <div class="card-body py-3">
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(30)"><i class="fas fa-plus me-1"></i>Adulto</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(8)"><i class="fas fa-plus me-1"></i>Niño</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPax(1)"><i class="fas fa-plus me-1"></i>Infante</button>
                </div>
                <div v-if="pax.length === 0" class="text-muted small fst-italic mb-2">Agregá al menos un pasajero.</div>
                <div class="d-flex flex-column gap-2">
                    <div v-for="(p, idx) in pax" :key="idx" class="d-flex align-items-center gap-2 border rounded p-2 small">
                        <label class="text-muted mb-0">Edad</label>
                        <input type="number" class="form-control form-control-sm" style="max-width:80px" v-model.number="p.edad" min="0" max="99">
                        <i class="fas fa-times text-danger ms-auto" style="cursor:pointer" @click="pax.splice(idx, 1)"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark">Servicio</span>
            </div>
            <div class="card-body py-3">
                <div class="mb-3">
                    <div class="btn-group btn-group-sm">
                        <button class="btn" :class="origenTipo === 'proveedor' ? 'btn-primary' : 'btn-outline-primary'" @click="origenTipo = 'proveedor'">
                            Del catálogo de proveedores
                        </button>
                        <button class="btn" :class="origenTipo === 'manual' ? 'btn-primary' : 'btn-outline-primary'" @click="origenTipo = 'manual'">
                            Concepto libre
                        </button>
                    </div>
                </div>

                <div v-if="origenTipo === 'proveedor'" class="row g-2">
                    <div class="col-12">
                        <label class="form-label small text-secondary mb-1">Buscar tarifa (proveedor o servicio)</label>
                        <input type="text" class="form-control form-control-sm" v-model="bibliotecaSearch" @input="onBibliotecaSearch" placeholder="Ej. Hotel Cumbaza, Traslado...">
                    </div>
                    <div class="col-12">
                        <select class="form-select form-select-sm" v-model="proveedorTarifaId" size="6">
                            <option v-for="t in bibliotecaTarifas" :key="t.id" :value="t.id">
                                {{ t.proveedor_servicio?.proveedor?.razon_social }} · {{ t.proveedor_servicio?.destino_servicio?.servicio?.nombre }}
                                {{ t.tipo_habitacion ? ('· ' + t.tipo_habitacion) : '' }} — {{ t.moneda }} {{ t.precio_venta_adulto }}
                            </option>
                        </select>
                        <div v-if="tarifaSeleccionada" class="alert alert-success py-2 px-3 mt-2 mb-0 small d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-circle-check me-1"></i>
                                <strong>Seleccionado:</strong>
                                {{ tarifaSeleccionada.proveedor_servicio?.proveedor?.razon_social }} ·
                                {{ tarifaSeleccionada.proveedor_servicio?.destino_servicio?.servicio?.nombre }}
                                <span v-if="tarifaSeleccionada.tipo_habitacion">· {{ tarifaSeleccionada.tipo_habitacion }}</span>
                                — {{ tarifaSeleccionada.moneda }} {{ tarifaSeleccionada.precio_venta_adulto }}
                            </span>
                            <button type="button" class="btn-close btn-sm" @click="proveedorTarifaId = null"></button>
                        </div>
                        <div v-else class="small text-muted mt-1">Ninguna tarifa seleccionada todavía.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-secondary mb-1">Modo de precio</label>
                        <select class="form-select form-select-sm" v-model="modoPrecio">
                            <option value="tarifa_fija">Tarifa fija (ej. hotel por habitación)</option>
                            <option value="por_persona">Por persona (adulto/niño/infante)</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-secondary mb-1">Cantidad</label>
                        <input type="number" min="1" class="form-control form-control-sm" v-model.number="cantidad">
                    </div>
                </div>

                <div v-else class="row g-2">
                    <div class="col-12">
                        <label class="form-label small text-secondary mb-1">Descripción</label>
                        <input type="text" class="form-control form-control-sm" v-model="descripcionManual" placeholder="Ej. Traslado aeropuerto-hotel">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-secondary mb-1">Precio de venta</label>
                        <input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="precioVentaSnapshot">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-secondary mb-1">Moneda</label>
                        <select class="form-select form-select-sm" v-model="monedaCosto">
                            <option value="PEN">Soles</option>
                            <option value="USD">Dólares</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <button class="btn btn-primary fw-semibold" @click="crear" :disabled="creando">
                <span v-if="creando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-check me-2"></i>
                Crear venta directa
            </button>
        </div>

        <div class="modal fade" tabindex="-1" :class="{ show: showQuickClientModal, 'd-block': showQuickClientModal }"
            style="background:rgba(0,0,0,.5)" v-if="showQuickClientModal">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Registrar Cliente Rápido</h6>
                        <button class="btn-close" @click="showQuickClientModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <ClientFormQuick :initial-data="null" @saved="onClientCreated" @cancel="showQuickClientModal = false" />
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { proveedorService } from '@/services/admin/proveedorService';
import { ventaDirectaService } from '@/services/admin/ventaDirectaService';
import type { Client } from '@/types/clients';
import type { ProveedorTarifa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const router = useRouter();

const clientSearchText = ref<string>('');
const clientSuggestions = ref<Client[]>([]);
const clienteSeleccionado = ref<Client | null>(null);
const showClientSuggestions = ref<boolean>(false);
const showQuickClientModal = ref<boolean>(false);
let clientSearchTimeout: any = null;

const destino = ref<string>('');
const fechaServicio = ref<string>('');
const pax = ref<Array<{ edad: number }>>([]);
const creando = ref<boolean>(false);

const origenTipo = ref<'proveedor' | 'manual'>('proveedor');

const bibliotecaSearch = ref('');
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const proveedorTarifaId = ref<number | null>(null);
const modoPrecio = ref<'por_persona' | 'tarifa_fija'>('tarifa_fija');
const cantidad = ref<number>(1);
let bibliotecaTimeout: any = null;

const tarifaSeleccionada = computed(() => bibliotecaTarifas.value.find((t) => t.id === proveedorTarifaId.value) ?? null);

const descripcionManual = ref('');
const precioVentaSnapshot = ref<number | null>(null);
const monedaCosto = ref<'PEN' | 'USD'>('PEN');

const onClientSearchInput = () => {
    clearTimeout(clientSearchTimeout);
    clientSearchTimeout = setTimeout(buscarClientes, 300);
};

const buscarClientes = async () => {
    if (clientSearchText.value.trim().length < 2) { clientSuggestions.value = []; return; }
    const res = await httpClient.get('/clients', { params: { search: clientSearchText.value } });
    // ClientController::index() envuelve el listado en ClientCollection
    // ({ data: [...] }) — clients.data, no clients directo (mismo acceso
    // que ya usa sale/register.vue).
    clientSuggestions.value = res.data.clients?.data ?? [];
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

const onClientSearchBlur = () => { setTimeout(() => { showClientSuggestions.value = false; }, 150); };

const onClientCreated = (client: Client) => {
    seleccionarCliente(client);
    showQuickClientModal.value = false;
};

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(async () => {
        const res = await proveedorService.biblioteca(bibliotecaSearch.value || undefined);
        bibliotecaTarifas.value = res.proveedor_tarifas;
    }, 250);
};

const agregarPax = (edadSugerida: number) => { pax.value.push({ edad: edadSugerida }); };

const crear = async () => {
    if (!clienteSeleccionado.value) { (Swal as TVueSwalInstance).fire('Error', 'Seleccioná un cliente.', 'error'); return; }
    if (!destino.value.trim()) { (Swal as TVueSwalInstance).fire('Error', 'Ingresá un destino.', 'error'); return; }
    if (pax.value.length === 0) { (Swal as TVueSwalInstance).fire('Error', 'Agregá al menos un pasajero.', 'error'); return; }
    if (origenTipo.value === 'proveedor' && !proveedorTarifaId.value) { (Swal as TVueSwalInstance).fire('Error', 'Seleccioná una tarifa del catálogo.', 'error'); return; }
    if (origenTipo.value === 'manual' && (!descripcionManual.value.trim() || !precioVentaSnapshot.value)) { (Swal as TVueSwalInstance).fire('Error', 'Completá la descripción y el precio.', 'error'); return; }

    creando.value = true;
    try {
        const res = await ventaDirectaService.crear({
            cliente_id: clienteSeleccionado.value.id,
            destino: destino.value.trim(),
            fecha_servicio: fechaServicio.value || null,
            origen_tipo: origenTipo.value,
            pax: pax.value,
            ...(origenTipo.value === 'proveedor'
                ? { proveedor_tarifa_id: proveedorTarifaId.value!, modo_precio: modoPrecio.value, cantidad: cantidad.value }
                : { descripcion_manual: descripcionManual.value.trim(), precio_venta_snapshot: precioVentaSnapshot.value!, moneda_costo: monedaCosto.value }),
        });

        router.push(`/agencia-viajes/reservas/${res.reserva.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear la venta directa', 'error');
    } finally {
        creando.value = false;
    }
};
</script>
