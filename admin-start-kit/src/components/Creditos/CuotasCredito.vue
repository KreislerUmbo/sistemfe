<template>
  <div>
    <!-- Celular y tablet: filas tipo tarjeta (mockup 2) -->
    <ul class="list-group list-group-flush d-xl-none cifra">
      <li v-for="c in visibles" :key="c.id" class="list-group-item d-flex align-items-center gap-3 py-3" :class="fondo(c)">
        <span class="text-muted numero">#{{ c.numero_cuota }}</span>
        <div class="flex-grow-1">
          <div class="fw-semibold">{{ fechaCorta(c.fecha_vencimiento) }}</div>
          <small :class="detalleClase(c)">{{ detalle(c) }}</small>
        </div>
        <div class="text-end">
          <div class="fw-bold">{{ formatoSoles(c.monto_total) }}</div>
          <small class="fw-semibold" :class="presentacion(c).clase"><i :class="presentacion(c).icono" class="me-1"></i>{{ presentacion(c).texto }}</small>
        </div>
      </li>
    </ul>
    <button v-if="cuotas.length > visibles.length || todas" type="button" class="btn btn-link w-100 fw-semibold d-xl-none fila-toque"
      @click="todas = !todas">
      {{ todas ? 'Ver menos' : `Ver las ${cuotas.length} cuotas` }}
    </button>

    <!-- Escritorio: tabla completa -->
    <div class="table-responsive d-none d-xl-block">
      <table class="table table-hover align-middle mb-0 cifra">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Vence</th><th class="text-end">Capital</th><th class="text-end">Interés</th>
            <th class="text-end">Mora hoy</th><th class="text-end">Pagado</th><th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in cuotas" :key="c.id" :class="fondo(c)">
            <td class="text-muted">{{ c.numero_cuota }}</td>
            <td>
              {{ formatoFecha(c.fecha_vencimiento) }}
              <small v-if="c.fecha_vencimiento !== c.fecha_vencimiento_original" class="text-muted d-block">antes {{ formatoFecha(c.fecha_vencimiento_original) }}</small>
            </td>
            <td class="text-end">{{ formatoSoles(c.monto_capital) }}</td>
            <td class="text-end">{{ formatoSoles(c.monto_interes) }}</td>
            <td class="text-end" :class="{ 'text-danger fw-semibold': tieneMora(c) }">
              {{ tieneMora(c) ? formatoSoles(c.mora_pendiente_hoy) : '—' }}
            </td>
            <td class="text-end">{{ c.estado === 'pagada' ? formatoSoles(c.monto_total) : pagadoParcial(c) }}</td>
            <td>
              <span class="fw-semibold" :class="presentacion(c).clase"><i :class="presentacion(c).icono" class="me-1"></i>{{ presentacion(c).texto }}</span>
              <small v-if="c.dias_atraso_hoy" class="text-danger d-block">{{ textoDias(c.dias_atraso_hoy) }}</small>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 2): cuotas vigentes con estado y mora al día (calculada por el
// backend). En celular se muestra una ventana alrededor de hoy; en escritorio, todas.
import { computed, ref } from 'vue'
import { estadoCuota, fechaCorta, PRESENTACION_CUOTA, textoDias } from '@/helpers/creditos/estados'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import type { Cuota } from '@/types/creditos'

const props = defineProps<{ cuotas: Cuota[]; hoy: string }>()

const VENTANA = 5
const todas = ref(false)

const presentacion = (c: Cuota) => PRESENTACION_CUOTA[estadoCuota(c, props.hoy)]
const tieneMora = (c: Cuota) => !!c.mora_pendiente_hoy && c.mora_pendiente_hoy !== '0.00'

/** Última pagada + las siguientes (la cuota "de hoy" siempre a la vista). */
const visibles = computed(() => {
  if (todas.value || props.cuotas.length <= VENTANA) return props.cuotas
  const primeraPendiente = props.cuotas.findIndex((c) => c.estado === 'pendiente')
  const inicio = Math.max(0, (primeraPendiente === -1 ? props.cuotas.length - VENTANA : primeraPendiente) - 1)
  return props.cuotas.slice(inicio, inicio + VENTANA)
})

function fondo(c: Cuota) {
  const estado = estadoCuota(c, props.hoy)
  return { 'fila-vencida': estado === 'vencida', 'fila-hoy': estado === 'hoy' }
}

function detalle(c: Cuota): string {
  const estado = estadoCuota(c, props.hoy)
  if (estado === 'pagada') return c.fecha_pago ? `Pagada el ${formatoFecha(c.fecha_pago).slice(0, 5)}` : 'Pagada'
  if (estado === 'vencida') {
    const mora = tieneMora(c) ? ` · mora ${formatoSoles(c.mora_pendiente_hoy)}${c.mora_tope_alcanzado ? ' (tope)' : ''}` : ''
    return `Vencida · ${textoDias(c.dias_atraso_hoy ?? 0)}${mora}`
  }
  if (estado === 'hoy') return 'Vence hoy'
  return pagadoParcial(c) !== '—' ? `Abonado ${pagadoParcial(c)}` : 'Pendiente'
}

const detalleClase = (c: Cuota) => (estadoCuota(c, props.hoy) === 'vencida' ? 'text-danger fw-semibold' : 'text-muted')

/** Abonos parciales: se muestran los montos pagados que ya vienen del backend, sin sumarlos. */
function pagadoParcial(c: Cuota): string {
  const partes = [['capital', c.capital_pagado], ['interés', c.interes_pagado]].filter(([, m]) => m !== '0.00')
  return partes.length ? partes.map(([t, m]) => `${t} ${formatoSoles(m)}`).join(' · ') : '—'
}
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
.numero {
  width: 2.25rem;
}
.fila-toque {
  min-height: 44px;
}
.fila-vencida {
  background: var(--bs-danger-bg-subtle);
}
.fila-hoy {
  background: var(--bs-success-bg-subtle);
}
</style>
