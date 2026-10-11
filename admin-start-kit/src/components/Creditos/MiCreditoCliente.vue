<template>
  <div class="card border-0 shadow-sm mb-0">
    <div class="card-body">
      <p class="fw-bold mb-1"><i class="fas fa-qrcode me-2 text-primary"></i>Mi crédito</p>
      <p class="small text-muted mb-2">El link para que el cliente vea su cuota, cuánto le falta y sus pagos. También va en el QR de sus recibos y contrato.</p>

      <div v-if="cargando" class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>
      <div v-else-if="error" class="small text-warning-emphasis"><i class="fas fa-exclamation-triangle me-1"></i>{{ error }}</div>

      <template v-else-if="enlace">
        <div class="input-group input-group-sm mb-2">
          <input :value="enlace.url" type="text" class="form-control" readonly aria-label="Link Mi crédito" @focus="($event.target as HTMLInputElement).select()" />
          <button type="button" class="btn btn-outline-secondary" title="Copiar link" aria-label="Copiar link" @click="copiar">
            <i :class="copiado ? 'fas fa-check text-success' : 'far fa-copy'"></i>
          </button>
        </div>

        <div class="small mb-2" :class="enlace.accesos ? 'text-success' : 'text-muted'">
          <template v-if="enlace.accesos">
            <i class="fas fa-eye me-1"></i>Entró {{ enlace.accesos === 1 ? '1 vez' : `${enlace.accesos} veces` }} · última {{ formatFechaHora(enlace.ultimo_acceso_en) }}
          </template>
          <template v-else>Todavía no entró.</template>
        </div>
        <div v-if="enlace.bloqueado_hasta" class="small text-warning-emphasis mb-2">
          <i class="fas fa-lock me-1"></i>Bloqueado hasta {{ formatFechaHora(enlace.bloqueado_hasta) }} por intentos fallidos con el documento.
        </div>

        <div class="d-flex flex-wrap gap-2">
          <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="btn btn-sm btn-success">
            <i class="fab fa-whatsapp me-1"></i>Enviar por WhatsApp
          </a>
          <span v-else class="small text-muted">Sin celular registrado: copia el link y envíalo.</span>
          <button v-if="puedeRegenerar" type="button" class="btn btn-sm btn-outline-danger" @click="dialogo = true">
            <i class="fas fa-sync-alt me-1"></i>Regenerar
          </button>
        </div>
      </template>
    </div>

    <DialogoBase v-model="dialogo" titulo="Regenerar el link Mi crédito" texto-confirmar="Regenerar" variante="danger"
      :procesando="regenerando" @confirmar="regenerar">
      <p class="small mb-2">Úsalo si el link llegó a otra persona o el cliente perdió su celular.</p>
      <ul class="small mb-0">
        <li>El link actual y <strong>los QR ya impresos</strong> en sus recibos y contrato dejan de funcionar.</li>
        <li>El cliente tendrá que volver a escribir sus 4 dígitos en su celular.</li>
        <li>Los recibos que imprimas desde ahora llevarán el link nuevo; envíaselo por WhatsApp.</li>
      </ul>
    </DialogoBase>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (Fase 5b, entrega 3) — el link "Mi crédito" en la ficha del cliente: enviarlo
// por WhatsApp, copiarlo, ver si el cliente entró y regenerarlo. GET lo crea la primera vez; sin
// documento del cliente el backend responde 422 con el motivo y aquí se muestra.
import { computed, onMounted, ref, watch } from 'vue'
import DialogoBase from '@/components/Creditos/DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { enlaceWhatsapp } from '@/helpers/creditos/cobranza'
import { enlaceWhatsappConTexto } from '@/helpers/creditos/documentos'
import { mensajeMiCredito } from '@/helpers/creditos/miCredito'
import { formatFechaHora } from '@/helpers/fecha'
import type { EnlaceMiCredito } from '@/types/creditos'

const props = defineProps<{
  clienteId: number
  nombre: string
  telefono: string | null
  /** Regenerar exige creditos.crear (como en el backend). */
  puedeRegenerar: boolean
  /** Cambia cuando se guarda la ficha (p. ej. al completar el documento): vuelve a consultar. */
  version?: number
}>()

const toast = useToast()
const enlace = ref<EnlaceMiCredito | null>(null)
const cargando = ref(true)
const error = ref<string | null>(null)
const copiado = ref(false)
const dialogo = ref(false)
const regenerando = ref(false)

const whatsapp = computed(() => enlace.value && enlaceWhatsapp(props.telefono)
  ? enlaceWhatsappConTexto(props.telefono, mensajeMiCredito(props.nombre, enlace.value.url))
  : null)

async function cargar() {
  cargando.value = true
  error.value = null
  try {
    enlace.value = await creditoService.enlaceMiCredito(props.clienteId)
  } catch (e) {
    enlace.value = null
    error.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
}

async function copiar() {
  if (!enlace.value) return
  try {
    await navigator.clipboard.writeText(enlace.value.url)
  } catch {
    // Sin HTTPS el navegador no deja usar el portapapeles: copia "a la antigua".
    const campo = document.createElement('textarea')
    campo.value = enlace.value.url
    document.body.appendChild(campo)
    campo.select()
    document.execCommand('copy')
    campo.remove()
  }
  copiado.value = true
  setTimeout(() => { copiado.value = false }, 2000)
}

async function regenerar() {
  regenerando.value = true
  try {
    enlace.value = await creditoService.regenerarMiCredito(props.clienteId)
    dialogo.value = false
    toast.success('Link regenerado. Envíale el nuevo al cliente.')
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    regenerando.value = false
  }
}

onMounted(cargar)
watch(() => props.version, cargar)
</script>
