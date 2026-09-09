<template>
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-calculator me-1"></i>Calculadora de tipo de cambio</h6>
                    <button class="btn-close" @click="$emit('close')"></button>
                </div>
                <div class="modal-body">
                    <small class="text-muted d-block mb-3">
                        Solo para consultarle al cliente cuánto es en otra moneda — no cambia la moneda
                        de esta alternativa ni ningún ítem de la cotización.
                    </small>

                    <div class="d-flex justify-content-between align-items-baseline mb-3">
                        <span class="small text-secondary">Total de la alternativa</span>
                        <span class="fs-5 fw-semibold">{{ monedaCotizacion }} {{ totalAlternativa.toFixed(2) }}</span>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de cambio a usar</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">PEN por USD</span>
                            <input type="number" step="0.0001" min="0.0001" class="form-control" v-model.number="tipoCambio">
                        </div>
                        <small v-if="cargandoActual" class="text-muted">Cargando último tipo de cambio registrado…</small>
                        <small v-else-if="fechaUltimoRegistrado" class="text-muted">
                            Prellenado con el último registrado el {{ fechaUltimoRegistrado }} ({{ origenUltimoRegistrado }}).
                        </small>
                        <small v-else class="text-warning">
                            <i class="fas fa-exclamation-triangle me-1"></i>Todavía no hay ningún tipo de cambio registrado — ingresalo a mano.
                        </small>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="small text-secondary">Resultado en {{ monedaDestino }}</span>
                        <span class="fs-4 fw-semibold text-primary">{{ monedaDestino }} {{ resultado.toFixed(2) }}</span>
                    </div>
                    <small class="text-muted d-block">
                        {{ monedaCotizacion }} {{ totalAlternativa.toFixed(2) }} {{ monedaCotizacion === 'USD' ? '×' : '÷' }} {{ tipoCambio || 0 }} = {{ monedaDestino }} {{ resultado.toFixed(2) }}
                        · calculado hoy {{ fechaHoy }}
                    </small>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-secondary" @click="$emit('close')">Cerrar</button>
                    <button class="btn btn-sm btn-outline-primary" :disabled="!tipoCambio || guardando" @click="guardarTipoCambio()">
                        <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="fas fa-save me-1"></i>Guardar este tipo de cambio
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// 07-sep-2026 — pedido real del usuario: puede necesitar decirle a un
// cliente cuánto es su cotización (toda en una moneda) en la otra moneda,
// y no tenía forma de saber "qué tipo de cambio habré guardado" para hacer
// esa cuenta. Calculadora de bolsillo: NUNCA toca la alternativa ni sus
// ítems — solo lee/opcionalmente guarda un TipoCambioAgencia histórico,
// igual que el que ya usa AlternativaController::resolverTipoCambio() al
// crear una alternativa nueva. Guardar es una acción explícita separada del
// cálculo (el usuario aprobó este diseño), nunca implícita.
import { ref, computed, onMounted } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { tipoCambioAgenciaService } from '@/services/admin/tipoCambioAgenciaService';

const props = defineProps<{ monedaCotizacion: 'PEN' | 'USD'; totalAlternativa: number }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const tipoCambio = ref<number | null>(null);
const cargandoActual = ref(true);
const guardando = ref(false);
const fechaUltimoRegistrado = ref<string | null>(null);
const origenUltimoRegistrado = ref<string>('');

const monedaDestino = computed(() => (props.monedaCotizacion === 'USD' ? 'PEN' : 'USD'));
const fechaHoy = new Date().toLocaleDateString('es-PE');

// tipoCambio siempre es "PEN por 1 USD" (mismo criterio que
// PriceEngineService::convertirMoneda()) — de USD a PEN se multiplica, de
// PEN a USD se divide. Nunca invertir el valor mostrado/guardado según la
// dirección, solo la operación.
const resultado = computed(() => {
    const tc = tipoCambio.value || 0;
    if (!tc) return 0;
    return props.monedaCotizacion === 'USD' ? props.totalAlternativa * tc : props.totalAlternativa / tc;
});

onMounted(async () => {
    try {
        const res = await tipoCambioAgenciaService.obtenerActual();
        const ultimo = res.tipo_cambio_agencia;
        if (ultimo) {
            tipoCambio.value = Number(ultimo.valor);
            fechaUltimoRegistrado.value = new Date(ultimo.fecha).toLocaleDateString('es-PE');
            origenUltimoRegistrado.value = ultimo.origen === 'dia' ? 'tipo de cambio del día' : 'registrado por la agencia';
        }
    } catch {
        // sin bloquear la calculadora — el vendedor igual puede tipear el valor a mano
    } finally {
        cargandoActual.value = false;
    }
});

const guardarTipoCambio = async (confirmado = false) => {
    if (!tipoCambio.value) return;
    guardando.value = true;
    try {
        await tipoCambioAgenciaService.guardar({ valor: tipoCambio.value, origen: 'agencia', confirmado });
        Swal.fire({ icon: 'success', title: 'Tipo de cambio guardado', timer: 1500, showConfirmButton: false });
    } catch (error: any) {
        // El backend pide un segundo paso consciente cuando el valor cae
        // fuera del rango de sanidad (2.0-6.0 USD/PEN) — no bloquea, solo
        // exige confirmar explícitamente que no es un error de tipeo.
        if (error.response?.status === 422 && error.response?.data?.requiere_confirmacion) {
            guardando.value = false;
            const confirmacion = await Swal.fire({
                icon: 'warning',
                title: 'Valor fuera de lo esperado',
                text: error.response.data.message,
                showCancelButton: true,
                confirmButtonText: 'Guardar igual',
                cancelButtonText: 'Corregir',
            });
            if (confirmacion.isConfirmed) {
                await guardarTipoCambio(true);
            }
            return;
        }
        Swal.fire('Error', error.response?.data?.message ?? 'No se pudo guardar el tipo de cambio', 'error');
    } finally {
        guardando.value = false;
    }
};
</script>
