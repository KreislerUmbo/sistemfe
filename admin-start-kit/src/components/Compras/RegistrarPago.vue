<template>
    <!-- T-136 — un pago nuevo y cómo se reparte entre las compras pendientes. Lo que no se
         reparte queda «a cuenta» del proveedor. T-140: también compras en la otra moneda, cada
         una con su tipo de cambio. -->
    <b-modal v-model="abierto" title="Registrar pago a proveedor" hide-footer centered size="lg" no-close-on-backdrop>
        <form @submit.prevent="guardar">
            <div v-if="error" class="alert alert-danger py-2 small" role="alert">{{ error }}</div>
            <div class="row g-2 mb-2">
                <div :class="moneda === 'USD' ? 'col-md-5' : 'col-md-6'">
                    <label class="form-label small mb-1" for="pago-proveedor">Proveedor *</label>
                    <ProveedorSelector id="pago-proveedor" v-model="proveedor" :disabled="!!compra" />
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="pago-moneda">Moneda *</label>
                    <select id="pago-moneda" v-model="moneda" class="form-select form-select-sm" :disabled="!!compra">
                        <option value="PEN">Soles (PEN)</option>
                        <option value="USD">Dólares (USD)</option>
                    </select>
                </div>
                <div :class="moneda === 'USD' ? 'col-md-2' : 'col-md-3'">
                    <label class="form-label small mb-1" for="pago-monto">Monto *</label>
                    <input id="pago-monto" v-model="monto" type="number" step="0.01" min="0.01"
                        class="form-control form-control-sm text-end">
                </div>
                <div v-if="moneda === 'USD'" class="col-md-2">
                    <label class="form-label small mb-1" for="pago-tc">T.C. *</label>
                    <input id="pago-tc" :value="tipoCambio" type="number" step="0.0001" min="0.0001"
                        class="form-control form-control-sm text-end" @input="tcEscrito = valorDe($event)">
                </div>
                <div v-if="moneda === 'USD' && !sugerido" class="col-12 small text-muted">
                    No hay tipo de cambio SUNAT para esa fecha: escríbelo.
                </div>
            </div>

            <CamposOrigenPago v-model="origen" :moneda="moneda" :cuentas="cuentas" :metodos="metodos" en-modal />

            <h6 class="small fw-semibold mt-3 mb-1">Compras pendientes en {{ moneda }}</h6>
            <div v-if="!proveedor" class="small text-muted">Elige el proveedor.</div>
            <div v-else-if="!deLaMoneda.length" class="small text-muted">
                No tiene compras pendientes en {{ moneda }}: lo que no se aplique queda a cuenta.
            </div>
            <div v-else class="table-responsive" style="max-height: 240px; overflow-y: auto">
                <table class="table table-sm small align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Comprobante</th>
                            <th>Emisión</th>
                            <th class="text-end">Se puede aplicar</th>
                            <th class="text-end" style="width: 130px">Aplicar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in deLaMoneda" :key="c.id">
                            <td>{{ c.serie }}-{{ c.numero }}</td>
                            <td>{{ formatFecha(c.fecha_emision) }}</td>
                            <td class="text-end">{{ formatMonto(c.maximo, c.moneda) }}</td>
                            <td>
                                <input v-model="montos[c.id]" type="number" step="0.01" min="0" :max="c.maximo"
                                    class="form-control form-control-sm text-end" :aria-label="`Aplicar a ${c.serie}-${c.numero}`">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template v-if="deOtraMoneda.length">
                <h6 class="small fw-semibold mt-3 mb-1">Compras pendientes en {{ otraMoneda }} (con tipo de cambio)</h6>
                <div class="table-responsive" style="max-height: 240px; overflow-y: auto">
                    <table class="table table-sm small align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Comprobante</th>
                                <th>Emisión</th>
                                <th class="text-end">Se puede aplicar</th>
                                <th class="text-end" style="width: 120px">Aplicar {{ otraMoneda }}</th>
                                <th class="text-end" style="width: 100px">T.C.</th>
                                <th class="text-end">Sale del pago</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in deOtraMoneda" :key="c.id">
                                <td>{{ c.serie }}-{{ c.numero }}</td>
                                <td>{{ formatFecha(c.fecha_emision) }}</td>
                                <td class="text-end">{{ formatMonto(c.maximo, c.moneda) }}</td>
                                <td>
                                    <input v-model="montos[c.id]" type="number" step="0.01" min="0" :max="c.maximo"
                                        class="form-control form-control-sm text-end" :aria-label="`Aplicar a ${c.serie}-${c.numero}`">
                                </td>
                                <td>
                                    <!-- 6 decimales: así el pago puede cancelar exacto en la otra moneda -->
                                    <input :value="tcDe(c.id)" type="number" step="0.000001" min="0.000001"
                                        class="form-control form-control-sm text-end"
                                        :aria-label="`Tipo de cambio de ${c.serie}-${c.numero}`"
                                        @input="tcCompra[c.id] = valorDe($event)">
                                </td>
                                <td class="text-end text-nowrap">{{ formatMonto(equivalente(c) / 100, moneda) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <div class="small mt-1" :class="seExcede ? 'text-danger' : 'text-muted'">
                {{ seExcede ? 'Se está repartiendo más que el monto del pago.' : `Queda a cuenta: ${formatMonto(aCuenta / 100, moneda)}` }}
            </div>

            <div class="mt-2">
                <label class="form-label small mb-1" for="pago-obs">Observaciones</label>
                <input id="pago-obs" v-model="observaciones" type="text" maxlength="2000" class="form-control form-control-sm">
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-secondary" @click="abierto = false">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-primary" :disabled="guardando || seExcede">
                    {{ guardando ? 'Guardando…' : 'Registrar pago' }}
                </button>
            </div>
        </form>
    </b-modal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import CamposOrigenPago from '@/components/Compras/CamposOrigenPago.vue';
import ProveedorSelector from '@/components/Compras/ProveedorSelector.vue';
import httpClient from '@/helpers/http-client';
import { enCentimos, formatMonto } from '@/helpers/compras';
import { formatFecha, hoyPeru } from '@/helpers/fecha';
import { datosDeOrigen, enMonedaDelPago, faltaEnOrigen, filasAplicadas, origenVacio } from '@/helpers/pagosProveedor';
import { ventaSunat } from '@/helpers/tipoCambioSugerido';
import type { CompraProveedor } from '@/types/compras';
import type { CompraPendiente, CuentaParaPagar, MetodoDeCaja } from '@/types/pagosProveedor';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/**
 * `compra`: el pago se abre desde una compra, con su saldo precargado.
 * `proveedor` (T-137): proveedor y moneda elegidos de entrada, editables; solo sin `compra`.
 */
const props = defineProps<{
    compra?: { id: number; moneda: 'PEN' | 'USD'; proveedor: CompraProveedor | null } | null;
    proveedor?: { proveedor: CompraProveedor; moneda: 'PEN' | 'USD' } | null;
}>();
const emit = defineEmits<{ (e: 'guardado'): void }>();
const abierto = defineModel<boolean>({ required: true });

const proveedor = ref<CompraProveedor | null>(null);
const moneda = ref<'PEN' | 'USD'>('PEN');
const monto = ref<string | number>('');
const observaciones = ref('');
const origen = ref(origenVacio());
const cuentas = ref<CuentaParaPagar[]>([]);
const metodos = ref<MetodoDeCaja[]>([]);
const pendientes = ref<CompraPendiente[]>([]);
const montos = ref<Record<number, string | number>>({});
// T-140: la venta SUNAT de la fecha del pago, y lo que la persona escribió encima.
const sugerido = ref<string | null>(null);
const tcEscrito = ref<string | null>(null);
const tcCompra = ref<Record<number, string>>({});
const error = ref('');
const guardando = ref(false);
let pedido = 0;

const valorDe = (e: Event) => (e.target as HTMLInputElement).value;
// En Caja la fecha la pone el servidor: hoy.
const fechaDelPago = computed(() => (origen.value.origen === 'banco' && origen.value.fecha) || hoyPeru());
const tipoCambio = computed(() => tcEscrito.value ?? sugerido.value ?? '');

const otraMoneda = computed(() => (moneda.value === 'PEN' ? 'USD' : 'PEN'));
const deLaMoneda = computed(() => pendientes.value.filter((c) => c.moneda === moneda.value));
const deOtraMoneda = computed(() => pendientes.value.filter((c) => c.moneda !== moneda.value));
// El T.C. propuesto para una compra de la otra moneda: el del pago si es en USD; si no, SUNAT.
const tcDe = (compraId: number) => tcCompra.value[compraId] ?? (moneda.value === 'USD' ? tipoCambio.value : (sugerido.value ?? ''));
const equivalente = (c: CompraPendiente) => enMonedaDelPago(enCentimos(montos.value[c.id]), c.moneda, tcDe(c.id));

/** En céntimos de la moneda del pago (negativo = se está repartiendo de más). */
const aCuenta = computed(() => enCentimos(monto.value)
    - deLaMoneda.value.reduce((suma, c) => suma + enCentimos(montos.value[c.id]), 0)
    - deOtraMoneda.value.reduce((suma, c) => suma + equivalente(c), 0));
const seExcede = computed(() => aCuenta.value < 0);
const mensajeDe = (e: any) => e.response?.data?.message ?? 'No se pudo completar la operación.';

const sugerir = async () => {
    const fecha = fechaDelPago.value;
    const venta = await ventaSunat(fecha);
    if (fecha === fechaDelPago.value) sugerido.value = venta;
};

const cargarPendientes = async () => {
    const actual = ++pedido;
    pendientes.value = [];
    montos.value = {};
    tcCompra.value = {};
    if (!abierto.value || !proveedor.value) return;
    try {
        const { data } = await httpClient.get(`proveedores/${proveedor.value.id}/compras-pendientes`);
        if (actual !== pedido) return;
        pendientes.value = data.compras;
        const elegida = deLaMoneda.value.find((c) => c.id === props.compra?.id);
        if (elegida) {
            montos.value = { [elegida.id]: elegida.maximo };
            monto.value = elegida.maximo;
        }
    } catch (e) {
        error.value = mensajeDe(e);
    }
};

watch(abierto, async (valor) => {
    if (!valor) return;
    proveedor.value = props.compra ? props.compra.proveedor : (props.proveedor?.proveedor ?? null);
    moneda.value = props.compra?.moneda ?? props.proveedor?.moneda ?? 'PEN';
    monto.value = '';
    observaciones.value = '';
    origen.value = origenVacio();
    tcEscrito.value = null;
    sugerido.value = null;
    error.value = '';
    cargarPendientes();
    sugerir();
    try {
        const { data } = await httpClient.get('compras/catalogos');
        cuentas.value = data.cuentas_bancarias;
        metodos.value = data.metodos_pago;
    } catch (e) {
        error.value = mensajeDe(e);
    }
});

watch(() => [proveedor.value?.id, moneda.value], () => {
    if (abierto.value) cargarPendientes();
});
watch(fechaDelPago, () => {
    if (abierto.value) sugerir();
});

const guardar = async () => {
    error.value = '';
    const conMonto = deOtraMoneda.value.filter((c) => enCentimos(montos.value[c.id]) > 0);
    const falta = !proveedor.value ? 'Elige el proveedor.'
        : enCentimos(monto.value) <= 0 ? 'Escribe el monto del pago.'
            : moneda.value === 'USD' && !(Number(tipoCambio.value) > 0) ? 'En dólares se necesita el tipo de cambio.'
                : conMonto.some((c) => !(Number(tcDe(c.id)) > 0)) ? 'Indica el tipo de cambio de las compras en otra moneda.'
                    : faltaEnOrigen(origen.value);
    if (falta) {
        error.value = falta;
        return;
    }

    guardando.value = true;
    try {
        const { data } = await httpClient.post('compras/pagos', {
            proveedor_id: proveedor.value!.id,
            moneda: moneda.value,
            monto: String(monto.value),
            tipo_cambio: moneda.value === 'USD' ? String(tipoCambio.value) : null,
            observaciones: observaciones.value.trim() || null,
            ...datosDeOrigen(origen.value),
            aplicaciones: filasAplicadas(montos.value, Object.fromEntries(conMonto.map((c) => [c.id, String(tcDe(c.id))]))),
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
