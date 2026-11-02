<template>
    <div v-if="visible" class="card mb-2">
        <div class="card-header py-1 px-2 d-flex align-items-center justify-content-between">
            <span class="small fw-semibold"><i class="fas fa-cash-register me-1"></i>Cobro al contado</span>
            <span class="small text-muted">Total a cobrar: <strong>{{ simbolo }} {{ saldo.toFixed(2) }}</strong></span>
        </div>
        <div class="card-body p-2">
            <div v-if="!cajaAbierta" class="alert alert-warning py-1 px-2 small mb-2">
                <i class="fas fa-triangle-exclamation me-1"></i>Abre tu caja para cobrar.
                <router-link to="/caja" target="_blank" class="ms-1">Ir a Caja</router-link>
            </div>

            <!-- T-012: cobro ya hecho en un comprobante anulado con NC de esta misma reserva -->
            <div v-for="d in traspasosDisponibles" :key="d.sale_id" class="border rounded p-2 mb-2 bg-body-tertiary">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" :id="`traspaso-${d.sale_id}`"
                                :checked="!!traspasoDe(d.sale_id)" @change="alternarTraspaso(d, ($event.target as HTMLInputElement).checked)" />
                            <label class="form-check-label small" :for="`traspaso-${d.sale_id}`">
                                Usar lo cobrado en <strong>{{ d.comprobante }}</strong> (disponible {{ simbolo }} {{ d.disponible.toFixed(2) }})
                            </label>
                        </div>
                    </div>
                    <div class="col-5 col-md-3" v-if="traspasoDe(d.sale_id)">
                        <input type="number" min="0" :max="d.disponible" step="0.01" class="form-control form-control-sm"
                            :value="traspasoDe(d.sale_id)!.monto" @input="editarTraspaso(d, $event)" />
                    </div>
                </div>
                <div class="small text-muted mt-1">El dinero ya entró a caja con ese comprobante: no se vuelve a registrar.</div>
            </div>

            <div v-for="(fila, i) in pagos" :key="i" class="border rounded p-2 mb-2">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small text-secondary">Forma de pago</label>
                        <select class="form-select form-select-sm" v-model="fila.method_payment"
                            @change="ajustarMoneda(fila)">
                            <option v-for="f in formasPago" :key="f.code" :value="f.code">{{ f.name }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small text-secondary">Moneda</label>
                        <select class="form-select form-select-sm" v-model="fila.moneda_pago"
                            @change="alCambiarMoneda(fila)">
                            <option v-for="m in monedasDe(fila)" :key="m" :value="m">{{ m === 'USD' ? 'Dólares' : 'Soles' }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small text-secondary">Monto</label>
                        <input type="number" min="0" step="0.01" class="form-control form-control-sm"
                            v-model.number="fila.monto_pagado" @input="tocado = true" />
                    </div>
                    <div class="col-6 col-md-2" v-if="fila.moneda_pago !== moneda">
                        <label class="form-label mb-1 small text-secondary">Tipo de cambio</label>
                        <input type="number" min="0" step="0.001" class="form-control form-control-sm"
                            v-model.number="fila.tipo_cambio" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small text-secondary">N.º operación</label>
                        <input type="text" maxlength="50" class="form-control form-control-sm" v-model="fila.nro_operacion"
                            placeholder="Opcional" />
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" :disabled="pagos.length === 1 && traspasado <= 0"
                            @click="quitar(i)"><i class="fas fa-trash-alt small"></i></button>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    Equivale a {{ simbolo }} {{ equivalenteFila(fila, moneda).toFixed(2) }}
                    <span v-if="avisoTipoCambio(fila)" class="text-warning-emphasis ms-2">
                        <i class="fas fa-circle-exclamation me-1"></i>Se aleja más de 3% del tipo de cambio del día
                        ({{ tipoCambioDia }}).
                    </span>
                </div>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm" @click="agregar">
                <i class="fas fa-plus me-1"></i>Agregar pago
            </button>

            <div class="mt-2 small">
                <div v-if="traspasado > 0">Traspasado: <strong>{{ simbolo }} {{ traspasado.toFixed(2) }}</strong></div>
                <div>Total cobrado: <strong>{{ simbolo }} {{ cobrado.toFixed(2) }}</strong></div>
                <div v-if="dif > 0" class="text-danger">Falta: <strong>{{ simbolo }} {{ dif.toFixed(2) }}</strong></div>
                <div v-else-if="dif < 0" class="text-success-emphasis">
                    Vuelto:
                    <strong>{{ vueltoMostrado }}</strong>
                    <template v-if="opcionesVuelto.length > 1">
                        <select class="form-select form-select-sm d-inline-block w-auto ms-2" v-model="vueltoMoneda">
                            <option v-for="m in opcionesVuelto" :key="m" :value="m">{{ m === 'USD' ? 'En dólares' : 'En soles' }}</option>
                        </select>
                    </template>
                </div>
                <div v-else class="text-success">Cobro completo.</div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Bloque de cobro al contado de los modales de facturar una reserva (T-011). Visible solo si el
// cobro está habilitado, la condición es contado y hay saldo. Los cálculos son solo para mostrar:
// el backend (CobroVentaService) decide. Ver helpers/cobroFactura.ts.
import { computed, ref, watch } from 'vue';
import {
    diferencia, equivalenteFila, filaNueva, monedasDeVuelto, totalCobrado, totalTraspasado, vueltoEn,
    type FilaCobro, type FilaTraspaso, type FormaPagoCobro, type MonedaCobro, type TraspasoDisponible,
} from '@/helpers/cobroFactura';

const props = defineProps<{
    habilitado: boolean;
    condicion: 'contado' | 'credito';
    saldo: number;
    moneda: MonedaCobro;
    formasPago: FormaPagoCobro[];
    tipoCambioDia: number | null;
    cajaAbierta: boolean;
    traspasosDisponibles?: TraspasoDisponible[];
}>();

const pagos = defineModel<FilaCobro[]>('pagos', { required: true });
const vueltoMoneda = defineModel<MonedaCobro>('vueltoMoneda', { required: true });
const traspasos = defineModel<FilaTraspaso[]>('traspasos', { default: () => [] });

const tocado = ref(false);
const simbolo = computed(() => (props.moneda === 'USD' ? 'US$' : 'S/'));
const visible = computed(() => props.habilitado && props.condicion === 'contado' && props.saldo > 0);

const traspasado = computed(() => totalTraspasado(traspasos.value));
// Lo que falta pagar con dinero nuevo.
const porPagar = computed(() => Math.max(0, Math.round((props.saldo - traspasado.value) * 100) / 100));
const cobrado = computed(() => totalCobrado(pagos.value, props.moneda) + traspasado.value);
const dif = computed(() => diferencia(pagos.value, props.saldo, props.moneda, traspasado.value));
const opcionesVuelto = computed(() => monedasDeVuelto(pagos.value, props.moneda, props.formasPago));
const vueltoMostrado = computed(() => {
    const monto = vueltoEn(-dif.value, vueltoMoneda.value, props.moneda, pagos.value, props.formasPago);
    const s = vueltoMoneda.value === 'USD' ? 'US$' : 'S/';
    return monto === null ? 'sin efectivo en esa moneda' : `${s} ${monto.toFixed(2)}`;
});

// Una fila de efectivo en la moneda de la venta por el saldo completo (el caso más común).
// Mientras el usuario no toque el monto, sigue al saldo.
watch(() => [visible.value, porPagar.value, props.formasPago.length] as const, () => {
    if (!visible.value || props.formasPago.length === 0) return;
    if (!tocado.value && porPagar.value <= 0 && traspasado.value > 0) {
        pagos.value = [];
    } else if (pagos.value.length === 0 && porPagar.value > 0) {
        pagos.value = [filaNueva(props.formasPago, props.moneda, porPagar.value, props.tipoCambioDia)];
    } else if (!tocado.value && pagos.value.length === 1) {
        pagos.value[0].monto_pagado = porPagar.value;
    }
}, { immediate: true });

// T-012: mientras el usuario no decida, se propone usar todo lo traspasable (hasta el saldo): es lo
// que evita cobrar dos veces. Tildar/destildar o editar el monto es decisión suya y no se pisa.
const eligioTraspaso = ref(false);
const traspasoDe = (saleId: number) => traspasos.value.find((t) => t.sale_id === saleId);
const restanteParaTraspaso = (salvo: number | null) => {
    const otros = traspasos.value.filter((t) => t.sale_id !== salvo).reduce((s, t) => s + Number(t.monto), 0);
    return Math.max(0, Math.round((props.saldo - otros) * 100) / 100);
};
const alternarTraspaso = (d: TraspasoDisponible, marcado: boolean) => {
    eligioTraspaso.value = true;
    tocado.value = false;
    if (!marcado) {
        traspasos.value = traspasos.value.filter((t) => t.sale_id !== d.sale_id);
        return;
    }
    traspasos.value = [...traspasos.value, { sale_id: d.sale_id, monto: Math.min(d.disponible, restanteParaTraspaso(null)) }];
};
const editarTraspaso = (d: TraspasoDisponible, evento: Event) => {
    eligioTraspaso.value = true;
    tocado.value = false;
    const valor = Math.min(Number((evento.target as HTMLInputElement).value) || 0, d.disponible);
    traspasos.value = traspasos.value.map((t) => (t.sale_id === d.sale_id ? { ...t, monto: valor } : t));
};
watch(() => [props.traspasosDisponibles, visible.value, props.saldo] as const, () => {
    const disponibles = props.traspasosDisponibles ?? [];
    // Lo que ya no se ofrece (cambió la moneda o la selección) deja de estar elegido.
    traspasos.value = traspasos.value.filter((t) => disponibles.some((d) => d.sale_id === t.sale_id));
    if (!visible.value || eligioTraspaso.value) return;
    let restante = props.saldo;
    traspasos.value = disponibles.map((d) => {
        const monto = Math.min(d.disponible, Math.max(0, restante));
        restante = Math.round((restante - monto) * 100) / 100;
        return { sale_id: d.sale_id, monto };
    }).filter((t) => t.monto > 0);
}, { immediate: true });

watch(opcionesVuelto, (opciones) => {
    if (!opciones.includes(vueltoMoneda.value)) vueltoMoneda.value = props.moneda;
});

const monedasDe = (fila: FilaCobro): MonedaCobro[] =>
    props.formasPago.find((f) => f.code === fila.method_payment)?.monedas ?? ['PEN', 'USD'];

// Si la forma de pago no acepta la moneda elegida, pasa a la primera que sí acepta.
const ajustarMoneda = (fila: FilaCobro) => {
    const aceptadas = monedasDe(fila);
    if (!aceptadas.includes(fila.moneda_pago)) fila.moneda_pago = aceptadas[0];
    alCambiarMoneda(fila);
};

const alCambiarMoneda = (fila: FilaCobro) => {
    if (fila.moneda_pago === props.moneda) fila.tipo_cambio = null;
    else if (!fila.tipo_cambio) fila.tipo_cambio = props.tipoCambioDia;
};

const avisoTipoCambio = (fila: FilaCobro) => {
    if (fila.moneda_pago === props.moneda || !props.tipoCambioDia || !fila.tipo_cambio) return false;
    return Math.abs(fila.tipo_cambio - props.tipoCambioDia) / props.tipoCambioDia > 0.03;
};

const agregar = () => {
    tocado.value = true;
    const falta = Math.max(0, dif.value);
    pagos.value.push(filaNueva(props.formasPago, props.moneda, falta, props.tipoCambioDia));
};

const quitar = (i: number) => {
    tocado.value = true;
    pagos.value.splice(i, 1);
};
</script>
