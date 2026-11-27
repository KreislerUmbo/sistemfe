<template>
    <!-- T-136 — de dónde sale el dinero: Caja (egreso en mi turno) o Banco (sin turno). -->
    <div class="row g-2">
        <div class="col-md-3">
            <label class="form-label small mb-1" :for="`${prefijo}-origen`">Sale de *</label>
            <select :id="`${prefijo}-origen`" v-model="modelo.origen" class="form-select form-select-sm">
                <option value="caja">Caja (mi turno)</option>
                <option value="banco">Banco</option>
            </select>
        </div>
        <div v-if="modelo.origen === 'caja'" class="col-md-3">
            <label class="form-label small mb-1" :for="`${prefijo}-metodo`">Método de pago *</label>
            <select :id="`${prefijo}-metodo`" v-model="modelo.payment_method_id" class="form-select form-select-sm">
                <option v-for="m in metodos" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
        </div>
        <template v-else>
            <div class="col-md-4">
                <label class="form-label small mb-1" :for="`${prefijo}-cuenta`">Cuenta bancaria *</label>
                <select :id="`${prefijo}-cuenta`" v-model="modelo.cuenta_bancaria_id" class="form-select form-select-sm">
                    <option :value="null">— Elegir —</option>
                    <option v-for="c in cuentasDeLaMoneda" :key="c.id" :value="c.id">
                        {{ c.banco }} {{ c.numero_cuenta }}{{ c.moneda ? '' : ' (falta su moneda)' }}
                    </option>
                </select>
                <small v-if="!cuentasDeLaMoneda.length" class="text-warning">No hay cuentas en {{ moneda }}.</small>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" :for="`${prefijo}-operacion`">N° de operación *</label>
                <input :id="`${prefijo}-operacion`" v-model="modelo.numero_operacion" type="text" class="form-control form-control-sm"
                    maxlength="30">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" :for="`${prefijo}-fecha`">Fecha del pago *</label>
                <CampoFecha :id="`${prefijo}-fecha`" v-model="modelo.fecha" :max="hoy" etiqueta="pago" :estatico="enModal" />
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import CampoFecha from '@/components/CampoFecha.vue';
import { hoyPeru } from '@/helpers/fecha';
import type { CuentaParaPagar, MetodoDeCaja, OrigenPagoForm } from '@/types/pagosProveedor';

const props = withDefaults(defineProps<{
    moneda: 'PEN' | 'USD';
    cuentas: CuentaParaPagar[];
    metodos: MetodoDeCaja[];
    prefijo?: string;
    enModal?: boolean;
}>(), { prefijo: 'pago', enModal: false });

const modelo = defineModel<OrigenPagoForm>({ required: true });
const hoy = hoyPeru();

// Las que no tienen moneda se muestran: al pagar se pide completarla.
const cuentasDeLaMoneda = computed(() => props.cuentas.filter((c) => !c.moneda || c.moneda === props.moneda));

// Efectivo es el primero de los métodos de Caja.
watch(() => props.metodos, (metodos) => {
    if (!modelo.value.payment_method_id && metodos.length) modelo.value.payment_method_id = metodos[0].id;
}, { immediate: true });

watch(cuentasDeLaMoneda, (cuentas) => {
    if (!cuentas.some((c) => c.id === modelo.value.cuenta_bancaria_id)) {
        modelo.value.cuenta_bancaria_id = cuentas.length === 1 ? cuentas[0].id : null;
    }
});
</script>

<style scoped>
/* En modo `estatico` flatpickr envuelve el input y el botón del calendario se caía a otra línea. */
:deep(.campo-fecha > .flatpickr-wrapper) {
    flex: 1 1 auto;
    width: 1%;
    min-width: 0;
}

:deep(.campo-fecha > .flatpickr-wrapper > .form-control) {
    padding: 0.25rem 0.5rem;
    font-size: var(--bs-body-font-size);
    min-height: calc(1.5em + 0.5rem + 2px);
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}
</style>
