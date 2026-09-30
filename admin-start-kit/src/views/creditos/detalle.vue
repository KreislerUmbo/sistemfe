<template>
  <DefaultLayout>
    <div class="creditos-contenedor mx-auto">
      <!-- Encabezado (mockup 2) -->
      <div class="d-flex align-items-center gap-3 mb-3">
        <button type="button" class="btn btn-light btn-volver" aria-label="Volver" @click="volver">
          <i class="fas fa-chevron-left"></i>
        </button>
        <div class="flex-grow-1 min-w-0">
          <h5 class="fw-bold mb-0">{{ credito ? (credito.numero_credito ?? 'Borrador') : 'Crédito' }}</h5>
          <small class="text-muted text-truncate d-block">{{ subtitulo }}</small>
        </div>
        <span v-if="credito" class="badge rounded-pill px-3 py-2" :class="insignia.clase">
          <i :class="insignia.icono" class="me-1"></i>{{ insignia.texto }}
        </span>
      </div>

      <div v-if="cargando && !detalle" class="text-center py-5 text-muted">
        <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
      </div>
      <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga.mensaje }}</div>

      <div v-else-if="detalle && credito" class="row g-3">
        <!-- Columna principal -->
        <div class="col-12 col-xl">
          <!-- Saldo y progreso -->
          <div class="card bg-dark text-white border-0 mb-3">
            <div class="card-body p-3 p-md-4 cifra">
              <div class="small opacity-75">Saldo por pagar</div>
              <div class="saldo fw-bold">{{ formatoSoles(credito.estado === 'borrador' ? credito.monto_total : resumen.saldo_por_pagar) }}</div>
              <div class="progress bg-secondary bg-opacity-50 my-2" style="height: 8px" role="progressbar"
                :aria-valuenow="resumen.cuotas_pagadas" :aria-valuemax="resumen.cuotas_total">
                <div class="progress-bar bg-success" :style="{ width: `${progreso}%` }"></div>
              </div>
              <div class="d-flex justify-content-between small opacity-75">
                <span>{{ resumen.cuotas_pagadas }} de {{ resumen.cuotas_total || credito.numero_cuotas }} pagos</span>
                <span>Pagado {{ formatoSoles(resumen.total_pagado) }}</span>
              </div>
            </div>
          </div>

          <!-- Cifras -->
          <div class="row g-2 mb-3 cifra">
            <div class="col-6 col-md-3">
              <div class="card border h-100 mb-0"><div class="card-body p-3">
                <div class="small text-muted">Prestado</div>
                <div class="fw-bold fs-6">{{ formatoSoles(credito.monto_capital) }}</div>
              </div></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card border h-100 mb-0"><div class="card-body p-3">
                <div class="small text-muted">Interés ({{ tasaTexto }})</div>
                <div class="fw-bold fs-6">{{ formatoSoles(credito.interes_total) }}</div>
              </div></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card border h-100 mb-0"><div class="card-body p-3">
                <div class="small text-muted">Mora pendiente</div>
                <div class="fw-bold fs-6" :class="{ 'text-danger': resumen.mora_pendiente !== '0.00' }">{{ formatoSoles(resumen.mora_pendiente) }}</div>
              </div></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card border h-100 mb-0"><div class="card-body p-3">
                <div class="small text-muted">Próximo pago</div>
                <div class="fw-bold fs-6">
                  <template v-if="resumen.proxima">
                    {{ resumen.proxima.fecha_vencimiento === hoy ? 'Hoy' : formatoFecha(resumen.proxima.fecha_vencimiento).slice(0, 5) }}
                    · {{ formatoSoles(resumen.proxima.pendiente) }}
                  </template>
                  <template v-else>—</template>
                </div>
              </div></div>
            </div>
          </div>

          <!-- Acciones en celular y tablet -->
          <AccionesCredito class="d-xl-none mb-3" :acciones="acciones" @accion="alAccionar" />

          <!-- Pestañas -->
          <ul class="nav nav-pills flex-nowrap gap-1 mb-3" role="tablist">
            <li v-for="t in PESTANAS" :key="t.id" class="nav-item" role="presentation">
              <button type="button" class="nav-link rounded-pill px-3 py-2 text-nowrap" :class="{ active: pestana === t.id }" role="tab"
                :aria-selected="pestana === t.id" @click="pestana = t.id">
                {{ t.texto }}<span v-if="t.id === 'pagos' && pagosValidos" class="ms-1 opacity-75">({{ pagosValidos }})</span>
              </button>
            </li>
          </ul>

          <div class="card border-0 shadow-sm">
            <div class="card-body p-0 p-xl-3">
              <CuotasCredito v-if="pestana === 'cuotas'" :cuotas="detalle.cuotas" :hoy="hoy" />
              <PagosCredito v-else-if="pestana === 'pagos'" :pagos="estadoCuenta?.pagos ?? []" :puede-editar="puede('creditos.cobrar')"
                :puede-anular="(p) => puedeAnularPago(p.registrado_por, usuarioId, puede)" @anular="abrirAnularPago" @editar="abrirEditarPago" />
              <HistorialCredito v-else-if="estadoCuenta" :estado="estadoCuenta" />
            </div>
          </div>
        </div>

        <!-- Columna derecha en escritorio (~340 px): acciones, pagos recientes, cliente -->
        <div class="col-12 col-xl-auto d-none d-xl-block columna-lateral">
          <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3">
              <AccionesCredito :acciones="acciones" vertical @accion="alAccionar" />
            </div>
          </div>
          <div v-if="estadoCuenta?.pagos.length" class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-transparent fw-semibold">Pagos recientes</div>
            <ul class="list-group list-group-flush cifra small">
              <li v-for="p in pagosRecientes" :key="p.id" class="list-group-item d-flex justify-content-between">
                <span>{{ p.numero_recibo }} · {{ formatoFecha(p.fecha_pago) }}</span>
                <span :class="{ 'text-decoration-line-through text-muted': p.estado === 'anulado' }">{{ formatoSoles(p.monto_aplicado) }}</span>
              </li>
            </ul>
          </div>
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">Cliente</div>
            <div class="card-body p-3 small">
              <div class="fw-semibold">{{ credito.cliente?.nombre }}</div>
              <div class="text-muted">DNI {{ credito.cliente?.documento }}</div>
              <a v-if="credito.cliente?.telefono" :href="`tel:${credito.cliente.telefono}`" class="d-block mt-1">
                <i class="fas fa-phone me-1"></i>{{ credito.cliente.telefono }}
              </a>
            </div>
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
import AccionesCredito, { type IdAccion } from '@/components/Creditos/AccionesCredito.vue'
import CuotasCredito from '@/components/Creditos/CuotasCredito.vue'
import PagosCredito from '@/components/Creditos/PagosCredito.vue'
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
  { id: 'cuotas', texto: 'Cuotas' },
  { id: 'pagos', texto: 'Pagos' },
  { id: 'historial', texto: 'Historial' },
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

const creditoId = computed(() => Number(route.params.id))
const credito = computed(() => detalle.value?.credito ?? null)
const resumen = computed(() => detalle.value!.resumen)
const pagosValidos = computed(() => estadoCuenta.value?.pagos.filter((p) => p.estado === 'valido').length ?? 0)
const pagosRecientes = computed(() => [...(estadoCuenta.value?.pagos ?? [])].reverse().slice(0, 4))
const progreso = computed(() => (resumen.value.cuotas_total ? Math.round((resumen.value.cuotas_pagadas / resumen.value.cuotas_total) * 100) : 0))
const tasaTexto = computed(() => `${Number.parseFloat(credito.value!.tasa_interes)}% ${credito.value!.unidad_tasa === 'total' ? 'total' : 'mensual'}`)
const acciones = computed(() => (credito.value ? accionesDisponibles(credito.value.estado, puede, pagosValidos.value > 0) : []))

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

function volver() {
  if (window.history.length > 1) router.back()
  else router.push({ name: 'dashboards.analytics' })
}
</script>

<style scoped>
.creditos-contenedor {
  max-width: 1440px;
}
.btn-volver {
  width: 44px;
  height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.cifra {
  font-variant-numeric: tabular-nums;
}
.saldo {
  font-size: 2.1rem;
  letter-spacing: -0.02em;
}
.min-w-0 {
  min-width: 0;
}
@media (min-width: 1200px) {
  .columna-lateral {
    width: 340px;
  }
}
</style>
