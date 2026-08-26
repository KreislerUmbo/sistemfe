<template>
    <DefaultLayout>
        <div class="mb-4">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fas fa-hashtag me-2 text-primary"></i>
                Códigos y numeración
            </h5>
            <small class="text-muted">
                Formato del código con el que se identifican tours, paquetes, cotizaciones, reservas y ventas directas.
                <router-link :to="{ name: 'agencia.configuracion.index' }">Configurar sigla comercial</router-link>
            </small>
        </div>

        <div v-if="cargando" class="text-center py-5 text-muted">
            <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
        </div>

        <template v-else>
            <div class="card border-0 shadow-sm mb-3" v-for="fila in filas" :key="fila.tipo">
                <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-2">
                    <span class="fw-semibold text-dark">{{ etiquetaTipo(fila.tipo) }}</span>
                    <span v-if="fila.deriva_de" class="badge bg-info-subtle text-info-emphasis">
                        Deriva del código de {{ etiquetaTipo(fila.deriva_de) }}
                    </span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Prefijo</label>
                            <input type="text" class="form-control form-control-sm" v-model="fila.prefijo" maxlength="20">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Separador</label>
                            <input type="text" class="form-control form-control-sm" v-model="fila.separador" maxlength="1">
                        </div>

                        <template v-if="!fila.deriva_de">
                            <div class="col-6 col-md-2">
                                <div class="form-check mt-4">
                                    <input type="checkbox" class="form-check-input" :id="`periodo-${fila.tipo}`" v-model="fila.incluye_periodo">
                                    <label class="form-check-label small" :for="`periodo-${fila.tipo}`">Incluye periodo</label>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Dígitos correlativo</label>
                                <input type="number" min="1" max="15" class="form-control form-control-sm" v-model.number="fila.longitud_correlativo">
                            </div>
                            <div class="col-6 col-md-3" v-if="fila.incluye_periodo">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Reinicia correlativo</label>
                                <select class="form-select form-select-sm" v-model="fila.reinicio_correlativo">
                                    <option value="nunca">Nunca</option>
                                    <option value="mensual">Mensual</option>
                                    <option value="anual">Anual</option>
                                </select>
                            </div>
                        </template>
                        <template v-else>
                            <div class="col-12 col-md-5">
                                <small class="text-muted">No aplica periodo ni correlativo propio — reusa el de la cotización que originó la reserva.</small>
                            </div>
                        </template>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-3">
                        <div class="text-muted small">
                            Próximo código:
                            <span class="fw-semibold text-dark font-monospace">{{ previews[fila.tipo] ?? '...' }}</span>
                        </div>
                        <button class="btn btn-sm btn-primary" @click="guardarFila(fila)" :disabled="guardando === fila.tipo">
                            <span v-if="guardando === fila.tipo" class="spinner-border spinner-border-sm me-1"></span>
                            Guardar
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { configuracionCodigosService } from '@/services/admin/configuracionCodigosService';
import type { ConfiguracionCodigo } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// Orden fijo de despliegue — no depende del orden en que el backend
// devuelva las filas (id ascendente, que coincide hoy, pero no hay que
// asumirlo).
const ORDEN_TIPOS: ConfiguracionCodigo['tipo'][] = ['tour', 'paquete', 'cotizacion', 'reserva', 'venta_directa'];
const ETIQUETAS: Record<string, string> = {
    tour: 'Tour',
    paquete: 'Paquete / Combo',
    cotizacion: 'Cotización',
    reserva: 'Reserva',
    venta_directa: 'Venta directa',
};
const etiquetaTipo = (tipo: string) => ETIQUETAS[tipo] ?? tipo;

const cargando = ref<boolean>(true);
const guardando = ref<string | null>(null);
const filas = reactive<ConfiguracionCodigo[]>([]);
const previews = reactive<Record<string, string>>({});
const timeouts: Record<string, ReturnType<typeof setTimeout>> = {};

const cargar = async () => {
    cargando.value = true;
    try {
        const { configuracion_codigos } = await configuracionCodigosService.obtener();
        const porTipo = new Map(configuracion_codigos.map((f) => [f.tipo, f]));
        filas.splice(0, filas.length, ...ORDEN_TIPOS.map((tipo) => porTipo.get(tipo)).filter((f): f is ConfiguracionCodigo => !!f));

        await Promise.all(filas.map((fila) => actualizarPreview(fila)));
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar la configuración', 'error');
    } finally {
        cargando.value = false;
    }
};

// Vista previa en vivo: refleja lo que hay tipeado en el formulario TODAVÍA
// sin guardar (previsualizar() acepta overrides sin persistirlos), no lo
// último guardado en BD.
const actualizarPreview = async (fila: ConfiguracionCodigo) => {
    try {
        const { proximo_codigo } = await configuracionCodigosService.previsualizar(fila.tipo, {
            prefijo: fila.prefijo,
            separador: fila.separador,
            incluye_periodo: fila.incluye_periodo,
            longitud_correlativo: fila.longitud_correlativo,
        });
        previews[fila.tipo] = proximo_codigo;
    } catch {
        previews[fila.tipo] = '—';
    }
};

watch(filas, () => {
    for (const fila of filas) {
        clearTimeout(timeouts[fila.tipo]);
        timeouts[fila.tipo] = setTimeout(() => actualizarPreview(fila), 400);
    }
}, { deep: true });

const guardarFila = async (fila: ConfiguracionCodigo) => {
    guardando.value = fila.tipo;
    try {
        const { configuracion_codigo } = await configuracionCodigosService.actualizar(fila.tipo, {
            prefijo: fila.prefijo,
            separador: fila.separador,
            incluye_periodo: fila.incluye_periodo,
            longitud_correlativo: fila.longitud_correlativo,
            reinicio_correlativo: fila.reinicio_correlativo,
            activo: fila.activo,
        });
        Object.assign(fila, configuracion_codigo);
        await actualizarPreview(fila);
        (Swal as TVueSwalInstance).fire({ icon: 'success', title: 'Guardado', timer: 1200, showConfirmButton: false });
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = null;
    }
};

onMounted(cargar);
</script>
