<template>
  <div class="mi-credito min-vh-100 bg-light py-3 px-3">
    <div class="mx-auto" style="max-width: 480px">
      <!-- Negocio -->
      <header class="text-center mb-3">
        <img v-if="negocio.logo" :src="negocio.logo" :alt="negocio.nombre ?? 'Logo'" class="mb-2" style="max-height: 56px; max-width: 220px" />
        <h1 v-else-if="negocio.nombre" class="h5 fw-bold mb-1">{{ negocio.nombre }}</h1>
        <p class="text-muted small mb-0">Mi crédito</p>
      </header>

      <div v-if="estado === 'cargando'" class="text-center text-muted py-5">
        <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
      </div>

      <div v-else-if="estado === 'invalido'" class="card border-0 shadow-sm">
        <div class="card-body p-4 text-center">
          <i class="fas fa-link-slash fs-2 text-muted mb-3 d-block"></i>
          <h2 class="h6 fw-bold">Este enlace ya no es válido</h2>
          <p class="text-muted small mb-0">Pide un enlace nuevo al negocio donde tienes tu crédito.</p>
        </div>
      </div>

      <div v-else-if="estado === 'error'" class="card border-0 shadow-sm">
        <div class="card-body p-4 text-center">
          <h2 class="h6 fw-bold">No pudimos cargar tu crédito</h2>
          <p class="text-muted small">Revisa tu conexión a internet e intenta de nuevo.</p>
          <button type="button" class="btn btn-primary" @click="cargar">Reintentar</button>
        </div>
      </div>

      <!-- Verificación: últimos 4 del documento -->
      <form v-else-if="estado === 'verificar'" class="card border-0 shadow-sm" novalidate @submit.prevent="verificar">
        <div class="card-body p-4">
          <h2 class="h6 fw-bold mb-1">Confirma que eres tú</h2>
          <p class="text-muted small mb-3">Para ver tu crédito, escribe los <strong>últimos 4 dígitos de tu DNI</strong> (o de tu documento).</p>
          <label for="mc-digitos" class="visually-hidden">Últimos 4 dígitos de tu documento</label>
          <input id="mc-digitos" ref="campo" :value="digitos" type="text" inputmode="numeric" autocomplete="off" maxlength="4"
            class="form-control form-control-lg text-center fw-bold mb-2 digitos" :class="{ 'is-invalid': mensaje && !bloqueado }"
            placeholder="····" :disabled="bloqueado || enviando" @input="alEscribir" />
          <div v-if="mensaje" class="small mb-2" :class="bloqueado ? 'text-warning' : 'text-danger'" role="alert">{{ mensaje }}</div>
          <button type="submit" class="btn btn-primary btn-lg w-100" :disabled="digitos.length !== 4 || bloqueado || enviando">
            <span v-if="enviando" class="spinner-border spinner-border-sm me-2"></span>Ver mi crédito
          </button>
          <p class="text-muted small mt-3 mb-0"><i class="fas fa-lock me-1"></i>Este celular quedará recordado por 90 días.</p>
        </div>
      </form>

      <!-- Datos -->
      <template v-else-if="estado === 'datos' && datos">
        <p class="fw-semibold mb-3">Hola {{ primerNombre(datos.cliente.nombre) }}, este es tu crédito al {{ formatoFecha(datos.hoy) }}.</p>

        <div v-if="!datos.creditos.length" class="card border-0 shadow-sm mb-3">
          <div class="card-body p-4 text-center text-muted">No tienes créditos vigentes.</div>
        </div>

        <div v-for="c in datos.creditos" :key="c.numero" class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <span class="small text-muted">Crédito {{ c.numero }}</span>
            <span class="badge" :class="ESTADOS[c.estado].clase">{{ ESTADOS[c.estado].texto }}</span>
          </div>

          <div v-if="c.estado === 'cancelado'" class="card-body p-3 small">
            <i class="fas fa-check-circle text-success me-1"></i>Pagado por completo. Préstamo de {{ formatoSoles(c.monto_prestado) }}.
          </div>

          <div v-else class="card-body p-3">
            <div v-if="c.vencido" class="alert alert-danger py-2 px-3 small mb-3">
              <div class="fw-semibold">{{ textoVencido(c.vencido) }}</div>
              Debes <strong>{{ formatoSoles(c.vencido.total) }}</strong>
              <template v-if="Number(c.vencido.mora) > 0"> (incluye mora de {{ formatoSoles(c.vencido.mora) }})</template>.
            </div>

            <template v-if="c.proxima">
              <div class="small text-muted">Próxima cuota</div>
              <div class="d-flex justify-content-between align-items-end flex-wrap gap-1">
                <div class="fs-5 fw-bold">Cuota {{ c.proxima.numero_cuota }} de {{ c.cuotas_total }}</div>
                <div class="fs-4 fw-bold">{{ formatoSoles(c.proxima.monto) }}</div>
              </div>
              <div class="small mb-3" :class="c.proxima.fecha === datos.hoy ? 'text-danger fw-semibold' : 'text-muted'">
                {{ textoVence(c.proxima.fecha, datos.hoy) }}
              </div>
            </template>

            <div class="progress mb-1" style="height: 8px" role="progressbar" :aria-valuenow="c.cuotas_pagadas" aria-valuemin="0" :aria-valuemax="c.cuotas_total"
              :aria-label="`${c.cuotas_pagadas} de ${c.cuotas_total} cuotas pagadas`">
              <div class="progress-bar bg-success" :style="{ width: `${c.cuotas_total ? (c.cuotas_pagadas / c.cuotas_total) * 100 : 0}%` }"></div>
            </div>
            <div class="d-flex justify-content-between small">
              <span class="text-muted">{{ c.cuotas_pagadas }} de {{ c.cuotas_total }} pagadas</span>
              <span>Te {{ c.cuotas_restantes === 1 ? 'falta 1 cuota' : `faltan ${c.cuotas_restantes} cuotas` }}</span>
            </div>
            <div class="d-flex justify-content-between small mt-2 pt-2 border-top">
              <span class="text-muted">Total por pagar</span>
              <strong>{{ formatoSoles(c.saldo_por_pagar) }}</strong>
            </div>
          </div>
        </div>

        <div v-if="datos.pagos.length" class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-white border-bottom py-2 small fw-semibold">Tus últimos pagos</div>
          <ul class="list-unstyled mb-0 small">
            <li v-for="(p, i) in datos.pagos" :key="i" class="d-flex justify-content-between px-3 py-2" :class="{ 'border-top': i > 0 }">
              <span>
                {{ formatFechaHora(p.fecha) }}
                <span v-if="p.recibo" class="text-muted d-block">Recibo {{ p.recibo }}</span>
              </span>
              <strong>{{ formatoSoles(p.monto) }}</strong>
            </li>
          </ul>
        </div>

        <p class="text-muted small text-center mb-2">
          Datos al {{ formatFechaHora(datos.generado_en) }}. Si ya pagaste y no aparece, consulta con {{ negocio.nombre ?? 'el negocio' }}.
        </p>
        <div class="d-flex justify-content-center gap-2 mb-3">
          <a v-if="whatsappNegocio" :href="whatsappNegocio" target="_blank" rel="noopener" class="btn btn-success btn-sm">
            <i class="fab fa-whatsapp me-1"></i>Escribir al negocio
          </a>
          <a v-else-if="negocio.telefono" :href="`tel:${negocio.telefono}`" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-phone me-1"></i>Llamar al negocio
          </a>
        </div>
        <p class="text-center mb-0">
          <button type="button" class="btn btn-link btn-sm text-muted" @click="salir">Salir en este celular</button>
        </p>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (Fase 5b) — "Mi crédito": el cliente consulta su crédito con el link o el QR de
// su contrato/recibo, sin usuario ni contraseña. Primero confirma los últimos 4 dígitos de su
// documento; ese celular queda recordado 90 días. Solo lectura; los montos vienen calculados del
// backend (mismo cálculo que el detalle del crédito).
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { miCreditoService } from '@/services/public/miCreditoService'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import { formatFechaHora } from '@/helpers/fecha'
import { enlaceWhatsapp } from '@/helpers/creditos/cobranza'
import { limpiarDigitos, primerNombre, textoVence, textoVencido } from '@/helpers/creditos/miCredito'
import type { MiCredito } from '@/types/creditos'

const ESTADOS = {
  al_dia: { texto: 'Al día', clase: 'bg-success-subtle text-success' },
  atrasado: { texto: 'Con atraso', clase: 'bg-danger-subtle text-danger' },
  cancelado: { texto: 'Cancelado', clase: 'bg-secondary-subtle text-secondary' },
} as const

const route = useRoute()
const token = String(route.params.token ?? '')

const estado = ref<'cargando' | 'verificar' | 'datos' | 'invalido' | 'error'>('cargando')
const datos = ref<MiCredito | null>(null)
const digitos = ref('')
const mensaje = ref('')
const bloqueado = ref(false)
const enviando = ref(false)
const campo = ref<HTMLInputElement | null>(null)

const marca = ref<{ nombre: string | null; logo: string | null }>({ nombre: null, logo: null })
const negocio = computed(() => datos.value?.negocio ?? { ...marca.value, telefono: null })
const whatsappNegocio = computed(() => enlaceWhatsapp(negocio.value.telefono))

async function cargar() {
  estado.value = 'cargando'
  try {
    const r = await miCreditoService.consultar(token)
    if (r.tipo === 'datos') {
      datos.value = r.datos
      document.title = `Mi crédito${r.datos.negocio.nombre ? ` | ${r.datos.negocio.nombre}` : ''}`
    }
    estado.value = r.tipo
    if (r.tipo === 'verificar') {
      await nextTick()
      campo.value?.focus()
    }
  } catch {
    estado.value = 'error'
  }
}

function alEscribir(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  digitos.value = limpiarDigitos(entrada.value)
  entrada.value = digitos.value
  if (!bloqueado.value) mensaje.value = ''
}

async function verificar() {
  if (digitos.value.length !== 4 || enviando.value) return
  enviando.value = true
  try {
    const r = await miCreditoService.verificar(token, digitos.value)
    if (r.tipo === 'correcto') {
      await cargar()
    } else if (r.tipo === 'incorrecto') {
      mensaje.value = r.restantes === 1
        ? 'Los dígitos no coinciden. Te queda 1 intento.'
        : `Los dígitos no coinciden. Te quedan ${r.restantes} intentos.`
      digitos.value = ''
    } else if (r.tipo === 'bloqueado') {
      bloqueado.value = true
      mensaje.value = 'Por seguridad, bloqueamos el acceso unos minutos. Intenta de nuevo en 15 minutos o consulta con el negocio.'
    } else {
      estado.value = 'invalido'
    }
  } catch {
    mensaje.value = 'No pudimos comprobarlo. Revisa tu conexión e intenta de nuevo.'
  } finally {
    enviando.value = false
  }
}

function salir() {
  miCreditoService.olvidar(token)
  datos.value = null
  digitos.value = ''
  estado.value = 'verificar'
}

onMounted(() => {
  // La marca del negocio llega aparte: el cliente ve de quién es la página antes de escribir sus dígitos.
  miCreditoService.negocio().then((m) => { marca.value = m }).catch(() => { /* sin marca: igual funciona */ })
  cargar()
})
</script>

<style scoped>
.digitos {
  letter-spacing: 0.75em;
  font-size: 1.75rem;
}
</style>
