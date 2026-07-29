<template>
    <DefaultLayout>
        <div v-if="proveedor" class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-truck-loading me-2 text-primary"></i>
                    {{ proveedor.razon_social }}
                </h5>
                <small class="text-muted">{{ nombreTipo(proveedor.tipo_id) }}</small>
            </div>
            <div class="d-flex gap-2">
                <router-link :to="`/agencia-viajes/proveedores/${proveedor.id}/editar`" class="btn btn-outline-secondary">
                    <i class="fas fa-pen me-2"></i>Editar
                </router-link>
                <router-link to="/agencia-viajes/proveedores" class="btn btn-outline-secondary">
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
                <a class="nav-link" :class="{ active: tabActiva === 'servicios' }" href="#" @click.prevent="tabActiva = 'servicios'">
                    <i class="fas fa-map-marked-alt me-1"></i>Servicios y Tarifas
                </a>
            </li>
        </ul>

        <!-- ═══ TAB: Datos ═══ -->
        <div v-if="tabActiva === 'datos' && proveedor" class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-md-4"><strong>Nombre comercial:</strong> {{ proveedor.nombre_comercial ?? '—' }}</div>
                    <div class="col-md-4"><strong>Documento:</strong> {{ proveedor.tipo_documento ?? '—' }} {{ proveedor.numero_documento ?? '' }}</div>
                    <div class="col-md-4"><strong>Estado:</strong> {{ proveedor.estado ? 'Activo' : 'Inactivo' }}</div>
                    <div class="col-md-4"><strong>Teléfono:</strong> {{ proveedor.telefono ?? '—' }}</div>
                    <div class="col-md-4"><strong>WhatsApp:</strong> {{ proveedor.whatsapp ?? '—' }}</div>
                    <div class="col-md-4"><strong>Email:</strong> {{ proveedor.email ?? '—' }}</div>
                    <div class="col-md-8"><strong>Dirección:</strong> {{ proveedor.direccion ?? '—' }}</div>
                    <div class="col-12" v-if="proveedor.observaciones"><strong>Observaciones:</strong> {{ proveedor.observaciones }}</div>
                </div>
            </div>
        </div>

        <!-- ═══ TAB: Servicios y Tarifas ═══ -->
        <div v-if="tabActiva === 'servicios'">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="badge bg-primary rounded-pill">1</span>
                    <span class="fw-semibold text-dark">Asociar servicio a destino</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Destino / Atractivo</label>
                            <DestinoTreeSelect v-model="nuevoDestinoId" nivel-min="lugar" />
                        </div>
                        <div class="col-8 col-md-4">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Servicio</label>
                            <select class="form-select form-select-sm" v-model="nuevoServicioId" :disabled="!nuevoDestinoId">
                                <option :value="null">— Selecciona —</option>
                                <option v-for="ds in destinoServicios" :key="ds.id" :value="ds.id">{{ ds.servicio?.nombre }}</option>
                            </select>
                            <small class="text-muted" v-if="nuevoDestinoId && destinoServicios.length === 0">
                                Este destino todavía no tiene ningún servicio asociado.
                            </small>
                        </div>
                        <div class="col-4 col-md-3">
                            <button class="btn btn-primary btn-sm w-100" @click="asociarServicio" :disabled="!nuevoDestinoId || !nuevoServicioId">
                                <i class="fas fa-plus me-1"></i>Asociar
                            </button>
                        </div>
                    </div>
                    <small class="text-muted">El destino/atractivo debe tener este servicio ya cargado ahí para poder elegirlo aquí — ver <router-link to="/agencia-viajes/destinos">Destinos</router-link>.</small>
                </div>
            </div>

            <div v-for="ps in proveedorServicios" :key="ps.id" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-2">
                    <span class="fw-semibold text-dark">
                        <i class="fas fa-map-marker-alt me-1 text-primary"></i>
                        {{ ps.destino_servicio?.destino_atractivo?.nombre }} — {{ ps.destino_servicio?.servicio?.nombre }}
                    </span>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" @click="abrirFormTarifa(ps)">
                            <i class="fas fa-plus me-1"></i>Nueva tarifa
                        </button>
                        <button class="btn btn-sm btn-outline-danger" @click="quitarServicio(ps)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr class="small text-secondary text-uppercase">
                                    <th class="ps-3">Tipo</th>
                                    <th>Modalidad</th>
                                    <th v-if="esHotel">Habitación</th>
                                    <th class="text-end">Costo</th>
                                    <th class="text-end">Venta Adulto</th>
                                    <th class="text-center">Vigencia</th>
                                    <th class="text-center pe-3">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!(tarifasPorServicio[ps.id]?.length)">
                                    <td :colspan="esHotel ? 7 : 6" class="text-center text-muted py-3 fst-italic">Sin tarifas cargadas.</td>
                                </tr>
                                <tr v-for="tarifa in tarifasPorServicio[ps.id]" :key="tarifa.id">
                                    <td class="ps-3">{{ tarifa.tipo_tarifa }}</td>
                                    <td>{{ tarifa.modalidad }}</td>
                                    <td v-if="esHotel">{{ tarifa.tipo_habitacion ?? '—' }}</td>
                                    <td class="text-end">{{ tarifa.moneda }} {{ tarifa.precio_costo }}</td>
                                    <td class="text-end">{{ tarifa.moneda }} {{ tarifa.precio_venta_adulto }}</td>
                                    <td class="text-center small">
                                        {{ tarifa.vigente_desde }} — {{ tarifa.vigente_hasta ?? 'indefinido' }}
                                    </td>
                                    <td class="text-center pe-3">
                                        <button class="btn btn-sm btn-outline-secondary" @click="abrirFormTarifa(ps, tarifa)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="proveedorServicios.length === 0" class="text-muted fst-italic text-center py-4">
                Este proveedor todavía no tiene servicios asociados.
            </div>
        </div>

        <!-- Modal simple de tarifa (crear/editar) -->
        <div v-if="modalTarifaAbierto" class="modal d-block" style="background:rgba(0,0,0,.5)" @click.self="modalTarifaAbierto = false">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ tarifaEditando ? 'Editar' : 'Nueva' }} Tarifa</h6>
                        <button class="btn-close" @click="modalTarifaAbierto = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de tarifa</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.tipo_tarifa">
                                    <option value="corporativa">Corporativa</option>
                                    <option value="grupal">Grupal</option>
                                    <option value="publica">Pública</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Modalidad</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.modalidad">
                                    <option value="compartido">Compartido</option>
                                    <option value="privado">Privado</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.moneda">
                                    <option value="PEN">Soles</option>
                                    <option value="USD">Dólares</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4" v-if="esHotel">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de habitación *</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.tipo_habitacion">
                                    <option :value="null">— Selecciona —</option>
                                    <option value="simple">Simple</option>
                                    <option value="matrimonial">Matrimonial</option>
                                    <option value="doble">Doble</option>
                                    <option value="triple">Triple</option>
                                    <option value="familiar">Familiar</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio costo</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_costo">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Margen tipo</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.margen_tipo">
                                    <option value="porcentaje">Porcentaje</option>
                                    <option value="fijo">Fijo</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Margen valor</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.margen_valor">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta adulto</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_venta_adulto">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta niño</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_venta_nino">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta infante</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_venta_infante">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Destino tributario</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.destino_tributario">
                                    <option value="nacional">Nacional</option>
                                    <option value="amazonia">Amazonía</option>
                                    <option value="extranjero">Extranjero</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Tip. Afectación IGV</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.tip_afe_igv">
                                    <option value="10">Gravado</option>
                                    <option value="17">IVAP</option>
                                    <option value="20">Exonerado</option>
                                    <option value="30">Inafecto</option>
                                    <option value="40">Exportación</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Vigente desde</label>
                                <input type="date" class="form-control form-control-sm" v-model="formTarifa.vigente_desde">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Vigente hasta</label>
                                <input type="date" class="form-control form-control-sm" v-model="formTarifa.vigente_hasta">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" @click="modalTarifaAbierto = false">Cancelar</button>
                        <button class="btn btn-primary" @click="guardarTarifa">Guardar</button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import type { Proveedor, ProveedorTipo, ProveedorServicio, ProveedorTarifa, DestinoServicio } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const proveedorId = computed(() => Number(route.params.id));

const proveedor = ref<Proveedor | null>(null);
const proveedorTipos = ref<ProveedorTipo[]>([]);
const proveedorServicios = ref<ProveedorServicio[]>([]);
const tarifasPorServicio = ref<Record<number, ProveedorTarifa[]>>({});

const tabActiva = ref<'datos' | 'servicios'>('datos');

const nuevoDestinoId = ref<number | null>(null);
const nuevoServicioId = ref<number | null>(null);
// Servicios ya asociados al destino elegido (destino_servicio real) — nuevoServicioId
// termina siendo el id de esta tabla puente, NUNCA el id crudo de Servicio (eso rompía
// la asociación: ProveedorServicioController::store() valida destino_servicio_id contra
// la tabla destino_servicio, no contra servicios).
const destinoServicios = ref<DestinoServicio[]>([]);

watch(nuevoDestinoId, async (destinoId) => {
    nuevoServicioId.value = null;
    destinoServicios.value = destinoId ? (await destinoAtractivoService.listarServicios(destinoId)).destino_servicios : [];
});

const nombreTipo = (tipoId: number) => proveedorTipos.value.find((t) => t.id === tipoId)?.nombre ?? '—';
const esHotel = computed(() => {
    const tipo = proveedorTipos.value.find((t) => t.id === proveedor.value?.tipo_id);
    return tipo?.slug === 'hotel';
});

const cargarProveedor = async () => {
    const res = await proveedorService.obtener(proveedorId.value);
    proveedor.value = res.proveedor;
};

const cargarServicios = async () => {
    const res = await proveedorService.listarServicios(proveedorId.value);
    proveedorServicios.value = res.proveedor_servicios;
    for (const ps of proveedorServicios.value) {
        const tarifasRes = await proveedorService.listarTarifas(ps.id);
        tarifasPorServicio.value[ps.id] = tarifasRes.proveedor_tarifas;
    }
};

const asociarServicio = async () => {
    try {
        await proveedorService.asociarServicio(proveedorId.value, nuevoServicioId.value!);
        nuevoDestinoId.value = null;
        nuevoServicioId.value = null;
        await cargarServicios();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo asociar', 'error');
    }
};

const quitarServicio = (ps: ProveedorServicio) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar', text: '¿Quitar este servicio del proveedor?', icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Sí, quitar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            await proveedorService.desasociarServicio(proveedorId.value, ps.id);
            await cargarServicios();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo quitar', 'error');
        }
    });
};

// ── Tarifas (modal) ──────────────────────────────────────────────────
const modalTarifaAbierto = ref<boolean>(false);
const tarifaEditando = ref<ProveedorTarifa | null>(null);
const proveedorServicioActivo = ref<ProveedorServicio | null>(null);

const formTarifa = ref<Partial<ProveedorTarifa>>({});

const abrirFormTarifa = (ps: ProveedorServicio, tarifa: ProveedorTarifa | null = null) => {
    proveedorServicioActivo.value = ps;
    tarifaEditando.value = tarifa;
    formTarifa.value = tarifa
        ? { ...tarifa }
        : {
            tipo_tarifa: 'publica', modalidad: 'compartido', moneda: 'PEN', tipo_habitacion: null,
            precio_costo: 0, margen_tipo: 'porcentaje', margen_valor: 0,
            precio_venta_adulto: 0, precio_venta_nino: null, precio_venta_infante: null,
            destino_tributario: 'nacional', tip_afe_igv: '10',
            vigente_desde: new Date().toISOString().slice(0, 10), vigente_hasta: null,
        };
    modalTarifaAbierto.value = true;
};

const guardarTarifa = async () => {
    if (!proveedorServicioActivo.value) return;
    try {
        const res = tarifaEditando.value
            ? await proveedorService.actualizarTarifa(tarifaEditando.value.id, formTarifa.value)
            : await proveedorService.crearTarifa(proveedorServicioActivo.value.id, formTarifa.value);

        modalTarifaAbierto.value = false;
        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        await cargarServicios();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

onMounted(async () => {
    const tiposRes = await proveedorTipoService.listar();
    proveedorTipos.value = tiposRes.proveedor_tipos;

    await cargarProveedor();
    await cargarServicios();
});
</script>
