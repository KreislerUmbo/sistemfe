<template>
  <DialogoBase v-model="abierto" titulo="Condonar interés moratorio" texto-confirmar="Condonar" variante="warning"
    :procesando="procesando" :deshabilitado="!cuotaId || !montoValido || !motivo.trim()" :error="error" @confirmar="confirmar">
    <p v-if="!conMora.length" class="text-muted mb-0">Ninguna cuota tiene mora pendiente hoy.</p>
    <template v-else>
      <label class="form-label small fw-semibold" for="cm-cuota">Cuota</label>
      <select id="cm-cuota" v-model="cuotaId" class="form-select mb-3" @change="usarTodo">
        <option v-for="c in conMora" :key="c.id" :value="c.id">
          #{{ c.numero_cuota }} · vence {{ formatoFecha(c.fecha_vencimiento) }} · mora {{ formatoSoles(c.mora_pendiente_hoy) }}
        </option>
      </select>
      <label class="form-label small fw-semibold" for="cm-monto">Monto a condonar</label>
      <div class="input-group mb-1">
        <span class="input-group-text">S/</span>
        <input id="cm-monto" v-model="monto" type="text" inputmode="decimal" class="form-control" :class="{ 'is-invalid': monto && !montoValido }" />
      </div>
      <small class="text-muted d-block mb-3">Hasta la mora pendiente de hoy. El backend valida el tope.</small>
      <label class="form-label small fw-semibold" for="cm-motivo">Motivo <span class="text-danger">*</span></label>
      <textarea id="cm-motivo" v-model="motivo" class="form-control" rows="2" maxlength="500"></textarea>
    </template>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.6): condonación total o parcial de la mora de una cuota.
import { computed, ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import type { Cuota } from '@/types/creditos'

const props = defineProps<{ creditoId: number; cuotas: Cuota[] }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

const cuotaId = ref<number | null>(null)
const monto = ref('')
const motivo = ref('')
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const { clave, renovar } = useClaveIdempotencia()

// Comparación de texto: "0.00" es sin mora (no se hace aritmética de dinero).
const conMora = computed(() => props.cuotas.filter((c) => c.mora_pendiente_hoy && c.mora_pendiente_hoy !== '0.00'))
const montoValido = computed(() => /^\d+([.,]\d{1,2})?$/.test(monto.value.trim()))

function usarTodo() {
  monto.value = conMora.value.find((c) => c.id === cuotaId.value)?.mora_pendiente_hoy ?? ''
}

watch(abierto, (valor) => {
  if (!valor) return
  renovar()
  error.value = null
  motivo.value = ''
  cuotaId.value = conMora.value[0]?.id ?? null
  usarTodo()
})

async function confirmar() {
  if (!cuotaId.value || !montoValido.value || !motivo.value.trim() || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    await creditoService.condonar(props.creditoId, cuotaId.value, monto.value.trim().replace(',', '.'), motivo.value.trim(), clave.value)
    renovar()
    abierto.value = false
    emit('hecho')
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    procesando.value = false
  }
}
</script>
