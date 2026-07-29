<template>
    <DefaultLayout>
        <div v-if="cotizacion" class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-0"><i class="fas fa-route me-2 text-primary"></i>Cotización {{ cotizacion.codigo }}</h5>
                <small class="text-muted">
                    {{ cotizacion.cliente?.full_name }} · {{ cotizacion.destino }} ·
                    {{ resumenPax }}
                </small>
            </div>
            <router-link to="/agencia-viajes/cotizador" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <!-- Pestañas de alternativas -->
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap" v-if="cotizacion">
            <span v-for="alt in cotizacion.alternativas" :key="alt.id" class="badge rounded-pill px-3 py-2"
                :class="alt.id === alternativaActivaId ? 'bg-primary' : 'bg-light text-dark border'"
                style="cursor:pointer" @click="seleccionarAlternativa(alt.id)">
                {{ alt.nombre }} · {{ alt.moneda_cotizacion }} {{ Number(alt.total).toFixed(0) }}
                <span v-if="alt.estado === 'aceptada'" class="ms-1"><i class="fas fa-check-circle"></i></span>
                <span v-else-if="alt.estado === 'descartada'" class="ms-1 opacity-50"><i class="fas fa-times-circle"></i></span>
            </span>
            <span v-if="(cotizacion.alternativas?.length ?? 0) < 5" class="badge rounded-pill px-3 py-2 bg-light text-dark border"
                style="cursor:pointer;border-style:dashed" @click="mostrarFormAlternativa = true">
                <i class="fas fa-plus me-1"></i>Nueva
            </span>
        </div>

        <!-- Form nueva alternativa -->
        <div v-if="mostrarFormAlternativa" class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre</label>
                        <input type="text" class="form-control form-control-sm" v-model="formAlternativa.nombre" placeholder="Alternativa B">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                        <select class="form-select form-select-sm" v-model="formAlternativa.moneda_cotizacion">
                            <option value="PEN">Soles</option>
                            <option value="USD">Dólares</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de cambio</label>
                        <select class="form-select form-select-sm" v-model="formAlternativa.tipo_cambio_origen">
                            <option value="dia">Del día</option>
                            <option value="agencia">De la agencia</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Valor nuevo (opcional)</label>
                        <input type="number" step="0.0001" class="form-control form-control-sm" v-model.number="formAlternativa.tipo_cambio_valor">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm w-100" @click="crearAlternativa">Crear</button>
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarFormAlternativa = false"><i class="fas fa-times"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3" v-if="alternativaActiva">
            <!-- ═══ COLUMNA IZQUIERDA: biblioteca / comparador ═══ -->
            <div class="col-12 col-lg-3">
                <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm flex-fill" :class="modo === 'local' ? 'btn-primary' : 'btn-outline-secondary'" @click="modo = 'local'">Local / Nacional</button>
                    <button class="btn btn-sm flex-fill" :class="modo === 'intl' ? 'btn-primary' : 'btn-outline-secondary'" @click="modo = 'intl'">Internacional</button>
                </div>

                <!-- Biblioteca local -->
                <div v-if="modo === 'local'" class="card border-0 shadow-sm">
                    <div class="card-body p-2">
                        <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar servicio..."
                            v-model="bibliotecaSearch" @input="onBibliotecaSearch">
                        <div class="d-flex flex-column gap-2" style="max-height:480px;overflow-y:auto;">
                            <div v-for="t in bibliotecaTarifas" :key="t.id" class="border rounded p-2 small lib-item" style="cursor:pointer"
                                @click="clicBibliotecaItem(t)">
                                <div class="d-flex justify-content-between">
                                    <span>
                                        <i class="fas me-1 text-primary" :class="t.tipo_habitacion ? 'fa-bed' : 'fa-concierge-bell'"></i>
                                        {{ t.proveedor_servicio?.proveedor?.razon_social }}
                                    </span>
                                    <span class="text-muted">{{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(0) }}</span>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    {{ t.proveedor_servicio?.destino_servicio?.servicio?.nombre }}
                                    <span v-if="t.tipo_habitacion"> · {{ t.tipo_habitacion }}</span>
                                </div>
                            </div>
                            <div v-if="bibliotecaTarifas.length === 0" class="text-muted small text-center py-3">Sin tarifas encontradas.</div>
                        </div>
                        <small class="text-muted d-block mt-2"><i class="fas fa-hand-pointer me-1"></i>Clic para agregar a la alternativa activa</small>
                    </div>
                    <div class="card-footer bg-white border-0 p-2">
                        <button class="btn btn-outline-secondary btn-sm w-100" @click="mostrarFormManual = true"><i class="fas fa-plus me-1"></i>Ítem manual</button>
                    </div>
                </div>

                <!-- Comparador de mayoristas -->
                <div v-else class="d-flex flex-column gap-2">
                    <div v-for="op in opcionesMayorista" :key="op.id" class="card border p-2 small mayorista-card"
                        :class="{ 'border-primary border-2': op.estado === 'elegida' }">
                        <strong>{{ op.proveedor?.razon_social }}</strong>
                        <div class="text-muted" v-if="op.vuelo_aerolinea"><i class="fas fa-plane me-1"></i>{{ op.vuelo_aerolinea }}</div>
                        <div class="text-muted mb-1" v-if="op.incluye">{{ op.incluye }}</div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge" :class="op.estado === 'elegida' ? 'bg-primary' : 'bg-light text-dark border'">{{ op.estado }}</span>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-primary" @click="verHoteles(op)">Hoteles</button>
                                <button v-if="op.estado !== 'elegida'" class="btn btn-sm btn-outline-success" @click="elegirOpcion(op)">Elegir</button>
                            </div>
                        </div>
                        <div v-if="opcionHotelesActivaId === op.id" class="mt-2 border-top pt-2">
                            <HabitacionMatrixPicker v-if="op.estado === 'elegida'"
                                :tarifas="tarifasHotelPlanas(op)" :moneda="op.moneda"
                                @seleccionar="({ id, cantidad }) => agregarItemMayorista(op, id, cantidad)" />
                            <div v-else class="text-muted small fst-italic">Marcá esta opción como elegida para poder agregar una habitación.</div>
                            <button class="btn btn-sm btn-outline-secondary w-100 mt-2" @click="mostrarFormHotel = op.id">
                                <i class="fas fa-plus me-1"></i>Agregar hotel a esta opción
                            </button>
                            <div v-if="mostrarFormHotel === op.id" class="border rounded p-2 mt-2">
                                <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre del hotel" v-model="formHotel.nombre_hotel">
                                <div v-for="(tf, idx) in formHotel.tarifas" :key="idx" class="row g-1 mb-1">
                                    <div class="col-4">
                                        <select class="form-select form-select-sm" v-model="tf.tipo_habitacion">
                                            <option value="matrimonial">Matrimonial</option>
                                            <option value="doble">Doble</option>
                                            <option value="triple">Triple</option>
                                            <option value="familiar">Familiar</option>
                                        </select>
                                    </div>
                                    <div class="col-4"><input type="number" class="form-control form-control-sm" placeholder="Costo" v-model.number="tf.precio_costo"></div>
                                    <div class="col-4"><input type="number" class="form-control form-control-sm" placeholder="Venta" v-model.number="tf.precio_venta"></div>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary mb-1" @click="formHotel.tarifas.push({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 })">+ tipo de habitación</button>
                                <button class="btn btn-sm btn-primary w-100" @click="guardarHotel(op)">Guardar hotel</button>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm w-100" @click="mostrarFormMayorista = true">
                        <i class="fas fa-plus me-1"></i>Agregar cotización de mayorista
                    </button>
                    <div v-if="mostrarFormMayorista" class="card border p-2 small">
                        <select class="form-select form-select-sm mb-1" v-model="formMayorista.proveedor_id">
                            <option :value="null">— Proveedor mayorista —</option>
                            <option v-for="p in proveedoresMayoristas" :key="p.id" :value="p.id">{{ p.razon_social }}</option>
                        </select>
                        <select class="form-select form-select-sm mb-1" v-model="formMayorista.moneda">
                            <option value="USD">USD</option>
                            <option value="PEN">PEN</option>
                        </select>
                        <input type="text" class="form-control form-control-sm mb-1" placeholder="Vuelo (aerolínea)" v-model="formMayorista.vuelo_aerolinea">
                        <textarea class="form-control form-control-sm mb-1" rows="2" placeholder="Incluye..." v-model="formMayorista.incluye"></textarea>
                        <button class="btn btn-primary btn-sm w-100" @click="guardarOpcionMayorista">Guardar</button>
                    </div>
                </div>
            </div>

            <!-- ═══ COLUMNA CENTRO: lienzo ═══ -->
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div v-if="(alternativaActiva.items?.length ?? 0) === 0" class="drop-hint text-center text-muted py-4 border rounded" style="border-style:dashed">
                            Agregá un servicio desde la biblioteca de la izquierda
                        </div>
                        <div v-for="item in alternativaActiva.items" :key="item.id" class="canvas-item border rounded p-2 mb-2 small">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="fas me-2 text-primary" :class="iconoItem(item)"></i>
                                    {{ etiquetaItem(item) }}
                                    <span v-if="item.cantidad > 1 && item.modo_precio === 'tarifa_fija' && item.origen_tipo !== 'manual'" class="text-muted"> × {{ item.cantidad }}</span>
                                </span>
                                <span class="d-flex align-items-center gap-2">
                                    <strong>{{ alternativaActiva.moneda_cotizacion }} {{ Number(item.total_convertido).toFixed(2) }}</strong>
                                    <i class="fas fa-times text-danger" style="cursor:pointer" @click="eliminarItem(item)"></i>
                                </span>
                            </div>
                            <div class="text-muted mt-1" style="font-size:11px" v-if="item.origen_tipo === 'pasaje_aereo' && item.cotizacion_pasaje_aereo">
                                {{ item.cotizacion_pasaje_aereo.aerolinea }}
                            </div>
                        </div>

                        <div v-if="mostrarFormManual" class="border rounded p-2 mt-2">
                            <ItemManualForm :alternativa-id="alternativaActiva.id" @agregado="onItemAgregado" />
                        </div>
                        <div v-if="mostrarFormPasajeAereo" class="border rounded p-2 mt-2">
                            <PasajeAereoForm :alternativa-id="alternativaActiva.id" @agregado="onItemAgregado" />
                        </div>
                        <button class="btn btn-outline-secondary btn-sm mt-2" @click="mostrarFormPasajeAereo = !mostrarFormPasajeAereo">
                            <i class="fas fa-plane me-1"></i>{{ mostrarFormPasajeAereo ? 'Cerrar' : 'Agregar pasaje aéreo suelto' }}
                        </button>
                    </div>
                </div>

                <!-- Matriz de habitación (biblioteca local, tipo Hotel) -->
                <div v-if="matrizHotelActiva" class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white small fw-semibold d-flex justify-content-between">
                        <span>Elegí la habitación — {{ matrizHotelActiva.nombreProveedor }}</span>
                        <i class="fas fa-times text-danger" style="cursor:pointer" @click="matrizHotelActiva = null"></i>
                    </div>
                    <div class="card-body p-0">
                        <HabitacionMatrixPicker :tarifas="matrizHotelActiva.tarifas" :moneda="matrizHotelActiva.moneda"
                            @seleccionar="({ id, cantidad }) => agregarItemProveedorHotel(id, cantidad)" />
                    </div>
                </div>

                <!-- Selección de modo_precio para ítems no-hotel -->
                <div v-if="modoPrecioPendiente" class="card border-0 shadow-sm mt-3">
                    <div class="card-body small">
                        <p class="fw-semibold mb-2">¿Cómo se cobra "{{ modoPrecioPendiente.nombre }}"?</p>
                        <div class="d-flex gap-2 mb-2">
                            <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('tarifa_fija')">Tarifa fija (total)</button>
                            <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('por_persona')">Por persona (adulto/niño/infante)</button>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary w-100" @click="modoPrecioPendiente = null">Cancelar</button>
                    </div>
                </div>
            </div>

            <!-- ═══ COLUMNA DERECHA: precio en vivo ═══ -->
            <div class="col-12 col-lg-3">
                <div class="card border-0 shadow-sm price-panel">
                    <div class="card-body">
                        <p class="small fw-semibold text-secondary mb-2">Alternativa {{ alternativaActiva.nombre }}</p>
                        <div class="small mb-2" style="max-height:340px;overflow-y:auto;">
                            <div v-for="item in alternativaActiva.items" :key="item.id" class="mb-2 pb-2 border-bottom">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted text-truncate" style="max-width:110px" :title="etiquetaItem(item)">{{ etiquetaItem(item) }}</span>
                                    <span>{{ Number(item.total_convertido).toFixed(2) }}</span>
                                </div>
                                <div class="d-flex gap-1 align-items-center mt-1" v-if="item.origen_tipo !== 'manual' && item.proveedor_tarifa_id">
                                    <input type="number" class="form-control form-control-sm" style="max-width:70px" placeholder="Desc %"
                                        v-model.number="edicionItems[item.id].descuento_pct" @input="onEditarDescuento(item)">
                                    <input type="number" class="form-control form-control-sm" :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                                        v-model.number="edicionItems[item.id].precio_convertido" @input="onEditarPrecio(item)">
                                </div>
                                <small v-if="alertasPiso[item.id]" class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Por debajo del piso permitido</small>
                            </div>
                            <div v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted">Sin ítems todavía</div>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="small text-secondary">Total</span>
                            <span class="fs-4 fw-semibold">{{ alternativaActiva.moneda_cotizacion }} {{ Number(alternativaActiva.total).toFixed(2) }}</span>
                        </div>
                        <div class="mt-3 d-flex gap-2">
                            <button v-if="alternativaActiva.estado !== 'aceptada'" class="btn btn-success btn-sm flex-fill" @click="marcarAceptada">
                                <i class="fas fa-check me-1"></i>Aceptar
                            </button>
                            <button class="btn btn-outline-danger btn-sm" @click="eliminarAlternativa"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="cotizacion" class="text-center text-muted py-5">
            Esta cotización todavía no tiene alternativas — creá la primera con el botón "+ Nueva" de arriba.
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import HabitacionMatrixPicker from '@/components/AgenciaViajes/HabitacionMatrixPicker.vue';
import PasajeAereoForm from '@/components/AgenciaViajes/PasajeAereoForm.vue';
import ItemManualForm from '@/components/AgenciaViajes/ItemManualForm.vue';
import { cotizacionService } from '@/services/admin/cotizacionService';
import { alternativaService } from '@/services/admin/alternativaService';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import type { Cotizacion, Alternativa, AlternativaItem, ProveedorTarifa, OpcionMayorista, Proveedor } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const cotizacionId = Number(route.params.id);

const cotizacion = ref<Cotizacion | null>(null);
const alternativaActivaId = ref<number | null>(null);
const modo = ref<'local' | 'intl'>('local');

const alternativaActiva = computed<Alternativa | null>(() =>
    cotizacion.value?.alternativas?.find((a) => a.id === alternativaActivaId.value) ?? null
);

const resumenPax = computed(() => {
    const pax = cotizacion.value?.pasajeros ?? [];
    const counts: Record<string, number> = {};
    pax.forEach((p) => { counts[p.tipo_pax] = (counts[p.tipo_pax] ?? 0) + 1; });
    return Object.entries(counts).map(([t, n]) => `${n} ${t}`).join(', ') || 'sin pasajeros';
});

const cargarCotizacion = async () => {
    const res = await cotizacionService.obtener(cotizacionId);
    cotizacion.value = res.cotizacion;
    if (!alternativaActivaId.value && cotizacion.value.alternativas?.length) {
        alternativaActivaId.value = cotizacion.value.alternativas[0].id;
    }
    inicializarEdicionItems();
};

const seleccionarAlternativa = (id: number) => {
    alternativaActivaId.value = id;
    if (modo.value === 'intl') cargarOpcionesMayorista();
};

// ── Nueva alternativa ─────────────────────────────────────────────────
const mostrarFormAlternativa = ref(false);
const formAlternativa = ref({ nombre: '', moneda_cotizacion: 'PEN' as 'PEN' | 'USD', tipo_cambio_origen: 'dia' as 'dia' | 'agencia', tipo_cambio_valor: null as number | null });

const crearAlternativa = async () => {
    try {
        const res = await alternativaService.crear(cotizacionId, formAlternativa.value);
        mostrarFormAlternativa.value = false;
        await cargarCotizacion();
        alternativaActivaId.value = res.alternativa.id;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear', 'error');
    }
};

const marcarAceptada = async () => {
    if (!alternativaActiva.value) return;
    await alternativaService.actualizar(alternativaActiva.value.id, { estado: 'aceptada' });
    await cargarCotizacion();
};

const eliminarAlternativa = async () => {
    if (!alternativaActiva.value) return;
    await alternativaService.eliminar(alternativaActiva.value.id);
    alternativaActivaId.value = null;
    await cargarCotizacion();
};

// ── Biblioteca local ──────────────────────────────────────────────────
const bibliotecaSearch = ref('');
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
let bibliotecaTimeout: any = null;

const cargarBiblioteca = async () => {
    const res = await proveedorService.biblioteca(bibliotecaSearch.value || undefined);
    bibliotecaTarifas.value = res.proveedor_tarifas;
};

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(cargarBiblioteca, 300);
};

const matrizHotelActiva = ref<{ tarifas: Array<{ id: number; tipo_habitacion: string; precio: number }>; moneda: string; nombreProveedor: string } | null>(null);
const modoPrecioPendiente = ref<{ id: number; nombre: string } | null>(null);

const clicBibliotecaItem = async (tarifa: ProveedorTarifa) => {
    if (tarifa.tipo_habitacion) {
        // Hotel: matriz completa de habitaciones de ESE proveedor_servicio.
        const res = await proveedorService.listarTarifas(tarifa.proveedor_servicio_id);
        matrizHotelActiva.value = {
            tarifas: res.proveedor_tarifas.map((t) => ({ id: t.id, tipo_habitacion: t.tipo_habitacion ?? '—', precio: Number(t.precio_venta_adulto) })),
            moneda: tarifa.moneda,
            nombreProveedor: tarifa.proveedor_servicio?.proveedor?.razon_social ?? '',
        };
        return;
    }

    modoPrecioPendiente.value = { id: tarifa.id, nombre: tarifa.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'este ítem' };
};

const confirmarModoPrecio = async (modoPrecio: 'tarifa_fija' | 'por_persona') => {
    if (!modoPrecioPendiente.value || !alternativaActiva.value) return;
    try {
        const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
            proveedor_tarifa_id: modoPrecioPendiente.value.id,
            modo_precio: modoPrecio,
            cantidad: 1,
        });
        onItemAgregado(res.alternativa_item);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    } finally {
        modoPrecioPendiente.value = null;
    }
};

const agregarItemProveedorHotel = async (proveedorTarifaId: number, cantidad: number) => {
    if (!alternativaActiva.value) return;
    try {
        const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
            proveedor_tarifa_id: proveedorTarifaId,
            modo_precio: 'tarifa_fija',
            cantidad,
        });
        onItemAgregado(res.alternativa_item);
        matrizHotelActiva.value = null;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

// ── Comparador de mayoristas ──────────────────────────────────────────
const opcionesMayorista = ref<OpcionMayorista[]>([]);
const proveedoresMayoristas = ref<Proveedor[]>([]);
const opcionHotelesActivaId = ref<number | null>(null);
const mostrarFormMayorista = ref(false);
const mostrarFormHotel = ref<number | null>(null);
const formMayorista = ref({ proveedor_id: null as number | null, moneda: 'USD' as 'PEN' | 'USD', vuelo_aerolinea: '', incluye: '' });
const formHotel = ref<{ nombre_hotel: string; tarifas: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number }> }>({
    nombre_hotel: '', tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 }],
});

const cargarOpcionesMayorista = async () => {
    if (!alternativaActiva.value) return;
    const res = await opcionMayoristaService.listar(alternativaActiva.value.id);
    opcionesMayorista.value = res.opciones_mayorista;
};

const verHoteles = (op: OpcionMayorista) => {
    opcionHotelesActivaId.value = opcionHotelesActivaId.value === op.id ? null : op.id;
};

const elegirOpcion = async (op: OpcionMayorista) => {
    await opcionMayoristaService.elegir(op.id);
    await cargarOpcionesMayorista();
};

const guardarOpcionMayorista = async () => {
    if (!alternativaActiva.value || !formMayorista.value.proveedor_id) return;
    try {
        await opcionMayoristaService.crear(alternativaActiva.value.id, formMayorista.value as any);
        mostrarFormMayorista.value = false;
        formMayorista.value = { proveedor_id: null, moneda: 'USD', vuelo_aerolinea: '', incluye: '' };
        await cargarOpcionesMayorista();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

const guardarHotel = async (op: OpcionMayorista) => {
    try {
        await opcionMayoristaService.crearHotel(op.id, formHotel.value);
        mostrarFormHotel.value = null;
        formHotel.value = { nombre_hotel: '', tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 }] };
        await cargarOpcionesMayorista();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

const tarifasHotelPlanas = (op: OpcionMayorista) => {
    const filas: Array<{ id: number; tipo_habitacion: string; precio: number }> = [];
    (op.opciones_hotel ?? []).forEach((h) => {
        (h.opciones_hotel_tarifas ?? []).forEach((t) => {
            filas.push({ id: t.id, tipo_habitacion: `${h.nombre_hotel} · ${t.tipo_habitacion}`, precio: Number(t.precio_venta) });
        });
    });
    return filas;
};

const agregarItemMayorista = async (op: OpcionMayorista, opcionHotelTarifaId: number, cantidad: number) => {
    if (!alternativaActiva.value) return;
    try {
        const res = await alternativaItemService.agregarMayorista(alternativaActiva.value.id, {
            opcion_mayorista_id: op.id,
            opcion_hotel_tarifa_id: opcionHotelTarifaId,
            cantidad,
        });
        onItemAgregado(res.alternativa_item);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

// ── Ítem manual / pasaje aéreo ────────────────────────────────────────
const mostrarFormManual = ref(false);
const mostrarFormPasajeAereo = ref(false);

const onItemAgregado = async (_item: AlternativaItem) => {
    mostrarFormManual.value = false;
    mostrarFormPasajeAereo.value = false;
    await cargarCotizacion();
};

const eliminarItem = async (item: AlternativaItem) => {
    await alternativaItemService.eliminar(item.id);
    await cargarCotizacion();
};

// ── Edición en vivo (descuento_pct / precio_convertido) ────────────────
const edicionItems = ref<Record<number, { descuento_pct: number; precio_convertido: number }>>({});
const alertasPiso = ref<Record<number, boolean>>({});
const edicionTimeouts: Record<number, any> = {};

const inicializarEdicionItems = () => {
    (alternativaActiva.value?.items ?? []).forEach((item) => {
        edicionItems.value[item.id] = { descuento_pct: Number(item.descuento_pct ?? 0), precio_convertido: Number(item.precio_convertido) };
    });
};

const onEditarDescuento = (item: AlternativaItem) => {
    clearTimeout(edicionTimeouts[item.id]);
    edicionTimeouts[item.id] = setTimeout(() => enviarEdicion(item.id, { descuento_pct: edicionItems.value[item.id].descuento_pct }), 500);
};

const onEditarPrecio = (item: AlternativaItem) => {
    clearTimeout(edicionTimeouts[item.id]);
    edicionTimeouts[item.id] = setTimeout(() => enviarEdicion(item.id, { precio_convertido: edicionItems.value[item.id].precio_convertido }), 500);
};

const enviarEdicion = async (itemId: number, payload: { descuento_pct?: number; precio_convertido?: number }) => {
    try {
        const res = await alternativaItemService.actualizar(itemId, payload);
        alertasPiso.value[itemId] = !!res.alerta_piso;
        edicionItems.value[itemId] = {
            descuento_pct: Number(res.alternativa_item.descuento_pct ?? 0),
            precio_convertido: Number(res.alternativa_item.precio_convertido),
        };
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar', 'error');
    }
};

const iconoItem = (item: AlternativaItem) => {
    if (item.origen_tipo === 'pasaje_aereo') return 'fa-plane';
    if (item.origen_tipo === 'mayorista') return 'fa-plane-departure';
    if (item.origen_tipo === 'manual') return 'fa-pen';
    if (item.proveedor_tarifa?.tipo_habitacion) return 'fa-bed';
    return 'fa-concierge-bell';
};

const etiquetaItem = (item: AlternativaItem) => {
    if (item.origen_tipo === 'manual') return item.descripcion_manual ?? 'Ítem manual';
    if (item.origen_tipo === 'pasaje_aereo') return item.cotizacion_pasaje_aereo?.aerolinea ?? 'Pasaje aéreo';
    if (item.origen_tipo === 'mayorista') return item.opcion_mayorista?.proveedor?.razon_social ?? 'Paquete mayorista';
    return item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'Servicio';
};

watch(modo, (m) => { if (m === 'intl') cargarOpcionesMayorista(); });
watch(alternativaActivaId, () => { inicializarEdicionItems(); if (modo.value === 'intl') cargarOpcionesMayorista(); });

onMounted(async () => {
    await cargarCotizacion();
    await cargarBiblioteca();

    const tipos = await proveedorTipoService.listar();
    const tipoMayorista = tipos.proveedor_tipos.find((t) => t.slug === 'mayorista');
    if (tipoMayorista) {
        const res = await httpClient.get('/proveedores', { params: { tipo_id: tipoMayorista.id } });
        proveedoresMayoristas.value = res.data.proveedores ?? [];
    }
});
</script>

<style scoped>
.price-panel { position: sticky; top: 1rem; }
.lib-item:hover { background: #eef2ff; border-color: #6366f1 !important; }
</style>
