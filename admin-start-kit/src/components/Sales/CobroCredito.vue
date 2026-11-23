<template>
    <div class="d-flex flex-column gap-2">
        <div class="cronograma-campos">
            <div class="row g-2">
                <CronogramaCuotasCampos v-model="cronograma" v-model:num-cuotas="numCuotas" v-model:periodicidad="periodicidad"
                    :monto-a-financiar="montoAFinanciar" :moneda="moneda" :fecha-base="fechaBase" permitir-una-cuota />
            </div>
        </div>

        <div class="row g-2">
            <div class="col-5">
                <label class="form-label small fw-semibold text-secondary mb-1">Método (pago inicial)</label>
                <select v-model="metodo" class="form-select form-select-sm">
                    <option v-for="m in metodos" :key="m.id" :value="m.code">{{ m.name }}</option>
                </select>
            </div>
            <div class="col-7">
                <label class="form-label small fw-semibold text-secondary mb-1" for="ccr-ini">Pago inicial (opcional)</label>
                <input id="ccr-ini" type="number" class="form-control form-control-sm" min="0" step="0.10" placeholder="0.00"
                    :value="inicial || ''" @change="cambiarInicial($event)" />
            </div>
        </div>
        <div v-if="inicial > 0 && metodo !== 'EFECTIVO'">
            <input v-model="nroOperacion" maxlength="50" class="form-control form-control-sm" placeholder="N.º de operación (opcional)" />
        </div>

        <button type="button" class="btn btn-link btn-sm p-0 align-self-start" @click="verMora = !verMora">
            {{ verMora ? 'Ocultar mora' : 'Aplicar mora' }}{{ aplicaMora ? ' (activa)' : '' }}
        </button>
        <div v-if="verMora" class="row g-2">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input id="ccr-mora" class="form-check-input" type="checkbox" role="switch" v-model="aplicaMora" />
                    <label class="form-check-label small fw-semibold text-secondary" for="ccr-mora">Aplica mora</label>
                </div>
            </div>
            <template v-if="aplicaMora">
                <div class="col-5">
                    <label class="form-label small fw-semibold text-secondary mb-1">Tasa de mora</label>
                    <input type="number" class="form-control form-control-sm" v-model.number="tasaMora" step="0.01" min="0" />
                </div>
                <div class="col-7">
                    <label class="form-label small fw-semibold text-secondary mb-1">Tipo de mora</label>
                    <select v-model="tipoMora" class="form-select form-select-sm">
                        <option value="fijo_por_cuota">Monto fijo por cuota vencida</option>
                        <option value="porcentaje_diario">% diario sobre saldo vencido</option>
                        <option value="porcentaje_fijo_unico">% fijo único sobre saldo vencido</option>
                    </select>
                </div>
            </template>
        </div>
        <div class="small text-muted">Los cobros siguientes se registran en Cuentas por Cobrar.</div>
    </div>
</template>

<script setup lang="ts">
// Cobro a crédito (T-106): cuotas con CronogramaCuotasCampos + pago inicial opcional + mora detrás de un enlace.
// Solo cuotas fijas (como hoy). El pago inicial sale en `pagos` con el mismo formato que usaba addPayment().
import { ref, watch, watchEffect } from 'vue';
import CronogramaCuotasCampos from '@/components/CronogramaCuotasCampos.vue';
import type { PaymentMethod } from '@/types/cash';
import { redondear2 } from '@/helpers/ventaRetail';

const props = defineProps<{
    metodos: PaymentMethod[];
    moneda: string;
    fechaBase: string;
    /** Total menos adelantos aplicados menos el pago inicial: lo que financia el cronograma. */
    montoAFinanciar: number;
    /** Máximo que se puede pagar de inicial (total menos adelantos). */
    totalAPagar: number;
}>();

const pagos = defineModel<any[]>('pagos', { required: true });
const cronograma = defineModel<{ numero_cuota: number; monto_programado: number; fecha_vencimiento: string }[]>('cronograma', { required: true });
const numCuotas = defineModel<number>('numCuotas', { required: true });
const periodicidad = defineModel<string>('periodicidad', { required: true });
const aplicaMora = defineModel<boolean>('aplicaMora', { required: true });
const tasaMora = defineModel<number>('tasaMora', { required: true });
const tipoMora = defineModel<string>('tipoMora', { required: true });

const metodo = ref<string>('');
const inicial = ref(0);
const nroOperacion = ref('');
const verMora = ref(false);

watch(() => props.metodos, (lista) => {
    if (!metodo.value && lista.length) metodo.value = (lista.find((m) => m.code === 'EFECTIVO') ?? lista[0]).code;
}, { immediate: true });

const cambiarInicial = (e: Event) => {
    inicial.value = Math.max(0, redondear2(Number((e.target as HTMLInputElement).value) || 0));
};

watchEffect(() => {
    const monto = Math.min(inicial.value, Math.max(0, props.totalAPagar));
    pagos.value = monto > 0 && metodo.value
        ? [{
            method_payment: metodo.value,
            amount: monto,
            date_payment: '',
            nro_operacion: metodo.value === 'EFECTIVO' ? null : (nroOperacion.value.trim() || null),
        }]
        : [];
});
</script>

<style scoped>
/* El panel mide ~380px: las columnas «md» del componente de cuotas se llevan a 2 por fila. */
.cronograma-campos :deep([class*='col-md-']),
.cronograma-campos :deep(.col-6) { flex: 0 0 50%; max-width: 50%; }
.cronograma-campos :deep(.col-12) { flex: 0 0 100%; max-width: 100%; }
</style>
