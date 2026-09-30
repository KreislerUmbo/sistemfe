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
            <tr>
              <td class="text-muted ps-0">Primero · último</td>
              <td class="text-end pe-0">{{ formatoFecha(preview.cronograma.primer_vencimiento) }} · {{ formatoFecha(preview.cronograma.ultimo_vencimiento) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else-if="error" class="mb-0 text-danger small"><i class="fas fa-exclamation-triangle me-1"></i>{{ error }}</p>
        <p v-else class="mb-0 small text-muted fst-italic">Completa cliente, monto, interés y n.º de pagos para ver el resumen.</p>
      </div>
    </div>

    <div v-if="preview" class="card border-0 shadow-sm mb-0">
      <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold text-dark">Cronograma</span>
        <button v-if="cuotas.length > FILAS_RESUMIDAS" type="button" class="btn btn-link btn-sm p-0" @click="completo = !completo">
          {{ completo ? 'Ver menos' : `Ver los ${cuotas.length}` }}
        </button>
      </div>
      <div class="table-responsive" :class="{ 'cronograma-completo': completo }">
        <table class="table table-sm table-hover align-middle mb-0 small cifra">
          <thead class="table-light">
            <tr><th class="ps-3">#</th><th>Vence</th><th class="text-end pe-3">Cuota</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in filas" :key="c.numero_cuota">
              <td class="ps-3 text-muted">{{ c.numero_cuota }}</td>
              <td>
                {{ formatoFecha(c.fecha_vencimiento) }}
                <i v-if="c.fecha_forzada_a_siguiente" class="fas fa-info-circle text-warning ms-1"
                  title="Cae en día sin cobro: se pasó al siguiente día hábil"></i>
                <span v-if="pagadas && c.numero_cuota <= pagadas" class="badge bg-success-subtle text-success-emphasis ms-1">Pagada</span>
              </td>
              <td class="text-end pe-3 fw-semibold">{{ formatoSoles(c.monto_total) }}</td>
            </tr>
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
import type { CuotaPrevia, PreviewCredito } from '@/types/creditos'

const props = defineProps<{
  preview: PreviewCredito | null
  capital: string
  cargando?: boolean
  error?: string | null
  /** Migración en modo rápido: las cuotas 1..N ya se pagaron a tiempo. */
  pagadas?: number
}>()

const FILAS_RESUMIDAS = 4
const completo = ref(false)

const cuotas = computed<CuotaPrevia[]>(() => props.preview?.cronograma.cuotas ?? [])

const filas = computed(() => {
  const lista = cuotas.value
  if (completo.value || lista.length <= FILAS_RESUMIDAS) return lista
  return [...lista.slice(0, FILAS_RESUMIDAS - 1), lista[lista.length - 1]]
})

/** "30 pagos de S/ 40.00" o "29 pagos de S/ 40.00 + 1 de S/ 40.10". */
const textoCuotas = computed(() => {
  const grupos: { monto: string; cantidad: number }[] = []
  for (const c of cuotas.value) {
    const ultimo = grupos[grupos.length - 1]
    if (ultimo && ultimo.monto === c.monto_total) ultimo.cantidad++
    else grupos.push({ monto: c.monto_total, cantidad: 1 })
  }
  const texto = (g: { monto: string; cantidad: number }, primero: boolean) =>
    `${g.cantidad} ${g.cantidad === 1 ? (primero ? 'pago' : '') : 'pagos'} de ${formatoSoles(g.monto)}`.replace(/\s+/g, ' ')

  if (grupos.length <= 2) return grupos.map((g, i) => texto(g, i === 0)).join(' + ')
  return `${cuotas.value.length} pagos · primero ${formatoSoles(grupos[0].monto)}`
})
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
.cronograma-completo {
  max-height: 420px;
  overflow-y: auto;
}
</style>
