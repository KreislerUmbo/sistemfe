<template>
  <DialogoBase v-model="abierto" titulo="Renovar crédito" texto-confirmar="Renovar y entregar" variante="primary" tamano="xl"
    :procesando="procesando" :deshabilitado="!condiciones || !preview || requiereMotivo" :error="error" @confirmar="confirmar">
    <p class="small text-muted">
      Lo que se debe hoy se descuenta del crédito nuevo: se entrega solo la diferencia y este crédito queda cerrado.
    </p>
    <div v-if="form" class="row g-3">
      <div class="col-12 col-lg-7">
        <FormCondiciones v-model="form" :errores="errores" :metodos-pago="metodosPago"
          :primer-sugerido="preview?.cronograma.primer_vencimiento ?? null" />
      </div>
      <div class="col-12 col-lg-5 d-flex flex-column gap-3">
        <div v-if="preview" class="card border mb-0">
          <ul class="list-group list-group-flush cifra">
            <li class="list-group-item d-flex justify-content-between"><span>Crédito nuevo</span><span>{{ formatoSoles(preview.capital_nuevo) }}</span></li>
            <li class="list-group-item d-flex justify-content-between"><span>Descuenta lo que debe hoy</span><span>− {{ formatoSoles(preview.liquidacion.monto_liquidacion) }}</span></li>
            <li class="list-group-item d-flex justify-content-between fw-bold fs-5"><span>Se entrega</span><span>{{ formatoSoles(preview.entrega_neta) }}</span></li>
          </ul>
        </div>
        <ResumenCronograma :preview="preview" :capital="condiciones?.monto_capital ?? '0'" :cargando="cargandoPreview"
          :error="errorPreview?.mensaje ?? null" />
        <AvisosLimites v-if="preview" :bloqueos="preview.limites.bloqueos" :advertencias="preview.limites.advertencias"
          titulo="La renovación supera los límites del negocio" />
        <div v-if="preview?.limites.bloquea">
          <template v-if="puedeAutorizar">
            <label class="form-label fw-semibold small" for="rn-motivo">Motivo de la autorización <span class="text-danger">*</span></label>
            <textarea id="rn-motivo" v-model="motivoAutorizacion" class="form-control" rows="2" maxlength="500"></textarea>
          </template>
          <p v-else class="small text-danger mb-0">Pide a un administrador que autorice la excepción.</p>
        </div>
      </div>
    </div>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.12): renovación con vista previa (liquidación de hoy, entrega
// neta, cronograma nuevo, límites). Si hay bloqueos autorizables, el admin los autoriza
// en el mismo acto con un motivo.
import { computed, ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import FormCondiciones from './FormCondiciones.vue'
import ResumenCronograma from './ResumenCronograma.vue'
import AvisosLimites from './AvisosLimites.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { usePreviewCredito } from '@/composables/creditos/usePreviewCredito'
import { aCondiciones, formularioDesdeCredito, type FormCredito } from '@/helpers/creditos/formulario'
import { formatoSoles, hoyEnLima } from '@/helpers/creditos/formato'
import type { Credito, MetodoPago, PreviewRenovacion } from '@/types/creditos'

const props = defineProps<{ credito: Credito; metodosPago: MetodoPago[]; puedeAutorizar: boolean }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [nuevoId: number] }>()

const form = ref<FormCredito | null>(null)
const motivoAutorizacion = ref('')
const errores = ref<Record<string, string>>({})
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const { clave, renovar } = useClaveIdempotencia()

const condiciones = computed(() => (abierto.value && form.value ? aCondiciones(form.value, props.credito.cliente_id) : null))
const { preview, cargando: cargandoPreview, error: errorPreview } = usePreviewCredito<PreviewRenovacion>(
  condiciones,
  (c, signal) => creditoService.previewRenovacion(props.credito.id, c, signal),
)
const requiereMotivo = computed(() => !!preview.value?.limites.bloquea && (!props.puedeAutorizar || !motivoAutorizacion.value.trim()))

watch(abierto, (valor) => {
  if (!valor) return
  renovar()
  error.value = null
  errores.value = {}
  motivoAutorizacion.value = ''
  // Mismas condiciones como punto de partida; el monto lo elige el cajero. Se entrega hoy.
  form.value = { ...formularioDesdeCredito(props.credito), monto_capital: '', fecha_desembolso: hoyEnLima(), fecha_primer_vencimiento: '' }
})

async function confirmar() {
  if (!condiciones.value || !preview.value || requiereMotivo.value || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    const { credito: nuevo } = await creditoService.renovar(props.credito.id, condiciones.value, motivoAutorizacion.value.trim() || null, clave.value)
    renovar()
    abierto.value = false
    emit('hecho', nuevo.id)
  } catch (e) {
    const interpretado = interpretarErrorCredito(e)
    if (interpretado.tipo === 'validacion') errores.value = interpretado.campos
    error.value = interpretado
  } finally {
    procesando.value = false
  }
}
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
