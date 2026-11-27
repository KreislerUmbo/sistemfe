<template>
    <!-- T-140 — el proveedor devuelve dinero: el saldo a favor de una compra o lo «a cuenta» de un
         pago. Entra a Caja (mi turno) o al banco. -->
    <b-modal v-model="abierto" title="Reembolso del proveedor" hide-footer centered size="lg" no-close-on-backdrop>
        <form v-if="destino" @submit.prevent="guardar">
            <div v-if="error" class="alert alert-danger py-2 small" role="alert">{{ error }}</div>
            <p class="small text-muted mb-2">
                {{ destino.titulo }} · se puede reembolsar hasta {{ formatMonto(destino.maximo, destino.moneda) }}
            </p>
            <div class="row g-2 mb-2">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="reembolso-monto">Monto ({{ destino.moneda }}) *</label>
                    <input id="reembolso-monto" v-model="monto" type="number" step="0.01" min="0.01" :max="destino.maximo"
                        class="form-control form-control-sm text-end">
                </div>
                <div v-if="destino.moneda === 'USD'" class="col-md-2">
                    <label class="form-label small mb-1" for="reembolso-tc">T.C. *</label>
                    <input id="reembolso-tc" :value="tipoCambio" type="number" step="0.0001" min="0.0001"
                        class="form-control form-control-sm text-end"
                        @input="tcEscrito = ($event.target as HTMLInputElement).value">
                </div>
                <div v-if="destino.moneda === 'USD' && !sugerido" class="col-12 small text-muted">
                    No hay tipo de cambio SUNAT para esa fecha: escríbelo.
                </div>
            </div>

            <CamposOrigenPago v-model="origen" :moneda="destino.moneda" :cuentas="cuentas" :metodos="metodos"
                prefijo="reembolso" ingreso en-modal />

            <div class="mt-2">
                <label class="form-label small mb-1" for="reembolso-obs">Observaciones</label>
                <input id="reembolso-obs" v-model="observaciones" type="text" maxlength="2000" class="form-control form-control-sm">
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-secondary" @click="abierto = false">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-primary" :disabled="guardando">
                    {{ guardando ? 'Guardando…' : 'Registrar reembolso' }}
                </button>
            </div>
        </form>
    </b-modal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import CamposOrigenPago from '@/components/Compras/CamposOrigenPago.vue';
import httpClient from '@/helpers/http-client';
import { enCentimos, formatMonto } from '@/helpers/compras';
import { hoyPeru } from '@/helpers/fecha';
import { datosDeOrigen, faltaEnOrigen, origenVacio } from '@/helpers/pagosProveedor';
import { ventaSunat } from '@/helpers/tipoCambioSugerido';
import type { CuentaParaPagar, DestinoReembolso, MetodoDeCaja } from '@/types/pagosProveedor';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{ destino: DestinoReembolso | null }>();
const emit = defineEmits<{ (e: 'guardado'): void }>();
const abierto = defineModel<boolean>({ required: true });

const monto = ref<string | number>('');
const observaciones = ref('');
const origen = ref(origenVacio());
const cuentas = ref<CuentaParaPagar[]>([]);
const metodos = ref<MetodoDeCaja[]>([]);
const sugerido = ref<string | null>(null);
const tcEscrito = ref<string | null>(null);
const error = ref('');
const guardando = ref(false);

// En Caja la fecha la pone el servidor: hoy.
const fecha = computed(() => (origen.value.origen === 'banco' && origen.value.fecha) || hoyPeru());
const tipoCambio = computed(() => tcEscrito.value ?? sugerido.value ?? '');
const mensajeDe = (e: any) => e.response?.data?.message ?? 'No se pudo completar la operación.';

const sugerir = async () => {
    const pedida = fecha.value;
    const venta = await ventaSunat(pedida);
    if (pedida === fecha.value) sugerido.value = venta;
};

watch(abierto, async (valor) => {
    if (!valor || !props.destino) return;
    monto.value = props.destino.maximo;
    observaciones.value = '';
    origen.value = origenVacio();
    tcEscrito.value = null;
    sugerido.value = null;
    error.value = '';
    sugerir();
    try {
        const { data } = await httpClient.get('compras/catalogos');
        cuentas.value = data.cuentas_bancarias;
        metodos.value = data.metodos_pago;
    } catch (e) {
        error.value = mensajeDe(e);
    }
});
watch(fecha, () => {
    if (abierto.value) sugerir();
});

const guardar = async () => {
    const destino = props.destino!;
    error.value = '';
    const falta = enCentimos(monto.value) <= 0 ? 'Escribe el monto del reembolso.'
        : destino.moneda === 'USD' && !(Number(tipoCambio.value) > 0) ? 'En dólares se necesita el tipo de cambio.'
            : faltaEnOrigen(origen.value, 'del reembolso');
    if (falta) {
        error.value = falta;
        return;
    }

    guardando.value = true;
    try {
        const { data } = await httpClient.post('compras/reembolsos', {
            proveedor_id: destino.proveedor_id,
            compra_id: destino.compra_id ?? null,
            pago_proveedor_id: destino.pago_proveedor_id ?? null,
            moneda: destino.moneda,
            monto: String(monto.value),
            tipo_cambio: destino.moneda === 'USD' ? String(tipoCambio.value) : null,
            observaciones: observaciones.value.trim() || null,
            ...datosDeOrigen(origen.value),
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
