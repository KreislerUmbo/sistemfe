<template>
  <div v-if="cargando" class="rounded-3 p-3 bg-light small text-muted mt-2">
    <span class="spinner-border spinner-border-sm me-2"></span>Revisando al cliente…
  </div>
  <div v-else-if="resumen" class="rounded-3 p-3 small mt-2" :class="`alert-${estado.color} bg-${estado.color}-subtle text-${estado.color}-emphasis`">
    <div class="fw-bold mb-1">
      <i :class="estado.icono" class="me-1"></i>{{ estado.texto }}
    </div>
    <div class="d-flex flex-wrap gap-x-2 column-gap-3 row-gap-1">
      <span>{{ resumen.creditos_activos }} de {{ resumen.max_creditos_activos }} créditos activos</span>
      <span>Debe {{ formatoSoles(resumen.deuda_actual) }}</span>
      <span v-if="resumen.deuda_disponible !== null">Disponible {{ formatoSoles(resumen.deuda_disponible) }}</span>
      <span v-if="resumen.dias_atraso_maximo > 0">Atraso {{ resumen.dias_atraso_maximo }} días</span>
      <span>{{ puntualidad }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 1, 00 1.10): tarjeta del cliente elegido — créditos activos,
// deuda, disponible, atraso y puntualidad. Informativa: el bloqueo real ocurre al activar.
import { computed, ref, watch } from 'vue'
import { creditoService } from '@/services/admin/creditoService'
import { formatoSoles } from '@/helpers/creditos/formato'
import type { ResumenClienteCredito } from '@/types/creditos'

const props = defineProps<{ clienteId: number | null }>()
const emit = defineEmits<{ cargado: [resumen: ResumenClienteCredito | null] }>()

const resumen = ref<ResumenClienteCredito | null>(null)
const cargando = ref(false)

watch(() => props.clienteId, async (id) => {
  resumen.value = null
  if (id === null) {
    emit('cargado', null)
    return
  }
  cargando.value = true
  try {
    resumen.value = await creditoService.resumenCliente(id)
  } catch {
    resumen.value = null
  } finally {
    cargando.value = false
    emit('cargado', resumen.value)
  }
}, { immediate: true })

const estado = computed(() => {
  const r = resumen.value
  if (r?.bloqueado) return { color: 'danger', icono: 'fas fa-ban', texto: 'Cliente bloqueado: requiere autorización' }
  if (r && r.creditos_activos >= r.max_creditos_activos) return { color: 'warning', icono: 'fas fa-exclamation-triangle', texto: 'Llegó al máximo de créditos activos' }
  if (r && r.dias_atraso_maximo > 0) return { color: 'warning', icono: 'fas fa-exclamation-triangle', texto: 'Tiene cuotas atrasadas' }
  return { color: 'success', icono: 'fas fa-check-circle', texto: 'Puede recibir crédito' }
})

const puntualidad = computed(() => {
  const r = resumen.value
  if (!r || r.cuotas_pagadas === 0) return 'Sin historial de pagos'
  return `Puntual ${Math.round((r.cuotas_pagadas_a_tiempo / r.cuotas_pagadas) * 100)}%`
})
</script>
