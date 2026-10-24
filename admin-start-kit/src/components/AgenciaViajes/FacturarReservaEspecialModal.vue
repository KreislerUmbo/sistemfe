<template>
    <!-- Facturación especial (2026-08-20): cada pasajero termina en un único
         comprobante. Se elige a quién se factura en esta pasada, con su propio
         cliente y texto, y el modal sigue abierto para el resto. -->
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">Facturación especial</h6>
                    <button class="btn-close" @click="emit('cerrar')"></button>
                </div>
                <div class="modal-body">
                    <!-- F1 (23-oct-2026): todos los pasajeros ya tienen comprobante pero
                         quedan servicios (p. ej. vuelos a un RUC y el resto a otro). -->
                    <div v-if="soloServicios" class="alert alert-info small py-2 mb-3">
                        <i class="fas fa-circle-info me-1"></i>
                        Todos los pasajeros ya tienen comprobante: esta factura lleva <strong>solo los servicios que marques</strong> abajo, al cliente que elijas.
                    </div>
                    <template v-else>
                        <label class="form-label small fw-semibold text-secondary">¿A quién le facturamos en esta pasada?</label>
                        <p class="small text-muted mb-2">Marca uno o varios pasajeros — cada grupo genera su propio comprobante, con su propio cliente. Puedes repetir esto después para el resto.</p>
                        <div class="d-flex flex-column gap-1 mb-3" style="max-height:160px;overflow-y:auto">
                            <label v-for="p in pasajerosPendientes" :key="p.id" class="small d-flex align-items-center border rounded px-2 py-1" style="cursor:pointer">
                                <input type="checkbox" class="form-check-input me-2" v-model="form.pasajero_ids" :value="p.id">
                                {{ nombrePasajero(p) }}
                            </label>
                        </div>
                    </template>

                    <!-- F1 (23-oct-2026): a nombre de quién sale, arriba y siempre visible. -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">¿A nombre de quién sale el comprobante?</label>
                        <ClienteComprobanteSelector v-model="cliente" @nuevo="abrirClienteRapido" />
                        <small class="text-muted">Los pasajeros marcados deciden qué servicios entran; el cliente decide a nombre de quién (DNI o RUC).</small>
                    </div>

                    <div v-if="cargando" class="small text-muted mb-2">
                        <span class="spinner-border spinner-border-sm me-1"></span>Calculando...
                    </div>

                    <template v-else-if="preview">
                        <!-- Servicios compartidos (p. ej. habitación doble) que esperan al
                             otro pasajero que también los usa. -->
                        <div v-if="preview.items_pendientes_por_pasajero_faltante.length > 0" class="alert alert-secondary small py-2 mb-2">
                            <i class="fas fa-circle-info me-1"></i>
                            {{ preview.items_pendientes_por_pasajero_faltante.length }} servicio(s) compartido(s) quedan pendientes porque los comparte otro pasajero que no está en esta selección:
                            {{ preview.items_pendientes_por_pasajero_faltante.map(it => it.nombre).join(', ') }}.
                        </div>

                        <!-- Servicios sin pasajero asignado: el vendedor decide si van aquí. -->
                        <div v-if="preview.items_sin_asignar_disponibles.length > 0" class="mb-2">
                            <label class="form-label small fw-semibold text-secondary">Otros servicios sin pasajero asignado (agrégalos si corresponden acá)</label>
                            <div class="d-flex flex-column gap-1" style="max-height:120px;overflow-y:auto">
                                <label v-for="it in preview.items_sin_asignar_disponibles" :key="it.reserva_item_id" class="small d-flex justify-content-between align-items-center border rounded px-2 py-1" style="cursor:pointer">
                                    <span>
                                        <input type="checkbox" class="form-check-input me-2" v-model="form.reserva_item_ids_manual" :value="it.reserva_item_id">
                                        {{ it.nombre }}
                                    </span>
                                    <span class="fw-semibold">{{ moneda }} {{ Number(it.total).toFixed(2) }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Anticipos (Tier 0, Adelantos↔Reservas): son de la reserva
                             completa, no de un pasajero; el vendedor elige cuáles aplicar. -->
                        <div v-if="!preview.bloqueado_tributario && (preview.anticipos_disponibles?.length ?? 0) > 0" class="mb-2">
                            <label class="form-label small fw-semibold text-secondary">Aplicar anticipos ya cobrados a este comprobante</label>
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:36px;"></th>
                                        <th>Anticipo</th>
                                        <th class="text-end" style="width:120px;">Disponible</th>
                                        <th style="width:130px;">Monto a aplicar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="a in anticipos" :key="a.id">
                                        <td>
                                            <input type="checkbox" v-model="a.seleccionado" @change="a.monto_aplicado = a.seleccionado ? a.disponible : 0">
                                        </td>
                                        <td>Anticipo #{{ a.advance_id }}</td>
                                        <td class="text-end">{{ a.moneda === 'USD' ? 'US$' : 'S/' }} {{ a.disponible.toFixed(2) }}</td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm" min="0" :max="a.disponible" step="0.01"
                                                v-model.number="a.monto_aplicado" :disabled="!a.seleccionado">
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <OpcionesFacturacionCampos :opciones="opciones" :moneda-cotizacion="moneda"
                            :tipo-cambio-sugerido="preview.tipo_cambio_sugerido" :serie-resuelta="preview.serie_resuelta" />

                        <!-- F1 (23-oct-2026): qué entra y cuánto, también con bloqueo. -->
                        <DetalleComprobantePreview :preview="preview" :anticipo-aplicado="anticipoAplicado" seleccionable @alternar="alternarServicio" />
                        <div v-if="(preview.items_por_destino_tributario?.length ?? 0) > 0" class="mb-2 small">
                            <span class="text-muted d-block mb-1">Tratamiento tributario de lo seleccionado:</span>
                            <span v-for="it in preview.items_por_destino_tributario" :key="it.reserva_item_id"
                                class="badge bg-light text-dark border me-1 mb-1">
                                {{ it.nombre }}: {{ etiquetaTipAfeIgv(it.tip_afe_igv) }}
                            </span>
                        </div>

                        <!-- Guardia tributario: este grupo mezcla tratamientos distintos. -->
                        <div v-if="preview.bloqueado_tributario" class="alert alert-danger mb-0">
                            <i class="fas fa-triangle-exclamation me-2"></i>
                            <strong>No se puede facturar este grupo así todavía.</strong>
                            <p class="mb-1 mt-1">{{ preview.motivo }}</p>
                            <ul v-if="(preview.items_por_destino_tributario?.length ?? 0) > 0" class="mb-0 small ps-3">
                                <li v-for="it in preview.items_por_destino_tributario" :key="it.reserva_item_id">
                                    {{ it.nombre }} — <span class="fw-semibold">{{ it.destino_tributario }}</span>
                                </li>
                            </ul>
                            <button v-if="(preview.items_sin_confirmar_ids?.length ?? 0) > 0" type="button"
                                class="btn btn-sm btn-outline-danger mt-2"
                                @click="emit('confirmar-tributario', preview.items_sin_confirmar_ids ?? [])">
                                Confirmar tratamiento tributario
                            </button>
                            <!-- F1 (23-oct-2026): que se vea que hay más pasos detrás del bloqueo. -->
                            <p class="small mb-0 mt-2 text-body-secondary">
                                Cuando lo resuelvas, aquí aparecen el tipo de comprobante (factura o boleta), el total y los anticipos para descontar.
                            </p>
                        </div>

                        <template v-else>
                            <CondicionPagoCampos v-model:condicion="condicionPago" v-model:cronograma="cronograma"
                                :saldo="saldoACobrar" :moneda="opciones.moneda" :fecha-base="hoyPeru()" />
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">Tipo de comprobante</label>
                                    <select class="form-select form-select-sm" v-model="form.tipo_comprobante_codigo">
                                        <option value="01" :disabled="!clienteTieneRuc">Factura</option>
                                        <option value="03">Boleta</option>
                                    </select>
                                    <small v-if="!clienteTieneRuc" class="text-muted">El cliente elegido no tiene RUC — solo puede recibir boleta.</small>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">Total de este comprobante</label>
                                    <input v-if="!opciones.requiereOverride || preview.tipo_cambio_aplicado" type="text"
                                        class="form-control form-control-sm fw-bold" disabled
                                        :value="`${opciones.moneda} ${Number(preview.total ?? 0).toFixed(2)}`">
                                    <input v-else type="text" class="form-control form-control-sm fw-bold text-danger" disabled
                                        value="Ingresa un tipo de cambio para calcular el total">
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Texto para este comprobante (opcional)</label>
                                <textarea class="form-control form-control-sm" rows="2"
                                    placeholder="Ej: Servicio de movilización y hospedaje — comisión de servicio"
                                    v-model="form.texto_personalizado"></textarea>
                            </div>

                            <p class="small text-muted mb-0">
                                <i class="fas fa-info-circle me-1"></i>La venta se crea pendiente de cobro. Cobrarla y
                                enviarla a SUNAT se hace después, desde la pantalla normal de ventas.
                            </p>
                        </template>
                    </template>

                    <p v-else-if="form.pasajero_ids.length === 0" class="small text-muted mb-0">
                        Marca al menos un pasajero para ver la propuesta de comprobante.
                    </p>
                </div>
                <div class="modal-footer">
                    <!-- F1 (23-oct-2026): a nombre de quién sale, junto al botón. -->
                    <span class="small me-auto" :class="cliente ? 'text-body' : 'text-warning-emphasis'">
                        <template v-if="cliente">
                            Se emitirá a: <strong>{{ cliente.full_name }}</strong> — {{ cliente.n_document }}
                        </template>
                        <template v-else><i class="fas fa-circle-exclamation me-1"></i>Elige a nombre de quién sale el comprobante</template>
                    </span>
                    <button class="btn btn-outline-secondary btn-sm" @click="emit('cerrar')">Cerrar</button>
                    <button v-if="preview && !preview.bloqueado_tributario" class="btn btn-primary btn-sm"
                        :disabled="facturando || cargando || form.pasajero_ids.length === 0 || !cliente
                            || !hayServiciosQueEntran"
                        @click="confirmar">
                        <span v-if="facturando" class="spinner-border spinner-border-sm me-1"></span>Emitir comprobante de este grupo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cliente nuevo sin salir del modal (2026-09-24): mismo componente que
         Venta Directa (búsqueda de RUC/DNI + alta manual). -->
    <div v-if="mostrarClienteRapido" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">Registrar cliente rápido</h6>
                    <button class="btn-close" @click="mostrarClienteRapido = false"></button>
                </div>
                <div class="modal-body">
                    <ClientFormQuick :initial-data="clienteRapidoDatos" @saved="onClienteRapidoCreado" @cancel="mostrarClienteRapido = false" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Modal "Facturación especial" de reservas/detalle.vue (salió de esa
// pantalla en la auditoría reserva + facturación, punto 5, 23-oct-2026).
// Factura un grupo de pasajeros (o, sin pasajeros pendientes, solo los
// servicios marcados) a un cliente elegido, con anticipos opcionales.
import { computed, onMounted, ref, toRef, watch } from 'vue';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import ClienteComprobanteSelector, { type ClienteComprobante } from '@/components/AgenciaViajes/ClienteComprobanteSelector.vue';
import DetalleComprobantePreview from '@/components/AgenciaViajes/DetalleComprobantePreview.vue';
import OpcionesFacturacionCampos from '@/components/AgenciaViajes/OpcionesFacturacionCampos.vue';
import CondicionPagoCampos from '@/components/AgenciaViajes/CondicionPagoCampos.vue';
import type { CuotaCronograma } from '@/components/CronogramaCuotasCampos.vue';
import { validarCondicionPago, payloadCondicionPago, type CondicionPago } from '@/helpers/condicionPago';
import { hoyPeru } from '@/helpers/fecha';
import { useOpcionesFacturacion } from '@/composables/agencia-viajes/useOpcionesFacturacion';
import { useToast } from '@/composables/useToast';
import { etiquetaTipAfeIgv } from '@/helpers/tratamientoTributarioAgencia';
import { nombrePasajero, pasajeroIdsParaFacturar } from '@/helpers/reservaPasajero';
import { reservaFacturacionService, type AnticipoDisponiblePreview, type PrepararFacturaResponse } from '@/services/admin/reservaFacturacionService';
import type { Reserva, ReservaPasajero } from '@/types/agencia-viajes';

const props = defineProps<{
    reserva: Reserva;
    moneda: 'PEN' | 'USD';
    pasajerosPendientes: ReservaPasajero[];
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

// Condición de pago (fila F5): contado por defecto; a crédito se financia el
// saldo que queda tras los anticipos (lo calcula el backend en la vista previa).
const condicionPago = ref<CondicionPago>('contado');
const cronograma = ref<CuotaCronograma[]>([]);
const saldoACobrar = computed(() => preview.value?.saldo_a_cobrar ?? Number(preview.value?.total ?? 0));
const cliente = ref<ClienteComprobante | null>(null);

type Formulario = {
    pasajero_ids: number[];
    reserva_item_ids_manual: number[];
    // Desmarcados en "Qué entra en este comprobante": quedan pendientes.
    reserva_item_ids_excluidos: number[];
    tipo_comprobante_codigo: '01' | '03';
    texto_personalizado: string;
};
const formVacio = (pasajeroIds: number[]): Formulario => ({
    pasajero_ids: pasajeroIds, reserva_item_ids_manual: [], reserva_item_ids_excluidos: [], tipo_comprobante_codigo: '01', texto_personalizado: '',
});
const form = ref<Formulario>(formVacio([]));

// F1 (23-oct-2026): sin pasajeros pendientes, solo servicios; se mandan
// todos los pasajeros (ya facturados), igual que "Facturar".
const soloServicios = computed(() => props.pasajerosPendientes.length === 0);

// Anticipos: casilla + monto editable, como en sale/register.vue.
type AnticipoMarcable = AnticipoDisponiblePreview & { seleccionado: boolean; monto_aplicado: number };
const anticipos = ref<AnticipoMarcable[]>([]);
// F1: lo que se descuenta, para el "saldo a cobrar" del detalle.
const anticipoAplicado = computed(() => anticipos.value
    .filter((a) => a.seleccionado)
    .reduce((s, a) => s + (Number(a.monto_aplicado) || 0), 0));

// Los anticipos marcados, como los recibe el backend (vista previa y emisión).
const anticiposMarcados = () => anticipos.value
    .filter((a) => a.seleccionado && a.monto_aplicado > 0)
    .map((a) => ({ advance_id: a.advance_id, amount: a.monto_aplicado }));

const refrescarPreview = async () => {
    if (form.value.pasajero_ids.length === 0) {
        preview.value = null;
        return;
    }
    cargando.value = true;
    try {
        preview.value = await reservaFacturacionService.prepararFactura(
            props.reserva.id,
            form.value.pasajero_ids,
            form.value.reserva_item_ids_manual,
            {
                ...opciones.opcionesPreview(form.value.tipo_comprobante_codigo),
                reservaItemIdsExcluidos: form.value.reserva_item_ids_excluidos,
                // Para el saldo a cobrar tras los anticipos (fila F5).
                clientId: cliente.value?.id,
                advanceApplications: anticiposMarcados(),
            },
        );
        opciones.proponerTipoCambio(preview.value.tipo_cambio_sugerido);
        // La vista previa se pide de nuevo con cada cambio: se conserva lo
        // ya marcado de cada anticipo.
        const previos = new Map(anticipos.value.map((a) => [a.advance_id, a]));
        anticipos.value = (preview.value.anticipos_disponibles ?? []).map((a) => {
            const previo = previos.get(a.advance_id);
            return { ...a, seleccionado: previo?.seleccionado ?? false, monto_aplicado: previo?.monto_aplicado ?? 0 };
        });
        // Si el pasajero elegido ya tiene perfil de cliente, se propone (no obliga).
        if (preview.value.cliente_sugerido && !cliente.value) {
            cliente.value = preview.value.cliente_sugerido;
        }
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo calcular la vista previa de la factura');
        preview.value = null;
    } finally {
        cargando.value = false;
    }
};

const opciones = useOpcionesFacturacion(toRef(props, 'moneda'), refrescarPreview);

watch(
    () => [form.value.pasajero_ids, form.value.reserva_item_ids_manual, form.value.reserva_item_ids_excluidos],
    refrescarPreview,
    { deep: true },
);
// Boleta o factura cambia la serie que se resuelve.
watch(() => form.value.tipo_comprobante_codigo, refrescarPreview);

// Casilla de un servicio en "Qué entra en este comprobante" (24-oct-2026).
// Un servicio "sin asignar" entra porque se marcó en su lista: desmarcarlo lo
// quita de esa lista. Cualquier otro (los de los pasajeros y su parte de los
// tours) pasa a "queda pendiente" para otro comprobante, o vuelve a entrar.
const alternarServicio = (ids: number[], entra: boolean) => {
    const manuales = form.value.reserva_item_ids_manual;
    if (!entra && ids.every((id) => manuales.includes(id))) {
        form.value.reserva_item_ids_manual = manuales.filter((id) => !ids.includes(id));
        return;
    }
    const excluidos = form.value.reserva_item_ids_excluidos.filter((id) => !ids.includes(id));
    form.value.reserva_item_ids_excluidos = entra ? excluidos : [...excluidos, ...ids];
};

// No se emite un comprobante vacío (el backend también lo rechaza).
const hayServiciosQueEntran = computed(() => (preview.value?.detalle ?? []).some((f) => !f.excluido));

// Factura ('01') exige RUC (catálogo 06 SUNAT, '6'); el backend también lo valida.
const clienteTieneRuc = computed(() => cliente.value?.cod_tipo_doc_sunat === '6');
watch(cliente, (c) => {
    if (c && c.cod_tipo_doc_sunat !== '6' && form.value.tipo_comprobante_codigo === '01') {
        form.value.tipo_comprobante_codigo = '03';
    }
});

// El saldo a cobrar (para las cuotas a crédito, fila F5) depende del cliente
// (un anticipo solo se descuenta al mismo cliente) y de los anticipos
// marcados: si cambian, se vuelve a pedir la vista previa. Los anticipos se
// comparan por lo marcado (no por la lista, que la vista previa rearma) y con
// una espera, porque el monto se edita tecla a tecla.
watch(() => cliente.value?.id, refrescarPreview);
let esperaAnticipos: ReturnType<typeof setTimeout> | undefined;
watch(() => anticiposMarcados().map((a) => `${a.advance_id}:${a.amount}`).join(','), () => {
    clearTimeout(esperaAnticipos);
    esperaAnticipos = setTimeout(refrescarPreview, 400);
});

// Cliente rápido: si lo escrito en el buscador parece un RUC (11 dígitos),
// se precarga para no escribirlo dos veces.
const mostrarClienteRapido = ref(false);
const clienteRapidoDatos = ref<{ type_document: string; n_document: string } | null>(null);
const abrirClienteRapido = (texto: string) => {
    clienteRapidoDatos.value = /^\d{11}$/.test(texto) ? { type_document: 'RUC', n_document: texto } : null;
    mostrarClienteRapido.value = true;
};
const onClienteRapidoCreado = (nuevo: ClienteComprobante) => {
    cliente.value = nuevo;
    mostrarClienteRapido.value = false;
};

const empezar = (pasajeroIds: number[]) => {
    form.value = formVacio(pasajeroIds);
    preview.value = null;
    anticipos.value = [];
    cliente.value = null;
    opciones.resetear();
    condicionPago.value = 'contado';
    cronograma.value = [];
};

const confirmar = async () => {
    if (!cliente.value) return;
    const error = opciones.validar() ?? validarCondicionPago(condicionPago.value, cronograma.value, saldoACobrar.value);
    if (error) {
        toast.error(error);
        return;
    }
    facturando.value = true;
    try {
        const anticiposAplicados = anticiposMarcados();

        const res = await reservaFacturacionService.facturar(props.reserva.id, {
            pasajero_ids: form.value.pasajero_ids,
            reserva_item_ids_manual: form.value.reserva_item_ids_manual,
            reserva_item_ids_excluidos: form.value.reserva_item_ids_excluidos,
            tipo_comprobante_codigo: form.value.tipo_comprobante_codigo,
            client_id: cliente.value.id,
            texto_personalizado: form.value.texto_personalizado || null,
            advance_applications: anticiposAplicados.length > 0 ? anticiposAplicados : undefined,
            ...payloadCondicionPago(condicionPago.value, cronograma.value),
            ...opciones.payload(),
        });
        toast.success(`${res.message} (serie ${res.serie})`);
        await props.recargar();
        // Si quedan pasajeros, el modal sigue abierto para el siguiente grupo.
        if (props.pasajerosPendientes.length > 0) {
            empezar([]);
        } else {
            emit('cerrar');
        }
    } catch (error: any) {
        // El guardia tributario también puede rechazar aquí (422).
        toast.error(error.response?.data?.message ?? 'No se pudo facturar la reserva');
    } finally {
        facturando.value = false;
    }
};

// La pantalla lo llama tras confirmar el tratamiento tributario de un servicio.
defineExpose({ refrescarPreview });

onMounted(() => {
    empezar(pasajeroIdsParaFacturar(props.reserva.pasajeros ?? [], props.pasajerosPendientes));
    opciones.cargarSucursales();
});
</script>
