<template>
  <div>
    <div v-if="cargando" class="small text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
    <div v-else-if="error" class="small text-danger">{{ error }}</div>
    <template v-else-if="datos">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <div class="small text-secondary">Saldo disponible</div>
          <div class="fs-5 fw-semibold cifra" :class="tieneSaldo ? 'text-success' : 'text-muted'">{{ formatoSoles(datos.saldo) }}</div>
        </div>
        <button v-if="puedeDevolver && tieneSaldo" type="button" class="btn btn-sm btn-outline-primary" @click="abrirDevolucion">
          <i class="fas fa-hand-holding-usd me-1"></i>Devolver al cliente
        </button>
      </div>
      <small class="text-muted d-block mt-1">Se genera cuando sobra dinero en un cobro y se elige "Dejar como saldo a favor". Se usa al cobrar o se devuelve en caja.</small>

      <details v-if="datos.movimientos.length" class="mt-3 small">
        <summary class="text-secondary">Movimientos ({{ datos.movimientos.length }})</summary>
        <table class="table table-sm mb-0 mt-2 cifra">
          <thead class="table-light">
            <tr><th>Fecha</th><th>Movimiento</th><th>Detalle</th><th class="text-end">Monto</th></tr>
          </thead>
          <tbody>
            <tr v-for="m in datos.movimientos" :key="m.id">
              <td class="text-nowrap">{{ m.fecha ?? '—' }}</td>
              <td>{{ TIPO[m.tipo] }}</td>
              <td class="text-muted">{{ m.numero_recibo ?? m.motivo ?? '—' }}</td>
              <td class="text-end" :class="m.monto.startsWith('-') ? 'text-danger' : 'text-success'">{{ formatoSoles(m.monto) }}</td>
            </tr>
          </tbody>
        </table>
      </details>
    </template>

    <DialogoBase v-model="dialogo" titulo="Devolver saldo a favor" texto-confirmar="Devolver" :procesando="procesando" :error="errorDevolucion"
      :deshabilitado="!formularioValido" @confirmar="devolver">
      <p class="small text-muted mb-3">Sale de tu caja abierta y queda registrado en el saldo del cliente.</p>
      <div class="row g-3">
        <div class="col-12 col-sm-6">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="dev-monto">Monto</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text">S/</span>
            <input id="dev-monto" v-model="monto" type="text" inputmode="decimal" class="form-control cifra" :class="{ 'is-invalid': supera }" />
          </div>
          <small v-if="supera" class="text-danger">No puede superar {{ formatoSoles(datos?.saldo ?? '0') }}.</small>
        </div>
        <div class="col-12 col-sm-6">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="dev-metodo">Método</label>
          <select id="dev-metodo" v-model="metodo" class="form-select form-select-sm">
            <option v-for="m in metodos" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="dev-motivo">Motivo</label>
          <input id="dev-motivo" v-model="motivo" type="text" class="form-control form-control-sm" maxlength="500" placeholder="Ej.: el cliente lo pidió" />
        </div>
      </div>
    </DialogoBase>
  </div>
</template>

<script setup lang="ts">
// Saldo a favor del cliente en créditos (04c.1): cuánto tiene, de dónde salió y devolverlo.
// Montos como texto: la devolución la valida y registra el backend (caja + movimiento).
import { computed, ref, watch } from 'vue'
import DialogoBase from '@/components/Creditos/DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import { normalizarMonto } from '@/helpers/creditos/cobro'
import { formatoSoles } from '@/helpers/creditos/formato'
import type { MetodoPago, SaldoAFavor } from '@/types/creditos'

const props = defineProps<{ clienteId: number; puedeDevolver: boolean }>()
const emit = defineEmits<{ cambio: [saldo: string] }>()

const TIPO: Record<SaldoAFavor['movimientos'][number]['tipo'], string> = {
  abono: 'Excedente guardado', uso: 'Usado en un pago', devolucion: 'Devuelto en caja', reverso: 'Reverso por anulación',
}

const toast = useToast()
const catalogos = useCreditosCatalogosStore()
const { clave, renovar } = useClaveIdempotencia()
const datos = ref<SaldoAFavor | null>(null)
const cargando = ref(false)
const error = ref<string | null>(null)
const dialogo = ref(false)
const monto = ref('')
const metodo = ref<number | null>(null)
const motivo = ref('')
const metodos = ref<MetodoPago[]>([])
const procesando = ref(false)
const errorDevolucion = ref<ErrorCredito | null>(null)

const tieneSaldo = computed(() => !!datos.value && datos.value.saldo !== '0.00')
const montoValido = computed(() => normalizarMonto(monto.value))
// Solo comparación para guiar; el backend vuelve a validar contra el saldo real.
const supera = computed(() => !!montoValido.value && !!datos.value && Number(montoValido.value) > Number(datos.value.saldo))
const formularioValido = computed(() => !!montoValido.value && !supera.value && metodo.value !== null && motivo.value.trim() !== '')

async function cargar() {
  cargando.value = true
  error.value = null
  try {
    datos.value = await creditoService.saldoAFavor(props.clienteId)
    emit('cambio', datos.value.saldo)
  } catch (e) {
    error.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
}

watch(() => props.clienteId, cargar, { immediate: true })

async function abrirDevolucion() {
  monto.value = datos.value?.saldo ?? ''
  motivo.value = ''
  errorDevolucion.value = null
  dialogo.value = true
  if (!metodos.value.length) {
    metodos.value = await catalogos.obtenerMetodosPago()
    metodo.value = metodos.value[0]?.id ?? null
  }
}

async function devolver() {
  if (!formularioValido.value || !montoValido.value || metodo.value === null) return
  procesando.value = true
  errorDevolucion.value = null
  try {
    datos.value = await creditoService.devolverSaldo(props.clienteId, {
      monto: montoValido.value, payment_method_id: metodo.value, motivo: motivo.value.trim(),
    }, clave.value)
    renovar()
    dialogo.value = false
    emit('cambio', datos.value.saldo)
    toast.success(`Se devolvieron ${formatoSoles(montoValido.value)}`)
  } catch (e) {
    const interpretado = interpretarErrorCredito(e)
    if (interpretado.tipo === 'validacion') interpretado.mensaje = Object.values(interpretado.campos).join(' ') || interpretado.mensaje
    errorDevolucion.value = interpretado
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
