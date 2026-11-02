<template>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-code-compare me-2 text-primary"></i>Diferencia con lo vendido</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" :disabled="cargando" @click="$emit('refrescar')" title="Volver a calcular">
                    <span v-if="cargando" class="spinner-border spinner-border-sm"></span>
                    <i v-else class="fas fa-rotate"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" :disabled="!diferencia?.hay_cambios || descargandoPdf" @click="$emit('pdf')">
                    <span v-if="descargandoPdf" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-file-pdf me-1"></i>PDF para el cliente
                </button>
                <button class="btn btn-outline-danger btn-sm" :disabled="procesando" @click="$emit('descartar')">
                    <i class="fas fa-trash me-1"></i>Descartar
                </button>
                <button class="btn btn-success btn-sm" :disabled="!diferencia?.se_puede_aplicar || procesando || cargando"
                    :title="!diferencia?.se_puede_aplicar ? 'Resuelve los bloqueos o haz algún cambio para poder aplicar' : ''" @click="$emit('aplicar')">
                    <span v-if="procesando" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-check me-1"></i>Aplicar a la reserva
                </button>
            </div>
        </div>

        <div class="card-body py-3">
            <div v-if="error" class="alert alert-danger py-2 small mb-0">{{ error }}</div>
            <div v-else-if="!diferencia" class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary"></span></div>

            <template v-else>
                <!-- Totales: solo lo que el backend da como cifra cierta (T-006). -->
                <div class="row g-2 mb-3 text-center">
                    <div class="col-4">
                        <div class="small text-muted">Vendido</div>
                        <div class="fw-semibold">{{ textoMonto(diferencia.totales.vendido, diferencia.moneda) }}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Nuevo</div>
                        <div class="fw-semibold">{{ textoMonto(diferencia.totales.nuevo, diferencia.moneda) }}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Diferencia</div>
                        <div class="fw-bold" :class="diferencia.totales.diferencia > 0 ? 'text-danger' : diferencia.totales.diferencia < 0 ? 'text-success' : ''">
                            {{ textoMonto(diferencia.totales.diferencia, diferencia.moneda) }}
                        </div>
                    </div>
                </div>

                <div v-if="!diferencia.hay_cambios" class="alert alert-light border py-2 small mb-3">
                    Todavía no hay cambios respecto de lo vendido. Edita los servicios o los pasajeros en el cotizador.
                </div>

                <!-- Bloqueos: cada uno lleva al lugar donde se corrige. -->
                <div v-if="diferencia.bloqueos.length" class="mb-3">
                    <div class="small fw-semibold text-danger mb-1">
                        <i class="fas fa-triangle-exclamation me-1"></i>Falta resolver ({{ diferencia.bloqueos.length }})
                    </div>
                    <ul class="list-group list-group-flush border rounded">
                        <li v-for="(b, i) in diferencia.bloqueos" :key="`${b.tipo}-${i}`"
                            class="list-group-item d-flex justify-content-between align-items-center gap-2 py-2 small">
                            <span>{{ b.mensaje }}</span>
                            <span class="d-flex gap-1 flex-shrink-0">
                                <button v-if="b.tipo === 'para_revisar' && b.alternativa_item_id" class="btn btn-outline-primary btn-sm"
                                    @click="$emit('ir-a-item', b.alternativa_item_id)">Ver en el lienzo</button>
                                <button v-if="b.tipo === 'para_revisar' && b.alternativa_item_id" class="btn btn-primary btn-sm"
                                    :disabled="cargando" @click="$emit('confirmar', [b.alternativa_item_id])">Revisado</button>
                                <button v-if="b.tipo === 'servicio_sin_pasajeros' && b.alternativa_item_id" class="btn btn-outline-primary btn-sm"
                                    @click="$emit('ir-a-item', b.alternativa_item_id)">Ver en el lienzo</button>
                                <button v-if="b.tipo === 'requiere_nota_credito' && b.sale_id" class="btn btn-outline-primary btn-sm"
                                    @click="$emit('abrir-comprobante', b.sale_id)">Abrir comprobante</button>
                                <button v-if="b.tipo === 'falta_penalidad' && b.reserva_pasajero_id" class="btn btn-primary btn-sm"
                                    @click="$emit('abrir-penalidad', b.reserva_pasajero_id)">Cargar penalidad</button>
                                <button v-if="b.tipo === 'penalidad_sobrante' && b.alternativa_item_id" class="btn btn-outline-danger btn-sm"
                                    @click="$emit('borrar-penalidad', b.alternativa_item_id)">Borrar penalidad</button>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Servicios "para revisar": con qué pasajeros se vendió y con cuáles queda. -->
                <div v-if="lineasPorConfirmar.length" class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-semibold">Servicios para revisar ({{ lineasPorConfirmar.length }})</span>
                        <span class="d-flex gap-1">
                            <button class="btn btn-outline-primary btn-sm" :disabled="seleccionadas.size === 0 || cargando"
                                @click="confirmarSeleccionadas">Confirmar seleccionadas ({{ seleccionadas.size }})</button>
                            <button class="btn btn-primary btn-sm" :disabled="cargando"
                                @click="$emit('confirmar', lineasPorConfirmar.map((l) => l.alternativa_item_id!))">Confirmar todas</button>
                        </span>
                    </div>
                    <div v-for="l in lineasPorConfirmar" :key="l.clave" class="border rounded p-2 mb-1 small d-flex gap-2 align-items-start">
                        <input type="checkbox" class="form-check-input mt-1" :checked="seleccionadas.has(l.alternativa_item_id!)"
                            @change="alternar(l.alternativa_item_id!)">
                        <div class="flex-grow-1">
                            <div class="fw-semibold">{{ l.descripcion }}</div>
                            <div class="text-muted">
                                <template v-if="l.composicion_vendida || l.composicion_nueva">
                                    {{ textoComposicion(l.composicion_vendida) }} → {{ textoComposicion(l.composicion_nueva) }}
                                </template>
                                <template v-else>Cambió de pasajeros</template>
                                · {{ textoMonto(l.nuevo_centavos, diferencia.moneda) }}
                            </div>
                        </div>
                        <button class="btn btn-link btn-sm p-0" @click="$emit('ir-a-item', l.alternativa_item_id!)">Ver en el lienzo</button>
                    </div>
                </div>

                <!-- Líneas con cambio. -->
                <div v-if="lineasConCambio.length" class="mb-3">
                    <div class="small fw-semibold mb-1">Servicios</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th>Servicio</th><th>Estado</th><th class="text-end">Vendido</th><th class="text-end">Nuevo</th><th class="text-end">Diferencia</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in lineasConCambio" :key="l.clave">
                                    <td>{{ l.descripcion }}</td>
                                    <td><span class="badge" :class="ESTADOS[l.estado].clase">{{ ESTADOS[l.estado].texto }}</span>
                                        <span v-if="l.revisado" class="badge bg-success-subtle text-success ms-1">Confirmado</span></td>
                                    <td class="text-end">{{ textoMonto(l.vendido_centavos, diferencia.moneda) }}</td>
                                    <td class="text-end">{{ textoMonto(l.nuevo_centavos, diferencia.moneda) }}</td>
                                    <td class="text-end">{{ textoMonto(l.diferencia_centavos, diferencia.moneda) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <small v-if="lineasIguales > 0" class="text-muted d-block mb-3">{{ lineasIguales }} servicio(s) sin cambios.</small>

                <!-- Pasajeros con cambio. -->
                <div v-if="pasajerosConCambio.length">
                    <div class="small fw-semibold mb-1">Pasajeros</div>
                    <div v-for="p in pasajerosConCambio" :key="p.clave" class="d-flex justify-content-between align-items-center border rounded p-2 mb-1 small gap-2">
                        <span>
                            {{ p.nombre || (p.tipo_pax ? `Pasajero (${p.tipo_pax})` : 'Pasajero') }}
                            <span class="badge ms-1" :class="ESTADOS[p.estado].clase">{{ ESTADOS[p.estado].texto }}</span>
                        </span>
                        <span v-if="p.estado === 'quitado' && p.reserva_pasajero_id" class="d-flex gap-1">
                            <span v-if="p.penalidad_alternativa_item_id" class="text-success"><i class="fas fa-check me-1"></i>Penalidad cargada</span>
                            <button v-if="p.penalidad_alternativa_item_id" class="btn btn-outline-primary btn-sm"
                                @click="$emit('abrir-penalidad', p.reserva_pasajero_id)">Editar</button>
                            <button v-if="p.penalidad_alternativa_item_id" class="btn btn-outline-danger btn-sm"
                                @click="$emit('borrar-penalidad', p.penalidad_alternativa_item_id)">Borrar</button>
                            <button v-else class="btn btn-primary btn-sm" @click="$emit('abrir-penalidad', p.reserva_pasajero_id)">Cargar penalidad</button>
                        </span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { textoComposicion, textoMonto } from '@/composables/agencia-viajes/useModificacionReserva';
import type { DiferenciaModificacion } from '@/services/admin/reservaModificacionService';

// Panel "Diferencia" de una modificación de reserva (T-007). Solo muestra lo que da el backend
// (centavos enteros, estados, bloqueos): no recalcula nada y no deriva saldos.
const props = defineProps<{
    diferencia: DiferenciaModificacion | null;
    cargando: boolean;
    error: string | null;
    procesando: boolean;
    descargandoPdf: boolean;
}>();

const emit = defineEmits<{
    (e: 'refrescar'): void;
    (e: 'pdf'): void;
    (e: 'descartar'): void;
    (e: 'aplicar'): void;
    (e: 'ir-a-item', altItemId: number): void;
    (e: 'confirmar', altItemIds: number[]): void;
    (e: 'abrir-comprobante', saleId: number): void;
    (e: 'abrir-penalidad', reservaPasajeroId: number): void;
    (e: 'borrar-penalidad', altItemId: number): void;
}>();

const ESTADOS: Record<string, { texto: string; clase: string }> = {
    igual: { texto: 'Igual', clase: 'bg-light text-dark border' },
    agregado: { texto: 'Agregado', clase: 'bg-success-subtle text-success' },
    quitado: { texto: 'Quitado', clase: 'bg-danger-subtle text-danger' },
    cambio_precio: { texto: 'Cambió de precio', clase: 'bg-info-subtle text-info' },
    para_revisar: { texto: 'Para revisar', clase: 'bg-warning-subtle text-warning-emphasis' },
    requiere_nota_credito: { texto: 'Requiere nota de crédito', clase: 'bg-danger-subtle text-danger' },
    ajuste: { texto: 'Ajuste', clase: 'bg-info-subtle text-info' },
};

const lineasConCambio = computed(() => (props.diferencia?.lineas ?? []).filter((l) => l.estado !== 'igual'));
const lineasIguales = computed(() => (props.diferencia?.lineas ?? []).filter((l) => l.estado === 'igual').length);
const pasajerosConCambio = computed(() => (props.diferencia?.pasajeros ?? []).filter((p) => p.estado !== 'igual'));

// Las confirmables: "para revisar" con ítem, sin confirmar y que no sean "sin pasajeros"
// (esas no se confirman: se arreglan en el lienzo).
const lineasPorConfirmar = computed(() =>
    (props.diferencia?.lineas ?? []).filter((l) => l.estado === 'para_revisar' && !l.revisado && !l.sin_pasajeros && l.alternativa_item_id)
);

const seleccionadas = ref(new Set<number>());
const alternar = (id: number) => {
    const s = new Set(seleccionadas.value);
    if (!s.delete(id)) s.add(id);
    seleccionadas.value = s;
};
// Lo que ya no está por confirmar sale de la selección.
watch(lineasPorConfirmar, (lineas) => {
    const vigentes = new Set(lineas.map((l) => l.alternativa_item_id!));
    seleccionadas.value = new Set([...seleccionadas.value].filter((id) => vigentes.has(id)));
});
const confirmarSeleccionadas = () => {
    emit('confirmar', [...seleccionadas.value]);
    seleccionadas.value = new Set();
};
</script>
