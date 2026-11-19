<template>
    <div class="d-flex flex-column gap-1">
        <div class="small text-uppercase fw-semibold text-secondary">Adelantos del cliente</div>
        <div v-if="!hayCliente" class="small text-muted">Elige un cliente primero.</div>
        <div v-else-if="cargando" class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Buscando adelantos...</div>
        <div v-else-if="totalDelCliente === 0" class="small text-muted">Este cliente no tiene adelantos disponibles.</div>
        <div v-else-if="adelantos.length === 0" class="small text-muted">
            Este cliente tiene adelantos, pero en otra moneda: solo se aplican los de la moneda de la venta ({{ simbolo }}).
        </div>
        <table v-else class="table table-sm table-bordered align-middle mb-1">
            <thead class="table-light">
                <tr class="small text-secondary"><th style="width:30px"></th><th>Adelanto</th><th class="text-end">Disponible</th><th style="width:96px">Aplicar</th></tr>
            </thead>
            <tbody>
                <tr v-for="a in adelantos" :key="a.id">
                    <td><input type="checkbox" v-model="a.seleccionado" @change="alMarcar(a)" :aria-label="`Aplicar adelanto ${a.id}`" /></td>
                    <td class="small">#{{ a.id }}</td>
                    <td class="text-end small">{{ simbolo }} {{ Number(a.available_balance).toFixed(2) }}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm" min="0" :max="a.available_balance" step="0.01"
                            v-model.number="a.monto_aplicado" :disabled="!a.seleccionado" />
                    </td>
                </tr>
            </tbody>
        </table>
        <small v-if="excede" class="text-danger">La suma de adelantos aplicados no puede superar el total de la venta.</small>
    </div>
</template>

<script setup lang="ts">
// Adelantos del cliente que se aplican a la venta (T-106). Antes era una tabla en «Datos generales»; ahora vive en el panel de cobro.
// Los objetos `adelantos` son los de register.vue (seleccionado / monto_aplicado se editan sobre ellos).
import type { ClientAdvance } from '@/types/advances';

export type AdelantoParaCobro = ClientAdvance & { seleccionado: boolean; monto_aplicado: number };

defineProps<{
    /** Ya filtrados por la moneda de la venta. */
    adelantos: AdelantoParaCobro[];
    totalDelCliente: number;
    hayCliente: boolean;
    cargando: boolean;
    simbolo: string;
    excede: boolean;
}>();

const alMarcar = (a: AdelantoParaCobro) => {
    a.monto_aplicado = a.seleccionado ? a.available_balance : 0;
};
</script>
