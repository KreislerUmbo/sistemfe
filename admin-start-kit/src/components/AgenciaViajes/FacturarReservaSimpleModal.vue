<template>
    <!-- Facturar (2026-08-20): el camino de todos los días. Un solo
         comprobante con todos los pasajeros y servicios pendientes, a nombre
         del cliente de la cotización. Mismo backend que "Facturación
         especial": solo arma el pedido distinto. -->
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">Facturar reserva</h6>
                    <button class="btn-close" @click="emit('cerrar')"></button>
                </div>
                <div class="modal-body">
                    <!-- F1 (23-oct-2026): a nombre de quién sale, siempre visible. -->
                    <div class="border rounded px-2 py-1 small bg-light mb-3">
                        <i class="fas fa-user-tie me-1 text-muted"></i>A nombre de:
                        <strong>{{ cabecera?.cliente?.full_name ?? 'Sin cliente' }}</strong>{{ cabecera?.cliente?.n_document ? ` — ${cabecera.cliente.n_document}` : '' }}
                        <span class="text-muted">(cliente de la cotización; para otro DNI o RUC usa <strong>Facturación especial</strong>)</span>
                    </div>
                    <div v-if="cargando" class="small text-muted mb-2">
                        <span class="spinner-border spinner-border-sm me-1"></span>Calculando...
                    </div>

                    <template v-else-if="preview">
                        <OpcionesFacturacionCampos :opciones="opciones" :moneda-cotizacion="moneda"
                            :tipo-cambio-sugerido="preview.tipo_cambio_sugerido" :serie-resuelta="preview.serie_resuelta" />
                        <!-- F1 (23-oct-2026): qué entra y cuánto, también con bloqueo. -->
                        <DetalleComprobantePreview :preview="preview" />
                        <div v-if="(preview.items_por_destino_tributario?.length ?? 0) > 0" class="mb-2 small">
                            <span class="text-muted d-block mb-1">Tratamiento tributario de lo seleccionado:</span>
                            <span v-for="it in preview.items_por_destino_tributario" :key="it.reserva_item_id"
                                class="badge bg-light text-dark border me-1 mb-1">
                                {{ it.nombre }}: {{ etiquetaTipAfeIgv(it.tip_afe_igv) }}
                            </span>
                        </div>

                        <div v-if="preview.bloqueado_tributario" class="alert alert-danger mb-0">
                            <i class="fas fa-triangle-exclamation me-2"></i>
                            <strong>No se puede facturar toda la reserva en un solo comprobante.</strong>
                            <p class="mb-1 mt-1">{{ preview.motivo }}</p>
                            <ul v-if="(preview.items_por_destino_tributario?.length ?? 0) > 0" class="mb-1 small ps-3">
                                <li v-for="it in preview.items_por_destino_tributario" :key="it.reserva_item_id">
                                    {{ it.nombre }} — <span class="fw-semibold">{{ it.destino_tributario }}</span>
                                </li>
                            </ul>
                            <button v-if="(preview.items_sin_confirmar_ids?.length ?? 0) > 0" type="button"
                                class="btn btn-sm btn-outline-danger mb-2"
                                @click="emit('confirmar-tributario', preview.items_sin_confirmar_ids ?? [])">
                                Confirmar tratamiento tributario
                            </button>
                            <!-- Solo si el bloqueo es por mezcla: con servicios de la Amazonía sin
                                 confirmar, separar en grupos no lo resuelve (F1, 23-oct-2026). -->
                            <p v-if="(preview.items_sin_confirmar_ids?.length ?? 0) === 0" class="mb-0 small">Usa <strong>Facturación especial</strong> para separar los grupos con distinto tratamiento tributario.</p>
                        </div>

                        <template v-else>
                            <p class="small text-muted mb-2">
                                <template v-if="pasajerosPendientes.length > 0">
                                    Se factura de una vez a {{ pasajerosPendientes.length }} pasajero(s) pendiente(s)
                                </template>
                                <template v-else>
                                    Se facturan {{ itemsPendientesCount }} ítem(s) sueltos pendientes (todos los pasajeros ya fueron facturados)
                                </template>
                                , a nombre de
                                <strong>{{ cabecera?.cliente?.full_name ?? 'el cliente de la cotización' }}</strong>.
                                ¿Necesitas dividirlo en varios comprobantes o cambiar el cliente? Usa <strong>Facturación especial</strong>.
                            </p>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">Tipo de comprobante</label>
                                    <select class="form-select form-select-sm" v-model="tipoComprobante">
                                        <option value="01" :disabled="!clienteTieneRuc">Factura</option>
                                        <option value="03">Boleta</option>
                                    </select>
                                    <small v-if="!clienteTieneRuc" class="text-muted">Este cliente no tiene RUC — solo puede recibir boleta.</small>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">Total a facturar</label>
                                    <input v-if="!opciones.requiereOverride || preview.tipo_cambio_aplicado" type="text"
                                        class="form-control form-control-sm fw-bold" disabled
                                        :value="`${opciones.moneda} ${Number(preview.total ?? 0).toFixed(2)}`">
                                    <input v-else type="text" class="form-control form-control-sm fw-bold text-danger" disabled
                                        value="Ingresa un tipo de cambio para calcular el total">
                                </div>
                            </div>
                            <p class="small text-muted mb-0">
                                <i class="fas fa-info-circle me-1"></i>La venta se crea pendiente de cobro. Cobrarla y
                                enviarla a SUNAT se hace después, desde la pantalla normal de ventas.
                            </p>
                        </template>
                    </template>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary btn-sm" @click="emit('cerrar')">Cerrar</button>
                    <button v-if="preview && !preview.bloqueado_tributario" class="btn btn-primary btn-sm"
                        :disabled="facturando || cargando" @click="confirmar">
                        <span v-if="facturando" class="spinner-border spinner-border-sm me-1"></span>Emitir comprobante
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Modal "Facturar" de reservas/detalle.vue (salió de esa pantalla en la
// auditoría reserva + facturación, punto 5, 23-oct-2026). Toda la reserva
// pendiente en un comprobante, al cliente de la cotización.
import { computed, onMounted, ref, toRef, watch } from 'vue';
import OpcionesFacturacionCampos from '@/components/AgenciaViajes/OpcionesFacturacionCampos.vue';
import DetalleComprobantePreview from '@/components/AgenciaViajes/DetalleComprobantePreview.vue';
import { useOpcionesFacturacion } from '@/composables/agencia-viajes/useOpcionesFacturacion';
import { useToast } from '@/composables/useToast';
import { etiquetaTipAfeIgv } from '@/helpers/tratamientoTributarioAgencia';
import { pasajeroIdsParaFacturar } from '@/helpers/reservaPasajero';
import { reservaFacturacionService, type PrepararFacturaResponse } from '@/services/admin/reservaFacturacionService';
import type { Reserva, ReservaCabecera, ReservaPasajero } from '@/types/agencia-viajes';

const props = defineProps<{
    reserva: Reserva;
    cabecera: ReservaCabecera | null;
    moneda: 'PEN' | 'USD';
    pasajerosPendientes: ReservaPasajero[];
    itemsPendientesCount: number;
    // Vuelve a leer la reserva (la pantalla marca lo facturado).
    recargar: () => Promise<void>;
}>();

const emit = defineEmits<{
    cerrar: [];
    'confirmar-tributario': [itemIds: number[]];
}>();

const toast = useToast();
const cargando = ref(false);
const facturando = ref(false);
const preview = ref<PrepararFacturaResponse | null>(null);

// Factura ('01') exige RUC (catálogo 06 SUNAT, '6'); el backend también lo valida.
const clienteTieneRuc = computed(() => props.cabecera?.cliente?.cod_tipo_doc_sunat === '6');
const tipoComprobante = ref<'01' | '03'>(clienteTieneRuc.value ? '01' : '03');

const pasajeroIds = () => pasajeroIdsParaFacturar(props.reserva.pasajeros ?? [], props.pasajerosPendientes);

const refrescarPreview = async () => {
    cargando.value = true;
    try {
        const opcionesPreview = opciones.opcionesPreview(tipoComprobante.value);
        // Primero se pregunta qué servicios sin pasajero quedan; después se
        // piden todos, porque este modal no pregunta cuáles: van todos.
        const primero = await reservaFacturacionService.prepararFactura(props.reserva.id, pasajeroIds(), [], opcionesPreview);
        const idsSinAsignar = (primero.items_sin_asignar_disponibles ?? []).map((it) => it.reserva_item_id);
        preview.value = idsSinAsignar.length > 0
            ? await reservaFacturacionService.prepararFactura(props.reserva.id, pasajeroIds(), idsSinAsignar, opcionesPreview)
            : primero;
        opciones.proponerTipoCambio(preview.value.tipo_cambio_sugerido);
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo calcular la vista previa de la factura');
        emit('cerrar');
    } finally {
        cargando.value = false;
    }
};

const opciones = useOpcionesFacturacion(toRef(props, 'moneda'), refrescarPreview);

// Boleta o factura cambia la serie que se resuelve.
watch(tipoComprobante, refrescarPreview);

const confirmar = async () => {
    if (!props.cabecera?.cliente?.id) return;
    const error = opciones.validar();
    if (error) {
        toast.error(error);
        return;
    }
    facturando.value = true;
    try {
        const res = await reservaFacturacionService.facturar(props.reserva.id, {
            pasajero_ids: pasajeroIds(),
            reserva_item_ids_manual: (preview.value?.items_sin_asignar_disponibles ?? []).map((it) => it.reserva_item_id),
            tipo_comprobante_codigo: tipoComprobante.value,
            client_id: props.cabecera.cliente.id,
            ...opciones.payload(),
        });
        toast.success(`${res.message} (serie ${res.serie})`);
        emit('cerrar');
        await props.recargar();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo facturar la reserva');
    } finally {
        facturando.value = false;
    }
};

// La pantalla lo llama tras confirmar el tratamiento tributario de un servicio.
defineExpose({ refrescarPreview });

onMounted(() => {
    opciones.cargarSucursales();
    refrescarPreview();
});
</script>
