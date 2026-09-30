<template>
  <div class="d-flex flex-column gap-3">
    <!-- Resumen (tarjeta oscura del mockup 1) -->
    <div class="card bg-dark text-white border-0 mb-0">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="small fw-bold text-uppercase opacity-75">Resumen</span>
          <span v-if="cargando" class="spinner-border spinner-border-sm opacity-75" aria-label="Calculando"></span>
        </div>

        <template v-if="preview">
          <div class="d-flex justify-content-between cifra"><span>Capital</span><span>{{ formatoSoles(capital) }}</span></div>
          <div class="d-flex justify-content-between cifra"><span>Interés</span><span>{{ formatoSoles(preview.cronograma.interes_total) }}</span></div>
          <hr class="my-2 opacity-25" />
          <div class="d-flex justify-content-between cifra fs-5 fw-bold"><span>Total a devolver</span><span>{{ formatoSoles(preview.cronograma.monto_total) }}</span></div>
          <div class="bg-primary rounded-3 text-center fw-bold fs-5 py-2 px-2 my-2 cifra">{{ textoCuotas }}</div>
          <div class="d-flex justify-content-between small opacity-75 cifra">
            <span>Primer pago {{ formatoFecha(preview.cronograma.primer_vencimiento) }}</span>
            <span>Último {{ formatoFecha(preview.cronograma.ultimo_vencimiento) }}</span>
          </div>
        </template>
        <p v-else-if="error" class="mb-0 text-warning small"><i class="fas fa-exclamation-triangle me-1"></i>{{ error }}</p>
        <p v-else class="mb-0 small opacity-75">Completa cliente, monto, interés y n.º de pagos para ver el resumen.</p>
      </div>
    </div>

    <!-- Vista previa del cronograma -->
    <div v-if="preview" class="card border mb-0">
      <div class="card-header d-flex justify-content-between align-items-center bg-transparent">
        <span class="fw-semibold">Vista previa del cronograma</span>
        <button v-if="cuotas.length > FILAS_RESUMIDAS" type="button" class="btn btn-link btn-sm p-0 fw-semibold" @click="completo = !completo">
          {{ completo ? 'Ver menos' : 'Ver completo' }}
        </button>
      </div>
      <ul class="list-group list-group-flush cronograma" :class="{ 'cronograma-completo': completo }">
        <li v-for="c in filas" :key="c.numero_cuota" class="list-group-item d-flex align-items-center gap-3 cifra">
          <span class="text-muted numero">#{{ c.numero_cuota }}</span>
          <span class="flex-grow-1">
            {{ formatoFecha(c.fecha_vencimiento) }}
            <i v-if="c.fecha_forzada_a_siguiente" class="fas fa-info-circle text-warning ms-1"
              title="Cae en día sin cobro: se pasó al siguiente día hábil"></i>
          </span>
          <span v-if="pagadas && c.numero_cuota <= pagadas" class="small text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>Pagada</span>
          <span class="fw-bold">{{ formatoSoles(c.monto_total) }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 1): resumen y cronograma que devuelve POST creditos/preview.
// Solo muestra; el agrupado "N pagos de S/ X" compara los montos como texto, sin sumar.
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
.numero {
  width: 2.5rem;
}
.cronograma-completo {
  max-height: 420px;
  overflow-y: auto;
}
.list-group-item {
  min-height: 44px;
}
</style>
