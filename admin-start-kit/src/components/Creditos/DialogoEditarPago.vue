<template>
  <DialogoBase v-model="abierto" :titulo="`Editar ${pago?.numero_recibo ?? 'pago'}`" texto-confirmar="Guardar"
    :procesando="procesando" :error="error" @confirmar="confirmar">
    <p class="small text-muted">Solo se pueden cambiar la referencia y las observaciones; el cambio queda auditado.</p>
    <label class="form-label small fw-semibold" for="ep-ref">Referencia</label>
    <input id="ep-ref" v-model="referencia" type="text" class="form-control mb-3" maxlength="100" />
    <label class="form-label small fw-semibold" for="ep-obs">Observaciones</label>
    <textarea id="ep-obs" v-model="observaciones" class="form-control" rows="2" maxlength="500"></textarea>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.8): un pago nunca se edita salvo referencia y observaciones.
import { ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import type { Pago } from '@/types/creditos'

const props = defineProps<{ creditoId: number; pago: Pago | null }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

const referencia = ref('')
const observaciones = ref('')
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)

watch(abierto, (valor) => {
  if (!valor) return
  error.value = null
  referencia.value = props.pago?.referencia ?? ''
  observaciones.value = props.pago?.observaciones ?? ''
})

async function confirmar() {
  if (!props.pago || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    await creditoService.editarPago(props.creditoId, props.pago.id, {
      referencia: referencia.value.trim() || null,
      observaciones: observaciones.value.trim() || null,
    })
    abierto.value = false
    emit('hecho')
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    procesando.value = false
  }
}
</script>
