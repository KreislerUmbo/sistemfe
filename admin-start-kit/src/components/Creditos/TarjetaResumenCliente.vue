<template>
  <div v-if="cargando" class="rounded px-3 py-2 bg-light small text-muted mt-2">
    <span class="spinner-border spinner-border-sm me-2"></span>Revisando al cliente…
  </div>
  <div v-else-if="resumen" class="alert py-2 px-3 small mt-2 mb-0" :class="`alert-${estado.color}`">
    <div class="fw-semibold mb-1">
      <i :class="estado.icono" class="me-1"></i>{{ estado.texto }}
    </div>
    <div class="d-flex flex-wrap gap-x-2 column-gap-3 row-gap-1">
      <span>{{ resumen.creditos_activos }} de {{ resumen.max_creditos_activos }} créditos activos</span>
      <span>Debe {{ formatoSoles(resumen.deuda_actual) }}</span>
      <span v-if="resumen.deuda_disponible !== null">Disponible {{ formatoSoles(resumen.deuda_disponible) }}</span>
      <span v-if="resumen.dias_atraso_maximo > 0">Atraso {{ textoDias(resumen.dias_atraso_maximo) }}</span>
      <span>{{ puntualidad }}</span>
      <span v-if="resumen.saldo_a_favor && resumen.saldo_a_favor !== '0.00'" class="fw-semibold">Saldo a favor {{ formatoSoles(resumen.saldo_a_favor) }}</span>
    </div>
    <div v-if="faltante.length" class="mt-1">
      Falta: {{ faltante.map((f) => f.etiqueta).join(', ') }}.
      <a v-if="enlaceFicha" :href="urlFicha" target="_blank" rel="noopener" class="fw-semibold">Completar ficha</a>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 1, 00 1.10): tarjeta del cliente elegido — créditos activos,
// deuda, disponible, atraso y puntualidad. Informativa: el bloqueo real ocurre al activar.
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { creditoService } from '@/services/admin/creditoService'
import { textoDias } from '@/helpers/creditos/estados'
import { formatoSoles } from '@/helpers/creditos/formato'
import type { ResumenClienteCredito } from '@/types/creditos'

const props = withDefaults(defineProps<{
  clienteId: number | null
  /** En la propia página del cliente no hace falta el enlace a completar la ficha. */
  enlaceFicha?: boolean
}>(), { enlaceFicha: true })
const router = useRouter()
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

const faltante = computed(() => resumen.value?.ficha_faltante ?? [])
// Pestaña nueva: el formulario del crédito no se pierde mientras se completa la ficha.
const urlFicha = computed(() => (props.clienteId ? router.resolve({ name: 'clients.ficha', params: { id: props.clienteId } }).href : '#'))

const estado = computed(() => {
  const r = resumen.value
  if (r?.bloqueado) return { color: 'danger', icono: 'fas fa-ban', texto: 'Cliente bloqueado: requiere autorización' }
  // Lo más grave primero; lo que falta de la ficha igual se lista debajo.
  if (r && r.creditos_activos >= r.max_creditos_activos) return { color: 'warning', icono: 'fas fa-exclamation-triangle', texto: 'Llegó al máximo de créditos activos' }
  if (r && r.dias_atraso_maximo > 0) return { color: 'warning', icono: 'fas fa-exclamation-triangle', texto: 'Tiene cuotas atrasadas' }
  if (faltante.value.length) return { color: 'warning', icono: 'fas fa-id-card', texto: 'Ficha incompleta: requiere autorización para prestar' }
  return { color: 'success', icono: 'fas fa-check-circle', texto: 'Puede recibir crédito' }
})

const puntualidad = computed(() => {
  const r = resumen.value
  if (!r || r.cuotas_pagadas === 0) return 'Sin historial de pagos'
  return `Puntual ${Math.round((r.cuotas_pagadas_a_tiempo / r.cuotas_pagadas) * 100)}%`
})
</script>
