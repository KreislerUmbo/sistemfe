<template>
    <div class="mb-2">
        <label class="form-label small fw-semibold text-secondary">
            <i class="fas fa-handshake me-1"></i>Condición de pago
        </label>
        <div class="btn-group btn-group-sm w-100" role="group" aria-label="Condición de pago">
            <button type="button" class="btn" :class="condicion === 'contado' ? 'btn-primary' : 'btn-outline-primary'" @click="condicion = 'contado'">
                Contado
            </button>
            <button type="button" class="btn" :class="condicion === 'credito' ? 'btn-primary' : 'btn-outline-primary'"
                :disabled="saldo <= 0" @click="condicion = 'credito'">
                Crédito
            </button>
        </div>

        <!-- Ayudas: "contado" ante SUNAT declara que el cliente paga al emitir. -->
        <small v-if="saldo <= 0" class="text-muted d-block mt-1">
            Los anticipos cubren todo el comprobante: va al contado.
        </small>
        <small v-else-if="condicion === 'contado'" class="text-warning-emphasis d-block mt-1">
            <i class="fas fa-circle-exclamation me-1"></i>Contado: el cliente paga hoy {{ moneda }} {{ saldo.toFixed(2) }}.
            Si pagará después, elige <strong>Crédito</strong> con sus cuotas.
        </small>
    </div>

    <div v-if="condicion === 'credito' && saldo > 0" class="row g-2 mb-2">
        <CronogramaCuotasCampos v-model="cronograma" :monto-a-financiar="saldo" :moneda="moneda" :fecha-base="fechaBase"
            permitir-una-cuota dentro-de-modal />
    </div>
</template>

<script setup lang="ts">
// Condición de pago al facturar una reserva (fila F5, 24-oct-2026). Contado o
// crédito con cuotas; a crédito se financia lo que queda tras los anticipos
// (saldo_a_cobrar de la vista previa, la misma regla que la emisión). Las
// cuotas: components/CronogramaCuotasCampos.vue (una cuota con su fecha, o
// varias, igual que Ventas). El backend valida lo mismo
// (CronogramaCreditoValidator) y el XML sale con "Crédito" y estas cuotas.
import { watch } from 'vue';
import CronogramaCuotasCampos, { type CuotaCronograma } from '@/components/CronogramaCuotasCampos.vue';

const props = defineProps<{
    // Lo que queda por cobrar tras los anticipos, en la moneda del comprobante.
    saldo: number;
    moneda: string;
    fechaBase: string;
}>();

const condicion = defineModel<'contado' | 'credito'>('condicion', { required: true });
const cronograma = defineModel<CuotaCronograma[]>('cronograma', { required: true });

// Sin saldo no hay nada que dar a crédito.
watch(() => props.saldo, (saldo) => {
    if (saldo <= 0 && condicion.value === 'credito') condicion.value = 'contado';
});
watch(condicion, (c) => {
    if (c === 'contado') cronograma.value = [];
});
</script>
