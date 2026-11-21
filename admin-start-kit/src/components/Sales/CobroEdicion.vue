<template>
    <div class="d-flex flex-column gap-3">
        <div class="btn-group btn-group-sm w-100" role="group">
            <button type="button" class="btn" :class="typePayment == 1 ? 'btn-success' : 'btn-outline-secondary'"
                @click="typePayment = 1">Contado</button>
            <button type="button" class="btn" :class="typePayment == 2 ? 'btn-success' : 'btn-outline-secondary'"
                @click="typePayment = 2">Crédito</button>
        </div>

        <!-- Anticipo (comprobante previo): campo propio de la edición; no es el mecanismo de Adelantos de registro -->
        <div v-if="condicionEspecial === 'anticipo'">
            <label class="form-label mb-1 small fw-semibold text-secondary">
                <i class="fas fa-receipt me-1"></i>N° Comprobante de Anticipo
            </label>
            <div class="input-group input-group-sm">
                <input type="text" class="form-control form-control-sm" v-model="nComprobanteAnticipo"
                    placeholder="Ej: F001-00000123" :disabled="saleAnticipo > 0" @keyup.enter="searchAnticipo()" />
                <button v-if="saleAnticipo > 0" type="button" class="btn btn-outline-danger btn-sm" @click="resetAnticipo()">
                    <i class="fas fa-times"></i>
                </button>
                <button v-else type="button" class="btn btn-outline-primary btn-sm" @click="searchAnticipo()">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>

        <!-- Venta a crédito con cuotas cobradas o adelanto aplicado — bloqueada -->
        <div class="alert alert-danger py-2 px-3 mb-0 small" v-if="typePayment == 2 && tieneCobrosFormales">
            <i class="fas fa-lock me-1"></i>
            Esta venta ya tiene cuotas cobradas y/o un adelanto aplicado — no se pueden editar
            productos ni el pago inicial desde aquí. Para corregir un cobro real, anúlalo primero
            desde <strong>Cuentas por Cobrar</strong> (cuota/pago) o el módulo de <strong>Adelantos</strong>.
        </div>

        <!-- Pago nuevo: se registra al instante (POST sale_payments), no al guardar la venta -->
        <div class="row g-2">
            <div class="col-12" v-if="typePayment == 2 && !tieneCobrosFormales">
                <p class="small text-muted mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Esto registra un <strong>pago inicial (cuota inicial) opcional</strong>, no el cobro
                    completo de la venta — se descuenta del monto que se financiará con el cronograma de
                    cuotas. El seguimiento de cobros de la venta a crédito se hace después, desde
                    Cuentas por Cobrar.
                </p>
            </div>
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">
                    {{ typePayment == 2 ? 'Método (pago inicial)' : 'Método' }}
                </label>
                <select v-model="methodPayment" class="form-select form-select-sm" :disabled="typePayment == 2 && tieneCobrosFormales">
                    <option v-for="pm in metodos" :key="pm.id" :value="pm.code">{{ pm.name }}</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">
                    {{ typePayment == 2 ? 'Monto (pago inicial)' : 'Monto' }}
                </label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">{{ moneda }}</span>
                    <input type="number" class="form-control" v-model.number="amount" placeholder="0.00"
                        :disabled="typePayment == 2 && tieneCobrosFormales" />
                </div>
            </div>
            <div class="col-6" v-if="typePayment == 2">
                <label class="form-label mb-1 small fw-semibold text-secondary">Fecha de pago</label>
                <CampoFecha v-model="datePayment" etiqueta="pago" :disabled="tieneCobrosFormales" />
            </div>
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">N.º de operación</label>
                <input type="text" v-model="nroOperacion" maxlength="50" class="form-control form-control-sm"
                    placeholder="Opcional" :disabled="typePayment == 2 && tieneCobrosFormales" />
            </div>
            <div class="col-12">
                <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-semibold"
                    :disabled="typePayment == 2 && tieneCobrosFormales" @click="addPayment()">
                    <i class="fas fa-plus me-1"></i>{{ typePayment == 2 ? 'Agregar pago inicial' : 'Agregar pago' }}
                </button>
            </div>
        </div>

        <!-- Configuración de crédito — solo editable si no hay cobros formales aún -->
        <div class="row g-2 cronograma-campos" v-if="typePayment == 2 && !tieneCobrosFormales">
            <div class="col-12">
                <div class="alert alert-warning py-2 px-3 mb-0 small">
                    <i class="fas fa-info-circle me-1"></i>
                    Cualquier cambio en esta venta (productos, precios o pago inicial) invalida el
                    cronograma actual — hay que regenerarlo antes de guardar. Las cuotas viejas
                    quedan anuladas, no se pierden (quedan en el historial).
                </div>
            </div>
            <CronogramaCuotasCampos v-model="cronograma" v-model:num-cuotas="numCuotas" v-model:periodicidad="periodicidad"
                :monto-a-financiar="montoAFinanciar" :moneda="moneda" :fecha-base="fechaBase" />
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="aplica-mora-edit" v-model="aplicaMora">
                    <label class="form-check-label small fw-semibold text-secondary" for="aplica-mora-edit">Aplica mora</label>
                </div>
            </div>
            <template v-if="aplicaMora">
                <div class="col-5">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Tasa de mora</label>
                    <input type="number" class="form-control form-control-sm" v-model.number="tasaMora" step="0.01" min="0" />
                </div>
                <div class="col-7">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de mora</label>
                    <select v-model="tipoMora" class="form-select form-select-sm">
                        <option value="fijo_por_cuota">Monto fijo por cuota vencida</option>
                        <option value="porcentaje_diario">% diario sobre saldo vencido</option>
                        <option value="porcentaje_fijo_unico">% fijo único sobre saldo vencido</option>
                    </select>
                </div>
            </template>
        </div>

        <!-- Pagos registrados -->
        <div>
            <p class="small fw-semibold text-secondary mb-1">
                <i class="fas fa-list-ul me-1"></i>
                {{ typePayment == 2 ? 'Pago(s) inicial(es) registrado(s)' : 'Pagos registrados' }}
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr class="small text-secondary text-uppercase">
                            <th>#</th>
                            <th>Método</th>
                            <th>N.º operación</th>
                            <th class="text-end">Monto</th>
                            <th v-if="typePayment == 2">Fecha</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="pagos.length === 0">
                            <td colspan="6" class="text-center text-muted fst-italic py-3 small">
                                {{ typePayment == 2 ? 'Sin pago inicial' : 'Sin pagos aún' }}
                            </td>
                        </tr>
                        <tr v-for="(p, index) in pagos" :key="index">
                            <td class="text-muted small">{{ index + 1 }}</td>
                            <td class="fw-semibold small">{{ p.method_payment }}</td>
                            <td class="small text-muted">{{ p.nro_operacion || "—" }}</td>
                            <td class="text-end fw-bold">{{ moneda }} {{ p.amount }}</td>
                            <td v-if="typePayment == 2" class="small text-muted">{{ p.date_payment }}</td>
                            <td>
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                    :disabled="typePayment == 2 && tieneCobrosFormales" @click="removePayment(index)">
                                    <i class="fas fa-trash-alt small"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Cobro de la EDICIÓN de una venta (T-110). Es el cobro de edit.vue movido tal cual, sin cambiar su comportamiento
// (por eso no usa CobroContado/CobroCredito de registro, que arman los pagos en memoria y los mandan al guardar):
// - un pago nuevo se registra AL INSTANTE (POST sale_payments) y un pago se borra al instante (DELETE sale_payments/{id});
// - el anticipo usa el campo propio de la edición (n_comprobante_anticipo / amount_anticipo), no el mecanismo de Adelantos.
// Los modelos son las mismas variables de edit.vue; al agregar o quitar un pago avisa con `cambio` para recalcular totales.
import { ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import CampoFecha from '@/components/CampoFecha.vue';
import CronogramaCuotasCampos from '@/components/CronogramaCuotasCampos.vue';
import type { SalePayment } from '@/types/sales';
import type { PaymentMethod } from '@/types/cash';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    saleId: string;
    metodos: PaymentMethod[];
    moneda: string;
    /** Fecha de emisión de la venta: base de las fechas de las cuotas. */
    fechaBase: string;
    /** Lo que queda por cobrar: total de la venta menos el anticipo (los pagos no pueden superarlo). */
    totalVenta: number;
    /** La venta está guardada a crédito: sale_payments rechaza POST/DELETE aunque en pantalla se pase a Contado. */
    guardadaACredito: boolean;
    totalPagado: number;
    /** Lo que financia el cronograma (total − anticipo − pagos). */
    montoAFinanciar: number;
    /** Cuotas cobradas y/o adelanto aplicado: la venta a crédito no se puede editar desde aquí. */
    tieneCobrosFormales: boolean;
    condicionEspecial: string;
}>();
const emit = defineEmits<{ (e: 'cambio'): void }>();

const typePayment = defineModel<number>('typePayment', { required: true });
const pagos = defineModel<SalePayment[]>('pagos', { required: true });
const cronograma = defineModel<{ numero_cuota: number; monto_programado: number; fecha_vencimiento: string }[]>('cronograma', { required: true });
const numCuotas = defineModel<number>('numCuotas', { required: true });
const periodicidad = defineModel<string>('periodicidad', { required: true });
const aplicaMora = defineModel<boolean>('aplicaMora', { required: true });
const tasaMora = defineModel<number>('tasaMora', { required: true });
const tipoMora = defineModel<string>('tipoMora', { required: true });
const nComprobanteAnticipo = defineModel<string>('nComprobanteAnticipo', { required: true });
const saleAnticipo = defineModel<number>('saleAnticipo', { required: true });

// Formulario del pago nuevo
const methodPayment = ref<string>('EFECTIVO');
const amount = ref<number>(0);
const datePayment = ref<string>('');
const nroOperacion = ref<string>(''); // T-010: opcional, max 50

// Al cambiar contado/crédito se limpia el formulario del pago (igual que antes).
watch(typePayment, () => {
    setTimeout(() => {
        methodPayment.value = '';
        amount.value = 0;
        datePayment.value = '';
        nroOperacion.value = '';
    }, 50);
});

// ── Anticipo ──────────────────────────────────────────────────────
const searchAnticipo = () => {
    if (!nComprobanteAnticipo.value.trim()) {
        (Swal as TVueSwalInstance).fire('Advertencia', 'Ingresa un número de comprobante.', 'warning');
        return;
    }
    (Swal as TVueSwalInstance).fire('Buscando...', 'Función pendiente de implementar.', 'info');
};

const resetAnticipo = () => {
    nComprobanteAnticipo.value = '';
    saleAnticipo.value = 0;
};

// ── PAGOS ───────────────────────────────────────────────────────────
// Crédito en pantalla o venta guardada a crédito: los pagos se arman en memoria (ver addPayment()).
const pagosEnMemoria = (): boolean => typePayment.value == 2 || props.guardadaACredito;

const addPayment = () => {
    if (!methodPayment.value) {
        (Swal as TVueSwalInstance).fire('Error', 'El método de pago es obligatorio.', 'error'); return;
    }
    if (amount.value <= 0) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto debe ser mayor a 0.', 'error'); return;
    }
    if (typePayment.value == 2 && !datePayment.value) {
        (Swal as TVueSwalInstance).fire('Error', 'La fecha de pago es obligatoria en crédito.', 'error'); return;
    }
    if (props.totalVenta < (amount.value + props.totalPagado)) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto no puede superar lo que queda por cobrar de la venta.', 'error'); return;
    }
    if (methodPayment.value === 'EFECTIVO' && pagos.value.some(p => p.method_payment === 'EFECTIVO')) {
        (Swal as TVueSwalInstance).fire('Error', 'Ya hay un pago en efectivo registrado.', 'error'); return;
    }

    const limpiarFormulario = () => setTimeout(() => {
        methodPayment.value = '';
        amount.value = 0;
        datePayment.value = '';
        nroOperacion.value = '';
        emit('cambio');
    }, 50);

    // T-116: en crédito el pago inicial queda en memoria y lo guarda «Guardar cambios»
    // (PATCH sales/{id} reemplaza todos los pagos con los del payload). POST sale_payments
    // rechaza con 422 las ventas guardadas a crédito, también si en pantalla se pasó a Contado.
    if (pagosEnMemoria()) {
        pagos.value.push({
            method_payment: methodPayment.value,
            amount: amount.value,
            date_payment: datePayment.value,
            nro_operacion: nroOperacion.value.trim() || null,
        } as SalePayment);
        limpiarFormulario();
        return;
    }

    // Contado: el pago se registra al instante en el backend (decisión del 20-nov, T-110)
    const data = {
        sale_id: props.saleId,
        method_payment: methodPayment.value,
        amount: amount.value,
        date_payment: datePayment.value,
        nro_operacion: nroOperacion.value.trim() || null
    };

    httpClient.post('sale_payments', data)
        .then(res => {
            pagos.value.push(res.data.payment);
            limpiarFormulario();
        })
        .catch(err => {
            (Swal as TVueSwalInstance).fire('Error', err.response?.data?.message || 'Error al agregar pago', 'error');
        });
};

const removePayment = (index: number) => {
    const payment = pagos.value[index];
    if (!payment) return;
    // T-116: en crédito (o un pago que todavía no está en el backend) se quita de la lista y
    // el cambio se guarda con «Guardar cambios»; DELETE sale_payments rechaza ventas a crédito.
    if (pagosEnMemoria() || !payment.id) {
        pagos.value.splice(index, 1);
        emit('cambio');
        return;
    }
    (Swal as TVueSwalInstance)
        .fire({
            title: "Confirmar eliminación",
            text: `¿Estás seguro de eliminar el pago ${payment.method_payment}?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Sí, eliminar",
            cancelButtonText: "Cancelar"
        })
        .then(async (result: any) => {
            if (result.isConfirmed) {
                try {
                    await httpClient.delete(`sale_payments/${payment.id}`);
                    pagos.value.splice(index, 1);
                    (Swal as TVueSwalInstance).fire('Eliminado', 'Pago eliminado correctamente', 'success');
                    emit('cambio');
                } catch (err: any) {
                    (Swal as TVueSwalInstance).fire('Error', err.response?.data?.message || 'Error al eliminar', 'error');
                }
            }
        });
};
</script>

<style scoped>
/* El panel mide ~380px: las columnas «md» del componente de cuotas se llevan a 2 por fila (igual que CobroCredito). */
.cronograma-campos :deep([class*='col-md-']),
.cronograma-campos :deep(.col-6) { flex: 0 0 50%; max-width: 50%; }
.cronograma-campos :deep(.col-12) { flex: 0 0 100%; max-width: 100%; }
</style>
