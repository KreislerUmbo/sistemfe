<template>
    <div v-if="cotizacion" class="mb-3">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
            <div>
                <h5 v-if="modificacionInfo" class="fw-bold mb-1">
                    <i class="fas fa-pen-to-square me-2 text-primary"></i>Modificación de la reserva {{ modificacionInfo.reserva_codigo ?? `#${modificacionInfo.reserva_id}` }}
                    <span v-if="modificacionInfo.estado !== 'abierta'" class="badge bg-secondary ms-2">{{ modificacionInfo.estado === 'aplicada' ? 'Aplicada' : 'Descartada' }}</span>
                </h5>
                <h5 v-else class="fw-bold mb-1"><i class="fas fa-route me-2 text-primary"></i>Cotización {{ cotizacion.codigo }}</h5>
                <div class="fw-semibold text-dark mb-2"><i class="fas fa-user me-2 text-muted" style="font-size:12px"></i>{{ cotizacion.cliente?.full_name ?? 'Sin cliente' }}</div>
                <!-- T-051: la próxima acción pendiente de la cotización (la lista está en el panel de la alternativa). -->
                <div v-if="proximaAccion" class="small mb-2" :class="proximaAccion.fecha < hoyPeru() ? 'text-danger' : 'text-muted'">
                    <i class="fas fa-tasks me-1"></i>Próxima acción: {{ proximaAccion.tipo_etiqueta.toLowerCase() }} el
                    {{ formatDiaCorto(proximaAccion.fecha) }} ({{ proximaAccion.responsable.nombre.split(' ')[0] }})
                </div>
                <!-- T-052: cerrada como perdida (motivo y detalle como texto, nunca v-html). -->
                <div v-if="cotizacionPerdida" class="small text-danger mb-2">
                    <i class="fas fa-circle-xmark me-1"></i>Perdida: {{ cotizacion.resultado_motivo?.nombre ?? 'sin motivo' }}<template v-if="cotizacion.resultado_detalle"> — {{ cotizacion.resultado_detalle }}</template>
                    · {{ formatFechaHora(cotizacion.perdida_en) }}<template v-if="cotizacion.perdida_por"> ({{ cotizacion.perdida_por.name }})</template>
                </div>
                <PreferenciasViajeroResumen :cliente-id="cotizacion.cliente_id ?? null" />
            </div>
            <div class="d-flex flex-wrap gap-2">
                <template v-if="puedeCambiarResultado">
                    <button v-if="cotizacionPerdida" class="btn btn-outline-primary btn-sm" :disabled="reabriendo" @click="reabrirCotizacion">
                        <span v-if="reabriendo" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="fas fa-rotate-left me-1"></i>Reabrir
                    </button>
                    <button v-else class="btn btn-outline-danger btn-sm" @click="cerrandoComoPerdida = true">
                        <i class="fas fa-circle-xmark me-1"></i>Cerrar como perdida
                    </button>
                </template>
                <router-link v-if="modificacionInfo" :to="`/agencia-viajes/reservas/${modificacionInfo.reserva_id}`" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-2"></i>Volver a la reserva
                </router-link>
                <router-link v-else to="/agencia-viajes/cotizador" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </router-link>
            </div>
        </div>
        <CerrarComoPerdidaModal v-if="cerrandoComoPerdida" :cotizacion-id="cotizacion.id" :codigo="cotizacion.codigo"
            @cerrar="cerrandoComoPerdida = false" @cerrada="onResultadoCambiado('Cotización cerrada como perdida.')" />
        <AceptarAlternativaModal v-if="aceptandoAlternativa && alternativaActiva" :alternativa-nombre="alternativaActiva.nombre"
            :procesando="marcandoAceptada" @cerrar="aceptandoAlternativa = false" @confirmar="confirmarAceptada" />
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-map-marker-alt me-1 text-primary"></i>{{ cotizacion.destino }}</span>
            <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-calendar me-1 text-primary"></i>{{ textoFechasViaje }}</span>
            <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-users me-1 text-primary"></i>{{ resumenPax }}</span>
            <i class="fas fa-pen text-primary" style="cursor:pointer;font-size:12px" title="Corregir cliente/destino/fecha" @click="abrirEdicionCabecera"></i>
            <i v-if="!hayAlternativaAceptada" class="fas fa-user-edit text-primary" style="cursor:pointer;font-size:12px" title="Agregar o quitar pasajeros" @click="abrirEdicionPasajeros"></i>
            <i v-else class="fas fa-user-lock text-muted" style="font-size:12px" title="Esta cotización ya tiene una alternativa aceptada (con reserva generada) — los pasajeros ya no se pueden modificar desde acá."></i>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Cabecera del cotizador: título (cotización o modificación de reserva), cliente, próxima acción,
 * perdida, botones de resultado y volver, y los datos del viaje con sus lápices. Todo llega por inject
 * desde cotizador/editar.vue (T-105).
 */
import AceptarAlternativaModal from '@/components/AgenciaViajes/AceptarAlternativaModal.vue';
import CerrarComoPerdidaModal from '@/components/AgenciaViajes/CerrarComoPerdidaModal.vue';
import PreferenciasViajeroResumen from '@/components/AgenciaViajes/PreferenciasViajeroResumen.vue';
import { formatDiaCorto, formatFechaHora, hoyPeru } from '@/helpers/fecha';
import {
    CLAVE_NUCLEO, CLAVE_PASAJEROS, CLAVE_CABECERA, CLAVE_RESULTADO, CLAVE_ALTERNATIVAS, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';

const { alternativaActiva, cotizacion, modificacionInfo } = inyectar(CLAVE_NUCLEO);
const { abrirEdicionPasajeros, hayAlternativaAceptada, resumenPax } = inyectar(CLAVE_PASAJEROS);
const { abrirEdicionCabecera, textoFechasViaje } = inyectar(CLAVE_CABECERA);
const {
    cerrandoComoPerdida, cotizacionPerdida, onResultadoCambiado, proximaAccion, puedeCambiarResultado,
    reabriendo, reabrirCotizacion,
} = inyectar(CLAVE_RESULTADO);
const { aceptandoAlternativa, confirmarAceptada, marcandoAceptada } = inyectar(CLAVE_ALTERNATIVAS);
</script>
