<template>
  <DialogoBase v-model="abierto" titulo="Corregir crédito" texto-confirmar="Guardar corrección" variante="warning" tamano="xl"
    :procesando="procesando" :deshabilitado="!condiciones || !motivo.trim()" :error="error" @confirmar="confirmar">
    <div class="alert alert-warning py-2 small">
      Se genera un cronograma nuevo con el mismo número; el anterior queda anulado. Si cambia el monto, se ajusta el desembolso en caja.
    </div>
    <div v-if="form" class="row g-3">
      <div class="col-12 col-lg-7">
        <FormCondiciones v-model="form" :errores="errores" :metodos-pago="metodosPago"
          :primer-sugerido="preview?.cronograma.primer_vencimiento ?? null" />
      </div>
      <div class="col-12 col-lg-5 d-flex flex-column gap-3">
        <ResumenCronograma :preview="preview" :capital="condiciones?.monto_capital ?? '0'" :cargando="cargandoPreview"
          :error="errorPreview?.mensaje ?? null" />
        <div>
          <label class="form-label fw-semibold small" for="cc-motivo">Motivo <span class="text-danger">*</span></label>
          <textarea id="cc-motivo" v-model="motivo" class="form-control" rows="2" maxlength="500"></textarea>
        </div>
      </div>
    </div>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.9): corregir un crédito activo sin pagos. Mismo formulario y
// preview que "Nuevo crédito"; el cliente no cambia.
import { computed, ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import FormCondiciones from './FormCondiciones.vue'
import ResumenCronograma from './ResumenCronograma.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { usePreviewCredito } from '@/composables/creditos/usePreviewCredito'
import { aCondiciones, formularioDesdeCredito, type FormCredito } from '@/helpers/creditos/formulario'
import type { Credito, MetodoPago } from '@/types/creditos'

const props = defineProps<{ credito: Credito; metodosPago: MetodoPago[] }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

const form = ref<FormCredito | null>(null)
const motivo = ref('')
const errores = ref<Record<string, string>>({})
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const { clave, renovar } = useClaveIdempotencia()

const condiciones = computed(() => (abierto.value && form.value ? aCondiciones(form.value, props.credito.cliente_id) : null))
const { preview, cargando: cargandoPreview, error: errorPreview } = usePreviewCredito(condiciones)

watch(abierto, (valor) => {
  if (!valor) return
  renovar()
  error.value = null
  errores.value = {}
  motivo.value = ''
  form.value = formularioDesdeCredito(props.credito)
})

async function confirmar() {
  if (!condiciones.value || !motivo.value.trim() || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    await creditoService.corregir(props.credito.id, condiciones.value, motivo.value.trim(), clave.value)
    renovar()
    abierto.value = false
    emit('hecho')
  } catch (e) {
    const interpretado = interpretarErrorCredito(e)
    if (interpretado.tipo === 'validacion') errores.value = interpretado.campos
    error.value = interpretado
  } finally {
    procesando.value = false
  }
}
</script>
