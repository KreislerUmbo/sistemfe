<template>
  <DefaultLayout>
    <EncabezadoCredito :titulo="credito ? `Crédito ${credito.numero_credito ?? '(borrador)'}` : 'Crédito'" :subtitulo="subtitulo">
      <template v-if="credito" #insignia>
        <span class="badge ms-2 align-middle" :class="insignia.clase"><i :class="insignia.icono" class="me-1"></i>{{ insignia.texto }}</span>
      </template>
      <AccionesCredito v-if="credito" :acciones="acciones" @accion="alAccionar" />
      <DocumentosCredito v-if="credito && ['activo', 'castigado', 'finalizado'].includes(credito.estado)" :credito-id="credito.id"
        :estado="credito.estado" :puede-subir="puede('creditos.crear')" />
    </EncabezadoCredito>

    <div v-if="cargando && !detalle" class="text-center py-5 text-muted">
      <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
    </div>
    <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga.mensaje }}</div>

    <div v-else-if="detalle && credito" class="row g-3">
      <!-- Resumen (derecha en escritorio; arriba en celular) -->
      <div class="col-12 col-lg-4 order-lg-2">
        <div class="columna-resumen d-flex flex-column gap-3">
          <div class="card border-0 shadow-sm mb-0">
            <div class="card-body cifra">
              <p class="fw-bold mb-0">Resumen del crédito</p>
              <p class="small text-muted mb-3">{{ tasaTexto }} · {{ credito.numero_cuotas }} pagos<template v-if="!credito.cobra_mora"> · sin mora</template></p>

              <div class="d-flex justify-content-between align-items-baseline mb-1">
                <span class="fw-semibold">Saldo por pagar</span>
                <span class="fs-4 fw-semibold text-primary">{{ formatoSoles(credito.estado === 'borrador' ? credito.monto_total : resumen.saldo_por_pagar) }}</span>
              </div>
              <div class="d-flex justify-content-between small text-muted mb-1">
                <span><i class="fas fa-list-ol me-1"></i>Pagos completos</span>
                <span class="fw-semibold">{{ resumen.cuotas_pagadas }} / {{ resumen.cuotas_total || credito.numero_cuotas }}</span>
              </div>
              <div class="progress mb-3" style="height: 6px" role="progressbar" :aria-valuenow="resumen.cuotas_pagadas" :aria-valuemax="resumen.cuotas_total">
                <div class="progress-bar bg-success" :style="{ width: `${progreso}%` }"></div>
              </div>

              <table class="table table-sm table-borderless small mb-0">
                <tbody>
                  <tr><td class="text-muted ps-0">Prestado</td><td class="text-end pe-0">{{ formatoSoles(credito.monto_capital) }}</td></tr>
                  <tr><td class="text-muted ps-0">Interés</td><td class="text-end pe-0">{{ formatoSoles(credito.interes_total) }}</td></tr>
                  <tr><td class="text-muted ps-0">Pagado</td><td class="text-end pe-0">{{ formatoSoles(resumen.total_pagado) }}</td></tr>
                  <tr v-if="credito.cobra_mora || resumen.mora_pendiente !== '0.00'" :class="{ 'text-danger': resumen.mora_pendiente !== '0.00' }">
                    <td class="ps-0" :class="{ 'text-muted': resumen.mora_pendiente === '0.00' }">Mora pendiente</td>
                    <td class="text-end pe-0">{{ formatoSoles(resumen.mora_pendiente) }}</td>
                  </tr>
                  <tr class="border-top fw-semibold">
                    <td class="ps-0">Exigible hoy</td><td class="text-end pe-0">{{ formatoSoles(resumen.exigible_hoy) }}</td>
                  </tr>
                  <tr>
                    <td class="text-muted ps-0">Próximo pago</td>
                    <td class="text-end pe-0">
                      <template v-if="resumen.proxima">
                        {{ resumen.proxima.fecha_vencimiento === hoy ? 'Hoy' : formatoFecha(resumen.proxima.fecha_vencimiento) }} · {{ formatoSoles(resumen.proxima.pendiente) }}
                      </template>
                      <template v-else>—</template>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-if="credito.cliente && esEscritorio" class="card border-0 shadow-sm mb-0">
            <div class="card-header bg-white border-bottom py-2 fw-semibold text-dark">Ficha de cobro</div>
            <div class="card-body">
              <FichaCobro :cliente="credito.cliente" :puede-editar="puede('creditos.crear')" :puede-asignar="puede('creditos.cartera.asignar')" />
            </div>
          </div>
        </div>
      </div>

      <!-- Pestañas -->
      <div class="col-12 col-lg-8 order-lg-1">
        <ul class="nav nav-pills mb-3 flex-nowrap pestanas">
          <li v-for="t in PESTANAS" :key="t.id" class="nav-item" :class="{ 'd-lg-none': t.id === 'cliente' }">
            <button type="button" class="nav-link text-nowrap" :class="{ active: pestana === t.id }" @click="pestana = t.id">
              <i :class="t.icono" class="me-1"></i>{{ t.texto }}<span v-if="t.id === 'pagos' && pagosValidos" class="ms-1">({{ pagosValidos }})</span>
            </button>
          </li>
        </ul>

        <div class="card border-0 shadow-sm">
          <div :class="pestana === 'cliente' ? 'card-body' : 'card-body p-0'">
            <CuotasCredito v-if="pestana === 'cuotas'" :cuotas="detalle.cuotas" :hoy="hoy" />
            <PagosCredito v-else-if="pestana === 'pagos'" :pagos="estadoCuenta?.pagos ?? []" :credito-id="credito.id" :cliente="credito.cliente" :puede-editar="puede('creditos.cobrar')"
              :puede-anular="(p) => puedeAnularPago(p.registrado_por, usuarioId, puede)" @anular="abrirAnularPago" @editar="abrirEditarPago" />
            <FichaCobro v-else-if="pestana === 'cliente' && credito.cliente && !esEscritorio" :cliente="credito.cliente"
              :puede-editar="puede('creditos.crear')" :puede-asignar="puede('creditos.cartera.asignar')" />
            <HistorialCredito v-else-if="estadoCuenta" :estado="estadoCuenta" />
          </div>
        </div>
      </div>
    </div>

    <!-- Diálogos -->
    <template v-if="credito">
      <DialogoCobrar v-if="detalle" v-model="dialogo.cobrar" :detalle="detalle" :metodos-pago="metodosPago"
        :puede-fecha-anterior="puede('creditos.pago_fecha_anterior')" :puede-liquidar="acciones.includes('liquidar')"
        @hecho="alTerminar('Cobro registrado')" @liquidar="dialogo.liquidar = true" />
      <DialogoLiquidar v-model="dialogo.liquidar" :credito-id="credito.id" :metodos-pago="metodosPago" @hecho="alTerminar('Crédito liquidado')" />
      <DialogoReprogramar v-model="dialogo.reprogramar" :credito-id="credito.id" :cuotas="detalle?.cuotas ?? []" @hecho="alTerminar('Fechas reprogramadas')" />
      <DialogoCondonar v-model="dialogo.condonar" :credito-id="credito.id" :cuotas="detalle?.cuotas ?? []" @hecho="alTerminar('Mora condonada')" />
      <DialogoCorregir v-model="dialogo.corregir" :credito="credito" :metodos-pago="metodosPago" @hecho="alTerminar('Crédito corregido')" />
      <DialogoRenovar v-model="dialogo.renovar" :credito="credito" :metodos-pago="metodosPago" :puede-autorizar="puede('creditos.autorizar_excepcion')"
        @hecho="irAlRenovado" />
      <DialogoMotivo v-model="dialogo.motivo" v-bind="motivoActual" @hecho="alTerminar(motivoActual.exito)" />
      <DialogoEditarPago v-model="dialogo.editarPago" :credito-id="credito.id" :pago="pagoElegido" @hecho="alTerminar('Pago actualizado')" />
    </template>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Detalle del crédito (04-frontend pantalla 2, mockup 2). Todas las
// cifras vienen calculadas del backend (GET creditos/{id}); las acciones se ofrecen
// según estado y permisos, y el backend vuelve a validarlas.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import AccionesCredito, { type IdAccion } from '@/components/Creditos/AccionesCredito.vue'
import DocumentosCredito from '@/components/Creditos/DocumentosCredito.vue'
import CuotasCredito from '@/components/Creditos/CuotasCredito.vue'
import PagosCredito from '@/components/Creditos/PagosCredito.vue'
import FichaCobro from '@/components/Creditos/FichaCobro.vue'
import HistorialCredito from '@/components/Creditos/HistorialCredito.vue'
import DialogoCobrar from '@/components/Creditos/DialogoCobrar.vue'
import DialogoLiquidar from '@/components/Creditos/DialogoLiquidar.vue'
import DialogoReprogramar from '@/components/Creditos/DialogoReprogramar.vue'
import DialogoCondonar from '@/components/Creditos/DialogoCondonar.vue'
import DialogoCorregir from '@/components/Creditos/DialogoCorregir.vue'
import DialogoRenovar from '@/components/Creditos/DialogoRenovar.vue'
import DialogoMotivo from '@/components/Creditos/DialogoMotivo.vue'
import DialogoEditarPago from '@/components/Creditos/DialogoEditarPago.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { useToast } from '@/composables/useToast'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { useMediaQuery } from '@/composables/creditos/useMediaQuery'
import { accionesDisponibles, puedeAnularPago } from '@/composables/creditos/accionesCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { formatoFecha, formatoSoles, hoyEnLima } from '@/helpers/creditos/formato'
import { PRESENTACION_CREDITO, textoFrecuencia } from '@/helpers/creditos/estados'
import type { DetalleCredito, EstadoCuenta, MetodoPago, Pago } from '@/types/creditos'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const catalogos = useCreditosCatalogosStore()
const { puede, usuarioId } = usePermisosCredito()

const PESTANAS = [
  { id: 'cuotas', texto: 'Cuotas', icono: 'fas fa-list-ol' },
  { id: 'pagos', texto: 'Pagos', icono: 'fas fa-receipt' },
  { id: 'historial', texto: 'Historial', icono: 'fas fa-history' },
  // En escritorio la ficha está en la columna derecha.
  { id: 'cliente', texto: 'Cliente', icono: 'fas fa-user' },
] as const

const hoy = hoyEnLima()
const detalle = ref<DetalleCredito | null>(null)
const estadoCuenta = ref<EstadoCuenta | null>(null)
const metodosPago = ref<MetodoPago[]>([])
const cargando = ref(true)
const errorCarga = ref<ErrorCredito | null>(null)
const pestana = ref<(typeof PESTANAS)[number]['id']>('cuotas')
const pagoElegido = ref<Pago | null>(null)
const dialogo = reactive({ cobrar: false, liquidar: false, reprogramar: false, condonar: false, corregir: false, renovar: false, motivo: false, editarPago: false })
const motivoActual = ref({ titulo: '', descripcion: '', textoConfirmar: 'Confirmar', variante: 'danger', exito: '', ejecutar: async (_m: string, _c: string): Promise<unknown> => undefined })

/** ≥ 992 px: la ficha va en la columna derecha; debajo, en la pestaña "Cliente". */
const esEscritorio = useMediaQuery('(min-width: 992px)')
watch(esEscritorio, (valor) => {
  if (valor && pestana.value === 'cliente') pestana.value = 'cuotas'
})

const creditoId = computed(() => Number(route.params.id))
const credito = computed(() => detalle.value?.credito ?? null)
const resumen = computed(() => detalle.value!.resumen)
const pagosValidos = computed(() => estadoCuenta.value?.pagos.filter((p) => p.estado === 'valido').length ?? 0)
const progreso = computed(() => (resumen.value.cuotas_total ? Math.round((resumen.value.cuotas_pagadas / resumen.value.cuotas_total) * 100) : 0))
const tasaTexto = computed(() => `${Number.parseFloat(credito.value!.tasa_interes)}% ${credito.value!.unidad_tasa === 'total' ? 'total' : 'mensual'}`)
const acciones = computed(() => (credito.value ? accionesDisponibles(credito.value.estado, puede, pagosValidos.value > 0, credito.value.cobra_mora) : []))

const subtitulo = computed(() => {
  const c = credito.value
  if (!c) return ''
  return [c.cliente?.nombre, textoFrecuencia(c.frecuencia_unidad, c.frecuencia_intervalo), `${c.numero_cuotas} pagos`].filter(Boolean).join(' · ')
})

const insignia = computed(() => {
  const c = credito.value!
  const vencidas = detalle.value?.resumen.cuotas_vencidas ?? 0
  if (c.estado === 'activo' && vencidas > 0) {
    return { texto: `${vencidas} atrasada${vencidas > 1 ? 's' : ''}`, clase: 'bg-warning-subtle text-warning-emphasis', icono: 'fas fa-exclamation-triangle' }
  }
  return PRESENTACION_CREDITO[c.estado]
})

async function cargar() {
  cargando.value = true
  errorCarga.value = null
  try {
    const [d, e, metodos] = await Promise.all([
      creditoService.obtener(creditoId.value),
      creditoService.estadoCuenta(creditoId.value),
      catalogos.obtenerMetodosPago(),
    ])
    detalle.value = d
    estadoCuenta.value = e
    metodosPago.value = metodos
  } catch (e) {
    errorCarga.value = interpretarErrorCredito(e)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
// Renovar navega a otro crédito dentro de la misma vista.
watch(creditoId, cargar)

function alAccionar(accion: IdAccion) {
  const c = credito.value!
  switch (accion) {
    case 'cobrar':
      // 04-frontend: modal ancho en escritorio, pantalla completa en celular y tablet.
      if (window.matchMedia('(min-width: 992px)').matches) dialogo.cobrar = true
      else router.push({ name: 'creditos.cobrar', params: { id: c.id } })
      break
    case 'editar':
    case 'activar':
      router.push({ name: 'creditos.editar', params: { id: c.id } })
      break
    case 'liquidar':
    case 'reprogramar':
    case 'condonar':
    case 'corregir':
    case 'renovar':
      dialogo[accion] = true
      break
    case 'anular':
      abrirMotivo({
        titulo: 'Anular crédito', exito: 'Crédito anulado', textoConfirmar: 'Anular',
        descripcion: c.estado === 'activo' ? 'Se revierte el desembolso en caja. Solo es posible si no tiene pagos.' : 'El borrador queda anulado.',
        ejecutar: (motivo, clave) => creditoService.accionConMotivo(c.id, 'anular', motivo, clave),
      })
      break
    case 'castigar':
      abrirMotivo({
        titulo: 'Castigar crédito', exito: 'Crédito castigado', textoConfirmar: 'Castigar',
        descripcion: 'La mora deja de correr y el crédito sale de la cartera activa. El cliente queda bloqueado para nuevos créditos.',
        ejecutar: (motivo, clave) => creditoService.accionConMotivo(c.id, 'castigar', motivo, clave),
      })
      break
    case 'revertir_castigo':
      abrirMotivo({
        titulo: 'Revertir castigo', exito: 'Castigo revertido', textoConfirmar: 'Revertir', variante: 'primary',
        descripcion: 'La mora vuelve a correr desde hoy, sin cobrar el período castigado.',
        ejecutar: (motivo, clave) => creditoService.accionConMotivo(c.id, 'revertir-castigo', motivo, clave),
      })
      break
    default:
      break
  }
}

function abrirMotivo(config: Partial<typeof motivoActual.value> & Pick<typeof motivoActual.value, 'titulo' | 'ejecutar' | 'exito'>) {
  motivoActual.value = { descripcion: '', textoConfirmar: 'Confirmar', variante: 'danger', ...config }
  dialogo.motivo = true
}

function abrirAnularPago(pago: Pago) {
  abrirMotivo({
    titulo: `Anular ${pago.numero_recibo}`, exito: 'Pago anulado', textoConfirmar: 'Anular pago',
    descripcion: `Se recalcula el reparto de los demás pagos y se revierte ${formatoSoles(pago.monto_recibido)} en caja.`,
    ejecutar: (motivo, clave) => creditoService.anularPago(credito.value!.id, pago.id, motivo, clave),
  })
}

function abrirEditarPago(pago: Pago) {
  pagoElegido.value = pago
  dialogo.editarPago = true
}

async function alTerminar(mensaje: string) {
  toast.success(mensaje)
  await cargar()
}

async function irAlRenovado(nuevoId: number) {
  toast.success('Crédito renovado')
  await router.push({ name: 'creditos.detalle', params: { id: nuevoId } })
}

</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
/* Con 4 pestañas en 360 px: se desplazan en su fila, nunca ensanchan la página. */
.pestanas {
  overflow-x: auto;
  scrollbar-width: none;
}
@media (min-width: 992px) {
  .columna-resumen {
    position: sticky;
    top: 116px;
  }
}
</style>
