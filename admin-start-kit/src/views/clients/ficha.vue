<template>
  <DefaultLayout>
    <EncabezadoCredito :titulo="titulo" :subtitulo="subtitulo" icono="fas fa-user" :volver="{ name: 'clients.index' }">
      <template v-if="clienteId && datosCliente" #insignia>
        <span v-if="datosCliente.state == 2" class="badge bg-secondary-subtle text-secondary-emphasis ms-2 align-middle">Inactivo</span>
        <span v-if="limites?.bloqueado" class="badge bg-danger-subtle text-danger-emphasis ms-2 align-middle"><i class="fas fa-ban me-1"></i>Bloqueado</span>
      </template>
    </EncabezadoCredito>

    <div v-if="cargando" class="text-center py-5 text-muted">
      <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
    </div>
    <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga }}</div>

    <form v-else novalidate @submit.prevent="guardar">
      <div class="row g-3">
        <!-- Secciones -->
        <div class="col-12 col-lg-8">
          <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
              <span class="fw-semibold text-dark"><i class="fas fa-id-card me-2 text-primary"></i>Datos del cliente</span>
            </div>
            <div class="card-body">
              <ClienteDatosBase v-model="form" id="ficha" :errores="errores" completo :tributarios="!contexto.creditos" />
            </div>
          </div>

          <template v-if="contexto.creditos">
            <div class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark"><i class="fas fa-route me-2 text-primary"></i>Datos de cobro</span>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-12 col-md-8">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-direccion">Dirección de cobro</label>
                    <input id="cobro-direccion" v-model="ficha.direccion_cobro" type="text" class="form-control form-control-sm"
                      :class="{ 'is-invalid': errores.direccion_cobro }" placeholder="Donde el cobrador encuentra al cliente" />
                    <div v-if="errores.direccion_cobro" class="invalid-feedback">{{ errores.direccion_cobro }}</div>
                  </div>
                  <div class="col-12 col-md-4">
                    <span class="form-label mb-1 small fw-semibold text-secondary d-block">Es su…</span>
                    <div class="btn-group btn-group-sm w-100" role="group" aria-label="Tipo de dirección">
                      <input id="cobro-casa" v-model="ficha.tipo_direccion" type="radio" class="btn-check" value="casa" />
                      <label class="btn btn-outline-secondary" for="cobro-casa"><i class="fas fa-home me-1"></i>Casa</label>
                      <input id="cobro-negocio" v-model="ficha.tipo_direccion" type="radio" class="btn-check" value="negocio" />
                      <label class="btn btn-outline-secondary" for="cobro-negocio"><i class="fas fa-store me-1"></i>Negocio</label>
                    </div>
                  </div>
                  <div class="col-12">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-referencia">Referencia</label>
                    <input id="cobro-referencia" v-model="ficha.referencia" type="text" class="form-control form-control-sm" placeholder="Ej. frente al mercado, puerta verde" />
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-ocupacion">Ocupación o negocio</label>
                    <input id="cobro-ocupacion" v-model="ficha.ocupacion" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-telefono">Teléfono alterno</label>
                    <input id="cobro-telefono" v-model="ficha.telefono_alterno" type="tel" inputmode="tel" class="form-control form-control-sm"
                      :class="{ 'is-invalid': errores.telefono_alterno }" />
                    <div v-if="errores.telefono_alterno" class="invalid-feedback">{{ errores.telefono_alterno }}</div>
                  </div>
                  <div class="col-12">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="cobro-notas">Notas de cobranza</label>
                    <textarea id="cobro-notas" v-model="ficha.notas" rows="2" class="form-control form-control-sm" placeholder="Horario en que se le encuentra, etc."></textarea>
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark"><i class="fas fa-map-marker-alt me-2 text-primary"></i>Ubicación</span>
              </div>
              <div class="card-body">
                <MapaUbicacion v-model="coordenadas" :direccion="ficha.direccion_cobro" />
              </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark"><i class="far fa-id-card me-2 text-primary"></i>Documentos</span>
              </div>
              <div class="card-body">
                <DocumentosCliente ref="documentos" :cliente-id="clienteId" :archivos="archivos" :puede-subir="puedeEditar"
                  @subido="alSubir" />
              </div>
            </div>

            <!-- Cartera -->
            <div class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark"><i class="fas fa-user-tie me-2 text-primary"></i>Cartera</span>
              </div>
              <div class="card-body">
                <div v-if="usuarios" class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="cartera-asesor">
                      {{ asesorCobra ? 'Asesor (también cobra)' : 'Asesor' }}
                    </label>
                    <select id="cartera-asesor" v-model="cartera.asesor_id" class="form-select form-select-sm" :disabled="asignando" @change="asignar">
                      <option :value="null">{{ clienteId ? 'Sin asignar' : 'Quien lo registra (yo)' }}</option>
                      <option v-for="u in usuarios.asesores" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                    </select>
                  </div>
                  <div v-if="!asesorCobra && clienteId" class="col-12 col-md-6">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="cartera-cobrador">Cobrador</label>
                    <select id="cartera-cobrador" v-model="cartera.cobrador_id" class="form-select form-select-sm" :disabled="asignando" @change="asignar">
                      <option :value="null">Sin asignar</option>
                      <option v-for="u in usuarios.cobradores" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                    </select>
                  </div>
                </div>
                <div v-else-if="clienteId" class="small">
                  <div><span class="text-secondary">Asesor:</span> {{ vigente('asesor') ?? 'Sin asignar' }}</div>
                  <div v-if="!asesorCobra"><span class="text-secondary">Cobrador:</span> {{ vigente('cobrador') ?? 'Sin asignar' }}</div>
                </div>
                <p v-else class="small text-muted mb-0">El cliente quedará en tu cartera.</p>

                <details v-if="historial.length" class="mt-3 small">
                  <summary class="text-secondary">Historial de asignaciones ({{ historial.length }})</summary>
                  <table class="table table-sm mb-0 mt-2">
                    <thead class="table-light">
                      <tr><th>Función</th><th>Usuario</th><th>Desde</th><th>Hasta</th><th>Asignó</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(h, i) in historial" :key="i">
                        <td class="text-capitalize">{{ h.funcion }}</td>
                        <td>{{ h.usuario ?? '—' }}</td>
                        <td>{{ formatoFecha(h.vigente_desde) }}</td>
                        <td>{{ h.vigente_hasta ? formatoFecha(h.vigente_hasta) : 'Vigente' }}</td>
                        <td>{{ h.asignado_por ?? '—' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </details>
              </div>
            </div>

            <!-- Límites propios -->
            <div v-if="clienteId && puede('creditos.configurar')" class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark"><i class="fas fa-sliders-h me-2 text-primary"></i>Límites para prestar</span>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-6 col-md-4">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="lim-max">Créditos activos</label>
                    <input id="lim-max" v-model="formLimites.max_creditos_activos" type="text" inputmode="numeric" class="form-control form-control-sm"
                      :class="{ 'is-invalid': erroresLimites.max_creditos_activos }" placeholder="Según la configuración" />
                  </div>
                  <div class="col-6 col-md-4">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="lim-deuda">Deuda máxima (S/)</label>
                    <input id="lim-deuda" v-model="formLimites.deuda_maxima" type="text" inputmode="decimal" class="form-control form-control-sm"
                      :class="{ 'is-invalid': erroresLimites.deuda_maxima }" placeholder="Según la configuración" />
                  </div>
                  <div class="col-12 col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-1">
                      <input id="lim-bloqueado" v-model="formLimites.bloqueado" type="checkbox" role="switch" class="form-check-input" />
                      <label class="form-check-label small fw-semibold" for="lim-bloqueado">Bloquear nuevos créditos</label>
                    </div>
                  </div>
                  <div v-if="formLimites.bloqueado" class="col-12">
                    <label class="form-label mb-1 small fw-semibold text-secondary" for="lim-motivo">Motivo del bloqueo</label>
                    <input id="lim-motivo" v-model="formLimites.motivo_bloqueo" type="text" class="form-control form-control-sm"
                      :class="{ 'is-invalid': erroresLimites.motivo_bloqueo }" />
                    <div v-if="erroresLimites.motivo_bloqueo" class="invalid-feedback">{{ erroresLimites.motivo_bloqueo }}</div>
                  </div>
                  <div class="col-12 d-flex justify-content-between align-items-center">
                    <small class="text-muted">Vacío = usar los valores de Configuración.</small>
                    <button type="button" class="btn btn-sm btn-outline-primary" :disabled="guardandoLimites" @click="guardarLimites">
                      <span v-if="guardandoLimites" class="spinner-border spinner-border-sm me-1"></span>Guardar límites
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Saldo a favor (04c.1) -->
            <div v-if="clienteId" class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark"><i class="fas fa-wallet me-2 text-primary"></i>Saldo a favor</span>
              </div>
              <div class="card-body">
                <SaldoAFavorCliente :cliente-id="clienteId" :puede-devolver="puede('creditos.cobrar')" />
              </div>
            </div>

            <!-- Créditos del cliente -->
            <div v-if="clienteId" class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark"><i class="fas fa-hand-holding-usd me-2 text-primary"></i>Créditos</span>
              </div>
              <div class="card-body p-0">
                <div v-if="creditos.length" class="table-responsive">
                  <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                      <tr class="small text-secondary text-uppercase">
                        <th class="ps-3">Crédito</th><th>Estado</th><th>Entregado</th><th class="text-end">Total</th><th class="text-end pe-3">Saldo</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="c in creditos" :key="c.id" role="button" @click="router.push({ name: 'creditos.detalle', params: { id: c.id } })">
                        <td class="ps-3 fw-semibold">{{ c.numero_credito ?? 'Borrador' }}</td>
                        <td><span class="badge" :class="PRESENTACION_CREDITO[c.estado].clase">{{ PRESENTACION_CREDITO[c.estado].texto }}</span></td>
                        <td>{{ c.fecha_desembolso ? formatoFecha(c.fecha_desembolso) : '—' }}</td>
                        <td class="text-end">{{ formatoSoles(c.monto_total) }}</td>
                        <td class="text-end pe-3">{{ c.situacion ? formatoSoles(c.situacion.saldo_por_pagar) : '—' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p v-else class="small text-muted p-3 mb-0">Todavía no tiene créditos.</p>
              </div>
            </div>
          </template>
        </div>

        <!-- Resumen -->
        <div class="col-12 col-lg-4">
          <div class="columna-resumen d-flex flex-column gap-3">
            <div v-if="contexto.creditos" class="card border-0 shadow-sm mb-0">
              <div class="card-body">
                <p class="fw-bold mb-2">Ficha para prestar</p>
                <div v-if="!clienteId" class="small text-muted">Se revisa al guardar.</div>
                <div v-else-if="faltante.length === 0" class="small text-success"><i class="fas fa-check-circle me-1"></i>Completa: ya se le puede prestar.</div>
                <template v-else>
                  <div class="small text-warning-emphasis mb-1"><i class="fas fa-exclamation-triangle me-1"></i>Falta para activar un crédito:</div>
                  <ul class="small mb-0 ps-3">
                    <li v-for="f in faltante" :key="f.requisito">{{ f.etiqueta }}</li>
                  </ul>
                </template>
              </div>
            </div>
            <TarjetaResumenCliente v-if="contexto.creditos && clienteId" :key="faltante.length" :cliente-id="clienteId" :enlace-ficha="false" />
            <router-link v-if="contexto.creditos && clienteId && puede('creditos.crear')" class="btn btn-outline-primary"
              :to="{ name: 'creditos.nuevo', query: { cliente: clienteId } }">
              <i class="fas fa-plus me-2"></i>Nuevo crédito para este cliente
            </router-link>
          </div>
        </div>
      </div>

      <BarraAccionMovil>
        <button type="button" class="btn btn-outline-secondary" :disabled="guardando" @click="router.push({ name: 'clients.index' })">Cancelar</button>
        <button type="submit" class="btn btn-primary fw-semibold" :disabled="guardando || !puedeEditar">
          <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-save me-2"></i>
          {{ clienteId ? 'Guardar cambios' : 'Registrar cliente' }}
        </button>
      </BarraAccionMovil>
    </form>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Página del cliente (Fase 4c), todos los giros: reemplaza al modal de Clientes. En el giro
// Créditos suma datos de cobro, ubicación, documentos, cartera, límites y sus créditos.
// Las secciones se deciden por el giro (GET clients/contexto), no por permisos: el
// Super-Admin los tiene todos en cualquier giro.
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import BarraAccionMovil from '@/components/Creditos/BarraAccionMovil.vue'
import TarjetaResumenCliente from '@/components/Creditos/TarjetaResumenCliente.vue'
import ClienteDatosBase from '@/components/Clientes/ClienteDatosBase.vue'
import MapaUbicacion, { type Coordenadas } from '@/components/Clientes/MapaUbicacion.vue'
import DocumentosCliente from '@/components/Clientes/DocumentosCliente.vue'
import SaldoAFavorCliente from '@/components/Clientes/SaldoAFavorCliente.vue'
import { useToast } from '@/composables/useToast'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { clienteService, type ContextoCliente } from '@/services/admin/clienteService'
import { creditoService } from '@/services/admin/creditoService'
import { guardarCliente } from '@/helpers/clientes/guardarCliente'
import { aPayload, erroresDeValidacion, formDesdeCliente, formVacio, nombreCompleto, type FormCliente } from '@/helpers/clientes/formCliente'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import { PRESENTACION_CREDITO } from '@/helpers/creditos/estados'
import type {
  AsignacionCartera, CarteraCliente, DatosFicha, FilaCredito, LimitesCliente, RequisitoFaltante, TipoArchivoCliente, UsuarioCartera,
} from '@/types/creditos'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { puede } = usePermisosCredito()

const FICHA_VACIA: DatosFicha = {
  direccion_cobro: null, tipo_direccion: null, referencia: null, latitud: null, longitud: null, telefono_alterno: null, ocupacion: null, notas: null,
}

const clienteId = ref<number | null>(route.params.id ? Number(route.params.id) : null)
const contexto = ref<ContextoCliente>({ giro: null, creditos: false })
const cargando = ref(true)
const errorCarga = ref<string | null>(null)
const guardando = ref(false)
const errores = ref<Record<string, string>>({})

const form = ref<FormCliente>(formVacio())
const datosCliente = ref<{ state: number } | null>(null)
const ficha = ref<DatosFicha>({ ...FICHA_VACIA })
const fichaGuardada = ref<string>(JSON.stringify(FICHA_VACIA))
const archivos = ref<{ id: number; tipo: TipoArchivoCliente; created_at: string }[]>([])
const faltante = ref<RequisitoFaltante[]>([])
const cartera = reactive<CarteraCliente>({ asesor_id: null, cobrador_id: null, asesor_cobra: true })
const historial = ref<AsignacionCartera[]>([])
const usuarios = ref<{ asesores: UsuarioCartera[]; cobradores: UsuarioCartera[] } | null>(null)
const asignando = ref(false)
const limites = ref<LimitesCliente | null>(null)
const formLimites = reactive({ max_creditos_activos: '', deuda_maxima: '', bloqueado: false, motivo_bloqueo: '' })
const erroresLimites = ref<Record<string, string>>({})
const guardandoLimites = ref(false)
const creditos = ref<FilaCredito[]>([])
const documentos = ref<InstanceType<typeof DocumentosCliente> | null>(null)

const puedeEditar = computed(() => puede(clienteId.value ? 'edit_client' : 'register_client'))
const asesorCobra = computed(() => cartera.asesor_cobra)
const titulo = computed(() => (clienteId.value ? nombreCompleto(form.value) || 'Cliente' : 'Nuevo cliente'))
const subtitulo = computed(() => {
  if (!clienteId.value) return contexto.value.creditos ? 'Datos, cobro, ubicación y documentos' : 'Datos del cliente'
  return form.value.type_document === 'SND' ? 'Sin documento' : `${form.value.type_document} ${form.value.n_document}`
})

const coordenadas = computed<Coordenadas>({
  get: () => ({ latitud: ficha.value.latitud, longitud: ficha.value.longitud }),
  set: (c) => { ficha.value.latitud = c.latitud; ficha.value.longitud = c.longitud },
})

const vigente = (funcion: 'asesor' | 'cobrador') => historial.value.find((h) => h.funcion === funcion && !h.vigente_hasta)?.usuario ?? null

async function cargarCreditos() {
  if (!clienteId.value) return
  aplicarFicha(await creditoService.fichaCliente(clienteId.value))
  creditos.value = (await creditoService.creditosDeCliente(clienteId.value)).data
}

function aplicarFicha(f: Awaited<ReturnType<typeof creditoService.fichaCliente>>) {
  ficha.value = { ...FICHA_VACIA, ...(f.ficha ?? {}) }
  fichaGuardada.value = JSON.stringify(ficha.value)
  archivos.value = f.archivos
  faltante.value = f.ficha_faltante
  Object.assign(cartera, f.cartera)
  historial.value = f.historial_cartera
  limites.value = f.limites
  formLimites.max_creditos_activos = f.limites?.max_creditos_activos?.toString() ?? ''
  formLimites.deuda_maxima = f.limites?.deuda_maxima ?? ''
  formLimites.bloqueado = f.limites?.bloqueado ?? false
  formLimites.motivo_bloqueo = f.limites?.motivo_bloqueo ?? ''
}

async function cargar() {
  cargando.value = true
  errorCarga.value = null
  try {
    contexto.value = await clienteService.contexto()
    const tareas: Promise<unknown>[] = []
    if (clienteId.value) {
      tareas.push(clienteService.obtener(clienteId.value).then((c) => {
        form.value = formDesdeCliente(c)
        datosCliente.value = { state: Number(c.state) }
      }))
      if (contexto.value.creditos) tareas.push(cargarCreditos())
    } else {
      form.value = formVacio()
    }
    if (contexto.value.creditos && puede('creditos.cartera.asignar')) {
      tareas.push(creditoService.usuariosCartera().then((u) => { usuarios.value = u }))
    }
    if (contexto.value.creditos && !clienteId.value) {
      // asesor_cobra viene de la configuración; en el alta se lee de ahí.
      tareas.push(creditoService.configuracion().then((c) => { cartera.asesor_cobra = c.asesor_cobra ?? true }).catch(() => undefined))
    }
    await Promise.all(tareas)
  } catch (e: any) {
    errorCarga.value = e?.response?.status === 404 ? 'Cliente no encontrado.' : interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
}

watch(() => route.params.id, (id) => {
  clienteId.value = id ? Number(id) : null
  cargar()
}, { immediate: true })

/** Guarda datos base, ficha de cobro y fotos en cola. El cliente nunca se duplica: si falla
 * algo después de crearlo, la página queda en modo edición para reintentar. */
async function guardar() {
  if (!puedeEditar.value || guardando.value) return
  errores.value = {}
  guardando.value = true
  try {
    const payload = aPayload(form.value, { tributarios: !contexto.value.creditos })
    if (!clienteId.value && contexto.value.creditos && cartera.asesor_id) payload.asesor_id = cartera.asesor_id

    const resultado = await guardarCliente<{ id: number }>(payload, clienteId.value)
    if (!resultado.ok) return
    const id = resultado.client.id
    const nuevo = !clienteId.value

    let pendientes: TipoArchivoCliente[] = []
    if (contexto.value.creditos) {
      if (JSON.stringify(ficha.value) !== fichaGuardada.value) {
        await creditoService.guardarFicha(id, ficha.value)
        fichaGuardada.value = JSON.stringify(ficha.value)
      }
      if (documentos.value?.hayPendientes()) pendientes = await documentos.value.subirPendientes(id)
    }

    if (pendientes.length) toast.warning('El cliente se guardó, pero algunas fotos no se subieron. Vuelve a intentarlo desde Documentos.')
    else toast.success(resultado.restaurado ? 'Cliente restaurado' : nuevo ? 'Cliente registrado' : 'Cambios guardados')

    if (nuevo || resultado.restaurado) await router.replace({ name: 'clients.ficha', params: { id } })
    else if (contexto.value.creditos) await cargarCreditos()
  } catch (e) {
    errores.value = erroresDeValidacion(e)
    if (Object.keys(errores.value).length === 0) toast.warning(interpretarErrorCredito(e).mensaje)
    else toast.warning('Revisa los campos marcados.')
  } finally {
    guardando.value = false
  }
}

async function alSubir(respuesta: unknown) {
  const r = respuesta as { ficha_faltante?: RequisitoFaltante[] } | undefined
  if (r?.ficha_faltante) faltante.value = r.ficha_faltante
}

async function asignar() {
  if (!clienteId.value) return // en el alta el asesor viaja con el registro
  asignando.value = true
  try {
    const nueva = await creditoService.asignarCartera(clienteId.value, cartera.asesor_id, asesorCobra.value ? null : cartera.cobrador_id)
    Object.assign(cartera, nueva)
    historial.value = (await creditoService.fichaCliente(clienteId.value)).historial_cartera
    toast.success('Cartera actualizada')
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
    await cargarCreditos()
  } finally {
    asignando.value = false
  }
}

async function guardarLimites() {
  if (!clienteId.value) return
  erroresLimites.value = {}
  guardandoLimites.value = true
  try {
    limites.value = await creditoService.guardarLimitesCliente(clienteId.value, {
      max_creditos_activos: formLimites.max_creditos_activos.trim() === '' ? null : Number(formLimites.max_creditos_activos),
      deuda_maxima: formLimites.deuda_maxima.trim() === '' ? null : formLimites.deuda_maxima.trim().replace(',', '.'),
      bloqueado: formLimites.bloqueado,
      motivo_bloqueo: formLimites.bloqueado ? formLimites.motivo_bloqueo.trim() || null : null,
    })
    toast.success('Límites guardados')
  } catch (e) {
    erroresLimites.value = erroresDeValidacion(e)
    if (Object.keys(erroresLimites.value).length === 0) toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    guardandoLimites.value = false
  }
}
</script>

<style scoped>
@media (min-width: 992px) {
  .columna-resumen {
    position: sticky;
    top: 90px;
  }
}
</style>
