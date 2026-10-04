<template>
  <DialogoBase v-model="abierto" titulo="Liquidar crédito" texto-confirmar="Liquidar y cerrar" variante="success"
    :procesando="procesando" :deshabilitado="!cotizacion || !montoValido" :error="error" @confirmar="confirmar">
    <div v-if="cargando" class="text-center text-muted py-4">
      <span class="spinner-border spinner-border-sm me-2"></span>Calculando la liquidación de hoy…
    </div>
    <template v-else-if="cotizacion">
      <p class="small text-muted mb-2">
        Válida solo para hoy ({{ formatoFecha(cotizacion.fecha) }}): al confirmar se recalcula.
      </p>
      <ul class="list-group mb-3 cifra">
        <li class="list-group-item d-flex justify-content-between"><span>Capital pendiente</span><span>{{ formatoSoles(cotizacion.capital_pendiente) }}</span></li>
        <li class="list-group-item d-flex justify-content-between">
          <span>Interés a cobrar
            <small v-if="cotizacion.interes_final === cotizacion.interes_minimo" class="text-muted d-block">Interés mínimo por pagar antes</small>
          </span>
          <span>{{ formatoSoles(cotizacion.interes_a_cobrar) }}</span>
        </li>
        <li v-if="cotizacion.mora_pendiente !== '0.00'" class="list-group-item d-flex justify-content-between text-danger">
          <span>Interés moratorio</span><span>{{ formatoSoles(cotizacion.mora_pendiente) }}</span>
        </li>
        <li v-if="cotizacion.cargos_pendientes !== '0.00'" class="list-group-item d-flex justify-content-between">
          <span>Cargos</span><span>{{ formatoSoles(cotizacion.cargos_pendientes) }}</span>
        </li>
        <li class="list-group-item d-flex justify-content-between fw-bold fs-5">
          <span>Total para cancelar hoy</span><span>{{ formatoSoles(cotizacion.monto_liquidacion) }}</span>
        </li>
      </ul>
      <!-- Informativo, no es una resta: el interés que ya no se cobra por cancelar antes (1.7). -->
      <p v-if="cotizacion.interes_descontado !== '0.00'" class="small text-success mb-3">
        <i class="fas fa-piggy-bank me-1"></i>Se ahorra {{ formatoSoles(cotizacion.interes_descontado) }} de interés por cancelar antes.
      </p>

      <div class="row g-2">
        <div class="col-12 col-sm-6">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="liq-monto">Monto recibido</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text">S/</span>
            <input id="liq-monto" v-model="monto" type="text" inputmode="decimal" class="form-control form-control-sm" :class="{ 'is-invalid': !montoValido }" />
          </div>
          <small class="text-muted">Si recibes más, el vuelto sale de caja.</small>
        </div>
        <div v-if="metodosPago.length > 1" class="col-12 col-sm-6">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="liq-metodo">Método</label>
          <select id="liq-metodo" v-model="metodo" class="form-select form-select-sm">
            <option v-for="m in metodosPago" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="liq-ref">Referencia (opcional)</label>
          <input id="liq-ref" v-model="referencia" type="text" class="form-control form-control-sm" maxlength="100" placeholder="N.º de operación" />
        </div>
      </div>
    </template>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.7): cotización del día + confirmar. El backend recalcula a hoy y
// exige que lo recibido cubra la liquidación; aquí no se calcula nada.
import { computed, ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import type { Liquidacion, MetodoPago } from '@/types/creditos'

const props = defineProps<{ creditoId: number; metodosPago: MetodoPago[] }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

const cotizacion = ref<Liquidacion | null>(null)
const monto = ref('')
const metodo = ref<number | null>(null)
const referencia = ref('')
const cargando = ref(false)
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const { clave, renovar } = useClaveIdempotencia()

const montoValido = computed(() => /^\d+([.,]\d{1,2})?$/.test(monto.value.trim()))

watch(abierto, async (valor) => {
  if (!valor) return
  renovar()
  error.value = null
  cotizacion.value = null
  referencia.value = ''
  metodo.value = props.metodosPago[0]?.id ?? null
  cargando.value = true
  try {
    cotizacion.value = await creditoService.cotizarLiquidacion(props.creditoId)
    monto.value = cotizacion.value.monto_liquidacion
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    cargando.value = false
  }
})

async function confirmar() {
  if (!cotizacion.value || !montoValido.value || metodo.value === null || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    await creditoService.liquidar(props.creditoId, {
      monto_recibido: monto.value.trim().replace(',', '.'),
      payment_method_id: metodo.value,
      referencia: referencia.value.trim() || null,
    }, clave.value)
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

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
