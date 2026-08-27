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
                    <div class="col-12" v-if="proveedor.descripcion">
                        <strong>Descripción:</strong>
                        <div v-html="proveedor.descripcion"></div>
                    </div>
                    <div class="col-12" v-if="esHotel && proveedor.alojamiento_detalle">
                        <strong>Horario:</strong>
                        Check-in {{ proveedor.alojamiento_detalle.hora_checkin ?? '—' }} · Check-out {{ proveedor.alojamiento_detalle.hora_checkout ?? '—' }}
                        <span class="badge bg-light text-dark border ms-2">
                            <i class="fas fa-baby me-1"></i>Infante gratis hasta {{ proveedor.alojamiento_detalle.edad_max_infante_gratis }} años ·
                            cama adicional hasta {{ proveedor.alojamiento_detalle.edad_max_nino_cama_adicional }} años
                        </span>
                    </div>
                    <div class="col-12" v-if="(proveedor.amenidades?.length ?? 0) > 0">
                        <strong>Amenidades:</strong>
                        <span v-for="amenidad in proveedor.amenidades" :key="amenidad.id" class="badge bg-light text-dark border me-1">
                            <i :class="amenidad.icono" class="me-1 text-primary"></i>{{ amenidad.nombre }}
                        </span>
                    </div>
                    <div class="col-12" v-if="(proveedor.fotos?.length ?? 0) > 0">
                        <strong>Fotos:</strong>
                        <div class="d-flex flex-wrap gap-2 mt-1">
                            <img v-for="foto in proveedor.fotos" :key="foto" :src="foto" class="img-thumbnail" style="width:110px;height:110px;object-fit:cover;">
                        </div>
                    </div>
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
                            <DestinoTreeSelect v-model="nuevoDestinoId" nivel-min="zona" />
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
                                    <th class="text-end">Margen</th>
                                    <th class="text-center">Vigencia</th>
                                    <th class="text-center pe-3">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!(tarifasPorServicio[ps.id]?.length)">
                                    <td :colspan="esHotel ? 8 : 7" class="text-center text-muted py-3 fst-italic">Sin tarifas cargadas.</td>
                                </tr>
                                <tr v-for="tarifa in tarifasPorServicio[ps.id]" :key="tarifa.id" :class="{ 'opacity-50': !tarifa.activo }">
                                    <td class="ps-3">{{ tarifa.tipo_tarifa }}</td>
                                    <td>{{ tarifa.modalidad }}</td>
                                    <td v-if="esHotel">{{ tarifa.tipo_habitacion ?? '—' }}</td>
                                    <td class="text-end">{{ tarifa.moneda }} {{ tarifa.precio_costo }}</td>
                                    <td class="text-end">{{ tarifa.moneda }} {{ tarifa.precio_venta_adulto }}</td>
                                    <td class="text-end">
                                        <span class="badge" :class="claseMargen(margenTarifa(tarifa))">{{ margenTarifa(tarifa).toFixed(1) }}%</span>
                                    </td>
                                    <td class="text-center small">
                                        <span class="badge d-block mb-1" :class="tarifa.activo ? 'bg-success' : 'bg-secondary'">{{ tarifa.activo ? 'Activa' : 'Inactiva' }}</span>
                                        <span class="badge d-block mb-1" :class="estadoVigencia(tarifa).clase">{{ estadoVigencia(tarifa).label }}</span>
                                        {{ formatFecha(tarifa.vigente_desde) }} — {{ tarifa.vigente_hasta ? formatFecha(tarifa.vigente_hasta) : 'indefinido' }}
                                    </td>
                                    <td class="text-center pe-3">
                                        <button class="btn btn-sm btn-outline-secondary me-1" @click="abrirFormTarifa(ps, tarifa)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button v-if="tarifa.activo" class="btn btn-sm btn-outline-warning me-1" title="Retirar del catálogo activo (no borra el historial)" @click="desactivarTarifa(tarifa)">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                        <button v-else class="btn btn-sm btn-outline-success me-1" title="Reactivar" @click="activarTarifa(tarifa)">
                                            <i class="fas fa-rotate-left"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" @click="eliminarTarifa(tarifa)">
                                            <i class="fas fa-trash"></i>
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
                        <div class="btn-group mb-3" role="group">
                            <input type="radio" class="btn-check" name="tab-modal-tarifa" id="tab-modal-comercial" :checked="tabModalTarifa === 'comercial'" @click="tabModalTarifa = 'comercial'" autocomplete="off">
                            <label class="btn btn-outline-primary btn-sm" for="tab-modal-comercial"><i class="fas fa-tags me-1"></i>Comercial</label>
                            <input type="radio" class="btn-check" name="tab-modal-tarifa" id="tab-modal-tributario" :checked="tabModalTarifa === 'tributario'" @click="tabModalTarifa = 'tributario'" autocomplete="off">
                            <label class="btn btn-outline-primary btn-sm" for="tab-modal-tributario"><i class="fas fa-file-invoice me-1"></i>Tributario SUNAT</label>
                        </div>

                        <!-- ═══ Comercial ═══ -->
                        <div class="row g-3" v-if="tabModalTarifa === 'comercial'">
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de tarifa</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.tipo_tarifa">
                                    <option value="corporativa">Corporativa</option>
                                    <option value="grupal">Grupal</option>
                                    <option value="publica">Pública</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Modalidad</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.modalidad">
                                    <option value="compartido">Compartido</option>
                                    <option value="privado">Privado</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.moneda">
                                    <option value="PEN">Soles</option>
                                    <option value="USD">Dólares</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3" v-if="esHotel">
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

                            <template v-if="esHotel">
                                <div class="col-6 col-md-4">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
                                    <input type="text" class="form-control form-control-sm" placeholder="Ej. vista al mar, balcón" v-model="formTarifa.descripcion">
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Régimen de comida</label>
                                    <select class="form-select form-select-sm" v-model="formTarifa.regimen_comida">
                                        <option :value="null">—</option>
                                        <option value="solo_alojamiento">Solo alojamiento</option>
                                        <option value="desayuno">Desayuno</option>
                                        <option value="media_pension">Media pensión</option>
                                        <option value="pension_completa">Pensión completa</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de cama</label>
                                    <input type="text" class="form-control form-control-sm" placeholder="Ej. king, 2 twin" v-model="formTarifa.tipo_cama">
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Costo cama adicional</label>
                                    <input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_costo_cama_adicional">
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Venta cama adicional</label>
                                    <input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_venta_cama_adicional">
                                </div>
                                <small class="text-muted col-12">Cama adicional: dejar en blanco si esta habitación no la admite.</small>
                            </template>

                            <div class="col-12"><hr class="my-1"></div>

                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio costo</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_costo" @input="onEditarPrecioCosto">
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
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.margen_valor" @input="recalcularDesdeMargen">
                            </div>

                            <div class="col-12">
                                <div class="alert py-2 px-3 mb-0 small d-flex align-items-center gap-2" :class="margenModalPct >= MARGEN_MINIMO_ACEPTABLE_PCT ? 'alert-success' : 'alert-danger'">
                                    <span>Margen resultante:</span>
                                    <span class="badge" :class="claseMargen(margenModalPct)">{{ margenModalPct.toFixed(1) }}%</span>
                                </div>
                            </div>

                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta adulto</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_venta_adulto" @input="recalcularMargenDesdeAdulto">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta niño</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_venta_nino" @input="tocadoNino = true">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta infante</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.precio_venta_infante" @input="tocadoInfante = true">
                            </div>

                            <div class="col-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Edad mín. niño</label>
                                <input type="number" min="0" class="form-control form-control-sm" v-model.number="formTarifa.edad_min_nino">
                            </div>
                            <div class="col-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Edad máx. niño</label>
                                <input type="number" min="0" class="form-control form-control-sm" v-model.number="formTarifa.edad_max_nino">
                            </div>
                            <div class="col-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Edad máx. infante</label>
                                <input type="number" min="0" class="form-control form-control-sm" v-model.number="formTarifa.edad_max_infante">
                            </div>

                            <!-- Solo captura el dato — el cotizador todavía no lo valida/aplica como tope real. -->
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Descuento máximo</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.descuento_maximo_pct">
                                    <span class="input-group-text">%</span>
                                </div>
                                <small class="text-muted">Tope de descuento que puede aplicar el vendedor en el cotizador sin autorización.</small>
                            </div>
                        </div>

                        <!-- ═══ Tributario SUNAT ═══ -->
                        <div class="row g-3" v-if="tabModalTarifa === 'tributario'">
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Destino tributario</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.destino_tributario">
                                    <option value="nacional">Nacional</option>
                                    <option value="amazonia">Amazonía</option>
                                    <option value="extranjero">Extranjero</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Tip. Afectación IGV</label>
                                <select class="form-select form-select-sm" v-model="formTarifa.tip_afe_igv">
                                    <option value="10">Gravado</option>
                                    <option value="17">IVAP</option>
                                    <option value="20">Exonerado</option>
                                    <option value="30">Inafecto</option>
                                    <option value="40">Exportación</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Vigente desde</label>
                                <input type="date" class="form-control form-control-sm" v-model="formTarifa.vigente_desde">
                            </div>
                            <div class="col-6 col-md-3">
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
import { proveedorService } from '@/services/admin/proveedorService';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import { useAgenciaViajesCatalogosStore } from '@/stores/agenciaViajesCatalogos';
import { formatFecha } from '@/helpers/fecha';
import type { Proveedor, ProveedorTipo, ProveedorServicio, ProveedorTarifa, DestinoServicio, ConfiguracionAgencia } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const proveedorId = computed(() => Number(route.params.id));

const proveedor = ref<Proveedor | null>(null);
const proveedorTipos = ref<ProveedorTipo[]>([]);
const proveedorServicios = ref<ProveedorServicio[]>([]);
const tarifasPorServicio = ref<Record<number, ProveedorTarifa[]>>({});
const configAgencia = ref<ConfiguracionAgencia | null>(null);

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
    // slug='alojamiento-hoteles' es el slug REAL del catálogo proveedor_tipos
    // (confirmado contra datos reales) — 'hotel' nunca matcheaba nada, mismo
    // bug corregido en ProveedorTarifaController::proveedorEsHotel() (backend).
    return tipo?.slug === 'alojamiento-hoteles';
});

// ── Margen resultante (modal + tabla) ────────────────────────────────
const MARGEN_MINIMO_ACEPTABLE_PCT = 20;

const calcularMargenPct = (costo?: number | null, venta?: number | null): number => {
    const c = Number(costo ?? 0);
    if (c <= 0) return 0;
    return ((Number(venta ?? 0) - c) / c) * 100;
};
const claseMargen = (pct: number): string => (pct >= MARGEN_MINIMO_ACEPTABLE_PCT ? 'bg-success' : 'bg-danger');
const margenTarifa = (tarifa: ProveedorTarifa): number => calcularMargenPct(tarifa.precio_costo, tarifa.precio_venta_adulto);

const estadoVigencia = (tarifa: ProveedorTarifa): { label: string; clase: string } => {
    const hoy = new Date().toISOString().slice(0, 10);
    if (tarifa.vigente_hasta && tarifa.vigente_hasta < hoy) return { label: 'Vencida', clase: 'bg-secondary' };
    if (tarifa.vigente_desde > hoy) return { label: 'Programada', clase: 'bg-info' };
    return { label: 'Vigente hoy', clase: 'bg-success' };
};

const cargarProveedor = async () => {
    const res = await proveedorService.obtener(proveedorId.value);
    proveedor.value = res.proveedor;
};

const cargarServicios = async () => {
    const res = await proveedorService.listarServicios(proveedorId.value);
    proveedorServicios.value = res.proveedor_servicios;
    // Antes pedía las tarifas de cada servicio una por una (await dentro del
    // for) — un proveedor con 8-10 servicios asociados disparaba 8-10
    // requests secuenciales antes de poder ver/editar ningún precio. Ninguna
    // depende de las otras, así que van todas en paralelo.
    const tarifasPorPs = await Promise.all(
        proveedorServicios.value.map((ps) => proveedorService.listarTarifas(ps.id))
    );
    proveedorServicios.value.forEach((ps, i) => {
        tarifasPorServicio.value[ps.id] = tarifasPorPs[i].proveedor_tarifas;
    });
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
const tabModalTarifa = ref<'comercial' | 'tributario'>('comercial');

const formTarifa = ref<Partial<ProveedorTarifa>>({});

const margenModalPct = computed(() => calcularMargenPct(formTarifa.value.precio_costo, formTarifa.value.precio_venta_adulto));

// tocadoNino/tocadoInfante: una vez que el usuario edita ese precio a mano,
// el recálculo automático desde margen_valor deja de pisarlo hasta que se
// reabra el modal o se edite precio_costo (tarifa base nueva).
const tocadoNino = ref<boolean>(false);
const tocadoInfante = ref<boolean>(false);

const recalcularDesdeMargen = () => {
    if (formTarifa.value.margen_tipo !== 'porcentaje') return;
    const costo = formTarifa.value.precio_costo;
    const margen = formTarifa.value.margen_valor;
    if (costo == null || margen == null) return;
    const factor = 1 + margen / 100;
    formTarifa.value.precio_venta_adulto = Number((costo * factor).toFixed(2));
    if (!tocadoNino.value) formTarifa.value.precio_venta_nino = Number((costo * factor).toFixed(2));
    if (!tocadoInfante.value) formTarifa.value.precio_venta_infante = Number((costo * factor).toFixed(2));
};

const recalcularMargenDesdeAdulto = () => {
    if (formTarifa.value.margen_tipo !== 'porcentaje') return;
    const costo = formTarifa.value.precio_costo;
    if (!costo) return;
    const venta = formTarifa.value.precio_venta_adulto ?? 0;
    formTarifa.value.margen_valor = Number((((venta - costo) / costo) * 100).toFixed(2));
};

const onEditarPrecioCosto = () => {
    tocadoNino.value = false;
    tocadoInfante.value = false;
    recalcularDesdeMargen();
};

const abrirFormTarifa = (ps: ProveedorServicio, tarifa: ProveedorTarifa | null = null) => {
    proveedorServicioActivo.value = ps;
    tarifaEditando.value = tarifa;
    tabModalTarifa.value = 'comercial';
    tocadoNino.value = false;
    tocadoInfante.value = false;
    formTarifa.value = tarifa
        ? { ...tarifa }
        : {
            tipo_tarifa: 'publica', modalidad: 'compartido', moneda: 'PEN', tipo_habitacion: null,
            descripcion: null, regimen_comida: null, tipo_cama: null,
            precio_costo: 0, margen_tipo: 'porcentaje', margen_valor: 0,
            precio_venta_adulto: 0, precio_venta_nino: null, precio_venta_infante: null,
            precio_costo_cama_adicional: null, precio_venta_cama_adicional: null,
            edad_min_nino: 0,
            edad_max_nino: configAgencia.value?.edad_max_nino ?? null,
            edad_max_infante: configAgencia.value?.edad_max_infante ?? null,
            descuento_maximo_pct: null,
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

const eliminarTarifa = (tarifa: ProveedorTarifa) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar', text: '¿Eliminar esta tarifa?', icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            await proveedorService.eliminarTarifa(tarifa.id);
            await cargarServicios();
        } catch (error: any) {
            // Bloqueado porque ya está en uso (backend, 422) — ofrecer
            // desactivar en su lugar en vez de dejar un error sin salida.
            const mensaje = error.response?.data?.message ?? 'No se pudo eliminar';
            if (error.response?.status === 422) {
                const confirmar = await (Swal as TVueSwalInstance).fire({
                    title: 'No se puede eliminar', text: mensaje, icon: 'warning',
                    showCancelButton: true, confirmButtonText: 'Desactivar en su lugar',
                });
                if (confirmar.isConfirmed) await desactivarTarifa(tarifa);
                return;
            }
            (Swal as TVueSwalInstance).fire('Error', mensaje, 'error');
        }
    });
};

// 26-ago-2026 — retiro/reactivación del catálogo activo, sin tocar
// historial (a diferencia de eliminarTarifa()). Sin confirmación extra:
// nunca rompe nada (el mismo criterio que aplica a un combo con "componente
// inactivo" no aplica acá, una tarifa desactivada no bloquea nada más).
const desactivarTarifa = async (tarifa: ProveedorTarifa) => {
    try {
        await proveedorService.desactivarTarifa(tarifa.id);
        await cargarServicios();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo desactivar', 'error');
    }
};

const activarTarifa = async (tarifa: ProveedorTarifa) => {
    try {
        await proveedorService.activarTarifa(tarifa.id);
        await cargarServicios();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo reactivar', 'error');
    }
};

onMounted(async () => {
    // Las 4 son independientes entre sí — en paralelo. proveedor-tipos y
    // configuracion-agencia salen del cache compartido (TTL 60s, ver
    // src/stores/agenciaViajesCatalogos.ts) en vez de pedirse de cero.
    const catalogos = useAgenciaViajesCatalogosStore();
    const [tipos, config] = await Promise.all([
        catalogos.obtenerProveedorTipos(),
        catalogos.obtenerConfigAgencia(),
        cargarProveedor(),
        cargarServicios(),
    ]);
    proveedorTipos.value = tipos;
    configAgencia.value = config;
});
</script>
