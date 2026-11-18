<template>
    <!-- T-007 — modificación de reserva: aviso de edades estimadas, panel de diferencia. -->
    <div v-if="avisoEdadEstimada.length" class="alert alert-warning py-2 px-3 small mb-3 d-flex justify-content-between gap-2">
        <span>
            <i class="fas fa-triangle-exclamation me-1"></i><strong>Edad estimada:</strong>
            estos pasajeros de la reserva no tienen fecha de nacimiento; se usó una edad según su tipo. Corrígela si cambia el precio:
            <span v-for="(p, i) in avisoEdadEstimada" :key="p.reserva_pasajero_id">{{ i > 0 ? ', ' : ' ' }}{{ p.nombre || 'Pasajero' }} ({{ p.edad_estimada }} años)</span>.
        </span>
        <button class="btn-close" @click="avisoEdadEstimada = []"></button>
    </div>
    <div v-if="modificacionInfo && modificacionInfo.estado !== 'abierta'" class="alert alert-secondary py-2 small mb-3">
        Esta modificación ya está {{ modificacionInfo.estado === 'aplicada' ? 'aplicada a la reserva' : 'descartada' }}: solo se puede consultar.
    </div>
    <ModificacionDiferenciaPanel v-if="modificacionInfo?.estado === 'abierta'"
        :diferencia="modificacion.diferencia.value" :cargando="modificacion.cargando.value" :error="modificacion.error.value"
        :procesando="procesandoModificacion" :descargando-pdf="descargandoPdfModificacion"
        @refrescar="modificacion.cargar()" @pdf="descargarPdfModificacion" @descartar="descartarModificacion" @aplicar="aplicarModificacion"
        @ir-a-item="irAItem" @confirmar="confirmarRevisados" @abrir-comprobante="abrirComprobanteBloqueo"
        @abrir-penalidad="abrirPenalidad" @borrar-penalidad="borrarPenalidad" />
    <PenalidadPasajeroModal v-if="penalidadPasajero" :pasajero="penalidadPasajero" :moneda="modificacion.diferencia.value?.moneda ?? ''"
        :guardando="guardandoPenalidad" :error-mensaje="errorPenalidad" :inicial="penalidadInicial"
        @cerrar="penalidadPasajero = null" @guardar="guardarPenalidad" />
</template>

<script setup lang="ts">
/*
 * Modificación de reserva (T-007): aviso de edades estimadas, estado no abierta, panel de diferencia y
 * modal de penalidad. Todo llega por inject desde cotizador/editar.vue (T-105).
 */
import ModificacionDiferenciaPanel from '@/components/AgenciaViajes/ModificacionDiferenciaPanel.vue';
import PenalidadPasajeroModal from '@/components/AgenciaViajes/PenalidadPasajeroModal.vue';
import { CLAVE_NUCLEO, CLAVE_MODIFICACION, inyectar } from '@/composables/agencia-viajes/cotizador/claves';

const { modificacionInfo } = inyectar(CLAVE_NUCLEO);
const {
    abrirComprobanteBloqueo, abrirPenalidad, aplicarModificacion, avisoEdadEstimada, borrarPenalidad,
    confirmarRevisados, descargandoPdfModificacion, descargarPdfModificacion, descartarModificacion,
    errorPenalidad, guardandoPenalidad, guardarPenalidad, irAItem, modificacion, penalidadInicial,
    penalidadPasajero, procesandoModificacion,
} = inyectar(CLAVE_MODIFICACION);
</script>
