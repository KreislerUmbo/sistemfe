<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Mis avisos de cobranza" subtitulo="Cada mañana, lo que tienes que cobrar ese día, en tu correo y en tu celular" icono="far fa-envelope" :volver="false" />

    <div v-if="cargando" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
    <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga }}</div>

    <div v-else-if="prefs" class="row g-3">
      <div v-if="!prefs.aviso_activo" class="col-12">
        <div class="alert alert-warning small py-2 mb-0">
          <i class="fas fa-info-circle me-1"></i>El negocio tiene el aviso diario <strong>apagado</strong>: puedes dejar todo listo,
          pero no llegará nada hasta que el administrador lo encienda en Configuración de créditos → Avisos.
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold text-dark">Correo para avisos</span>
            <span v-if="prefs.correo" class="badge" :class="prefs.correo_verificado ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'">
              <i :class="prefs.correo_verificado ? 'fas fa-check' : 'far fa-clock'" class="me-1"></i>
              {{ prefs.correo_verificado ? 'Confirmado' : 'Sin confirmar' }}
            </span>
          </div>
          <div class="card-body py-3">
            <form novalidate @submit.prevent="guardarCorreo">
              <label class="form-label mb-1 small fw-semibold text-secondary" for="avisos-correo">Correo</label>
              <div class="input-group input-group-sm">
                <input id="avisos-correo" v-model.trim="correo" type="email" inputmode="email" autocomplete="email" class="form-control"
                  :class="{ 'is-invalid': errorCorreo }" placeholder="tu@correo.com" />
                <button type="submit" class="btn btn-primary" :disabled="!correoCambio || guardando">
                  <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>Guardar
                </button>
              </div>
              <div v-if="errorCorreo" class="invalid-feedback d-block">{{ errorCorreo }}</div>
              <small v-else-if="!prefs.correo && prefs.correo_sugerido" class="text-muted">
                Te sugerimos el de tu usuario; guárdalo para confirmarlo, o escribe otro.
              </small>
            </form>

            <div v-if="prefs.correo && !prefs.correo_verificado" class="alert alert-warning small py-2 mt-3 mb-0">
              Te enviamos un enlace a <strong>{{ prefs.correo }}</strong>. Ábrelo para confirmar el correo: hasta entonces no te llegan avisos.
              Si no lo ves, revisa Spam.
              <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" :disabled="reenviando" @click="reenviar">
                {{ reenviando ? 'Enviando…' : 'Reenviar enlace' }}
              </button>
            </div>

            <hr class="my-3" />

            <div class="form-check form-switch mb-2">
              <input id="avisos-recibir" :checked="prefs.recibir_correo" class="form-check-input" type="checkbox" role="switch"
                :disabled="guardando" @change="alternarRecibir(($event.target as HTMLInputElement).checked)" />
              <label class="form-check-label small" for="avisos-recibir">Recibir el aviso diario por correo</label>
            </div>
            <p class="small text-muted mb-3">
              <i class="fas fa-paperclip me-1"></i>
              <template v-if="prefs.pdf_adjunto">Tu aviso trae la hoja de ruta en PDF adjunta.</template>
              <template v-else>Tu aviso trae un botón que abre la Agenda (con tu usuario).</template>
              Sale solo si el negocio tiene el aviso encendido, a la hora y en los días configurados.
            </p>

            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="!puedeProbar || probando" @click="probar">
              <span v-if="probando" class="spinner-border spinner-border-sm me-1"></span><i v-else class="far fa-paper-plane me-1"></i>
              Enviarme una prueba ahora
            </button>
            <small v-if="!puedeProbar" class="text-muted d-block mt-1">Primero confirma tu correo y deja encendido el aviso.</small>
          </div>
        </div>

        <!-- Entrega 2: avisos push en el dispositivo -->
        <div class="card border-0 shadow-sm mt-3">
          <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold text-dark">Avisos en el celular</span>
            <span v-if="estadoDispositivo === 'activo'" class="badge bg-success-subtle text-success">
              <i class="fas fa-check me-1"></i>Activo en este dispositivo
            </span>
          </div>
          <div class="card-body py-3 small">
            <p class="text-muted mb-3">
              Una notificación a las {{ horaAviso }} con cuántos cobros tienes y cuánto suma; al tocarla se abre la Agenda.
              Solo los días con cobros, y sin nombres de clientes (se ve en la pantalla bloqueada).
            </p>

            <div v-if="!prefs.push_clave_publica" class="alert alert-secondary py-2 mb-0">
              Los avisos en el celular todavía no están disponibles en este sistema.
            </div>
            <template v-else>
              <div v-if="estadoDispositivo === 'ios-instalar'" class="alert alert-info py-2">
                <strong>En iPhone</strong> los avisos solo llegan con la app instalada: toca
                <i class="fas fa-arrow-up-from-bracket mx-1"></i><strong>Compartir</strong> → <strong>Agregar a inicio</strong>,
                abre UmboSystem desde ese ícono, inicia sesión y vuelve a esta pantalla.
              </div>
              <div v-else-if="estadoDispositivo === 'no-soportado'" class="alert alert-secondary py-2">
                Este navegador no permite avisos. En Android usa Chrome; en iPhone, la app agregada a la pantalla de inicio.
              </div>
              <div v-else-if="estadoDispositivo === 'bloqueado'" class="alert alert-warning py-2">
                Las notificaciones de este sitio están <strong>bloqueadas</strong> en el navegador. Permítelas desde el candado
                junto a la dirección (Configuración del sitio → Notificaciones) y recarga esta página.
              </div>

              <div class="d-flex flex-wrap gap-2 mb-3">
                <button v-if="estadoDispositivo === 'inactivo'" type="button" class="btn btn-sm btn-primary" :disabled="procesandoPush" @click="activarDispositivo">
                  <span v-if="procesandoPush" class="spinner-border spinner-border-sm me-1"></span><i v-else class="far fa-bell me-1"></i>
                  Activar avisos en este dispositivo
                </button>
                <template v-if="estadoDispositivo === 'activo'">
                  <button type="button" class="btn btn-sm btn-outline-primary" :disabled="procesandoPush" @click="probarDispositivo">
                    <span v-if="procesandoPush" class="spinner-border spinner-border-sm me-1"></span><i v-else class="far fa-paper-plane me-1"></i>
                    Enviarme un aviso de prueba
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="procesandoPush" @click="desactivarDispositivo">
                    <i class="far fa-bell-slash me-1"></i>Desactivar en este dispositivo
                  </button>
                </template>
              </div>

              <template v-if="prefs.push_dispositivos.length">
                <div class="form-check form-switch mb-2">
                  <input id="avisos-push" :checked="prefs.recibir_push" class="form-check-input" type="checkbox" role="switch"
                    :disabled="guardando" @change="alternarPush(($event.target as HTMLInputElement).checked)" />
                  <label class="form-check-label" for="avisos-push">Recibir avisos en mis dispositivos</label>
                </div>
                <span class="text-secondary fw-semibold d-block mb-1">Dispositivos con avisos activados</span>
                <ul class="list-unstyled mb-0">
                  <li v-for="d in prefs.push_dispositivos" :key="d.endpoint_hash" class="d-flex justify-content-between gap-2 py-1 border-bottom">
                    <span><i class="fas fa-mobile-alt me-1 text-muted"></i>{{ nombreDispositivo(d.agente) }}</span>
                    <span class="text-muted">{{ d.ultimo_envio_en ? `último aviso ${formatFechaHora(d.ultimo_envio_en)}` : 'sin avisos aún' }}</span>
                  </li>
                </ul>
              </template>
            </template>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-2 fw-semibold text-dark">Últimos avisos</div>
          <div class="card-body py-2">
            <p v-if="!prefs.ultimos.length" class="text-muted small mb-0 py-2">Todavía no hay avisos.</p>
            <ul v-else class="list-unstyled mb-0">
              <li v-for="a in prefs.ultimos" :key="`${a.fecha}-${a.canal}`" class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                <div class="small">
                  <div class="fw-semibold">{{ formatoFecha(a.fecha) }}</div>
                  <div class="text-muted">{{ a.cantidad_cobros }} {{ a.cantidad_cobros === 1 ? 'cobro' : 'cobros' }} · {{ formatoSoles(a.monto_esperado) }}</div>
                </div>
                <span class="badge" :class="estadoAviso(a).clase" :title="a.detalle ?? ''">{{ estadoAviso(a).texto }}</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos (Fase 5) — "Mis avisos": el correo de cada usuario para el aviso diario de
// cobranza. Solo un correo confirmado (enlace que manda el backend) recibe avisos, porque llevan
// datos de clientes. La configuración del negocio (hora, días, encendido) está en Configuración.
import { computed, onMounted, ref } from 'vue'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import { formatFechaHora } from '@/helpers/fecha'
import { estadoAviso } from '@/helpers/creditos/avisos'
import { activarPush, desactivarPush, estadoPush, nombreDispositivo, type EstadoPush } from '@/helpers/creditos/push'
import type { PreferenciasAvisos } from '@/types/creditos'

const toast = useToast()

const prefs = ref<PreferenciasAvisos | null>(null)
const correo = ref('')
const cargando = ref(true)
const guardando = ref(false)
const reenviando = ref(false)
const probando = ref(false)
const errorCarga = ref<string | null>(null)
const errorCorreo = ref<string | null>(null)

const estadoDispositivo = ref<EstadoPush>('no-soportado')
const procesandoPush = ref(false)

const correoCambio = computed(() => !!correo.value && correo.value.toLowerCase() !== (prefs.value?.correo ?? ''))
const puedeProbar = computed(() => !!prefs.value?.correo_verificado && !!prefs.value?.recibir_correo)
const horaAviso = computed(() => prefs.value?.aviso_hora?.slice(0, 5) ?? '06:45')

function aplicar(datos: PreferenciasAvisos) {
  prefs.value = datos
  correo.value = datos.correo ?? datos.correo_sugerido ?? ''
}

onMounted(async () => {
  try {
    const [datos, estado] = await Promise.all([creditoService.preferenciasAvisos(), estadoPush().catch((): EstadoPush => 'no-soportado')])
    aplicar(datos)
    estadoDispositivo.value = estado
  } catch (e) {
    errorCarga.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
})

async function activarDispositivo() {
  if (!prefs.value?.push_clave_publica) return
  procesandoPush.value = true
  try {
    const r = await activarPush(prefs.value.push_clave_publica)
    if (!r.ok) {
      estadoDispositivo.value = r.estado
      if (r.estado === 'inactivo') toast.warning('Necesitamos tu permiso para mostrarte avisos.')
      return
    }
    aplicar(await creditoService.activarPushDispositivo(r.suscripcion))
    estadoDispositivo.value = 'activo'
    toast.success('Avisos activados en este dispositivo. Prueba que te llegue uno.')
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje || 'No se pudo activar los avisos en este dispositivo.')
  } finally {
    procesandoPush.value = false
  }
}

async function desactivarDispositivo() {
  procesandoPush.value = true
  try {
    const endpoint = await desactivarPush()
    if (endpoint) aplicar(await creditoService.quitarPushDispositivo(endpoint))
    estadoDispositivo.value = await estadoPush()
    toast.success('Avisos desactivados en este dispositivo.')
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    procesandoPush.value = false
  }
}

async function probarDispositivo() {
  procesandoPush.value = true
  try {
    toast.success((await creditoService.probarPush()).mensaje)
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    procesandoPush.value = false
  }
}

async function alternarPush(recibir: boolean) {
  guardando.value = true
  try {
    aplicar(await creditoService.guardarPreferenciasAvisos({ recibir_push: recibir }))
    toast.success(recibir ? 'Avisos en el celular encendidos' : 'Avisos en el celular apagados')
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    guardando.value = false
  }
}

async function guardarCorreo() {
  if (!correoCambio.value || guardando.value) return
  errorCorreo.value = null
  guardando.value = true
  try {
    aplicar(await creditoService.guardarPreferenciasAvisos({ correo: correo.value }))
    toast.success(`Te enviamos un enlace a ${prefs.value?.correo} para confirmarlo.`)
  } catch (e) {
    const error = interpretarErrorCredito(e)
    if (error.tipo === 'validacion') errorCorreo.value = error.campos.correo ?? 'Revisa el correo.'
    else toast.warning(error.mensaje)
    // El correo pudo guardarse aunque falle el envío del enlace (502): se recarga para verlo.
    if (error.tipo !== 'validacion') aplicar(await creditoService.preferenciasAvisos())
  } finally {
    guardando.value = false
  }
}

async function alternarRecibir(recibir: boolean) {
  guardando.value = true
  try {
    aplicar(await creditoService.guardarPreferenciasAvisos({ recibir_correo: recibir }))
    toast.success(recibir ? 'Aviso por correo encendido' : 'Aviso por correo apagado')
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    guardando.value = false
  }
}

async function reenviar() {
  reenviando.value = true
  try {
    toast.success((await creditoService.reenviarVerificacionAvisos()).mensaje)
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    reenviando.value = false
  }
}

async function probar() {
  probando.value = true
  try {
    toast.success((await creditoService.probarAviso()).mensaje + ' Revisa tu correo.')
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    probando.value = false
  }
}
</script>
