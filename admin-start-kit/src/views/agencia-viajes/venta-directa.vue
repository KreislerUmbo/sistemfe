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
                        <div class="d-flex gap-2">
                            <CampoFecha v-model="fechaServicio" />
                            <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" @click="fechaServicio = hoyPeru()">Hoy</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" @click="fechaServicio = sumarDiasISO(hoyPeru(), 1)">Mañana</button>
                        </div>
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
                                {{ t.proveedor_servicio?.proveedor?.razon_social }}{{ t.proveedor_servicio?.proveedor?.es_referencial ? ' (Referencial)' : '' }} · {{ t.proveedor_servicio?.destino_servicio?.servicio?.nombre }}
                                {{ t.tipo_habitacion ? ('· ' + t.tipo_habitacion) : '' }} — {{ t.moneda }} {{ t.precio_venta_adulto }}
                            </option>
                        </select>
                        <div v-if="tarifaSeleccionada" class="alert alert-success py-2 px-3 mt-2 mb-0 small d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-circle-check me-1"></i>
                                <strong>Seleccionado:</strong>
                                {{ tarifaSeleccionada.proveedor_servicio?.proveedor?.razon_social }}
                                <span v-if="tarifaSeleccionada.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                                ·
                                {{ tarifaSeleccionada.proveedor_servicio?.destino_servicio?.servicio?.nombre }}
                                <span v-if="tarifaSeleccionada.tipo_habitacion">· {{ tarifaSeleccionada.tipo_habitacion }}</span>
                                — {{ tarifaSeleccionada.moneda }} {{ tarifaSeleccionada.precio_venta_adulto }}
                            </span>
                            <button type="button" class="btn-close btn-sm" @click="proveedorTarifaId = null"></button>
                        </div>
                        <div v-else class="small text-muted mt-1">Ninguna tarifa seleccionada todavía.</div>
                    </div>
                    <div v-if="tarifaSeleccionada" class="col-12">
                        <label class="form-label small text-secondary mb-1">¿Cómo se cobra? <span class="text-danger">*</span></label>
                        <div v-if="vistaPreviaError" class="alert alert-warning py-2 px-3 mb-0 small">{{ vistaPreviaError }}</div>
                        <div v-else-if="motivoSinCalculo" class="small text-warning-emphasis mb-2">
                            <i class="fas fa-circle-info me-1"></i>{{ motivoSinCalculo }}
                        </div>
                        <div v-if="!vistaPreviaError && !vistaPrevia && !motivoSinCalculo" class="small text-muted">
                            <span class="spinner-border spinner-border-sm me-1"></span>Calculando…
                        </div>
                        <div v-else-if="!vistaPreviaError && vistaPrevia" class="row g-2">
                            <div class="col-12 col-md-6">
                                <label class="border rounded p-2 d-block h-100" :class="{ 'border-primary bg-primary-subtle': modoPrecio === 'por_persona' }" style="cursor:pointer">
                                    <input type="radio" class="form-check-input me-2" value="por_persona" v-model="modoPrecio">
                                    <span class="fw-semibold">Por persona ({{ pax.length }} pasajero{{ pax.length === 1 ? '' : 's' }}): {{ totalesAlDia ? dinero(vistaPrevia.por_persona.total, vistaPrevia.moneda) : '…' }}</span>
                                    <div class="small text-muted mt-1">{{ totalesAlDia ? textoDesglose : 'Calculando…' }}</div>
                                </label>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="border rounded p-2 d-block h-100" :class="{ 'border-primary bg-primary-subtle': modoPrecio === 'tarifa_fija' }" style="cursor:pointer">
                                    <input type="radio" class="form-check-input me-2" value="tarifa_fija" v-model="modoPrecio">
                                    <span class="fw-semibold">Por unidad (cantidad {{ cantidad }}): {{ totalesAlDia ? dinero(vistaPrevia.tarifa_fija.total, vistaPrevia.moneda) : '…' }}</span>
                                    <div class="d-flex align-items-center gap-2 small text-muted mt-1">
                                        {{ dinero(vistaPrevia.tarifa_fija.precio_unitario, vistaPrevia.moneda) }} ×
                                        <input type="number" min="1" class="form-control form-control-sm" style="max-width:80px" v-model.number="cantidad"
                                            @focus="modoPrecio = 'tarifa_fija'" aria-label="Cantidad">
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="row g-2">
                    <div class="col-12">
                        <label class="form-label small text-secondary mb-1">Descripción</label>
                        <input type="text" class="form-control form-control-sm" v-model="descripcionManual" placeholder="Ej. Traslado aeropuerto-hotel">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">Costo</label>
                        <input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="costoSnapshot">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">Precio de venta</label>
                        <input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="precioVentaSnapshot">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">Cantidad</label>
                        <input type="number" min="1" class="form-control form-control-sm" v-model.number="cantidadManual">
                    </div>
                    <div class="col-6 col-md-3">
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
            <button class="btn btn-primary fw-semibold" @click="crear" :disabled="creando || totalPendiente">
                <span v-if="creando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-check me-2"></i>
                Crear venta directa<template v-if="totalEnVivo"> · {{ totalEnVivo }}</template>
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
import CampoFecha from '@/components/CampoFecha.vue';
import { ref, computed, watch } from 'vue';
import { hoyPeru, sumarDiasISO } from '@/helpers/fecha';
import { useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { proveedorService } from '@/services/admin/proveedorService';
import { ventaDirectaService, type TipoPaxVistaPrevia, type VistaPreviaVentaDirecta } from '@/services/admin/ventaDirectaService';
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
const fechaServicio = ref<string>(hoyPeru()); // T-045: obligatoria, por defecto hoy en Perú
const pax = ref<Array<{ edad: number }>>([]);
const creando = ref<boolean>(false);

const origenTipo = ref<'proveedor' | 'manual'>('proveedor');

const bibliotecaSearch = ref('');
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const proveedorTarifaId = ref<number | null>(null);
// T-045: sin valor por defecto; el vendedor elige viendo los dos totales.
const modoPrecio = ref<'por_persona' | 'tarifa_fija' | null>(null);
const cantidad = ref<number>(1);
let bibliotecaTimeout: any = null;

const tarifaSeleccionada = computed(() => bibliotecaTarifas.value.find((t) => t.id === proveedorTarifaId.value) ?? null);

// ── T-045: total en vivo ──────────────────────────────────────────────
// El backend clasifica a los pasajeros por edad (ConfiguracionAgencia, que
// un vendedor no puede leer), así que el total de una tarifa sale del
// endpoint de vista previa. Concepto libre se calcula acá.
const vistaPrevia = ref<VistaPreviaVentaDirecta | null>(null);
const vistaPreviaError = ref<string>('');
let vistaPreviaTimeout: any = null;
let vistaPreviaSeq = 0; // descarta respuestas viejas

const dinero = (monto: number, moneda: 'PEN' | 'USD') =>
    `${moneda === 'USD' ? 'US$' : 'S/'} ${monto.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const paxValidos = computed(() => pax.value.length > 0 && pax.value.every((p) => Number.isInteger(p.edad) && p.edad >= 0 && p.edad <= 120));

// Por qué no se puede calcular todavía (se muestra en lugar de un spinner sin fin).
const motivoSinCalculo = computed<string>(() => {
    if (pax.value.length === 0) return 'Agrega al menos un pasajero para ver el total.';
    if (!paxValidos.value) return 'Revisa las edades de los pasajeros (de 0 a 120).';
    if (!Number.isInteger(cantidad.value) || cantidad.value < 1) return 'La cantidad debe ser 1 o más.';
    return '';
});

// Hay un cálculo en espera (debounce) o en vuelo: los totales en pantalla
// pueden ser de los datos anteriores.
const calculando = ref<boolean>(false);

// Los totales corresponden a lo que está en pantalla ahora mismo.
const totalesAlDia = computed<boolean>(() => !!vistaPrevia.value && !calculando.value && !motivoSinCalculo.value);

const pedirVistaPrevia = () => {
    clearTimeout(vistaPreviaTimeout);
    const seq = ++vistaPreviaSeq; // invalida lo que esté en vuelo
    vistaPreviaError.value = '';
    // Con datos inválidos se conserva la vista previa anterior (las tarjetas
    // siguen visibles para poder corregir la cantidad), pero sin mostrar sus
    // montos: totalesAlDia queda en false por motivoSinCalculo.
    if (origenTipo.value !== 'proveedor' || !proveedorTarifaId.value || motivoSinCalculo.value) {
        calculando.value = false;
        return;
    }
    calculando.value = true;
    const tarifaId = proveedorTarifaId.value;
    vistaPreviaTimeout = setTimeout(async () => {
        try {
            const res = await ventaDirectaService.vistaPrevia({ pax: pax.value, proveedor_tarifa_id: tarifaId, cantidad: cantidad.value });
            if (seq === vistaPreviaSeq) vistaPrevia.value = res;
        } catch (error: any) {
            if (seq !== vistaPreviaSeq) return;
            vistaPrevia.value = null;
            vistaPreviaError.value = error.response?.data?.message ?? 'No se pudo calcular el total.';
        } finally {
            if (seq === vistaPreviaSeq) calculando.value = false;
        }
    }, 300);
};

// Al cambiar de tarifa el modo vuelve a "sin elegir" y la cantidad a 1.
watch(proveedorTarifaId, () => { modoPrecio.value = null; vistaPrevia.value = null; cantidad.value = 1; });
watch([proveedorTarifaId, cantidad, () => pax.value.map((p) => p.edad).join(','), origenTipo], pedirVistaPrevia);

const textoDesglose = computed(() => {
    if (!vistaPrevia.value) return '';
    const etiquetas: Record<TipoPaxVistaPrevia, [string, string]> = { adulto: ['adulto', 'adultos'], nino: ['niño', 'niños'], infante: ['infante', 'infantes'] };
    return (Object.keys(etiquetas) as TipoPaxVistaPrevia[])
        .filter((t) => vistaPrevia.value!.por_persona.desglose[t].cantidad > 0)
        .map((t) => {
            const { cantidad: n, precio } = vistaPrevia.value!.por_persona.desglose[t];
            return `${n} ${etiquetas[t][n === 1 ? 0 : 1]} × ${precio}`;
        })
        .join(' + ');
});

const totalEnVivo = computed<string>(() => {
    if (origenTipo.value === 'manual') {
        if (precioVentaSnapshot.value === null || !cantidadManual.value || cantidadManual.value < 1) return '';
        return dinero(Math.round(precioVentaSnapshot.value * 100) / 100 * cantidadManual.value, monedaCosto.value);
    }
    if (!totalesAlDia.value || !modoPrecio.value) return '';
    return dinero(vistaPrevia.value![modoPrecio.value].total, vistaPrevia.value!.moneda);
});

// Tarifa de proveedor con el total sin confirmar: no se deja crear, para no
// cobrar un monto distinto al que se ve (H42).
const totalPendiente = computed<boolean>(() => origenTipo.value === 'proveedor' && !!proveedorTarifaId.value && !totalesAlDia.value);

const descripcionManual = ref('');
const precioVentaSnapshot = ref<number | null>(null);
// Costo real del concepto libre (lo exige el backend desde la Sesión 11q) —
// 0 es válido si la agencia no tiene costo asociado.
const costoSnapshot = ref<number | null>(0);
const cantidadManual = ref<number>(1);
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
        const res = await proveedorService.biblioteca({ search: bibliotecaSearch.value || undefined });
        bibliotecaTarifas.value = res.proveedor_tarifas;
    }, 250);
};

const agregarPax = (edadSugerida: number) => { pax.value.push({ edad: edadSugerida }); };

const crear = async () => {
    if (!clienteSeleccionado.value) { (Swal as TVueSwalInstance).fire('Error', 'Seleccioná un cliente.', 'error'); return; }
    if (!destino.value.trim()) { (Swal as TVueSwalInstance).fire('Error', 'Ingresá un destino.', 'error'); return; }
    if (pax.value.length === 0) { (Swal as TVueSwalInstance).fire('Error', 'Agregá al menos un pasajero.', 'error'); return; }
    if (!fechaServicio.value) { (Swal as TVueSwalInstance).fire('Error', 'Indica la fecha del servicio.', 'error'); return; }
    if (origenTipo.value === 'proveedor' && !proveedorTarifaId.value) { (Swal as TVueSwalInstance).fire('Error', 'Seleccioná una tarifa del catálogo.', 'error'); return; }
    if (origenTipo.value === 'proveedor' && !modoPrecio.value) { (Swal as TVueSwalInstance).fire('Error', 'Elige cómo se cobra: por persona o por unidad.', 'error'); return; }
    if (origenTipo.value === 'manual' && (!descripcionManual.value.trim() || !precioVentaSnapshot.value)) { (Swal as TVueSwalInstance).fire('Error', 'Completá la descripción y el precio.', 'error'); return; }
    if (origenTipo.value === 'manual' && (costoSnapshot.value === null || costoSnapshot.value < 0 || !cantidadManual.value || cantidadManual.value < 1)) { (Swal as TVueSwalInstance).fire('Error', 'Revisá el costo (0 o más) y la cantidad (1 o más).', 'error'); return; }

    creando.value = true;
    try {
        const res = await ventaDirectaService.crear({
            cliente_id: clienteSeleccionado.value.id,
            destino: destino.value.trim(),
            fecha_servicio: fechaServicio.value,
            origen_tipo: origenTipo.value,
            pax: pax.value,
            ...(origenTipo.value === 'proveedor'
                ? { proveedor_tarifa_id: proveedorTarifaId.value!, modo_precio: modoPrecio.value!, cantidad: modoPrecio.value === 'por_persona' ? 1 : cantidad.value }
                : { descripcion_manual: descripcionManual.value.trim(), costo_snapshot: costoSnapshot.value!, precio_venta_snapshot: precioVentaSnapshot.value!, cantidad: cantidadManual.value, moneda_costo: monedaCosto.value }),
        });

        router.push(`/agencia-viajes/reservas/${res.reserva.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear la venta directa', 'error');
    } finally {
        creando.value = false;
    }
};
</script>
