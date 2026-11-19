<template>
    <div class="d-flex flex-column gap-2">
        <div v-if="totalAPagar <= 0" class="small text-muted">No hay monto que cobrar por ahora.</div>
        <template v-else>
            <div class="metodos">
                <button v-for="m in metodos" :key="m.id" type="button" class="btn btn-sm"
                    :class="m.code === metodo ? 'btn-success' : 'btn-outline-secondary'" @click="elegirMetodo(m.code)">
                    {{ m.name }}
                </button>
            </div>

            <template v-if="esEfectivo">
                <div>
                    <label class="form-label small fw-semibold text-secondary mb-1" for="cc-rec">¿Con cuánto paga?</label>
                    <input id="cc-rec" type="number" class="form-control form-control-sm" step="0.10" min="0"
                        :value="recibido ?? ''" :placeholder="`${restante.toFixed(2)} (exacto)`" @change="cambiarRecibido($event)" />
                </div>
                <div class="d-flex flex-wrap gap-1 align-items-center">
                    <span class="small text-muted">Atajos:</span>
                    <button v-for="v in atajos" :key="v" type="button" class="btn btn-outline-secondary btn-sm rounded-pill py-0"
                        @click="recibido = v">{{ simbolo }} {{ v.toFixed(2) }}</button>
                    <button v-if="recibido != null" type="button" class="btn btn-outline-secondary btn-sm rounded-pill py-0"
                        @click="recibido = null">Exacto</button>
                </div>
                <div class="d-flex justify-content-between rounded px-3 py-2 fw-semibold"
                    :class="efectivo.falta > 0 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success'">
                    <span>{{ efectivo.falta > 0 ? 'Falta' : 'Vuelto' }}</span>
                    <span>{{ simbolo }} {{ (efectivo.falta > 0 ? efectivo.falta : efectivo.vuelto).toFixed(2) }}</span>
                </div>
            </template>
            <div v-else class="row g-2">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-secondary mb-1">Monto</label>
                    <input class="form-control form-control-sm" :value="restante.toFixed(2)" readonly />
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="cc-nop">N.º de operación</label>
                    <input id="cc-nop" v-model="nroOperacion" maxlength="50" class="form-control form-control-sm" placeholder="Opcional" />
                </div>
            </div>

            <div v-for="(x, i) in extras" :key="i" class="extra">
                <select v-model="x.codigo" class="form-select form-select-sm" aria-label="Método">
                    <option v-for="m in metodos" :key="m.id" :value="m.code">{{ m.name }}</option>
                </select>
                <input v-model.number="x.monto" type="number" step="0.10" min="0" class="form-control form-control-sm" aria-label="Monto" />
                <button type="button" class="btn btn-sm btn-link text-muted fs-5 py-0" aria-label="Quitar" @click="quitarExtra(i)">×</button>
                <!-- Revisión T-106: cada pago que no es efectivo lleva su n.º de operación, como en la pantalla anterior. -->
                <input v-if="x.codigo !== CODIGO_EFECTIVO" v-model="x.nro" maxlength="50" class="form-control form-control-sm extra-nro"
                    placeholder="N.º de operación (opcional)" aria-label="N.º de operación" />
            </div>
            <button type="button" class="btn btn-link btn-sm p-0 align-self-start" @click="agregarExtra">+ Pagar con dos métodos</button>
        </template>
    </div>
</template>

<script setup lang="ts">
// Cobro al contado (T-106): método como botones, «¿con cuánto paga?» con atajos y vuelto, y pago en dos métodos.
// El vuelto es SOLO una calculadora: el pago que sale hacia el backend es lo que cubre la venta, nunca lo recibido.
// Escribe en `pagos` el mismo formato que usaba addPayment() (method_payment, amount, date_payment, nro_operacion).
import { computed, ref, watch, watchEffect } from 'vue';
import type { PaymentMethod } from '@/types/cash';
import { atajosEfectivo, calcularEfectivo, redondear2 } from '@/helpers/ventaRetail';

const props = defineProps<{
    metodos: PaymentMethod[];
    /** Lo que se cobra ahora: total menos adelantos aplicados. */
    totalAPagar: number;
    simbolo: string;
}>();

const pagos = defineModel<any[]>('pagos', { required: true });

const CODIGO_EFECTIVO = 'EFECTIVO';
const metodo = ref<string>('');
const recibido = ref<number | null>(null);
const nroOperacion = ref('');
const extras = ref<{ codigo: string; monto: number; nro: string }[]>([]);

// Método inicial: Efectivo si existe en el catálogo; si no, el primero.
watch(() => props.metodos, (lista) => {
    if (!metodo.value && lista.length) {
        metodo.value = (lista.find((m) => m.code === CODIGO_EFECTIVO) ?? lista[0]).code;
    }
}, { immediate: true });

const esEfectivo = computed(() => metodo.value === CODIGO_EFECTIVO);
const sumaExtras = computed(() => redondear2(extras.value.reduce((s, x) => s + (Number(x.monto) || 0), 0)));
const restante = computed(() => Math.max(0, redondear2(props.totalAPagar - sumaExtras.value)));
const efectivo = computed(() => calcularEfectivo(restante.value, recibido.value));
const atajos = computed(() => atajosEfectivo(restante.value));

const elegirMetodo = (codigo: string) => { metodo.value = codigo; recibido.value = null; nroOperacion.value = ''; };
const cambiarRecibido = (e: Event) => {
    const v = (e.target as HTMLInputElement).value.trim();
    recibido.value = v === '' ? null : Math.max(0, Number(v) || 0);
};
const agregarExtra = () => {
    const otro = props.metodos.find((m) => m.code !== metodo.value);
    extras.value.push({ codigo: otro?.code ?? metodo.value, monto: 0, nro: '' });
    recibido.value = null;
};
const quitarExtra = (i: number) => { extras.value.splice(i, 1); recibido.value = null; };

watchEffect(() => {
    const lista: any[] = [];
    const monto = esEfectivo.value ? efectivo.value.pagoQueSeEnvia : restante.value;
    if (props.totalAPagar > 0 && monto > 0 && metodo.value) {
        lista.push({
            method_payment: metodo.value,
            amount: monto,
            date_payment: '',
            nro_operacion: esEfectivo.value ? null : (nroOperacion.value.trim() || null),
        });
    }
    for (const x of extras.value) {
        if ((Number(x.monto) || 0) > 0) {
            lista.push({
                method_payment: x.codigo,
                amount: redondear2(Number(x.monto)),
                date_payment: '',
                nro_operacion: x.codigo === CODIGO_EFECTIVO ? null : (x.nro.trim() || null),
            });
        }
    }
    pagos.value = lista;
});
</script>

<style scoped>
.metodos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.extra { display: grid; grid-template-columns: 1fr 100px auto; gap: 6px; align-items: center; }
.extra-nro { grid-column: 1 / span 2; }
</style>
