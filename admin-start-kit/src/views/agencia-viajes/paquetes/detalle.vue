<template>
    <DefaultLayout>
        <div v-if="paquete" class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>
                    {{ paquete.nombre }}
                </h5>
                <small class="text-muted">{{ paquete.codigo ?? 'sin código' }} · {{ etiquetaCategoria(paquete.categoria) }}</small>
            </div>
            <div class="d-flex gap-2">
                <router-link :to="`/agencia-viajes/paquetes/${paquete.id}/editar`" class="btn btn-outline-secondary">
                    <i class="fas fa-pen me-2"></i>Editar
                </router-link>
                <router-link to="/agencia-viajes/paquetes" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </router-link>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'datos' }" href="#" @click.prevent="tabActiva = 'datos'">
                    <i class="fas fa-id-card me-1"></i>Datos
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'itinerario' }" href="#" @click.prevent="tabActiva = 'itinerario'">
                    <i class="fas fa-route me-1"></i>Itinerario
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'incluye' }" href="#" @click.prevent="tabActiva = 'incluye'">
                    <i class="fas fa-list-check me-1"></i>Incluye
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'hoteles' }" href="#" @click.prevent="tabActiva = 'hoteles'">
                    <i class="fas fa-bed me-1"></i>Hoteles
                </a>
            </li>
        </ul>

        <!-- ═══ TAB: Datos ═══ -->
        <div v-if="tabActiva === 'datos' && paquete" class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-md-4"><strong>Destino:</strong> {{ paquete.destino_atractivo?.nombre ?? '—' }}</div>
                    <div class="col-md-4"><strong>Duración:</strong> {{ paquete.duracion_horas }} h</div>
                    <div class="col-md-4"><strong>Horario:</strong> {{ paquete.hora_salida ?? '—' }} — {{ paquete.hora_retorno ?? '—' }}</div>
                    <div class="col-md-8"><strong>Lugar de recojo:</strong> {{ paquete.lugar_recojo ?? '—' }}</div>
                    <div class="col-md-4"><strong>Precio desde:</strong> {{ paquete.precio_venta_final != null ? `S/ ${Number(paquete.precio_venta_final).toFixed(2)}` : '—' }}</div>
                    <div class="col-12" v-if="paquete.descripcion"><strong>Descripción:</strong> {{ paquete.descripcion }}</div>
                    <div class="col-md-6" v-if="paquete.no_incluye"><strong>No incluye:</strong> {{ paquete.no_incluye }}</div>
                    <div class="col-md-6" v-if="paquete.recomendaciones"><strong>Recomendaciones:</strong> {{ paquete.recomendaciones }}</div>
                    <div class="col-12" v-if="paquete.vuelo_incluido">
                        <strong>Vuelo:</strong> {{ paquete.vuelo_aerolinea ?? '—' }} — {{ paquete.vuelo_detalle ?? '' }}
                    </div>
                    <div class="col-md-4"><strong>Vigencia:</strong> {{ paquete.vigencia_desde ?? 'sin inicio' }} — {{ paquete.vigencia_hasta ?? 'indefinida' }}</div>
                    <div class="col-md-4"><strong>Publicado web:</strong> {{ paquete.publicado_web ? 'Sí' : 'No' }}</div>
                </div>
            </div>
        </div>

        <!-- ═══ TAB: Itinerario ═══ -->
        <div v-if="tabActiva === 'itinerario'">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="fw-semibold text-dark small">Agregar paso</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Día</label>
                            <input type="number" min="1" class="form-control form-control-sm" v-model.number="formPaso.dia_relativo">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Hora</label>
                            <input type="time" class="form-control form-control-sm" v-model="formPaso.hora">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Orden</label>
                            <input type="number" min="0" class="form-control form-control-sm" v-model.number="formPaso.orden" placeholder="si no hay hora">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Destino/Atractivo</label>
                            <DestinoTreeSelect v-model="formPaso.destino_atractivo_id" placeholder="Opcional..." />
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Descripción *</label>
                            <input type="text" class="form-control form-control-sm" v-model="formPaso.descripcion" placeholder="Ej. Visita orquideario">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm mt-2" @click="agregarPaso">
                        <i class="fas fa-plus me-1"></i>Agregar paso
                    </button>
                </div>
            </div>

            <div v-for="(pasos, dia) in itinerarioPorDia" :key="dia" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom small fw-semibold">Día {{ dia }}</div>
                <ul class="list-group list-group-flush">
                    <li v-for="paso in pasos" :key="paso.id" class="list-group-item d-flex justify-content-between align-items-center small">
                        <span>
                            <span v-if="paso.hora" class="badge bg-light text-dark border me-2">{{ paso.hora }}</span>
                            <span v-else-if="paso.orden != null" class="badge bg-light text-dark border me-2">#{{ paso.orden }}</span>
                            {{ paso.descripcion }}
                            <span v-if="paso.destino_atractivo" class="text-muted"> — {{ paso.destino_atractivo.nombre }}</span>
                        </span>
                        <i class="fas fa-times text-danger" style="cursor:pointer" @click="quitarPaso(paso)"></i>
                    </li>
                </ul>
            </div>
            <div v-if="itinerario.length === 0" class="text-muted fst-italic text-center py-4">
                Este paquete/tour todavía no tiene itinerario cargado.
            </div>
        </div>

        <!-- ═══ TAB: Incluye ═══ -->
        <div v-if="tabActiva === 'incluye'">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small">Agregar ítem incluido</span>
                </div>
                <div class="card-body py-3">
                    <div class="btn-group btn-group-sm mb-2">
                        <button class="btn" :class="tipoItemNuevo === 'proveedor' ? 'btn-primary' : 'btn-outline-secondary'" @click="tipoItemNuevo = 'proveedor'">Servicio de proveedor</button>
                        <button class="btn" :class="tipoItemNuevo === 'guia' ? 'btn-primary' : 'btn-outline-secondary'" @click="tipoItemNuevo = 'guia'">Guía de turismo</button>
                    </div>

                    <div v-if="tipoItemNuevo === 'proveedor'">
                        <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar servicio de proveedor..."
                            v-model="bibliotecaSearch" @input="onBibliotecaSearch">
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in bibliotecaTarifas" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': proveedorTarifaSeleccionada?.id === t.id }"
                                style="cursor:pointer" @click="proveedorTarifaSeleccionada = t">
                                <strong>{{ t.proveedor_servicio?.proveedor?.razon_social }}</strong>
                                <span class="text-muted"> — {{ t.proveedor_servicio?.destino_servicio?.servicio?.nombre }}<span v-if="t.tipo_habitacion"> · {{ t.tipo_habitacion }}</span></span>
                            </div>
                            <div v-if="bibliotecaTarifas.length === 0" class="text-muted small text-center py-2">Sin resultados.</div>
                        </div>
                    </div>

                    <div v-else>
                        <select class="form-select form-select-sm mb-2" v-model="guiaSeleccionadaId" @change="cargarTarifasGuia">
                            <option :value="null">— Elegí un guía —</option>
                            <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}</option>
                        </select>
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in tarifasGuia" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': guiaTarifaSeleccionada?.id === t.id }"
                                style="cursor:pointer" @click="guiaTarifaSeleccionada = t">
                                {{ t.destino?.nombre }} — {{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }} ({{ t.moneda }} {{ t.costo_diario }})
                            </div>
                            <div v-if="guiaSeleccionadaId && tarifasGuia.length === 0" class="text-muted small text-center py-2">Este guía no tiene tarifas cargadas.</div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end mt-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Orden</label>
                            <input type="number" min="0" class="form-control form-control-sm" v-model.number="ordenItemNuevo">
                        </div>
                        <div class="col-6 col-md-3">
                            <button class="btn btn-primary btn-sm w-100" @click="agregarItem" :disabled="!proveedorTarifaSeleccionada && !guiaTarifaSeleccionada">
                                <i class="fas fa-plus me-1"></i>Agregar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <ul class="list-group list-group-flush">
                    <li v-for="item in items" :key="item.id" class="list-group-item d-flex justify-content-between align-items-center small">
                        <span v-if="item.proveedor_tarifa">
                            <i class="fas fa-concierge-bell text-primary me-1"></i>
                            {{ item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social }}
                            <span class="text-muted"> — {{ item.proveedor_tarifa.proveedor_servicio?.destino_servicio?.servicio?.nombre }}<span v-if="item.proveedor_tarifa.tipo_habitacion"> · {{ item.proveedor_tarifa.tipo_habitacion }}</span></span>
                        </span>
                        <span v-else-if="item.guia_tarifa">
                            <i class="fas fa-user-tie text-primary me-1"></i>
                            Guía: {{ item.guia_tarifa.guia?.nombre }}
                            <span class="text-muted"> — {{ item.guia_tarifa.destino?.nombre }}</span>
                        </span>
                        <i class="fas fa-times text-danger" style="cursor:pointer" @click="quitarItem(item)"></i>
                    </li>
                    <li v-if="items.length === 0" class="list-group-item text-muted fst-italic text-center py-4">
                        Este paquete/tour todavía no tiene ítems incluidos.
                    </li>
                </ul>
            </div>
        </div>

        <!-- ═══ TAB: Hoteles ═══ -->
        <div v-if="tabActiva === 'hoteles'">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small">Agregar hotel</span>
                </div>
                <div class="card-body py-3">
                    <input type="text" class="form-control form-control-sm mb-2" placeholder="Nombre del hotel" v-model="formHotel.nombre_hotel">
                    <div class="row g-2 mb-2">
                        <div class="col-6 col-md-4">
                            <input type="number" min="1" max="5" class="form-control form-control-sm" placeholder="Estrellas" v-model.number="formHotel.categoria_estrellas">
                        </div>
                    </div>
                    <div v-for="(tf, idx) in formHotel.tarifas" :key="idx" class="row g-1 mb-1">
                        <div class="col-4">
                            <select class="form-select form-select-sm" v-model="tf.tipo_habitacion">
                                <option value="simple">Simple</option>
                                <option value="matrimonial">Matrimonial</option>
                                <option value="doble">Doble</option>
                                <option value="triple">Triple</option>
                                <option value="familiar">Familiar</option>
                            </select>
                        </div>
                        <div class="col-4"><input type="number" class="form-control form-control-sm" placeholder="Costo" v-model.number="tf.precio_costo"></div>
                        <div class="col-3"><input type="number" class="form-control form-control-sm" placeholder="Venta" v-model.number="tf.precio_venta"></div>
                        <div class="col-1">
                            <button class="btn btn-sm btn-outline-danger" @click="formHotel.tarifas.splice(idx, 1)" :disabled="formHotel.tarifas.length === 1">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary mb-2" @click="formHotel.tarifas.push({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 })">
                        + tipo de habitación
                    </button>
                    <div>
                        <button class="btn btn-primary btn-sm" @click="guardarHotel">Guardar hotel</button>
                    </div>
                </div>
            </div>

            <div v-for="hotel in hoteles" :key="hotel.id" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                    <span class="fw-semibold text-dark small">
                        <i class="fas fa-hotel me-1 text-primary"></i>{{ hotel.nombre_hotel }}
                        <span v-if="hotel.categoria_estrellas" class="text-warning ms-1">
                            <i v-for="n in hotel.categoria_estrellas" :key="n" class="fas fa-star" style="font-size:10px"></i>
                        </span>
                    </span>
                    <i class="fas fa-times text-danger" style="cursor:pointer" @click="quitarHotel(hotel)"></i>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Habitación</th>
                                <th class="text-end">Costo</th>
                                <th class="text-end pe-3">Venta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tarifa in hotel.opciones_hotel_tarifas" :key="tarifa.id" class="small">
                                <td class="ps-3 text-capitalize">{{ tarifa.tipo_habitacion }}</td>
                                <td class="text-end">{{ tarifa.precio_costo }}</td>
                                <td class="text-end pe-3">{{ tarifa.precio_venta }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="hoteles.length === 0" class="text-muted fst-italic text-center py-4">
                Este paquete/tour todavía no tiene hoteles cargados.
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import { proveedorService } from '@/services/admin/proveedorService';
import { guiaService } from '@/services/admin/guiaService';
import type { PaquetePlantilla, PaquetePlantillaItem, TourItinerarioItem, OpcionHotel, ProveedorTarifa, Guia, GuiaTarifa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const paqueteId = computed(() => Number(route.params.id));

const paquete = ref<PaquetePlantilla | null>(null);
const tabActiva = ref<'datos' | 'itinerario' | 'incluye' | 'hoteles'>('datos');

const etiquetaCategoria = (c: string) => ({ local: 'Local', nacional: 'Nacional', internacional: 'Internacional' } as Record<string, string>)[c] ?? c;

const cargarPaquete = async () => {
    const res = await paquetePlantillaService.obtener(paqueteId.value);
    paquete.value = res.paquete_plantilla;
    hoteles.value = res.opciones_hotel;
};

// ── Itinerario ────────────────────────────────────────────────────────
const itinerario = ref<TourItinerarioItem[]>([]);
const formPaso = ref<{ dia_relativo: number; hora: string | null; orden: number | null; destino_atractivo_id: number | null; descripcion: string }>({
    dia_relativo: 1, hora: null, orden: null, destino_atractivo_id: null, descripcion: '',
});

const itinerarioPorDia = computed(() => {
    const grupos: Record<number, TourItinerarioItem[]> = {};
    for (const paso of itinerario.value) {
        (grupos[paso.dia_relativo] ??= []).push(paso);
    }
    return grupos;
});

const cargarItinerario = async () => {
    const res = await paquetePlantillaService.listarItinerario(paqueteId.value);
    itinerario.value = res.tour_itinerario_items;
};

const agregarPaso = async () => {
    if (!formPaso.value.descripcion.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La descripción del paso es obligatoria.', 'error');
        return;
    }
    try {
        await paquetePlantillaService.agregarPasoItinerario(paqueteId.value, formPaso.value);
        formPaso.value = { dia_relativo: formPaso.value.dia_relativo, hora: null, orden: null, destino_atractivo_id: null, descripcion: '' };
        await cargarItinerario();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

const quitarPaso = async (paso: TourItinerarioItem) => {
    await paquetePlantillaService.quitarPasoItinerario(paso.id);
    await cargarItinerario();
};

// ── Incluye (items) ──────────────────────────────────────────────────
const items = ref<PaquetePlantillaItem[]>([]);
const tipoItemNuevo = ref<'proveedor' | 'guia'>('proveedor');
const ordenItemNuevo = ref<number | null>(null);

const bibliotecaSearch = ref('');
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const proveedorTarifaSeleccionada = ref<ProveedorTarifa | null>(null);
let bibliotecaTimeout: any = null;

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(cargarBiblioteca, 300);
};

const cargarBiblioteca = async () => {
    const res = await proveedorService.biblioteca(bibliotecaSearch.value || undefined);
    bibliotecaTarifas.value = res.proveedor_tarifas;
};

const guias = ref<Guia[]>([]);
const guiaSeleccionadaId = ref<number | null>(null);
const tarifasGuia = ref<GuiaTarifa[]>([]);
const guiaTarifaSeleccionada = ref<GuiaTarifa | null>(null);

const cargarTarifasGuia = async () => {
    guiaTarifaSeleccionada.value = null;
    tarifasGuia.value = [];
    if (!guiaSeleccionadaId.value) return;
    const res = await guiaService.obtener(guiaSeleccionadaId.value);
    tarifasGuia.value = res.guia.guia_tarifas ?? [];
};

const cargarItems = async () => {
    const res = await paquetePlantillaService.listarItems(paqueteId.value);
    items.value = res.paquete_plantilla_items;
};

const agregarItem = async () => {
    try {
        await paquetePlantillaService.agregarItem(paqueteId.value, {
            proveedor_tarifa_id: proveedorTarifaSeleccionada.value?.id ?? undefined,
            guia_tarifa_id: guiaTarifaSeleccionada.value?.id ?? undefined,
            orden: ordenItemNuevo.value ?? undefined,
        });
        proveedorTarifaSeleccionada.value = null;
        guiaTarifaSeleccionada.value = null;
        ordenItemNuevo.value = null;
        await cargarItems();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

const quitarItem = async (item: PaquetePlantillaItem) => {
    await paquetePlantillaService.quitarItem(item.id);
    await cargarItems();
};

// ── Hoteles ───────────────────────────────────────────────────────────
const hoteles = ref<OpcionHotel[]>([]);
const formHotel = ref<{ nombre_hotel: string; categoria_estrellas: number | null; tarifas: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number }> }>({
    nombre_hotel: '', categoria_estrellas: null, tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 }],
});

const guardarHotel = async () => {
    if (!formHotel.value.nombre_hotel.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre del hotel es obligatorio.', 'error');
        return;
    }
    try {
        await paquetePlantillaService.agregarHotel(paqueteId.value, formHotel.value);
        formHotel.value = { nombre_hotel: '', categoria_estrellas: null, tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 }] };
        await cargarPaquete();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

const quitarHotel = async (hotel: OpcionHotel) => {
    await paquetePlantillaService.quitarHotel(hotel.id);
    await cargarPaquete();
};

onMounted(async () => {
    await cargarPaquete();
    await cargarItinerario();
    await cargarItems();
    await cargarBiblioteca();

    const res = await guiaService.listar({});
    guias.value = res.guias ?? [];
});
</script>
