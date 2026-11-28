<template>
    <!-- T-140 — la ficha de un pago: tipo de cambio, aplicaciones con su diferencia de cambio y
         reembolsos de lo «a cuenta». -->
    <b-modal v-model="abierta" title="Pago a proveedor" hide-footer centered size="lg">
        <div v-if="error" class="alert alert-danger py-2 small" role="alert">{{ error }}</div>
        <div v-if="!pago" class="small text-muted">{{ error ? '' : 'Cargando…' }}</div>
        <template v-else>
            <dl class="row small mb-2">
                <dt class="col-sm-4">Proveedor</dt>
                <dd class="col-sm-8">{{ pago.proveedor?.razon_social }}</dd>
                <dt class="col-sm-4">Fecha y origen</dt>
                <dd class="col-sm-8">
                    {{ formatFecha(pago.fecha) }} ·
                    {{ pago.origen === 'caja'
                        ? `Caja · ${pago.metodo_pago?.name}`
                        : `${pago.cuenta_bancaria?.banco} ${pago.cuenta_bancaria?.numero_cuenta} · op. ${pago.numero_operacion}` }}
                    <span class="badge ms-1" :class="ESTADOS_DEL_PAGO[pago.estado].clase">{{ ESTADOS_DEL_PAGO[pago.estado].texto }}</span>
                </dd>
                <dt class="col-sm-4">Monto</dt>
                <dd class="col-sm-8">{{ formatMonto(pago.monto, pago.moneda) }}</dd>
                <template v-if="pago.moneda === 'USD'">
                    <dt class="col-sm-4">Tipo de cambio</dt>
                    <dd class="col-sm-8">{{ pago.tipo_cambio ? Number(pago.tipo_cambio) : 'sin T.C.' }}</dd>
                </template>
                <template v-if="pago.estado !== 'anulado'">
                    <dt class="col-sm-4">A cuenta</dt>
                    <dd class="col-sm-8">{{ formatMonto(pago.a_cuenta, pago.moneda) }}</dd>
                </template>
                <template v-if="pago.observaciones">
                    <dt class="col-sm-4">Observaciones</dt>
                    <dd class="col-sm-8">{{ pago.observaciones }}</dd>
                </template>
                <template v-if="pago.motivo_anulacion">
                    <dt class="col-sm-4">Anulado</dt>
                    <dd class="col-sm-8 text-danger">{{ pago.motivo_anulacion }}</dd>
                </template>
            </dl>

            <div v-if="!pago.aplicaciones.length" class="small text-muted fst-italic mb-2">Sin compras aplicadas</div>
            <table v-else class="table table-sm small">
                <thead class="table-light">
                    <tr>
                        <th>Compra</th>
                        <th class="text-end">Cancela</th>
                        <th class="text-end">Sale del pago</th>
                        <th class="text-end">Dif. cambio</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in pago.aplicaciones" :key="a.id" :class="{ 'text-muted': a.desaplicada_at }">
                        <td>
                            {{ a.compra?.serie }}-{{ a.compra?.numero }}
                            <small v-if="a.desaplicada_at" class="d-block">Desaplicado: {{ a.motivo_desaplicacion }}</small>
                        </td>
                        <td class="text-end" :class="{ 'text-decoration-line-through': a.desaplicada_at }">
                            {{ formatMonto(a.monto_compra, a.compra?.moneda ?? pago.moneda) }}
                        </td>
                        <td class="text-end" :class="{ 'text-decoration-line-through': a.desaplicada_at }">
                            {{ formatMonto(a.monto, pago.moneda) }}
                            <small v-if="a.tipo_cambio" class="d-block text-muted">T.C. {{ Number(a.tipo_cambio) }}</small>
                        </td>
                        <td class="text-end">
                            {{ !a.desaplicada_at && pago.estado === 'vigente' && a.compra?.moneda === 'USD'
                                ? textoDiferencia(a.diferencia_cambio) : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <ListaReembolsos v-if="pago.reembolsos?.length" :reembolsos="pago.reembolsos" @anular="anularReembolso" />

            <div class="d-flex gap-2">
                <button v-if="puedeReembolsar" type="button" class="btn btn-sm btn-outline-primary" @click="reembolsar">
                    Registrar reembolso
                </button>
                <button type="button" class="btn btn-sm btn-secondary ms-auto" @click="abierta = false">Cerrar</button>
            </div>
        </template>
    </b-modal>
    <RegistrarReembolso v-model="registrando" :destino="destino" @guardado="emit('cambiado')" />
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ListaReembolsos from '@/components/Compras/ListaReembolsos.vue';
import RegistrarReembolso from '@/components/Compras/RegistrarReembolso.vue';
import httpClient from '@/helpers/http-client';
import { enCentimos, formatMonto } from '@/helpers/compras';
import { formatFecha } from '@/helpers/fecha';
import { ESTADOS_DEL_PAGO, textoDiferencia } from '@/helpers/pagosProveedor';
import { pedirMotivo } from '@/helpers/pedirMotivo';
import { useAuthStore } from '@/stores/auth';
import type { DestinoReembolso, PagoProveedor, Reembolso } from '@/types/pagosProveedor';

const emit = defineEmits<{ (e: 'cambiado'): void }>();
/** El pago que se muestra; null = cerrada. */
const pagoId = defineModel<number | null>({ required: true });

const authStore = useAuthStore();
const pago = ref<PagoProveedor | null>(null);
const abierta = ref(false);
const registrando = ref(false);
const destino = ref<DestinoReembolso | null>(null);
const error = ref('');
// Se cerró la ficha para abrir otro diálogo: no hay que soltar el pago.
let ocupada = false;

const puedeReembolsar = computed(() => authStore.isPermitedRoute('compras.pagar')
    && pago.value?.estado === 'vigente' && enCentimos(pago.value.a_cuenta) > 0);

const cargar = async () => {
    error.value = '';
    const id = pagoId.value;
    if (id === null) return;
    abierta.value = true;
    try {
        const { data } = await httpClient.get(`compras/pagos/${id}`);
        if (id === pagoId.value) pago.value = data.pago;
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo cargar el pago.';
    }
};

watch(pagoId, (id) => {
    pago.value = null;
    if (id !== null) cargar();
});
watch(abierta, (valor) => {
    if (!valor && !ocupada) pagoId.value = null;
});

const volver = () => {
    ocupada = false;
    cargar();
};

// Un modal a la vez.
const reembolsar = () => {
    destino.value = {
        proveedor_id: pago.value!.proveedor_id,
        pago_proveedor_id: pago.value!.id,
        moneda: pago.value!.moneda,
        maximo: pago.value!.a_cuenta,
        titulo: `Lo a cuenta del pago del ${formatFecha(pago.value!.fecha)} a ${pago.value!.proveedor?.razon_social}`,
    };
    ocupada = true;
    abierta.value = false;
    registrando.value = true;
};
watch(registrando, (valor) => {
    if (!valor) volver();
});

const anularReembolso = async (reembolso: Reembolso) => {
    ocupada = true;
    abierta.value = false;
    const hecho = await pedirMotivo(
        `Anular reembolso de ${formatMonto(reembolso.monto, reembolso.moneda)}`,
        reembolso.origen === 'caja' ? 'No se borra: el ingreso se corrige en tu turno de caja.' : 'No se borra: queda anulado.',
        'Anular',
        (motivo) => httpClient.post(`compras/reembolsos/${reembolso.id}/anular`, { motivo }),
    );
    if (hecho) emit('cambiado');
    volver();
};
</script>
