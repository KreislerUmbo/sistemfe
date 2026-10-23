<template>
    <div class="mb-3">
        <label class="form-label small fw-semibold text-secondary">Qué entra en este comprobante</label>
        <div v-if="detalle.length === 0" class="small text-muted fst-italic border rounded px-2 py-2">
            Todavía no entra ningún servicio. Marca pasajeros o servicios.
        </div>
        <div v-else class="table-responsive border rounded">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light">
                    <tr><th class="ps-2">Servicio</th><th>Fecha</th><th class="text-end pe-2">Monto</th></tr>
                </thead>
                <tbody>
                    <tr v-for="fila in detalle" :key="fila.reserva_item_ids.join('-')">
                        <td class="ps-2">
                            {{ fila.nombre }}
                            <!-- Un tour cobrado por grupo: entra solo la parte de estos pasajeros. -->
                            <div v-if="fila.parcial" class="text-muted" style="font-size:.75rem">
                                Parte de {{ fila.pasajeros_grupo }} de {{ fila.pasajeros_total }} pasajeros — del total del tour {{ dinero(fila.total_servicio) }}
                            </div>
                        </td>
                        <td class="text-nowrap">{{ fila.fecha ? formatFecha(fila.fecha) : '—' }}</td>
                        <td class="text-end pe-2 text-nowrap">{{ dinero(fila.monto) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td class="ps-2" colspan="2">Total</td>
                        <td class="text-end pe-2 text-nowrap">{{ dinero(totalDetalle) }}</td>
                    </tr>
                    <tr v-if="anticipoAplicado > 0">
                        <td class="ps-2" colspan="2">Anticipos que se descuentan</td>
                        <td class="text-end pe-2 text-nowrap">− {{ dinero(anticipoAplicado) }}</td>
                    </tr>
                    <tr v-if="anticipoAplicado > 0" class="fw-semibold">
                        <td class="ps-2" colspan="2">Saldo a cobrar</td>
                        <td class="text-end pe-2 text-nowrap">{{ dinero(Math.max(0, totalDetalle - anticipoAplicado)) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Cómo sale en la factura que va a SUNAT: una línea por categoría y tipo de IGV. -->
        <div v-if="!preview.bloqueado_tributario && (preview.grupos_propuestos?.length ?? 0) > 0" class="small text-muted mt-1">
            En la factura:
            <span v-for="(g, k) in preview.grupos_propuestos" :key="k" class="me-2">
                <strong>{{ g.categoria }}</strong> {{ etiquetaIgv(g.tip_afe_igv) }}: {{ g.total.toFixed(2) }}<span v-if="g.igv > 0"> (IGV {{ g.igv.toFixed(2) }})</span>
            </span>
        </div>
        <div v-if="monedaDistinta" class="small text-muted mt-1">
            Montos en {{ preview.moneda_cotizacion_original }}; el comprobante sale en {{ preview.moneda_facturacion }} (ver el total convertido abajo).
        </div>
    </div>
</template>

<script setup lang="ts">
// F1 (23-oct-2026, pedido del usuario): qué servicios entran en el comprobante
// y cuánto, en "Facturar" y "Facturación especial". El backend manda `detalle`
// también cuando hay un bloqueo tributario, así que se ve siempre.
import { computed } from 'vue';
import { formatFecha } from '@/helpers/fecha';
import type { PrepararFacturaResponse } from '@/services/admin/reservaFacturacionService';

const props = withDefaults(defineProps<{
    preview: PrepararFacturaResponse;
    // Anticipos marcados para descontar (en la moneda del comprobante).
    anticipoAplicado?: number;
}>(), { anticipoAplicado: 0 });

const detalle = computed(() => props.preview.detalle ?? []);
const totalDetalle = computed(() => Math.round(detalle.value.reduce((s, f) => s + f.monto, 0) * 100) / 100);
const monedaDistinta = computed(() => !!props.preview.moneda_facturacion
    && props.preview.moneda_facturacion !== props.preview.moneda_cotizacion_original);

const simbolo = computed(() => (props.preview.moneda_cotizacion_original === 'USD' ? 'US$' : 'S/'));
const dinero = (n: number) => `${simbolo.value} ${n.toFixed(2)}`;
const etiquetaIgv = (codigo?: string) =>
    ({ '10': 'gravado', '20': 'exonerado', '30': 'inafecto', '40': 'exportación' } as Record<string, string>)[codigo ?? ''] ?? '';
</script>
