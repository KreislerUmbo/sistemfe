<template>
  <div>
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
      <div class="btn-group" role="group" aria-label="Año">
        <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Año anterior" @click="anio--"><i class="fas fa-chevron-left"></i></button>
        <span class="btn btn-sm btn-outline-secondary fw-bold disabled text-body">{{ anio }}</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Año siguiente" @click="anio++"><i class="fas fa-chevron-right"></i></button>
      </div>
      <small class="text-muted text-end">Días sin cobro si el crédito tiene "No cobrar en feriados".</small>
    </div>

    <!-- Alta en línea -->
    <form class="row g-2 mb-3" novalidate @submit.prevent="agregar">
      <div class="col-12 col-sm-4 col-lg-3">
        <label class="visually-hidden" for="fer-fecha">Fecha</label>
        <CampoFecha id="fer-fecha" v-model="nuevo.fecha" :min="`${anio}-01-01`" :max="`${anio}-12-31`" etiqueta="el feriado" />
      </div>
      <div class="col-12 col-sm">
        <label class="visually-hidden" for="fer-desc">Descripción</label>
        <input id="fer-desc" v-model="nuevo.descripcion" type="text" class="form-control form-control-sm" maxlength="150" placeholder="Ej.: Aniversario de la ciudad" />
      </div>
      <div class="col-12 col-sm-auto">
        <button type="submit" class="btn btn-sm btn-primary w-100" :disabled="!nuevo.fecha || !nuevo.descripcion.trim() || guardando">
          <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span><i v-else class="fas fa-plus me-1"></i>Agregar
        </button>
      </div>
    </form>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div v-if="cargando" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
    <p v-else-if="!feriados.length" class="text-muted text-center py-4 mb-0">No hay feriados registrados para {{ anio }}.</p>
    <ul v-else class="list-group">
      <li v-for="f in feriados" :key="f.id" class="list-group-item py-2">
        <form v-if="editando?.id === f.id" class="row g-2 align-items-center" novalidate @submit.prevent="guardarEdicion">
          <div class="col-12 col-sm-4 col-lg-3">
            <CampoFecha v-model="editando.fecha" :etiqueta="f.descripcion" />
          </div>
          <div class="col-12 col-sm">
            <input v-model="editando.descripcion" type="text" class="form-control form-control-sm" maxlength="150" aria-label="Descripción" @keydown.esc="editando = null" />
          </div>
          <div class="col-12 col-sm-auto d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-grow-1" :disabled="guardando || !editando.fecha || !editando.descripcion.trim()">Guardar</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="editando = null">Cancelar</button>
          </div>
        </form>
        <div v-else class="d-flex align-items-center gap-3">
          <span class="fw-semibold fecha">{{ fechaCorta(f.fecha) }}</span>
          <span class="flex-grow-1">{{ f.descripcion }}</span>
          <span class="badge rounded-pill" :class="f.origen === 'nacional' ? 'bg-info-subtle text-info-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'">
            {{ f.origen === 'nacional' ? 'Nacional' : 'Propio' }}
          </span>
          <button type="button" class="btn btn-sm btn-outline-primary" :aria-label="`Editar ${f.descripcion}`" @click="editar(f)"><i class="fas fa-pen"></i></button>
          <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Quitar ${f.descripcion}`" @click="quitar(f)"><i class="fas fa-trash-alt"></i></button>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos — feriados del año (04-frontend pantalla 7). Solo afectan créditos nuevos o
// reprogramaciones: el cronograma de un crédito activo ya quedó calculado.
import { onMounted, ref, watch } from 'vue'
import Swal from 'sweetalert2/dist/sweetalert2.js'
import CampoFecha from '@/components/CampoFecha.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import { fechaCorta } from '@/helpers/creditos/estados'
import { hoyEnLima } from '@/helpers/creditos/formato'
import type { Feriado } from '@/types/creditos'

type TVueSwalInstance = typeof Swal & typeof Swal.fire

const toast = useToast()
const anio = ref(Number(hoyEnLima().slice(0, 4)))
const feriados = ref<Feriado[]>([])
const cargando = ref(false)
const guardando = ref(false)
const error = ref<string | null>(null)
const nuevo = ref({ fecha: '', descripcion: '' })
const editando = ref<{ id: number; fecha: string; descripcion: string } | null>(null)

async function cargar() {
  cargando.value = true
  error.value = null
  editando.value = null
  try {
    feriados.value = await creditoService.feriados(anio.value)
  } catch (e) {
    error.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
watch(anio, cargar)

function mensaje(e: unknown): string {
  const interpretado = interpretarErrorCredito(e)
  return interpretado.tipo === 'validacion' ? Object.values(interpretado.campos).join(' ') : interpretado.mensaje
}

async function agregar() {
  guardando.value = true
  error.value = null
  try {
    await creditoService.crearFeriado({ fecha: nuevo.value.fecha, descripcion: nuevo.value.descripcion.trim() })
    nuevo.value = { fecha: '', descripcion: '' }
    toast.success('Feriado agregado')
    await cargar()
  } catch (e) {
    error.value = mensaje(e)
  } finally {
    guardando.value = false
  }
}

function editar(f: Feriado) {
  editando.value = { id: f.id, fecha: f.fecha, descripcion: f.descripcion }
}

async function guardarEdicion() {
  if (!editando.value) return
  guardando.value = true
  error.value = null
  try {
    const { id, fecha, descripcion } = editando.value
    await creditoService.actualizarFeriado(id, { fecha, descripcion: descripcion.trim() })
    toast.success('Feriado actualizado')
    await cargar()
  } catch (e) {
    error.value = mensaje(e)
  } finally {
    guardando.value = false
  }
}

async function quitar(f: Feriado) {
  const { isConfirmed } = await (Swal as TVueSwalInstance).fire({
    title: `¿Quitar "${f.descripcion}"?`,
    text: 'Los créditos ya activos no cambian; afecta a los nuevos y a las reprogramaciones.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Quitar',
    cancelButtonText: 'Cancelar',
  })
  if (!isConfirmed) return
  try {
    await creditoService.eliminarFeriado(f.id)
    toast.success('Feriado quitado')
    await cargar()
  } catch (e) {
    error.value = mensaje(e)
  }
}
</script>

<style scoped>
.fecha {
  width: 5.5rem;
  font-variant-numeric: tabular-nums;
}
</style>
