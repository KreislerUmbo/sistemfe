<template>
    <DefaultLayout>
        <div class="mb-4">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fas fa-cogs me-2 text-primary"></i>
                Configuración de la Agencia
            </h5>
            <small class="text-muted">Valores por defecto que usan cotizaciones, reservas y recordatorios</small>
        </div>

        <div v-if="cargando" class="text-center py-5 text-muted">
            <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
        </div>

        <template v-else>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="badge bg-primary rounded-pill">1</span>
                    <span class="fw-semibold text-dark">Clasificación de pasajeros</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Edad máx. infante</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.edad_max_infante">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Edad máx. niño</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.edad_max_nino">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Meses margen doc. de viaje</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.meses_margen_vencimiento_documento">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="badge bg-primary rounded-pill">2</span>
                    <span class="fw-semibold text-dark">Cotizaciones y cupos</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Días vigencia cotización</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.dias_vigencia_cotizacion" placeholder="Sin límite">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Días limpieza alt. descartadas</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.dias_limpieza_alternativas_descartadas" placeholder="Sin límite">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Máx. pax por reserva (con vuelo)</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.max_pax_reserva_con_vuelo">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Máx. pax por reserva (grupo)</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.max_pax_reserva_grupo">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Margen mínimo aceptable (%)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="form.margen_minimo_aceptable_pct">
                            <small class="text-muted d-block mt-1">Usado en el tab "Incluye" de un tour para marcar en rojo el margen resultante bajo este umbral.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="badge bg-primary rounded-pill">3</span>
                    <span class="fw-semibold text-dark">Recordatorios</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Días aviso pago a proveedor</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.dias_aviso_pago_proveedor">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Días cotización estancada</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="form.dias_cotizacion_estancada">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="badge bg-primary rounded-pill">4</span>
                    <span class="fw-semibold text-dark">Descuentos en el PDF</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Formato</label>
                            <select class="form-select form-select-sm" v-model="form.formato_descuento_pdf">
                                <option value="solo_final">Solo precio final</option>
                                <option value="tachado">Precio tachado + final</option>
                                <option value="separado">Final + % aparte</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 d-flex align-items-center">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="mostrar-descuento-linea" v-model="form.mostrar_descuento_como_linea">
                                <label class="form-check-label small" for="mostrar-descuento-linea">Mostrar descuento como línea aparte</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sesión 11i — descuento en el cotizador: por ítem del lienzo y
                 global del resumen, independientes entre sí (ver
                 cotizador/editar.vue, Punto B/C). -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="badge bg-primary rounded-pill">5</span>
                    <span class="fw-semibold text-dark">Descuento en el cotizador</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4 d-flex align-items-center">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="permitir-descuento-item" v-model="form.permitir_descuento_item">
                                <label class="form-check-label small" for="permitir-descuento-item">Permitir descuento por ítem</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Modo de descuento por ítem</label>
                            <select class="form-select form-select-sm" v-model="form.modo_descuento_item" :disabled="!form.permitir_descuento_item">
                                <option value="porcentaje">Porcentaje (%)</option>
                                <option value="monto">Monto fijo</option>
                            </select>
                            <small class="text-muted d-block mt-1" v-if="!form.permitir_descuento_item">
                                Con esto desactivado, el vendedor edita el precio de venta directo — sin lenguaje de descuento en el lienzo.
                            </small>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Modo de descuento global</label>
                            <select class="form-select form-select-sm" v-model="form.modo_descuento_global">
                                <option value="porcentaje">Porcentaje (%)</option>
                                <option value="monto">Monto fijo</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sesión 11o — defaults precargados al crear un OpcionHotel
                 nuevo (cama adicional para niños), editables después por
                 hotel específico (ver paquetes/detalle.vue, tab Hoteles). -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="badge bg-primary rounded-pill">6</span>
                    <span class="fw-semibold text-dark">Hoteles — cama adicional para niños</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Edad máx. infante gratis (default)</label>
                            <input type="number" min="0" class="form-control form-control-sm" v-model.number="form.edad_max_infante_gratis_hotel_default">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Edad máx. cama adicional (default)</label>
                            <input type="number" min="0" class="form-control form-control-sm" v-model.number="form.edad_max_nino_cama_adicional_hotel_default">
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">Se precargan al crear un hotel nuevo en un tour/paquete — cada hotel puede editarlos después con su propio criterio.</small>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <button class="btn btn-primary fw-semibold" @click="guardar" :disabled="guardando">
                    <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="fas fa-save me-2"></i>
                    Guardar configuración
                </button>
            </div>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { configuracionAgenciaService } from '@/services/admin/configuracionAgenciaService';
import type { ConfiguracionAgencia } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const cargando = ref<boolean>(true);
const guardando = ref<boolean>(false);
const form = ref<ConfiguracionAgencia>({
    edad_max_infante: 2,
    edad_max_nino: 12,
    formato_descuento_pdf: 'solo_final',
    mostrar_descuento_como_linea: false,
    dias_vigencia_cotizacion: null,
    dias_limpieza_alternativas_descartadas: null,
    max_pax_reserva_con_vuelo: 15,
    max_pax_reserva_grupo: 50,
    meses_margen_vencimiento_documento: 6,
    dias_aviso_pago_proveedor: 2,
    dias_cotizacion_estancada: 15,
    permitir_descuento_item: true,
    modo_descuento_item: 'porcentaje',
    modo_descuento_global: 'porcentaje',
    margen_minimo_aceptable_pct: 20,
    edad_max_infante_gratis_hotel_default: 4,
    edad_max_nino_cama_adicional_hotel_default: 12,
});

const cargar = async () => {
    cargando.value = true;
    try {
        const res = await configuracionAgenciaService.obtener();
        form.value = res.configuracion_agencia;
    } finally {
        cargando.value = false;
    }
};

const guardar = async () => {
    guardando.value = true;
    try {
        const res = await configuracionAgenciaService.actualizar(form.value);
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(() => cargar());
</script>
