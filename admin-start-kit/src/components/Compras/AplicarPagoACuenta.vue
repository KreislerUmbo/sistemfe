<template>
    <!-- T-136 — aplica a una compra lo que un pago del mismo proveedor tiene «a cuenta». T-140: el
         pago puede estar en la otra moneda, con su tipo de cambio. -->
    <b-modal v-model="abierto" title="Aplicar pago a cuenta" hide-footer centered>
        <form @submit.prevent="guardar">
            <div v-if="error" class="alert alert-danger py-2 small" role="alert">{{ error }}</div>
            <div v-if="cargando" class="small text-muted">Cargando…</div>
            <div v-else-if="!pagos.length" class="small text-muted">Este proveedor no tiene pagos a cuenta.</div>
            <template v-else>
                <label class="form-label small mb-1" for="aplicar-pago">Pago *</label>
                <select id="aplicar-pago" v-model="pagoId" class="form-select form-select-sm mb-2">
                    <option v-for="p in pagos" :key="p.id" :value="p.id">
                        {{ formatFecha(p.fecha) }} · {{ p.origen === 'caja' ? 'Caja' : `Banco ${p.numero_operacion}` }} ·
                        a cuenta {{ formatMonto(p.a_cuenta, p.moneda) }}{{ p.estado === 'pendiente_aprobacion' ? ' (por aprobar)' : '' }}
                    </option>
                </select>
                <div class="row g-2">
                    <div :class="cruzada ? 'col-7' : 'col-12'">
                        <label class="form-label small mb-1" for="aplicar-monto">Monto a aplicar ({{ compra?.moneda }}) *</label>
                        <input id="aplicar-monto" v-model="monto" type="number" step="0.01" min="0.01"
                            class="form-control form-control-sm text-end" @input="montoEscrito = true">
                    </div>
                    <div v-if="cruzada" class="col-5">
                        <label class="form-label small mb-1" for="aplicar-tc">T.C. *</label>
                        <!-- 6 decimales: así el pago puede cancelar exacto en la otra moneda -->
                        <input id="aplicar-tc" :value="tipoCambio" type="number" step="0.000001" min="0.000001"
                            class="form-control form-control-sm text-end" @input="escribirTc">
                    </div>
                </div>
                <div v-if="cruzada && pago" class="small text-muted mt-1">
                    Sale del pago: {{ formatMonto(enMonedaDelPago(enCentimos(monto), compra!.moneda, tipoCambio) / 100, pago.moneda) }}
                </div>
            </template>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-secondary" @click="abierto = false">Cancelar</button>
                <button v-if="pagos.length" type="submit" class="btn btn-sm btn-primary" :disabled="guardando">
                    {{ guardando ? 'Aplicando…' : 'Aplicar' }}
                </button>
            </div>
        </form>
    </b-modal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import { enCentimos, formatMonto } from '@/helpers/compras';
import { formatFecha } from '@/helpers/fecha';
import { enMonedaDeLaCompra, enMonedaDelPago } from '@/helpers/pagosProveedor';
import { ventaSunat } from '@/helpers/tipoCambioSugerido';
import type { PagoProveedor } from '@/types/pagosProveedor';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{ compra: { id: number; proveedor_id: number; moneda: 'PEN' | 'USD'; saldo: string | null; por_aprobar?: string | null } | null }>();
const emit = defineEmits<{ (e: 'guardado'): void }>();
const abierto = defineModel<boolean>({ required: true });

const pagos = ref<PagoProveedor[]>([]);
const pagoId = ref<number | null>(null);
const monto = ref<string | number>('');
const tipoCambio = ref('');
// El monto escrito a mano no se reemplaza al corregir el T.C.; al elegir otro pago se vuelve a proponer.
const montoEscrito = ref(false);
const error = ref('');
const cargando = ref(false);
const guardando = ref(false);

const mensajeDe = (e: any) => e.response?.data?.message ?? 'No se pudo completar la operación.';
const pago = computed(() => pagos.value.find((p) => p.id === pagoId.value) ?? null);
const cruzada = computed(() => !!pago.value && pago.value.moneda !== props.compra?.moneda);

// Propone lo menor entre lo que queda a cuenta y lo que la compra todavía admite: su saldo menos
// lo que ya espera aprobación en Caja (el mismo límite que aplica el servidor).
const proponer = () => {
    if (!pago.value || !props.compra) return;
    const admite = enCentimos(props.compra.saldo) - enCentimos(props.compra.por_aprobar ?? 0);
    const disponible = cruzada.value
        ? enMonedaDeLaCompra(enCentimos(pago.value.a_cuenta), props.compra.moneda, tipoCambio.value)
        : enCentimos(pago.value.a_cuenta);
    monto.value = (Math.max(0, Math.min(disponible, admite)) / 100).toFixed(2);
};

// El del pago si es en dólares; si no, la venta SUNAT de su fecha.
const elegirPago = async () => {
    tipoCambio.value = '';
    montoEscrito.value = false;
    const elegido = pago.value;
    if (elegido && cruzada.value) {
        const propuesto = (elegido.moneda === 'USD' ? elegido.tipo_cambio : null) ?? await ventaSunat(elegido.fecha);
        if (elegido.id !== pagoId.value || tipoCambio.value) return;
        tipoCambio.value = propuesto ?? '';
    }
    proponer();
};
watch(pagoId, elegirPago);

const escribirTc = (e: Event) => {
    tipoCambio.value = (e.target as HTMLInputElement).value;
    if (!montoEscrito.value) proponer();
};

watch(abierto, async (valor) => {
    if (!valor || !props.compra) return;
    pagos.value = [];
    pagoId.value = null;
    error.value = '';
    cargando.value = true;
    try {
        const { data } = await httpClient.get('compras/pagos', {
            params: { proveedor_id: props.compra.proveedor_id, con_saldo: 1, per_page: 100 },
        });
        // Primero los de la moneda de la compra.
        pagos.value = (data.pagos as PagoProveedor[])
            .slice()
            .sort((a, b) => Number(b.moneda === props.compra?.moneda) - Number(a.moneda === props.compra?.moneda));
        pagoId.value = pagos.value[0]?.id ?? null;
    } catch (e) {
        error.value = mensajeDe(e);
    } finally {
        cargando.value = false;
    }
});

const guardar = async () => {
    error.value = '';
    if (cruzada.value && !(Number(tipoCambio.value) > 0)) {
        error.value = 'Indica el tipo de cambio.';
        return;
    }
    guardando.value = true;
    try {
        const { data } = await httpClient.post(`compras/pagos/${pagoId.value}/aplicaciones`, {
            aplicaciones: [{
                compra_id: props.compra!.id,
                monto: String(monto.value),
                ...(cruzada.value ? { tipo_cambio: String(tipoCambio.value) } : {}),
            }],
        });
        abierto.value = false;
        await (Swal as TVueSwalInstance).fire('Listo', data.message, 'success');
        emit('guardado');
    } catch (e) {
        error.value = mensajeDe(e);
    } finally {
        guardando.value = false;
    }
};
</script>
