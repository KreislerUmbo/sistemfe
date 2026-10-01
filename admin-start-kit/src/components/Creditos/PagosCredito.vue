<template>
  <div>
    <p v-if="!pagos.length" class="text-muted text-center py-4 mb-0">Todavía no hay pagos.</p>
    <ul v-else class="list-group list-group-flush cifra">
      <li v-for="p in ordenados" :key="p.id" class="list-group-item py-2 small" :class="{ 'opacity-50': p.estado === 'anulado' }">
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
        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-primary" :disabled="ocupado" title="Reimprimir (sale como COPIA)" @click="imprimir(p, formatoPorDefecto())">
              <i class="fas fa-print me-1"></i>Recibo
            </button>
            <button type="button" class="btn btn-outline-primary" :disabled="ocupado" :title="formatoPorDefecto() === 'a4' ? 'Ticket 80 mm' : 'A4'"
              @click="imprimir(p, formatoPorDefecto() === 'a4' ? 'ticket80mm' : 'a4')">
              {{ formatoPorDefecto() === 'a4' ? 'Ticket' : 'A4' }}
            </button>
          </span>
          <button type="button" class="btn btn-sm btn-outline-success" :disabled="ocupado" title="Compartir por WhatsApp" @click="compartirRecibo(p)">
            <i class="fab fa-whatsapp"></i>
          </button>
          <button v-if="p.estado === 'valido' && puedeEditar" type="button" class="btn btn-sm btn-light" @click="emit('editar', p)">
            <i class="fas fa-pen me-1"></i>Referencia
          </button>
          <button v-if="p.estado === 'valido' && puedeAnular(p) && p.origen !== 'renovacion'" type="button" class="btn btn-sm btn-outline-danger" @click="emit('anular', p)">
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
// no se anula como pago (se anula el crédito nuevo). Reimprimir un recibo lo marca "COPIA".
import { computed } from 'vue'
import { creditoService } from '@/services/admin/creditoService'
import { useDocumentosCredito } from '@/composables/creditos/useDocumentosCredito'
import { mensajeDocumento, nombreArchivo } from '@/helpers/creditos/documentos'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import type { FormatoPdf, OrigenPago, Pago } from '@/types/creditos'

const props = defineProps<{
  pagos: Pago[]
  creditoId: number
  cliente?: { nombre: string; telefono: string | null } | null
  puedeEditar: boolean
  puedeAnular: (p: Pago) => boolean
}>()
const emit = defineEmits<{ anular: [pago: Pago]; editar: [pago: Pago] }>()

const ORIGEN: Record<OrigenPago, string> = {
  cobro: 'Cobro', liquidacion: 'Liquidación', renovacion: 'Renovación', venta_prenda: 'Venta de prenda',
  saldo_a_favor: 'Saldo a favor', saldo_inicial: 'Pago anterior al sistema',
}
const CONCEPTO = { interes: 'interés', capital: 'capital', cargo: 'cargo', mora: 'mora' } as const

const ordenados = computed(() => [...props.pagos].reverse())
const { abrir, compartir, formatoPorDefecto, ocupado } = useDocumentosCredito()

const imprimir = (p: Pago, formato: FormatoPdf) => abrir(() => creditoService.reciboUrl(props.creditoId, p.id, formato, true))

const compartirRecibo = (p: Pago) => compartir(
  () => creditoService.reciboUrl(props.creditoId, p.id, formatoPorDefecto(), true),
  nombreArchivo('recibo', p.numero_recibo),
  mensajeDocumento(`recibo de pago ${p.numero_recibo}`, props.cliente?.nombre),
  props.cliente?.telefono,
)
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
