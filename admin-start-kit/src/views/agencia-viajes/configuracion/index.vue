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
            <!-- Acordeón (pedido del usuario 2026-08-29: la pantalla se hacía
                 muy extensa hacia abajo con 9 cards siempre expandidas) — un
                 solo panel abierto a la vez dentro de este grupo
                 (data-bs-parent), Bootstrap JS ya está cargado global
                 (main.ts), mismo patrón nativo que ya usa el FAQ del portal
                 (Contactos.vue). Primer panel abierto por defecto para que la
                 pantalla no arranque vacía. -->
            <div class="accordion mb-3" id="accConfigAgencia">
                <!-- Módulo 12 (códigos y numeración) — sigla única de la agencia,
                     leída por Configuración > Códigos y numeración para sugerir el
                     prefijo de cada tipo de documento (T/P/C/R/V + sigla). -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#accComerciales">
                            <span class="badge bg-primary rounded-pill me-2">1</span>
                            <span class="fw-semibold text-dark">Datos comerciales</span>
                        </button>
                    </h2>
                    <div id="accComerciales" class="accordion-collapse collapse show" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-6 col-md-3">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Sigla comercial</label>
                                    <input type="text" class="form-control form-control-sm" v-model="form.sigla_comercial" placeholder="Ej. DKM" maxlength="20">
                                </div>
                                <div class="col-12 col-md-9">
                                    <router-link :to="{ name: 'agencia.configuracion.codigos' }" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-hashtag me-1"></i>Configurar códigos y numeración
                                    </router-link>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">Usada para sugerir el prefijo de tour/paquete/cotización/reserva/venta directa (ej. "DKM" → "TDKM", "PDKM"...).</small>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accPasajeros">
                            <span class="badge bg-primary rounded-pill me-2">2</span>
                            <span class="fw-semibold text-dark">Clasificación de pasajeros</span>
                        </button>
                    </h2>
                    <div id="accPasajeros" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
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
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCotizaciones">
                            <span class="badge bg-primary rounded-pill me-2">3</span>
                            <span class="fw-semibold text-dark">Cotizaciones y cupos</span>
                        </button>
                    </h2>
                    <div id="accCotizaciones" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
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
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accRecordatorios">
                            <span class="badge bg-primary rounded-pill me-2">4</span>
                            <span class="fw-semibold text-dark">Recordatorios</span>
                        </button>
                    </h2>
                    <div id="accRecordatorios" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
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
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accDescuentosPdf">
                            <span class="badge bg-primary rounded-pill me-2">5</span>
                            <span class="fw-semibold text-dark">Descuentos en el PDF</span>
                        </button>
                    </h2>
                    <div id="accDescuentosPdf" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
                            <!-- Sesión 12f-3 (01-sep-2026) — el PDF de cotización dejó de
                                 mostrar precio por ítem, así que el select "Formato"
                                 (solo_final/tachado/separado, pensado para decorar esa fila)
                                 quedó sin ningún efecto visible y se quitó de acá — decisión
                                 confirmada con el usuario para no dejar un control que
                                 aparenta hacer algo y no hace nada. El campo sigue existiendo
                                 en ConfiguracionAgencia (sin editor en esta pantalla). -->
                            <div class="row g-3 align-items-end">
                                <div class="col-12 col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="mostrar-descuento-linea" v-model="form.mostrar_descuento_como_linea">
                                        <label class="form-check-label small" for="mostrar-descuento-linea">Mostrar descuento como línea aparte</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sesión 11i — descuento en el cotizador: por ítem del lienzo y
                     global del resumen, independientes entre sí (ver
                     cotizador/editar.vue, Punto B/C). -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accDescuentoCotizador">
                            <span class="badge bg-primary rounded-pill me-2">6</span>
                            <span class="fw-semibold text-dark">Descuento en el cotizador</span>
                        </button>
                    </h2>
                    <div id="accDescuentoCotizador" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
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
                </div>

                <!-- Sesión 11o — defaults precargados al crear un OpcionHotel
                     nuevo (cama adicional para niños), editables después por
                     hotel específico (ver paquetes/detalle.vue, tab Hoteles). -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accHoteles">
                            <span class="badge bg-primary rounded-pill me-2">7</span>
                            <span class="fw-semibold text-dark">Hoteles — cama adicional para niños</span>
                        </button>
                    </h2>
                    <div id="accHoteles" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
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
                </div>

                <!-- Condiciones generales del servicio — texto propio de la
                     agencia, se descarga aparte de la parte comercial de una
                     cotización (groundwork para el PDF de cotización, sesión
                     futura). -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCondiciones">
                            <span class="badge bg-primary rounded-pill me-2">8</span>
                            <span class="fw-semibold text-dark">Condiciones generales del servicio</span>
                        </button>
                    </h2>
                    <div id="accCondiciones" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
                            <RichTextEditor v-model="form.condiciones_generales_servicio" placeholder="Escribe las condiciones generales del servicio..." />
                        </div>
                    </div>
                </div>

                <!-- Análisis de impuestos (28-ago-2026) — default para prellenar el
                     tratamiento tributario al crear un ítem sin proveedor_tarifa
                     propia (manual/mayorista/guia/pasaje_aereo). Pensado para
                     agencias en Amazonía (caso común exonerado), sin dejar de
                     permitir el caso ocasional fuera de la región — el default
                     solo prellena, cada ítem lo puede cambiar antes de guardar. -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accTributario">
                            <span class="badge bg-primary rounded-pill me-2">9</span>
                            <span class="fw-semibold text-dark">Tratamiento tributario por defecto</span>
                        </button>
                    </h2>
                    <div id="accTributario" class="accordion-collapse collapse" data-bs-parent="#accConfigAgencia">
                        <div class="accordion-body">
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Tratamiento</label>
                                    <select class="form-select form-select-sm" v-model="form.tip_afe_igv_default">
                                        <option value="10">Gravado</option>
                                        <option value="20">Exonerado</option>
                                        <option value="30">Inafecto</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                                    <select class="form-select form-select-sm" v-model="form.destino_tributario_default">
                                        <option value="amazonia">Amazonía</option>
                                        <option value="nacional">Nacional</option>
                                        <option value="extranjero">Extranjero</option>
                                    </select>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">Se usa para prellenar ítems manuales, de mayorista, de guía y pasaje aéreo al crearlos — cada ítem lo puede cambiar antes de guardar. Los servicios con tarifa de proveedor registrada siguen tomando su propio tratamiento tributario, ya obligatorio al cargar la tarifa.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <button class="btn btn-primary fw-semibold" @click="guardar" :disabled="guardando">
                    <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="fas fa-save me-2"></i>
                    Guardar configuración
                </button>
            </div>

            <!-- Cuentas bancarias — cada cuenta se guarda/edita/elimina de
                 forma independiente (no con el botón general de arriba).
                 Acordeón propio (grupo aparte) para no interferir con el de
                 arriba — mantiene el botón "+ Agregar cuenta" en el header. -->
            <div class="accordion mb-3" id="accCuentasBancarias">
                <div class="accordion-item">
                    <h2 class="accordion-header d-flex align-items-center">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCuentas">
                            <span class="badge bg-primary rounded-pill me-2">10</span>
                            <span class="fw-semibold text-dark">Cuentas bancarias</span>
                        </button>
                        <button class="btn btn-sm btn-outline-primary flex-shrink-0 me-3" @click="abrirFormularioCuenta()">
                            <i class="fas fa-plus me-1"></i>Agregar cuenta
                        </button>
                    </h2>
                    <div id="accCuentas" class="accordion-collapse collapse" data-bs-parent="#accCuentasBancarias">
                        <div class="accordion-body">
                            <div v-if="mostrarFormCuenta" class="border rounded p-3 mb-3 bg-light-subtle">
                                <div class="row g-2">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label mb-1 small fw-semibold text-secondary">Banco</label>
                                        <input type="text" class="form-control form-control-sm" v-model="formCuenta.banco">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label mb-1 small fw-semibold text-secondary">Titular</label>
                                        <input type="text" class="form-control form-control-sm" v-model="formCuenta.titular">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label mb-1 small fw-semibold text-secondary">N° de cuenta</label>
                                        <input type="text" class="form-control form-control-sm" v-model="formCuenta.numero_cuenta">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label mb-1 small fw-semibold text-secondary">CCI</label>
                                        <input type="text" class="form-control form-control-sm" v-model="formCuenta.cci">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label mb-1 small fw-semibold text-secondary">Alias</label>
                                        <input type="text" class="form-control form-control-sm" v-model="formCuenta.alias" placeholder="Ej. Yape/Plin">
                                    </div>
                                    <div class="col-6 col-md-3 d-flex align-items-center">
                                        <div class="form-check form-switch mt-3">
                                            <input class="form-check-input" type="checkbox" id="cuenta-activa" v-model="formCuenta.activo">
                                            <label class="form-check-label small" for="cuenta-activa">Activo</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button class="btn btn-sm btn-outline-secondary" @click="cerrarFormularioCuenta">Cancelar</button>
                                    <button class="btn btn-sm btn-primary" @click="guardarCuenta" :disabled="guardandoCuenta">
                                        <span v-if="guardandoCuenta" class="spinner-border spinner-border-sm me-1"></span>
                                        {{ cuentaEditandoId ? 'Actualizar' : 'Guardar' }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="cargandoCuentas" class="text-center py-3 text-muted">
                                <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                            </div>
                            <div v-else-if="cuentasBancarias.length === 0" class="text-muted small fst-italic">
                                Sin cuentas bancarias registradas.
                            </div>
                            <div v-else class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr class="text-secondary small">
                                            <th>Banco</th>
                                            <th>Titular</th>
                                            <th>N° cuenta</th>
                                            <th>CCI</th>
                                            <th>Alias</th>
                                            <th class="text-center">Activo</th>
                                            <th class="text-end">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="cuenta in cuentasBancarias" :key="cuenta.id">
                                            <td>{{ cuenta.banco }}</td>
                                            <td>{{ cuenta.titular }}</td>
                                            <td>{{ cuenta.numero_cuenta }}</td>
                                            <td>{{ cuenta.cci || '—' }}</td>
                                            <td>{{ cuenta.alias || '—' }}</td>
                                            <td class="text-center">
                                                <span class="badge" :class="cuenta.activo ? 'bg-success' : 'bg-secondary'">
                                                    {{ cuenta.activo ? 'Sí' : 'No' }}
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-secondary me-1" @click="abrirFormularioCuenta(cuenta)">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" @click="eliminarCuenta(cuenta)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { configuracionAgenciaService } from '@/services/admin/configuracionAgenciaService';
import { cuentaBancariaService } from '@/services/admin/cuentaBancariaService';
import { useAgenciaViajesCatalogosStore } from '@/stores/agenciaViajesCatalogos';
import type { ConfiguracionAgencia, CuentaBancaria } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const cargando = ref<boolean>(true);
const guardando = ref<boolean>(false);
const form = ref<ConfiguracionAgencia>({
    sigla_comercial: null,
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
    condiciones_generales_servicio: null,
    tip_afe_igv_default: '10',
    destino_tributario_default: 'nacional',
});

const cuentasBancarias = ref<CuentaBancaria[]>([]);
const cargandoCuentas = ref<boolean>(true);
const guardandoCuenta = ref<boolean>(false);
const mostrarFormCuenta = ref<boolean>(false);
const cuentaEditandoId = ref<number | null>(null);
const formCuentaVacio = (): Partial<CuentaBancaria> => ({
    banco: '',
    titular: '',
    numero_cuenta: '',
    cci: '',
    alias: '',
    activo: true,
});
const formCuenta = ref<Partial<CuentaBancaria>>(formCuentaVacio());

const cargarCuentas = async () => {
    cargandoCuentas.value = true;
    try {
        const res = await cuentaBancariaService.listar();
        cuentasBancarias.value = res.cuentas_bancarias;
    } finally {
        cargandoCuentas.value = false;
    }
};

const abrirFormularioCuenta = (cuenta?: CuentaBancaria) => {
    if (cuenta) {
        cuentaEditandoId.value = cuenta.id;
        formCuenta.value = { ...cuenta };
    } else {
        cuentaEditandoId.value = null;
        formCuenta.value = formCuentaVacio();
    }
    mostrarFormCuenta.value = true;
};

const cerrarFormularioCuenta = () => {
    mostrarFormCuenta.value = false;
    cuentaEditandoId.value = null;
    formCuenta.value = formCuentaVacio();
};

const guardarCuenta = async () => {
    if (!formCuenta.value.banco?.trim() || !formCuenta.value.titular?.trim() || !formCuenta.value.numero_cuenta?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'Banco, titular y número de cuenta son obligatorios.', 'error');
        return;
    }

    guardandoCuenta.value = true;
    try {
        const res = cuentaEditandoId.value
            ? await cuentaBancariaService.actualizar(cuentaEditandoId.value, formCuenta.value)
            : await cuentaBancariaService.crear(formCuenta.value);
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        cerrarFormularioCuenta();
        await cargarCuentas();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar la cuenta', 'error');
    } finally {
        guardandoCuenta.value = false;
    }
};

const eliminarCuenta = async (cuenta: CuentaBancaria) => {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: '¿Eliminar cuenta bancaria?',
        text: `${cuenta.banco} — ${cuenta.numero_cuenta}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirmacion.isConfirmed) return;

    try {
        const res = await cuentaBancariaService.eliminar(cuenta.id);
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        await cargarCuentas();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la cuenta', 'error');
    }
};

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
        // Sin esto, paquetes/detalle.vue y cotizador/editar.vue seguirían
        // sirviendo el margen_minimo/etc. viejo desde el cache compartido
        // (TTL 60s) hasta que expire solo — ver agenciaViajesCatalogos.ts.
        useAgenciaViajesCatalogosStore().invalidarConfigAgencia();
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(() => {
    cargar();
    cargarCuentas();
});
</script>
