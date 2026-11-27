<template>
    <!-- T-136 — aplica a una compra lo que un pago del mismo proveedor tiene «a cuenta». -->
    <b-modal v-model="abierto" title="Aplicar pago a cuenta" hide-footer centered>
        <form @submit.prevent="guardar">
            <div v-if="error" class="alert alert-danger py-2 small" role="alert">{{ error }}</div>
            <div v-if="cargando" class="small text-muted">Cargando…</div>
            <div v-else-if="!pagos.length" class="small text-muted">
                Este proveedor no tiene pagos a cuenta en {{ compra?.moneda }}.
            </div>
            <template v-else>
                <label class="form-label small mb-1" for="aplicar-pago">Pago *</label>
                <select id="aplicar-pago" v-model="pagoId" class="form-select form-select-sm mb-2">
                    <option v-for="p in pagos" :key="p.id" :value="p.id">
                        {{ formatFecha(p.fecha) }} · {{ p.origen === 'caja' ? 'Caja' : `Banco ${p.numero_operacion}` }} ·
                        a cuenta {{ formatMonto(p.a_cuenta, p.moneda) }}{{ p.estado === 'pendiente_aprobacion' ? ' (por aprobar)' : '' }}
                    </option>
                </select>
                <label class="form-label small mb-1" for="aplicar-monto">Monto a aplicar *</label>
                <input id="aplicar-monto" v-model="monto" type="number" step="0.01" min="0.01" class="form-control form-control-sm text-end">
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
import { ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import { enCentimos, formatMonto } from '@/helpers/compras';
import { formatFecha } from '@/helpers/fecha';
import type { PagoProveedor } from '@/types/pagosProveedor';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{ compra: { id: number; proveedor_id: number; moneda: 'PEN' | 'USD'; saldo: string | null } | null }>();
const emit = defineEmits<{ (e: 'guardado'): void }>();
const abierto = defineModel<boolean>({ required: true });

const pagos = ref<PagoProveedor[]>([]);
const pagoId = ref<number | null>(null);
const monto = ref<string | number>('');
const error = ref('');
const cargando = ref(false);
const guardando = ref(false);

const mensajeDe = (e: any) => e.response?.data?.message ?? 'No se pudo completar la operación.';

// Propone lo menor entre lo que queda a cuenta y el saldo de la compra.
const proponer = () => {
    const pago = pagos.value.find((p) => p.id === pagoId.value);
    if (!pago) return;
    monto.value = (Math.min(enCentimos(pago.a_cuenta), enCentimos(props.compra?.saldo)) / 100).toFixed(2);
};
watch(pagoId, proponer);

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
        pagos.value = (data.pagos as PagoProveedor[]).filter((p) => p.moneda === props.compra?.moneda);
        pagoId.value = pagos.value[0]?.id ?? null;
    } catch (e) {
        error.value = mensajeDe(e);
    } finally {
        cargando.value = false;
    }
});

const guardar = async () => {
    error.value = '';
    guardando.value = true;
    try {
        const { data } = await httpClient.post(`compras/pagos/${pagoId.value}/aplicaciones`, {
            aplicaciones: [{ compra_id: props.compra!.id, monto: String(monto.value) }],
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
