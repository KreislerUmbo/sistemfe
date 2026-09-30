<template>
  <div v-if="bloqueos.length || advertencias.length" class="d-flex flex-column gap-2">
    <div v-if="bloqueos.length" class="alert alert-danger mb-0 py-2" role="alert">
      <div class="fw-semibold mb-1"><i class="fas fa-ban me-1"></i>{{ titulo }}</div>
      <ul class="mb-0 ps-3 small">
        <li v-for="b in bloqueos" :key="b.regla" class="d-flex align-items-center justify-content-between gap-2 flex-wrap py-1">
          <span>{{ ETIQUETA_REGLA[b.regla] ?? b.regla }}{{ detalle(b) }}</span>
          <button v-if="b.autorizable && puedeAutorizar" type="button" class="btn btn-sm btn-outline-danger"
            :disabled="autorizando === b.regla" @click="emit('autorizar', b.regla)">
            <span v-if="autorizando === b.regla" class="spinner-border spinner-border-sm me-1"></span>Autorizar
          </button>
          <small v-else-if="!b.autorizable" class="text-danger-emphasis">No se puede autorizar</small>
        </li>
      </ul>
    </div>
    <div v-if="advertencias.length" class="alert alert-warning mb-0 py-2 small" role="status">
      <i class="fas fa-exclamation-triangle me-1"></i>
      {{ advertencias.map((a) => (ETIQUETA_REGLA[a.regla] ?? a.regla) + detalle(a)).join(' · ') }}
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.10, 04-frontend "Errores"): infracciones de límites. Las
// autorizables ofrecen "Autorizar" solo si el usuario tiene creditos.autorizar_excepcion.
import { ETIQUETA_REGLA } from '@/composables/creditos/errorCredito'
import { textoDias } from '@/helpers/creditos/estados'
import { formatoCentavos } from '@/helpers/creditos/formato'
import type { Infraccion, ReglaLimite } from '@/types/creditos'

withDefaults(defineProps<{
  bloqueos?: Infraccion[]
  advertencias?: Infraccion[]
  puedeAutorizar?: boolean
  autorizando?: ReglaLimite | null
  titulo?: string
}>(), { bloqueos: () => [], advertencias: () => [], puedeAutorizar: false, autorizando: null, titulo: 'El crédito supera los límites del negocio' })

const emit = defineEmits<{ autorizar: [regla: ReglaLimite] }>()

function detalle(i: Infraccion): string {
  const d = i.detalle
  if (i.regla === 'max_creditos') return ` (${d.creditos_activos} de ${d.maximo})`
  if (i.regla === 'deuda_maxima' && d.deuda_con_nuevo != null && d.maximo != null) {
    // El detalle viene en centavos desde el motor.
    return ` (${formatoCentavos(d.deuda_con_nuevo)} de ${formatoCentavos(d.maximo)})`
  }
  if (i.regla === 'moroso') return ` (${textoDias(Number(d.dias_atraso))})`
  return ''
}
</script>
