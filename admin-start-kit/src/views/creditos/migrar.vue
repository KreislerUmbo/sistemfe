<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Registrar crédito existente" subtitulo="Un préstamo entregado antes de usar el sistema" icono="fas fa-file-import" />

    <div class="alert alert-info py-2 small d-flex gap-2 align-items-start">
      <i class="fas fa-info-circle mt-1"></i>
      <span>No genera movimientos de caja: el dinero ya se entregó y los pagos anteriores ya se cobraron. Los límites del negocio solo se muestran como aviso.</span>
    </div>

    <div v-if="cargandoInicial" class="text-center py-5 text-muted">
      <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
    </div>

    <form v-else class="row g-3" novalidate @submit.prevent="registrar">
      <div class="col-12 col-xl-7 d-flex flex-column gap-3">
        <div class="card border-0 shadow-sm mb-0">
          <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
            <span class="badge bg-primary rounded-pill">1</span>
            <span class="fw-semibold text-dark">Cliente</span>
          </div>
          <div class="card-body py-3">
            <BuscadorCliente v-model="cliente" :error="errores.cliente_id" autofocus />
            <TarjetaResumenCliente :cliente-id="cliente?.id ?? null" />
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-0">
          <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
            <span class="badge bg-primary rounded-pill">2</span>
            <span class="fw-semibold text-dark">Condiciones originales</span>
          </div>
          <div class="card-body py-3">
            <FormCondiciones v-model="form" :errores="errores" desembolso-editable :fecha-maxima="ayer"
              :primer-sugerido="preview?.cronograma.primer_vencimiento ?? null"
              :primera-cuota="preview?.cronograma.cuotas[0] ?? null" :tasa-mensual="preview?.cronograma.tasa_mensual_equivalente ?? null" />
          </div>
        </div>

        <!-- Pagos anteriores al sistema -->
        <div class="card border-0 shadow-sm mb-0">
          <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2 flex-wrap">
            <span class="badge bg-primary rounded-pill">3</span>
            <span class="fw-semibold text-dark">Pagos que ya hizo</span>
          </div>
          <div class="card-body py-3">
            <div class="btn-group btn-group-sm mb-1 flex-wrap" role="group" aria-label="Cómo registrar los pagos">
              <input id="mg-modo-rapido" v-model="modo" type="radio" class="btn-check" value="rapido" />
              <label class="btn btn-outline-primary" for="mg-modo-rapido">Rápido: marcar cuotas pagadas</label>
              <input id="mg-modo-detallado" v-model="modo" type="radio" class="btn-check" value="detallado" />
              <label class="btn btn-outline-primary" for="mg-modo-detallado">Detallado: pago por pago</label>
            </div>
            <small class="text-muted d-block mb-3">
              {{ modo === 'rapido'
                ? 'Para quien pagó sus cuotas puntualmente: indicas cuántas y se registran en su fecha, sin mora.'
                : 'Para pagos con atraso o montos distintos: cada pago con su fecha real; el sistema calcula la mora.' }}
            </small>
            <div v-if="modo === 'rapido'">
              <label class="form-label mb-1 small fw-semibold text-secondary" for="mg-pagadas">¿Cuántas cuotas pagó a tiempo?</label>
              <input id="mg-pagadas" v-model="cuotasPagadas" type="number" inputmode="numeric" min="0" :max="totalCuotas || undefined"
                class="form-control form-control-sm campo-corto" :class="{ 'is-invalid': errores.cuotas_pagadas }" />
              <small class="text-muted d-block mt-1">{{ Number(cuotasPagadas) > 0 ? `Se registran las cuotas 1 a ${cuotasPagadas} como pagadas en su fecha de vencimiento, sin mora.` : "Todavía no pagó ninguna cuota." }}</small>
              <div v-if="errores.cuotas_pagadas" class="invalid-feedback d-block">{{ errores.cuotas_pagadas }}</div>
            </div>

            <div v-else>
              <p class="small text-muted mb-2">Cada pago con su fecha real y monto. El sistema los aplica como si se hubieran registrado ese día (calcula la mora de los atrasos).</p>
              <div v-for="(fila, i) in filas" :key="fila.id" class="d-flex align-items-start gap-2 mb-2 flex-wrap">
                <div class="campo-fecha">
                  <label class="visually-hidden" :for="`mg-f${fila.id}`">Fecha del pago {{ i + 1 }}</label>
                  <CampoFecha :id="`mg-f${fila.id}`" v-model="fila.fecha" :min="form.fecha_desembolso || undefined" :max="hoy"
                    :etiqueta="`el pago ${i + 1}`" :invalido="!!validacion.errores[fila.id]" />
                </div>
                <div class="campo-monto">
                  <label class="visually-hidden" :for="`mg-m${fila.id}`">Monto del pago {{ i + 1 }}</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">S/</span>
                    <input :id="`mg-m${fila.id}`" v-model="fila.monto" type="text" inputmode="decimal" class="form-control"
                      :class="{ 'is-invalid': validacion.errores[fila.id] }" />
                  </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Quitar pago ${i + 1}`" @click="quitarFila(fila.id)">
                  <i class="fas fa-trash"></i>
                </button>
                <div v-if="mostrarErroresFilas && validacion.errores[fila.id]" class="w-100 small text-danger">{{ validacion.errores[fila.id] }}</div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" @click="agregarFila">
                <i class="fas fa-plus me-1"></i>Agregar pago
              </button>
              <div v-if="errores.pagos" class="small text-danger mt-2">{{ errores.pagos }}</div>
            </div>

            <div class="form-check mt-3 mb-0">
              <input id="mg-condonar" v-model="condonarMora" class="form-check-input" type="checkbox" />
              <label class="form-check-label small" for="mg-condonar">
                No cobrar la mora de los atrasos anteriores
                <span class="text-muted d-block">La mora acumulada hasta hoy queda condonada (con registro). Si quedan cuotas vencidas, sigue sumando desde mañana.</span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-5">
        <div class="columna-resumen d-flex flex-column gap-3">
          <ResumenCronograma :preview="preview" :capital="condiciones?.monto_capital ?? '0'" :cargando="cargandoPreview"
            :pagadas="modo === 'rapido' ? Number(cuotasPagadas) || 0 : 0"
            :error="errorPreview?.tipo === 'validacion' ? primerError(errorPreview.campos) : errorPreview?.mensaje ?? null" />
          <AvisosLimites v-if="preview && (preview.limites.bloqueos.length || preview.limites.advertencias.length)"
            titulo="Avisos de límites (no bloquean un crédito existente)"
            :advertencias="[...preview.limites.bloqueos, ...preview.limites.advertencias]" />
          <div v-if="errorAccion" class="alert mb-0 py-2 small" :class="errorAccion.tipo === 'red' ? 'alert-warning' : 'alert-danger'" role="alert">
            {{ errorAccion.mensaje }}
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-7">
        <BarraAccionMovil :resumen="resumenMovil">
          <button type="submit" class="btn btn-primary fw-semibold" :disabled="guardando || !condiciones">
            <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-check me-2"></i>
            {{ errorAccion?.reintentable ? 'Reintentar' : 'Registrar crédito' }}
          </button>
        </BarraAccionMovil>
      </div>
    </form>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Registrar crédito existente (04-frontend pantalla 6, 00 1.12). Condiciones
// con fecha pasada + pagos históricos (rápido o detallado); no toca la caja. El cronograma
// es el del preview; la situación real (mora, saldo) se ve en el detalle al terminar.
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import CampoFecha from '@/components/CampoFecha.vue'
import BuscadorCliente, { type ClienteBuscado } from '@/components/Creditos/BuscadorCliente.vue'
import TarjetaResumenCliente from '@/components/Creditos/TarjetaResumenCliente.vue'
import FormCondiciones from '@/components/Creditos/FormCondiciones.vue'
import ResumenCronograma from '@/components/Creditos/ResumenCronograma.vue'
import AvisosLimites from '@/components/Creditos/AvisosLimites.vue'
import BarraAccionMovil from '@/components/Creditos/BarraAccionMovil.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { useToast } from '@/composables/useToast'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { usePreviewCredito } from '@/composables/creditos/usePreviewCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { aCondiciones, formularioDesdeConfiguracion, type FormCredito } from '@/helpers/creditos/formulario'
import { validarPagosHistoricos, type FilaPagoHistorico } from '@/helpers/creditos/migracion'
import { formatoSoles, hoyEnLima } from '@/helpers/creditos/formato'
import { textoPagos } from '@/helpers/creditos/cronograma'

const router = useRouter()
const toast = useToast()
const catalogos = useCreditosCatalogosStore()

const hoy = hoyEnLima()
/** Un crédito existente se entregó antes de hoy (lo de hoy es "Nuevo crédito"). */
const ayer = (() => {
  const [a, m, d] = hoy.split('-').map(Number)
  return new Date(Date.UTC(a, m - 1, d - 1)).toISOString().slice(0, 10)
})()

const cliente = ref<ClienteBuscado | null>(null)
const form = ref<FormCredito>(formularioDesdeConfiguracion({
  tasa_interes_minimo: '10', dias_gracia: 0, paso_redondeo: '0.10', dias_no_laborables: [7], saltar_feriados: false,
  regla_no_laborable: 'siguiente', mora_cuenta_no_laborables: true, cobra_mora: true, tope_mora_tipo: 'porcentaje_cuota', tope_mora_valor: 100,
  max_numero_cuotas: 365, tasa_maxima: null,
}, '', null))
const modo = ref<'rapido' | 'detallado'>('rapido')
const cuotasPagadas = ref('0')
let siguienteId = 1
const filas = ref<FilaPagoHistorico[]>([{ id: siguienteId++, fecha: '', monto: '' }])
const condonarMora = ref(false)
const cargandoInicial = ref(true)
const guardando = ref(false)
const errores = ref<Record<string, string>>({})
const errorAccion = ref<ErrorCredito | null>(null)
const mostrarErroresFilas = ref(false)
const { clave, renovar } = useClaveIdempotencia()

const condiciones = computed(() => {
  if (!form.value.fecha_desembolso) return null
  const c = aCondiciones(form.value, cliente.value?.id ?? null)
  // Sin caja: el método de entrega no aplica.
  return c ? { ...c, payment_method_id: null } : null
})
const { preview, cargando: cargandoPreview, error: errorPreview } = usePreviewCredito(condiciones)
const totalCuotas = computed(() => preview.value?.cronograma.cuotas.length ?? 0)
const validacion = computed(() => validarPagosHistoricos(filas.value, form.value.fecha_desembolso, hoy))
/** Línea fija en celular: "Total S/ 1,200.00 · 30 pagos de S/ 40.00". */
const resumenMovil = computed(() => (preview.value
  ? `Total ${formatoSoles(preview.value.cronograma.monto_total)} · ${textoPagos(preview.value.cronograma.cuotas, formatoSoles)}`
  : null))
const primerError = (campos: Record<string, string>) => Object.values(campos)[0] ?? null

onMounted(async () => {
  try {
    const config = await catalogos.obtenerConfiguracion()
    form.value = formularioDesdeConfiguracion(config, '', null)
  } catch (e) {
    errorAccion.value = interpretarErrorCredito(e)
  } finally {
    cargandoInicial.value = false
  }
})

function agregarFila() {
  filas.value.push({ id: siguienteId++, fecha: '', monto: '' })
}

function quitarFila(id: number) {
  filas.value = filas.value.filter((f) => f.id !== id)
  if (!filas.value.length) agregarFila()
}

async function registrar() {
  const datos = condiciones.value
  if (!datos || guardando.value) return
  errores.value = {}
  errorAccion.value = null

  let pagos: { modo_pagos: 'rapido'; cuotas_pagadas: number } | { modo_pagos: 'detallado'; pagos: { fecha: string; monto: string }[] }
  if (modo.value === 'rapido') {
    const n = Number.parseInt(cuotasPagadas.value, 10)
    if (!Number.isInteger(n) || n < 0 || (totalCuotas.value && n > totalCuotas.value)) {
      errores.value = { cuotas_pagadas: `Debe estar entre 0 y ${totalCuotas.value || 'el n.º de pagos'}.` }
      return
    }
    pagos = { modo_pagos: 'rapido', cuotas_pagadas: n }
  } else {
    mostrarErroresFilas.value = true
    if (!validacion.value.pagos) {
      if (!Object.keys(validacion.value.errores).length) errores.value = { pagos: 'Agrega al menos un pago o usa "Cuotas al día" con 0.' }
      return
    }
    pagos = { modo_pagos: 'detallado', pagos: validacion.value.pagos }
  }

  guardando.value = true
  try {
    const { credito } = await creditoService.migrar({ ...datos, ...pagos, condonar_mora: condonarMora.value, clave_idempotencia: clave.value })
    renovar()
    toast.success(`Crédito ${credito.numero_credito} registrado`)
    await router.push({ name: 'creditos.detalle', params: { id: credito.id } })
  } catch (e) {
    const error = interpretarErrorCredito(e)
    if (error.tipo === 'validacion') {
      errores.value = error.campos
      toast.warning(Object.values(error.campos)[0] ?? error.mensaje)
    } else {
      errorAccion.value = error
    }
  } finally {
    guardando.value = false
  }
}

</script>

<style scoped>
.campo-corto {
  max-width: 140px;
}
.campo-fecha {
  width: 170px;
}
.campo-monto {
  width: 150px;
}
@media (min-width: 1200px) {
  .columna-resumen {
    position: sticky;
    top: 116px;
  }
}
</style>
