<template>
  <DialogoBase v-model="abierto" :titulo="titulo" :texto-confirmar="textoConfirmar" :variante="variante"
    :procesando="procesando" :deshabilitado="!motivo.trim()" :error="error" @confirmar="confirmar">
    <p v-if="descripcion" class="mb-3">{{ descripcion }}</p>
    <label class="form-label fw-semibold" for="dm-motivo">Motivo <span class="text-danger">*</span></label>
    <textarea id="dm-motivo" v-model="motivo" class="form-control" rows="3" maxlength="500"
      placeholder="Queda registrado en la auditoría"></textarea>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos: acciones que solo piden un motivo obligatorio (anular crédito o pago,
// castigar, revertir castigo). La acción real la hace el padre en `ejecutar`.
import { ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'

const props = withDefaults(defineProps<{
  titulo: string
  descripcion?: string
  textoConfirmar?: string
  variante?: string
  ejecutar: (motivo: string, clave: string) => Promise<unknown>
}>(), { descripcion: '', textoConfirmar: 'Confirmar', variante: 'danger' })

const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

const motivo = ref('')
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const { clave, renovar } = useClaveIdempotencia()

// Cada apertura es un intento nuevo (clave nueva); los reintentos pasan con el diálogo abierto.
watch(abierto, (valor) => {
  if (valor) {
    motivo.value = ''
    error.value = null
    renovar()
  }
})

async function confirmar() {
  if (!motivo.value.trim() || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    await props.ejecutar(motivo.value.trim(), clave.value)
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
