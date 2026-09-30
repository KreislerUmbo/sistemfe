<template>
  <div>
    <p v-if="!pagos.length" class="text-muted text-center py-4 mb-0">Todavía no hay pagos.</p>
    <ul v-else class="list-group list-group-flush cifra">
      <li v-for="p in ordenados" :key="p.id" class="list-group-item py-3" :class="{ 'opacity-50': p.estado === 'anulado' }">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <div class="fw-semibold">
              {{ p.numero_recibo }}
              <span v-if="p.estado === 'anulado'" class="badge bg-dark-subtle text-dark-emphasis ms-1">Anulado</span>
              <span v-else-if="p.origen !== 'cobro'" class="badge bg-info-subtle text-info-emphasis ms-1">{{ ORIGEN[p.origen] }}</span>
            </div>
            <small class="text-muted">{{ formatoFecha(p.fecha_pago) }} {{ p.fecha_pago.slice(11, 16) }}{{ p.referencia ? ` · Ref. ${p.referencia}` : '' }}</small>
          </div>
          <div class="text-end">
            <div class="fw-bold">{{ formatoSoles(p.monto_aplicado) }}</div>
            <small v-if="p.monto_excedente !== '0.00'" class="text-muted">
              +{{ formatoSoles(p.monto_excedente) }} {{ p.destino_excedente === 'saldo_a_favor' ? 'a saldo a favor' : 'devuelto' }}
            </small>
          </div>
        </div>
        <div v-if="p.aplicaciones?.length && p.estado === 'valido'" class="small text-muted mt-1">
          {{ p.aplicaciones.map((a) => `#${a.numero_cuota} ${CONCEPTO[a.concepto]} ${formatoSoles(a.monto)}`).join(' · ') }}
        </div>
        <div v-if="p.estado === 'anulado' && p.motivo_anulacion" class="small mt-1">Motivo: {{ p.motivo_anulacion }}</div>
        <div v-if="p.estado === 'valido' && (puedeEditar || puedeAnular(p))" class="d-flex gap-2 mt-2">
          <button v-if="puedeEditar" type="button" class="btn btn-sm btn-light" @click="emit('editar', p)">
            <i class="fas fa-pen me-1"></i>Referencia
          </button>
          <button v-if="puedeAnular(p) && p.origen !== 'renovacion'" type="button" class="btn btn-sm btn-outline-danger" @click="emit('anular', p)">
            <i class="fas fa-undo me-1"></i>Anular
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.8): pagos con su reparto. Anular muestra el botón si es propio o
// con creditos.anular_pago; el backend decide si la caja sigue abierta. Una renovación
// no se anula como pago (se anula el crédito nuevo).
import { computed } from 'vue'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import type { OrigenPago, Pago } from '@/types/creditos'

const props = defineProps<{ pagos: Pago[]; puedeEditar: boolean; puedeAnular: (p: Pago) => boolean }>()
const emit = defineEmits<{ anular: [pago: Pago]; editar: [pago: Pago] }>()

const ORIGEN: Record<OrigenPago, string> = {
  cobro: 'Cobro', liquidacion: 'Liquidación', renovacion: 'Renovación', venta_prenda: 'Venta de prenda',
  saldo_a_favor: 'Saldo a favor', saldo_inicial: 'Pago anterior al sistema',
}
const CONCEPTO = { interes: 'interés', capital: 'capital', cargo: 'cargo', mora: 'mora' } as const

const ordenados = computed(() => [...props.pagos].reverse())
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
