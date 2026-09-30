<template>
  <!-- Resultado: cobro registrado -->
  <div v-if="pagoHecho" class="text-center py-3 cifra">
    <i class="fas fa-check-circle text-success fs-1 mb-2 d-block"></i>
    <h5 class="fw-bold mb-1">Cobro registrado</h5>
    <p class="text-muted small mb-3">{{ pagoHecho.numero_recibo }} · {{ formatoSoles(pagoHecho.monto_aplicado) }} aplicado</p>
    <div v-if="pagoHecho.monto_excedente !== '0.00'" class="alert alert-warning d-inline-block py-2 small">
      <template v-if="pagoHecho.destino_excedente === 'saldo_a_favor'">
        {{ formatoSoles(pagoHecho.monto_excedente) }} quedan como saldo a favor del cliente.
      </template>
      <template v-else>
        Entrega de vuelto: <b>{{ formatoSoles(pagoHecho.monto_excedente) }}</b>
      </template>
    </div>
    <!-- Recibos: se activan con la Fase 4b (documentos). -->
    <div class="d-flex flex-wrap gap-2 justify-content-center my-3">
      <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Disponible con los documentos (Fase 4b)">
        <i class="fas fa-print me-1"></i>Ticket 80mm
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Disponible con los documentos (Fase 4b)">
        <i class="fas fa-file-pdf me-1"></i>PDF
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Disponible con los documentos (Fase 4b)">
        <i class="fab fa-whatsapp me-1"></i>WhatsApp
      </button>
    </div>
    <button type="button" class="btn btn-primary px-4" @click="emit('cerrar')">Listo</button>
  </div>

  <form v-else novalidate @submit.prevent="confirmar">
    <!-- Caja (mockup 3): sin caja abierta no se puede cobrar -->
    <div v-if="caja" class="alert py-2 px-3 small mb-3" :class="caja.abierta ? 'alert-success' : 'alert-danger'">
      <template v-if="caja.abierta">
        <i class="fas fa-cash-register me-1"></i>Caja abierta{{ caja.cajero ? ` · ${caja.cajero}` : '' }}{{ caja.desde ? ` · desde ${horaEnLima(caja.desde)}` : '' }}
      </template>
      <template v-else>
        <i class="fas fa-lock me-1"></i>No tienes caja abierta.
        <router-link :to="{ name: 'cash.session' }" class="alert-link">Abrir caja</router-link>
      </template>
    </div>

    <div class="row g-3">
      <!-- Izquierda: deuda, monto, método, excedente -->
      <div class="col-12 col-lg-7 d-flex flex-column gap-3">
        <div class="card border-0 shadow-sm mb-0">
          <div class="card-header bg-white border-bottom py-2 fw-semibold text-dark">Deuda de hoy</div>
          <div class="card-body py-2">
            <table class="table table-sm table-borderless small mb-0 cifra">
              <tbody>
                <tr v-for="f in filas" :key="f.clave" :class="{ 'text-danger': f.mora }">
                  <td class="ps-0">{{ f.texto }}</td><td class="text-end pe-0 text-nowrap">{{ formatoSoles(f.monto) }}</td>
                </tr>
                <tr v-if="!filas.length"><td class="ps-0 text-muted fst-italic" colspan="2">No tiene deuda exigible hoy.</td></tr>
                <tr class="border-top fw-semibold">
                  <td class="ps-0">Total para ponerse al día</td>
                  <td class="text-end pe-0 text-nowrap text-primary">{{ formatoSoles(resumen.exigible_hoy) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-0">
          <div class="card-header bg-white border-bottom py-2 fw-semibold text-dark">Pago</div>
          <div class="card-body py-3">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-monto">Monto recibido</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">S/</span>
                  <input id="cobro-monto" ref="campoMonto" v-model="montoTexto" type="text" inputmode="decimal" autocomplete="off"
                    class="form-control fw-semibold cifra" :class="{ 'is-invalid': montoTexto !== '' && !monto }" />
                </div>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-metodo">Método de pago</label>
                <select id="cobro-metodo" v-model="metodo" class="form-select form-select-sm">
                  <option v-for="m in metodosPago" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
              </div>
              <div class="col-12">
                <div class="d-flex flex-wrap gap-2">
                  <button v-for="a in atajos" :key="a.id" type="button" class="btn btn-sm"
                    :class="monto === a.monto ? 'btn-primary' : 'btn-outline-primary'" @click="usarMonto(a.monto)">
                    {{ a.texto }} · {{ formatoSoles(a.monto) }}
                  </button>
                  <button v-if="puedeLiquidar" type="button" class="btn btn-sm btn-outline-secondary" @click="emit('liquidar')">
                    <i class="fas fa-check-double me-1"></i>Liquidar todo…
                  </button>
                </div>
              </div>
              <div v-if="!esEfectivo" class="col-12 col-md-6">
                <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-ref">Referencia (opcional)</label>
                <input id="cobro-ref" v-model="referencia" type="text" class="form-control form-control-sm" maxlength="100" placeholder="N.º de operación" />
              </div>
            </div>

            <!-- Excedente (00 1.4): solo si lo recibido supera lo exigible -->
            <div v-if="hayExcedente" class="alert alert-warning py-2 small mt-3 mb-0">
              <div class="fw-semibold mb-1">
                {{ cotizacion && cotizacion.monto_excedente !== '0.00' ? `Sobran ${formatoSoles(cotizacion.monto_excedente)}` : 'Recibe más de lo que debe hoy' }}: ¿qué se hace?
              </div>
              <div v-for="o in OPCIONES_EXCEDENTE" :key="o.valor" class="form-check mb-1">
                <input :id="`exc-${o.valor}`" v-model="destino" class="form-check-input" type="radio" :value="o.valor" />
                <label class="form-check-label" :for="`exc-${o.valor}`">
                  <span class="fw-semibold">{{ o.texto }}</span> <span class="text-muted">— {{ o.ayuda }}</span>
                </label>
              </div>
            </div>
            <small v-else class="text-muted d-block mt-3">
              <i class="fas fa-info-circle me-1"></i>Si recibe más de lo que debe hoy, se le preguntará qué hacer con el excedente.
            </small>

            <!-- Pago con fecha anterior (1.12): solo con permiso -->
            <div v-if="puedeFechaAnterior" class="mt-3">
              <div class="form-check form-switch mb-0">
                <input id="cobro-retro" v-model="conFechaAnterior" class="form-check-input" type="checkbox" role="switch" />
                <label class="form-check-label small" for="cobro-retro">Registrar con fecha anterior</label>
              </div>
              <div v-if="conFechaAnterior" class="row g-2 mt-1">
                <div class="col-12 col-sm-5">
                  <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-fecha">Fecha del pago</label>
                  <input id="cobro-fecha" v-model="fechaPago" type="date" class="form-control form-control-sm" :max="hoy" />
                </div>
                <div class="col-12 col-sm-7">
                  <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-motivo">Motivo</label>
                  <input id="cobro-motivo" v-model="motivo" type="text" class="form-control form-control-sm" maxlength="500" placeholder="Ej.: pagó ayer y no se registró" />
                </div>
                <small class="col-12 text-muted">La vista previa es a hoy; al confirmar, el reparto se recalcula a la fecha indicada.</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Derecha: cómo se aplicará + confirmar -->
      <div class="col-12 col-lg-5">
        <div class="columna-confirmar">
          <div class="card border-0 shadow-sm mb-0" :class="{ 'opacity-50': cargandoCotizacion }">
            <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
              <span class="fw-semibold text-dark">Así se aplicará el pago</span>
              <span v-if="cargandoCotizacion" class="spinner-border spinner-border-sm text-muted"></span>
            </div>
            <div class="card-body py-2 cifra">
              <table v-if="cotizacion" class="table table-sm table-borderless small mb-0">
                <tbody>
                  <tr v-for="(a, i) in cotizacion.aplicacion" :key="i">
                    <td class="ps-0 text-muted">{{ textoAplicacion(a) }}</td><td class="text-end pe-0">{{ formatoSoles(a.monto) }}</td>
                  </tr>
                  <tr v-if="cotizacion.monto_excedente !== '0.00'" class="text-warning-emphasis">
                    <td class="ps-0">{{ destino === 'devolver' ? 'Vuelto' : destino === 'saldo_a_favor' ? 'Saldo a favor' : 'Excedente' }}</td>
                    <td class="text-end pe-0">{{ formatoSoles(cotizacion.monto_excedente) }}</td>
                  </tr>
                  <tr class="border-top fw-semibold">
                    <td class="ps-0">Saldo después del pago</td><td class="text-end pe-0">{{ formatoSoles(cotizacion.saldo_despues) }}</td>
                  </tr>
                  <tr v-if="cotizacion.finaliza_credito" class="text-success fw-semibold">
                    <td class="ps-0" colspan="2"><i class="fas fa-check-circle me-1"></i>Con este pago el crédito queda cancelado.</td>
                  </tr>
                  <tr v-else>
                    <td class="ps-0 text-muted">Próximo pago</td><td class="text-end pe-0">{{ textoProxima(cotizacion.proxima_despues, formatoSoles) }}</td>
                  </tr>
                </tbody>
              </table>
              <p v-else-if="errorCotizacion" class="text-danger small mb-0">{{ errorCotizacion.mensaje }}</p>
              <p v-else class="text-muted small fst-italic mb-0">Escribe el monto recibido para ver el reparto.</p>
            </div>
          </div>

          <div v-if="error" class="alert mt-3 mb-0 py-2 small" :class="error.tipo === 'red' ? 'alert-warning' : 'alert-danger'" role="alert">
            {{ error.mensaje }}
            <button v-if="error.tipo === 'conflicto'" type="button" class="btn btn-sm btn-danger d-block mt-2" @click="emit('cerrar')">Revisar pagos</button>
          </div>

          <BarraAccionMovil>
            <button type="submit" class="btn btn-success fw-semibold w-100" :disabled="!puedeConfirmar">
              <span v-if="procesando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-check me-2"></i>
              {{ error?.reintentable ? 'Reintentar' : `Confirmar cobro${monto ? ` · ${formatoSoles(monto)}` : ''}` }}
            </button>
          </BarraAccionMovil>
        </div>
      </div>
    </div>
  </form>
</template>

<script setup lang="ts">
// Módulo Créditos — Cobrar (04-frontend pantalla 3, mockup 3). La deuda, los atajos y el
// reparto vienen del backend (GET creditos/{id} + POST pagos/cotizar); al confirmar, el
// backend recalcula todo de nuevo y el botón queda bloqueado mientras procesa.
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import BarraAccionMovil from './BarraAccionMovil.vue'
import { creditoService } from '@/services/admin/creditoService'
import { usePreviewCredito } from '@/composables/creditos/usePreviewCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { atajosCobro, filasDeuda, normalizarMonto, textoAplicacion, textoProxima } from '@/helpers/creditos/cobro'
import { formatoSoles, hoyEnLima, horaEnLima } from '@/helpers/creditos/formato'
import type { CotizacionPago, DestinoExcedente, DetalleCredito, EstadoCaja, MetodoPago, Pago } from '@/types/creditos'

const props = defineProps<{
  detalle: DetalleCredito
  metodosPago: MetodoPago[]
  /** undefined mientras carga; null si el usuario no ve la caja. */
  caja?: EstadoCaja | null
  puedeFechaAnterior: boolean
  puedeLiquidar: boolean
}>()
const emit = defineEmits<{ hecho: [pago: Pago]; cerrar: []; liquidar: [] }>()

const OPCIONES_EXCEDENTE: { valor: DestinoExcedente; texto: string; ayuda: string }[] = [
  { valor: 'devolver', texto: 'Devolver el vuelto', ayuda: 'Sale de caja en el mismo momento.' },
  { valor: 'adelanto', texto: 'Adelantar cuotas', ayuda: 'Se aplica a las siguientes cuotas.' },
  { valor: 'saldo_a_favor', texto: 'Dejar como saldo a favor', ayuda: 'Queda a nombre del cliente para un próximo pago.' },
]

const hoy = hoyEnLima()
const resumen = computed(() => props.detalle.resumen)
const filas = computed(() => filasDeuda(resumen.value.deuda_hoy, hoy))
const atajos = computed(() => atajosCobro(resumen.value, hoy))

const montoTexto = ref(resumen.value.exigible_hoy !== '0.00' ? resumen.value.exigible_hoy : '')
const monto = computed(() => normalizarMonto(montoTexto.value))
const metodo = ref<number | null>(props.metodosPago[0]?.id ?? null)
const referencia = ref('')
const destino = ref<DestinoExcedente>('devolver')
const conFechaAnterior = ref(false)
const fechaPago = ref(hoy)
const motivo = ref('')
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const pagoHecho = ref<Pago | null>(null)
const campoMonto = ref<HTMLInputElement | null>(null)
const { clave, renovar } = useClaveIdempotencia()

// Solo una comparación para mostrar las opciones; cuánto sobra lo dice la cotización.
const hayExcedente = computed(() => !!monto.value && Number(monto.value) > Number(resumen.value.exigible_hoy))
watch(hayExcedente, (valor) => {
  if (!valor) destino.value = 'devolver'
})

const esEfectivo = computed(() => /efectivo/i.test(props.metodosPago.find((m) => m.id === metodo.value)?.name ?? ''))

// Vista previa del reparto (debounce + descarte de respuestas viejas).
const solicitud = computed(() => (monto.value ? { monto: monto.value, destino: destino.value } : null))
const { preview: cotizacion, cargando: cargandoCotizacion, error: errorCotizacion } = usePreviewCredito<CotizacionPago, { monto: string; destino: DestinoExcedente }>(
  solicitud,
  (s, signal) => creditoService.cotizarPago(props.detalle.credito.id, s.monto, s.destino, signal),
)

// La clave NO se renueva al cambiar datos: si un intento anterior sí llegó al servidor,
// reenviarla con otro contenido da 409 en vez de un segundo cobro. Solo se limpia el error.
watch([monto, destino, metodo, conFechaAnterior, fechaPago], () => {
  if (error.value && !error.value.reintentable) error.value = null
})

watch(() => props.metodosPago, (lista) => {
  if (metodo.value === null && lista.length) metodo.value = lista[0].id
})

const puedeConfirmar = computed(() =>
  !procesando.value
  && !!monto.value
  && metodo.value !== null
  && props.caja?.abierta !== false
  && !cargandoCotizacion.value
  && !!cotizacion.value
  && cotizacion.value.monto_aplicado !== '0.00'
  && (!conFechaAnterior.value || (fechaPago.value !== '' && fechaPago.value <= hoy && motivo.value.trim() !== '')),
)

function usarMonto(valor: string) {
  montoTexto.value = valor
  campoMonto.value?.focus()
}

async function confirmar() {
  if (!puedeConfirmar.value || !monto.value || metodo.value === null) return
  procesando.value = true
  error.value = null
  const retroactivo = conFechaAnterior.value && fechaPago.value !== hoy
  try {
    const { pago } = await creditoService.cobrar(props.detalle.credito.id, {
      monto_recibido: monto.value,
      destino_excedente: destino.value,
      payment_method_id: metodo.value,
      referencia: esEfectivo.value ? null : referencia.value.trim() || null,
      fecha_pago: retroactivo ? fechaPago.value : null,
      motivo: retroactivo ? motivo.value.trim() : null,
    }, clave.value)
    renovar()
    pagoHecho.value = pago
    emit('hecho', pago)
  } catch (e) {
    const interpretado = interpretarErrorCredito(e)
    if (interpretado.tipo === 'conflicto') {
      // La clave ya se usó: lo más probable es que un intento anterior sí se registró.
      interpretado.mensaje = 'Un intento anterior de este cobro ya se registró. Revisa los pagos del crédito antes de volver a cobrar.'
    } else if (interpretado.tipo === 'validacion') {
      interpretado.mensaje = Object.values(interpretado.campos).join(' ') || interpretado.mensaje
    }
    error.value = interpretado
  } finally {
    procesando.value = false
  }
}

onMounted(async () => {
  await nextTick()
  campoMonto.value?.focus()
  campoMonto.value?.select()
})
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
/* Escritorio: el reparto y el botón acompañan el scroll de la columna izquierda. */
@media (min-width: 992px) {
  .columna-confirmar {
    position: sticky;
    top: 0;
  }
}
</style>
