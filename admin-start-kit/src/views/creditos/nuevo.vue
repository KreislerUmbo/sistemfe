<template>
  <DefaultLayout>
    <EncabezadoCredito :titulo="creditoId ? 'Editar borrador' : 'Nuevo crédito'"
      :subtitulo="`${credito?.numero_credito ?? 'Borrador'} · el dinero sale al activar`" icono="fas fa-hand-holding-usd" />

    <div v-if="cargandoInicial" class="text-center py-5 text-muted">
      <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
    </div>

    <div v-else-if="noEditable" class="alert alert-warning">
      Este crédito ya no es un borrador: sus condiciones quedaron congeladas al activarlo.
    </div>

    <form v-else class="row g-3" novalidate @submit.prevent>
      <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm mb-3">
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
            <span class="fw-semibold text-dark">Condiciones del crédito</span>
          </div>
          <div class="card-body py-3">
            <FormCondiciones v-model="form" :errores="errores" :metodos-pago="metodosPago"
              :primer-sugerido="preview?.cronograma.primer_vencimiento ?? null"
              :primera-cuota="preview?.cronograma.cuotas[0] ?? null" :tasa-mensual="preview?.cronograma.tasa_mensual_equivalente ?? null" />
          </div>
        </div>
      </div>

      <!-- Resumen + cronograma fijos a la derecha en escritorio -->
      <div class="col-12 col-xl-5">
        <div class="columna-resumen d-flex flex-column gap-3">
          <ResumenCronograma :preview="preview" :capital="condiciones?.monto_capital ?? '0'" :cargando="cargandoPreview"
            :error="errorPreview?.tipo === 'validacion' ? primerError(errorPreview.campos) : errorPreview?.mensaje ?? null" />

          <AvisosLimites v-if="bloqueosActivacion.length" :bloqueos="bloqueosActivacion" :puede-autorizar="puedeAutorizar"
            :autorizando="autorizando" @autorizar="autorizar" />
          <AvisosLimites v-else-if="preview" :advertencias="[...preview.limites.bloqueos, ...preview.limites.advertencias]" />

          <div v-if="errorAccion" class="alert mb-0 py-2 small" :class="errorAccion.tipo === 'red' ? 'alert-warning' : 'alert-danger'" role="alert">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
              <span>{{ errorAccion.mensaje }}</span>
              <button v-if="errorAccion.reintentable && ultimaAccion" type="button" class="btn btn-sm btn-outline-dark" @click="ultimaAccion()">
                Reintentar
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-7">
        <BarraAccionMovil :resumen="resumenMovil">
          <button type="button" class="btn btn-outline-secondary" :disabled="ocupado" @click="guardarBorrador">
            <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-save me-2"></i>Guardar borrador
          </button>
          <button type="button" class="btn btn-primary fw-semibold" :disabled="ocupado || !condiciones" @click="activar">
            <span v-if="activando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-check me-2"></i>Activar y entregar
          </button>
        </BarraAccionMovil>
      </div>
    </form>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Nuevo crédito / editar borrador (04-frontend pantalla 1, mockup 1).
// El resumen y el cronograma vienen del backend (preview); aquí no se calcula dinero.
// Toda escritura lleva su clave de idempotencia: un reintento tras un corte no duplica.
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Swal from 'sweetalert2/dist/sweetalert2.js'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
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
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { aCondiciones, formularioDesdeConfiguracion, formularioDesdeCredito, type FormCredito } from '@/helpers/creditos/formulario'
import { formatoSoles, hoyEnLima } from '@/helpers/creditos/formato'
import { textoPagos } from '@/helpers/creditos/cronograma'
import type { Credito, Infraccion, MetodoPago, ReglaLimite } from '@/types/creditos'

type TVueSwalInstance = typeof Swal & typeof Swal.fire

const route = useRoute()
const router = useRouter()
const toast = useToast()
const catalogos = useCreditosCatalogosStore()
const { puede } = usePermisosCredito()

const creditoId = ref<number | null>(route.params.id ? Number(route.params.id) : null)
const credito = ref<Credito | null>(null)
const cliente = ref<ClienteBuscado | null>(null)
const form = ref<FormCredito>(formularioDesdeConfiguracion({
  tasa_interes_minimo: '10', dias_gracia: 0, paso_redondeo: '0.10', dias_no_laborables: [7], saltar_feriados: false,
  regla_no_laborable: 'siguiente', mora_cuenta_no_laborables: true, cobra_mora: true, tope_mora_tipo: 'porcentaje_cuota', tope_mora_valor: 100,
  max_numero_cuotas: 365, tasa_maxima: null,
}, hoyEnLima(), null))
const metodosPago = ref<MetodoPago[]>([])
const cargandoInicial = ref(true)
const noEditable = ref(false)

const errores = ref<Record<string, string>>({})
const errorAccion = ref<ErrorCredito | null>(null)
const ultimaAccion = ref<(() => void) | null>(null)
const bloqueosActivacion = ref<Infraccion[]>([])
const guardando = ref(false)
const activando = ref(false)
const autorizando = ref<ReglaLimite | null>(null)
const ocupado = computed(() => guardando.value || activando.value)
const puedeAutorizar = computed(() => puede('creditos.autorizar_excepcion'))

const claveGuardar = useClaveIdempotencia()
const claveActivar = useClaveIdempotencia()

const condiciones = computed(() => aCondiciones(form.value, cliente.value?.id ?? null))
const { preview, cargando: cargandoPreview, error: errorPreview } = usePreviewCredito(condiciones)

/** Línea fija en celular: "Total S/ 1,200.00 · 30 pagos de S/ 40.00". */
const resumenMovil = computed(() => (preview.value
  ? `Total ${formatoSoles(preview.value.cronograma.monto_total)} · ${textoPagos(preview.value.cronograma.cuotas, formatoSoles)}`
  : null))
const primerError = (campos: Record<string, string>) => Object.values(campos)[0] ?? null

onMounted(async () => {
  try {
    const [config, metodos] = await Promise.all([catalogos.obtenerConfiguracion(), catalogos.obtenerMetodosPago()])
    metodosPago.value = metodos
    form.value = formularioDesdeConfiguracion(config, hoyEnLima(), metodos[0]?.id ?? null)

    if (creditoId.value) {
      const detalle = await creditoService.obtener(creditoId.value)
      credito.value = detalle.credito
      if (detalle.credito.estado !== 'borrador') {
        noEditable.value = true
        return
      }
      form.value = formularioDesdeCredito(detalle.credito)
      // El dinero sale al activar: un borrador de otro día pasa a la fecha de hoy (03-api).
      form.value.fecha_desembolso = hoyEnLima()
      form.value.payment_method_id ??= metodos[0]?.id ?? null
      const c = detalle.credito.cliente
      if (c) cliente.value = { id: c.id, full_name: c.nombre, n_document: c.documento, phone: c.telefono }
    }
  } catch (e) {
    errorAccion.value = interpretarErrorCredito(e)
  } finally {
    cargandoInicial.value = false
  }
})

function manejarError(e: unknown, reintento: () => void) {
  const error = interpretarErrorCredito(e)
  if (error.tipo === 'validacion') {
    errores.value = error.campos
    toast.warning(error.mensaje)
    return
  }
  if (error.tipo === 'limites') {
    bloqueosActivacion.value = error.bloqueos
    return
  }
  errorAccion.value = error
  ultimaAccion.value = error.reintentable ? reintento : null
}

function limpiarErrores() {
  errores.value = {}
  errorAccion.value = null
  ultimaAccion.value = null
}

/** Crea o actualiza el borrador. Devuelve su id, o null si falló. */
async function guardar(): Promise<number | null> {
  const datos = condiciones.value
  if (!datos) {
    toast.warning('Completa cliente, monto, interés y n.º de pagos.')
    return null
  }
  const cuerpo = { ...datos, clave_idempotencia: claveGuardar.clave.value }
  if (creditoId.value) {
    await creditoService.actualizar(creditoId.value, cuerpo)
  } else {
    const creado = await creditoService.crear(cuerpo)
    creditoId.value = creado.credito.id
    credito.value = creado.credito
  }
  claveGuardar.renovar()

  return creditoId.value
}

async function guardarBorrador() {
  limpiarErrores()
  guardando.value = true
  try {
    const eraNuevo = creditoId.value === null
    const id = await guardar()
    if (!id) return
    toast.success('Borrador guardado')
    // Recién aquí se pasa a la URL de edición (otra ruta = el componente se vuelve a montar,
    // por eso no se hace en medio de "Activar").
    if (eraNuevo) await router.replace({ name: 'creditos.editar', params: { id } })
  } catch (e) {
    manejarError(e, guardarBorrador)
  } finally {
    guardando.value = false
  }
}

async function activar() {
  limpiarErrores()
  bloqueosActivacion.value = []
  activando.value = true
  try {
    const id = await guardar()
    if (!id) return
    const { credito: activo } = await creditoService.activar(id, claveActivar.clave.value)
    claveActivar.renovar()
    toast.success(`Crédito ${activo.numero_credito} activado`)
    if (router.hasRoute('creditos.detalle')) await router.push({ name: 'creditos.detalle', params: { id } })
  } catch (e) {
    manejarError(e, activar)
  } finally {
    activando.value = false
  }
}

async function autorizar(regla: ReglaLimite) {
  if (!creditoId.value) return
  const { value: motivo } = await (Swal as TVueSwalInstance).fire({
    title: 'Autorizar excepción',
    input: 'textarea',
    inputLabel: 'Motivo (queda registrado)',
    inputValidator: (v: string) => (v?.trim() ? null : 'El motivo es obligatorio'),
    showCancelButton: true,
    confirmButtonText: 'Autorizar',
    cancelButtonText: 'Cancelar',
  })
  if (!motivo) return

  const clave = useClaveIdempotencia()
  autorizando.value = regla
  try {
    await creditoService.autorizar(creditoId.value, regla, motivo.trim(), clave.clave.value)
    bloqueosActivacion.value = bloqueosActivacion.value.filter((b) => b.regla !== regla)
    toast.success('Excepción autorizada. Ya puedes activar.')
  } catch (e) {
    errorAccion.value = interpretarErrorCredito(e)
  } finally {
    autorizando.value = null
  }
}

</script>

<style scoped>
@media (min-width: 1200px) {
  .columna-resumen {
    position: sticky;
    top: 116px;
  }
}
</style>
