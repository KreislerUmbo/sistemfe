<template>
  <div class="d-flex flex-column gap-3">
    <div class="card border-0 shadow-sm mb-0">
      <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold text-dark"><i class="fas fa-calculator me-2 text-primary"></i>Resumen</span>
        <span v-if="cargando" class="spinner-border spinner-border-sm text-muted" aria-label="Calculando"></span>
      </div>
      <div class="card-body py-3">
        <table v-if="preview" class="table table-sm table-borderless mb-0 small cifra">
          <tbody>
            <tr><td class="text-muted ps-0">Capital</td><td class="text-end pe-0">{{ formatoSoles(capital) }}</td></tr>
            <tr><td class="text-muted ps-0">Interés</td><td class="text-end pe-0">{{ formatoSoles(preview.cronograma.interes_total) }}</td></tr>
            <tr class="border-top fw-semibold">
              <td class="ps-0">Total a devolver</td>
              <td class="text-end pe-0 text-primary fs-6">{{ formatoSoles(preview.cronograma.monto_total) }}</td>
            </tr>
            <tr><td class="text-muted ps-0">Pagos</td><td class="text-end pe-0 fw-semibold">{{ textoCuotas }}</td></tr>
            <tr><td class="text-muted ps-0">Primer pago</td><td class="text-end pe-0">{{ formatoFecha(preview.cronograma.primer_vencimiento) }}</td></tr>
            <tr><td class="text-muted ps-0">Último pago</td><td class="text-end pe-0">{{ formatoFecha(preview.cronograma.ultimo_vencimiento) }}</td></tr>
          </tbody>
        </table>
        <p v-else-if="error" class="mb-0 text-danger small"><i class="fas fa-exclamation-triangle me-1"></i>{{ error }}</p>
        <p v-else class="mb-0 small text-muted fst-italic">Completa cliente, monto, interés y n.º de pagos para ver el resumen.</p>
      </div>
    </div>

    <div v-if="preview" class="card border-0 shadow-sm mb-0">
      <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold text-dark">Cronograma</span>
        <button v-if="cronogramaResumido(cuotas)" type="button" class="btn btn-link btn-sm p-0" @click="completo = !completo">
          {{ completo ? 'Ver menos' : `Ver los ${cuotas.length}` }}
        </button>
      </div>
      <div class="table-responsive" :class="{ 'cronograma-completo': completo }">
        <table class="table table-sm table-hover align-middle mb-0 small cifra">
          <thead class="table-light">
            <tr><th class="ps-3">#</th><th>Vence</th><th class="text-end pe-3">Cuota</th></tr>
          </thead>
          <tbody>
            <template v-for="(fila, i) in filas" :key="fila.tipo === 'cuota' ? fila.cuota.numero_cuota : `resto${i}`">
              <tr v-if="fila.tipo === 'resto'" class="fila-resto" role="button" @click="completo = true">
                <td colspan="3" class="text-center text-muted">⋯ {{ fila.cantidad }} cuota{{ fila.cantidad === 1 ? '' : 's' }} más</td>
              </tr>
              <tr v-else>
                <td class="ps-3 text-muted">{{ fila.cuota.numero_cuota }}</td>
                <td>
                  {{ formatoFecha(fila.cuota.fecha_vencimiento) }}
                  <span v-if="fila.cuota.feriado" class="badge bg-warning-subtle text-warning-emphasis ms-1" :title="fila.cuota.feriado">feriado</span>
                  <span v-if="pagadas && fila.cuota.numero_cuota <= pagadas" class="badge bg-success-subtle text-success-emphasis ms-1">Pagada</span>
                  <small v-if="textoAjuste(fila.cuota)" class="d-block text-muted">{{ textoAjuste(fila.cuota) }}</small>
                </td>
                <td class="text-end pe-3 fw-semibold">{{ formatoSoles(fila.cuota.monto_total) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 1): resumen y cronograma que devuelve POST creditos/preview, con el
// mismo estilo de totales que el registro de ventas (card clara + tabla). Solo muestra; el
// agrupado "N pagos de S/ X" compara los montos como texto, sin sumar.
import { computed, ref } from 'vue'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import { cronogramaResumido, filasCronograma, textoAjuste, textoPagos } from '@/helpers/creditos/cronograma'
import type { CuotaPrevia, PreviewCredito } from '@/types/creditos'

const props = defineProps<{
  preview: PreviewCredito | null
  capital: string
  cargando?: boolean
  error?: string | null
  /** Migración en modo rápido: las cuotas 1..N ya se pagaron a tiempo. */
  pagadas?: number
}>()

const completo = ref(false)

const cuotas = computed<CuotaPrevia[]>(() => props.preview?.cronograma.cuotas ?? [])

const filas = computed(() => filasCronograma(cuotas.value, completo.value))

const textoCuotas = computed(() => textoPagos(cuotas.value, formatoSoles))
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
.fila-resto {
  cursor: pointer;
}
.cronograma-completo {
  max-height: 420px;
  overflow-y: auto;
}
</style>
