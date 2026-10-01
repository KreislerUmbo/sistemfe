<template>
  <!-- El JS de Bootstrap no se carga en la app (main.ts): el menú usa bootstrap-vue-next. -->
  <b-dropdown size="sm" variant="outline-secondary" end :disabled="ocupado" menu-class="menu-documentos" @show="cargar">
    <template #button-content>
      <span v-if="ocupado" class="spinner-border spinner-border-sm me-1"></span><i v-else class="far fa-file-alt me-1"></i>Documentos
    </template>

    <b-dropdown-header>Ver o imprimir</b-dropdown-header>
    <li v-for="d in CON_FORMATO" :key="d.id" class="d-flex align-items-center justify-content-between gap-3 px-3 py-1">
      <span class="small text-nowrap"><i :class="d.icono" class="me-2 text-muted"></i>{{ d.texto }}</span>
      <span class="btn-group btn-group-sm">
        <button type="button" class="btn btn-outline-secondary" @click="abrirDocumento(d.id, 'a4')">A4</button>
        <button type="button" class="btn btn-outline-secondary" @click="abrirDocumento(d.id, 'ticket80mm')">Ticket</button>
      </span>
    </li>
    <b-dropdown-item-button @click="abrirContrato">
      <i class="fas fa-file-signature me-2 text-muted"></i>Contrato
      <small v-if="contrato" class="text-muted ms-1">(v{{ contrato.plantilla_version }})</small>
    </b-dropdown-item-button>
    <b-dropdown-item-button v-if="estado === 'finalizado'" @click="abrirDocumento('constancia', 'a4')">
      <i class="fas fa-award me-2 text-muted"></i>Constancia de cancelación
    </b-dropdown-item-button>
    <b-dropdown-item-button v-for="r in lista?.reprogramaciones ?? []" :key="`r${r.id}`" @click="abrirDocumento('reprogramacion', 'a4', r.id)">
      <i class="far fa-calendar-alt me-2 text-muted"></i>Acuerdo de reprogramación {{ r.fecha ? formatoFecha(r.fecha) : '' }}
    </b-dropdown-item-button>

    <template v-if="firmados.length || puedeSubir">
      <b-dropdown-divider />
      <b-dropdown-header>Contrato firmado</b-dropdown-header>
      <b-dropdown-item-button v-for="(f, i) in firmados" :key="`f${f.id}`" @click="abrirArchivo(f.id)">
        <i class="fas fa-paperclip me-2 text-muted"></i>Ver firmado{{ firmados.length > 1 ? ` ${i + 1}` : '' }}
        <small v-if="f.creado" class="text-muted ms-1">{{ formatoFecha(f.creado.slice(0, 10)) }}</small>
      </b-dropdown-item-button>
      <b-dropdown-item-button v-if="puedeSubir" @click="elegirArchivo">
        <i class="fas fa-upload me-2 text-muted"></i>Subir contrato firmado
      </b-dropdown-item-button>
    </template>
  </b-dropdown>
  <input ref="campoArchivo" type="file" class="d-none" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment" @change="subir" />
</template>

<script setup lang="ts">
// Módulo Créditos (Fase 4b, Plan 1.15): documentos del crédito. Cronograma y estado de cuenta
// en A4 o ticket; el contrato se congela la primera vez que se pide; el firmado se sube como
// foto o PDF (en el celular abre la cámara).
import { computed, ref } from 'vue'
import { creditoService } from '@/services/admin/creditoService'
import { useDocumentosCredito } from '@/composables/creditos/useDocumentosCredito'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import { formatoFecha } from '@/helpers/creditos/formato'
import type { CreditoEstado, DocumentosCredito, FormatoPdf, TipoDocumentoPdf } from '@/types/creditos'

const props = defineProps<{ creditoId: number; estado: CreditoEstado; puedeSubir: boolean }>()

const CON_FORMATO: { id: TipoDocumentoPdf; texto: string; icono: string }[] = [
  { id: 'cronograma', texto: 'Cronograma', icono: 'fas fa-list-ol' },
  { id: 'estado-cuenta', texto: 'Estado de cuenta', icono: 'fas fa-file-invoice-dollar' },
]

const toast = useToast()
const { abrir, ocupado } = useDocumentosCredito()
const lista = ref<DocumentosCredito | null>(null)
const campoArchivo = ref<HTMLInputElement | null>(null)

const contrato = computed(() => lista.value?.documentos.find((d) => d.tipo === 'contrato') ?? null)
const firmados = computed(() => lista.value?.documentos.filter((d) => d.tipo === 'contrato_firmado') ?? [])

async function cargar() {
  try {
    lista.value = await creditoService.documentos(props.creditoId)
  } catch {
    // Sin la lista igual se pueden pedir cronograma, estado de cuenta y contrato.
  }
}

const abrirDocumento = (documento: TipoDocumentoPdf, formato: FormatoPdf, reprogramacionId?: number) =>
  abrir(() => creditoService.documentoUrl(props.creditoId, documento, formato, reprogramacionId))

async function abrirContrato() {
  const primeraVez = !contrato.value
  await abrir(() => creditoService.documentoUrl(props.creditoId, 'contrato'))
  if (primeraVez) {
    await cargar()
    if (contrato.value) toast.success('Contrato generado y guardado: desde ahora se entrega siempre este mismo documento.')
  }
}

const abrirArchivo = (documentoId: number) => abrir(() => creditoService.archivoUrl(props.creditoId, documentoId))

function elegirArchivo() {
  campoArchivo.value?.click()
}

async function subir(evento: Event) {
  const input = evento.target as HTMLInputElement
  const archivo = input.files?.[0]
  input.value = ''
  if (!archivo) return
  try {
    await creditoService.subirContratoFirmado(props.creditoId, archivo)
    toast.success('Contrato firmado guardado')
    await cargar()
  } catch (e) {
    const error = interpretarErrorCredito(e)
    toast.warning(error.tipo === 'validacion' ? Object.values(error.campos).join(' ') : error.mensaje)
  }
}
</script>

<style scoped>
:deep(.menu-documentos) {
  min-width: 290px;
}
</style>
