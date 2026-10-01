<template>
  <div v-if="cargando" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
  <div v-else-if="errorCarga" class="alert alert-danger py-2 small">{{ errorCarga }}</div>

  <div v-else class="row g-3">
    <div class="col-12 col-xl-8">
      <div class="card border-0 shadow-sm mb-0">
        <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span>
            <span class="fw-semibold text-dark">Texto del contrato</span>
            <small class="text-muted ms-2">Versión vigente: v{{ version }}</small>
          </span>
          <span v-if="hayCambios" class="badge bg-warning text-dark">Cambios sin guardar</span>
        </div>
        <div class="card-body py-3">
          <div class="alert alert-warning py-2 small mb-3">
            <i class="fas fa-balance-scale me-1"></i>El texto legal lo define su abogado (tasas máximas BCRP, Ley 29733).
            Guardar crea una versión nueva: los contratos ya emitidos no cambian.
          </div>
          <RichTextEditor ref="editor" v-model="contenido" placeholder="Texto del contrato…" />
          <div v-if="error" class="alert alert-danger py-2 small mt-3 mb-0">{{ error }}</div>
        </div>
      </div>
      <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-outline-secondary" :disabled="!hayCambios || guardando" @click="descartar">
          <i class="fas fa-undo me-2"></i>Descartar
        </button>
        <button type="button" class="btn btn-outline-primary" :disabled="previsualizando || !contenido" @click="vistaPrevia">
          <span v-if="previsualizando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="far fa-eye me-2"></i>Vista previa
        </button>
        <button type="button" class="btn btn-primary fw-semibold" :disabled="!hayCambios || guardando" @click="guardar">
          <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-save me-2"></i>Guardar versión nueva
        </button>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card border-0 shadow-sm mb-0 columna-variables">
        <div class="card-header bg-white border-bottom py-2 fw-semibold text-dark">Variables</div>
        <div class="card-body py-2">
          <p class="small text-muted mb-2">Clic para insertar donde está el cursor. Al generar el contrato se reemplazan por los datos del crédito.</p>
          <ul class="list-group list-group-flush small">
            <li v-for="v in variables" :key="v.clave" class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center gap-2">
              <span class="text-muted">{{ v.descripcion }}</span>
              <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap font-monospace" @click="insertar(v.clave)">{{ '{' + v.clave + '}' }}</button>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (Fase 4b, Plan 1.15): plantilla del contrato. El backend sanitiza el HTML y
// rechaza variables fuera de la lista blanca; guardar crea una versión nueva.
import { computed, onMounted, ref } from 'vue'
import RichTextEditor from '@/components/RichTextEditor.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import type { PlantillaContrato } from '@/types/creditos'

const toast = useToast()
const editor = ref<{ insertarTexto: (texto: string) => void } | null>(null)
const contenido = ref('')
const original = ref('')
const version = ref(0)
const variables = ref<PlantillaContrato['variables']>([])
const cargando = ref(true)
const guardando = ref(false)
const previsualizando = ref(false)
const errorCarga = ref<string | null>(null)
const error = ref<string | null>(null)

const hayCambios = computed(() => contenido.value !== original.value)

onMounted(async () => {
  try {
    const plantilla = await creditoService.plantillaContrato()
    contenido.value = plantilla.contenido
    variables.value = plantilla.variables
    version.value = plantilla.version
    // Quill normaliza el HTML al cargarlo: se toma como "original" lo que deja el editor.
    setTimeout(() => {
      original.value = contenido.value
    }, 0)
  } catch (e) {
    errorCarga.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
})

function insertar(clave: string) {
  editor.value?.insertarTexto(`{${clave}}`)
}

function mensajeError(e: unknown): string {
  const interpretado = interpretarErrorCredito(e)
  return interpretado.tipo === 'validacion' ? Object.values(interpretado.campos).join(' ') : interpretado.mensaje
}

async function guardar() {
  guardando.value = true
  error.value = null
  try {
    const nueva = await creditoService.guardarPlantillaContrato(contenido.value)
    contenido.value = nueva.contenido
    version.value = nueva.version
    setTimeout(() => {
      original.value = contenido.value
    }, 0)
    toast.success(`Contrato guardado como versión ${nueva.version}`)
  } catch (e) {
    error.value = mensajeError(e)
  } finally {
    guardando.value = false
  }
}

function descartar() {
  contenido.value = original.value
  error.value = null
}

async function vistaPrevia() {
  // La pestaña se abre antes del await para que el navegador no la bloquee.
  const ventana = window.open('', '_blank')
  previsualizando.value = true
  error.value = null
  try {
    const pdf = await creditoService.vistaPreviaContrato(contenido.value)
    const url = URL.createObjectURL(pdf)
    if (ventana) ventana.location.href = url
    else window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e) {
    ventana?.close()
    error.value = mensajeError(e)
  } finally {
    previsualizando.value = false
  }
}
</script>

<style scoped>
@media (min-width: 1200px) {
  .columna-variables {
    position: sticky;
    top: 116px;
  }
}
</style>
