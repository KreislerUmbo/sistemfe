<template>
  <DialogoBase v-model="abierto" titulo="Renovar crédito" :texto-confirmar="textoConfirmar" variante="primary" tamano="xl"
    :procesando="procesando" :deshabilitado="!condiciones || !preview || requiereMotivo" :error="error" @confirmar="confirmar">
    <p class="small text-muted">
      El crédito nuevo cancela lo que se debe hoy y este crédito queda cerrado. Si el nuevo es mayor, se entrega la
      diferencia; si es menor (por ejemplo, paga el interés y sigue con el mismo capital), el cliente paga la diferencia.
    </p>
    <div v-if="form" class="row g-3">
      <div class="col-12 col-lg-7">
        <FormCondiciones v-model="form" :errores="errores" :metodos-pago="metodosPago"
          :primer-sugerido="preview?.cronograma.primer_vencimiento ?? null"
          :etiqueta-metodo="preview?.movimiento === 'cobro' ? 'Método de pago del cliente' : 'Método de entrega'" />
      </div>
      <div class="col-12 col-lg-5 d-flex flex-column gap-3">
        <div v-if="preview" class="card border mb-0">
          <ul class="list-group list-group-flush cifra">
            <li class="list-group-item d-flex justify-content-between"><span>Crédito nuevo</span><span>{{ formatoSoles(preview.capital_nuevo) }}</span></li>
            <li class="list-group-item d-flex justify-content-between"><span>Descuenta lo que debe hoy</span><span>− {{ formatoSoles(preview.liquidacion.monto_liquidacion) }}</span></li>
            <li v-if="preview.movimiento === 'entrega'" class="list-group-item d-flex justify-content-between fw-bold fs-5">
              <span><i class="fas fa-arrow-up me-2 text-danger"></i>Se entrega</span><span>{{ formatoSoles(preview.monto_movimiento) }}</span>
            </li>
            <li v-else-if="preview.movimiento === 'cobro'" class="list-group-item bg-success-subtle">
              <div class="d-flex justify-content-between fw-bold fs-5">
                <span><i class="fas fa-arrow-down me-2 text-success"></i>El cliente paga</span><span>{{ formatoSoles(preview.monto_movimiento) }}</span>
              </div>
              <small class="text-muted">Entra a tu caja. El crédito nuevo cubre el resto de lo que debe.</small>
            </li>
            <li v-else class="list-group-item d-flex justify-content-between fw-bold">
              <span><i class="fas fa-equals me-2 text-muted"></i>Sin movimiento de dinero</span><span>{{ formatoSoles(0) }}</span>
            </li>
          </ul>
        </div>
        <ResumenCronograma :preview="preview" :capital="condiciones?.monto_capital ?? '0'" :cargando="cargandoPreview"
          :error="errorPreview?.mensaje ?? null" />
        <AvisosLimites v-if="preview" :bloqueos="preview.limites.bloqueos" :advertencias="preview.limites.advertencias"
          titulo="La renovación supera los límites del negocio" />
        <div v-if="preview?.limites.bloquea">
          <template v-if="puedeAutorizar">
            <label class="form-label mb-1 small fw-semibold text-secondary" for="rn-motivo">Motivo de la autorización <span class="text-danger">*</span></label>
            <textarea id="rn-motivo" v-model="motivoAutorizacion" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
          </template>
          <p v-else class="small text-danger mb-0">Pide a un administrador que autorice la excepción.</p>
        </div>
      </div>
    </div>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.21): renovación con vista previa (liquidación de hoy, qué pasa con el
// dinero —se entrega, no se mueve o el cliente paga la diferencia—, cronograma nuevo, límites). Si hay bloqueos autorizables, el admin los autoriza
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
// El botón dice qué va a pasar con el dinero, para confirmar sin dudas.
const textoConfirmar = computed(() => {
  const p = preview.value
  if (!p) return 'Renovar'
  if (p.movimiento === 'entrega') return `Renovar y entregar ${formatoSoles(p.monto_movimiento)}`
  if (p.movimiento === 'cobro') return `Renovar y cobrar ${formatoSoles(p.monto_movimiento)}`
  return 'Renovar'
})
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
