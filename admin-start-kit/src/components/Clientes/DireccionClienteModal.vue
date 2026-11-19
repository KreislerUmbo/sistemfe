<template>
  <!-- T-065: alta y edición de una «otra dirección» del cliente (sucursal). Reutilizable: la ficha del
       cliente la usa ahora y las pantallas de emisión (T-107) la usarán para «+ Agregar dirección».
       Al crear devuelve la dirección guardada en `guardada`; al editar mantiene su estado `activo`. -->
  <Teleport to="body">
    <div v-if="modelValue" class="modal fade show d-block" tabindex="-1" style="background: rgba(0, 0, 0, 0.5)">
      <div class="modal-dialog">
        <form class="modal-content" novalidate @submit.prevent="guardar">
          <div class="modal-header">
            <h6 class="modal-title"><i class="fas fa-map-marker-alt me-2 text-primary"></i>{{ direccion ? 'Editar dirección' : 'Agregar dirección' }}</h6>
            <button type="button" class="btn-close" :disabled="guardando" @click="cerrar"></button>
          </div>
          <div class="modal-body small">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label mb-1 fw-semibold text-secondary" for="dir-nombre">Nombre</label>
                <input id="dir-nombre" v-model="form.nombre" type="text" maxlength="100" class="form-control form-control-sm"
                  :class="{ 'is-invalid': errores.nombre }" placeholder="Por ejemplo: Sucursal Tarapoto" />
                <div v-if="errores.nombre" class="invalid-feedback">{{ errores.nombre }}</div>
              </div>
              <div class="col-12">
                <label class="form-label mb-1 fw-semibold text-secondary" for="dir-direccion">Dirección</label>
                <input id="dir-direccion" v-model="form.direccion" type="text" maxlength="255" class="form-control form-control-sm"
                  :class="{ 'is-invalid': errores.direccion }" placeholder="Es la que se imprime en el comprobante" />
                <div v-if="errores.direccion" class="invalid-feedback">{{ errores.direccion }}</div>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label mb-1 fw-semibold text-secondary" for="dir-region">Región</label>
                <select id="dir-region" v-model="form.ubigeo_region" class="form-select form-select-sm" @change="cambiarRegion">
                  <option value="">Seleccionar</option>
                  <option v-for="r in regiones" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label mb-1 fw-semibold text-secondary" for="dir-provincia">Provincia</label>
                <select id="dir-provincia" v-model="form.ubigeo_provincia" class="form-select form-select-sm" :disabled="!form.ubigeo_region"
                  @change="form.ubigeo_distrito = ''">
                  <option value="">Seleccionar</option>
                  <option v-for="p in provincias" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label mb-1 fw-semibold text-secondary" for="dir-distrito">Distrito</label>
                <select id="dir-distrito" v-model="form.ubigeo_distrito" class="form-select form-select-sm" :disabled="!form.ubigeo_provincia">
                  <option value="">Seleccionar</option>
                  <option v-for="d in distritos" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label mb-1 fw-semibold text-secondary" for="dir-referencia">Referencia <span class="text-muted fw-normal">(opcional)</span></label>
                <input id="dir-referencia" v-model="form.referencia" type="text" maxlength="200" class="form-control form-control-sm"
                  :class="{ 'is-invalid': errores.referencia }" />
                <div v-if="errores.referencia" class="invalid-feedback">{{ errores.referencia }}</div>
              </div>
            </div>
            <div v-if="errorGeneral" class="text-danger mt-2">{{ errorGeneral }}</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="cerrar">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" :disabled="guardando">
              <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="fas fa-check me-1"></i>Guardar
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { distritosDe, erroresDeValidacion, listaRegiones, provinciasDe } from '@/helpers/clientes/formCliente'
import { direccionClienteService, type DireccionCliente } from '@/services/admin/clienteService'

const props = defineProps<{
  modelValue: boolean
  clienteId: number
  /** Dirección a editar; sin ella el modal crea una nueva. */
  direccion?: DireccionCliente | null
}>()
const emit = defineEmits<{
  (e: 'update:modelValue', abierto: boolean): void
  (e: 'guardada', direccion: DireccionCliente): void
}>()

const vacio = () => ({ nombre: '', direccion: '', referencia: '', ubigeo_region: '', ubigeo_provincia: '', ubigeo_distrito: '' })
const form = ref(vacio())
const errores = ref<Record<string, string>>({})
const errorGeneral = ref('')
const guardando = ref(false)

const regiones = listaRegiones()
const provincias = computed(() => (form.value.ubigeo_region ? provinciasDe(form.value.ubigeo_region) : []))
const distritos = computed(() => (form.value.ubigeo_provincia ? distritosDe(form.value.ubigeo_provincia) : []))

function cambiarRegion() {
  form.value.ubigeo_provincia = ''
  form.value.ubigeo_distrito = ''
}

// Cada vez que se abre, parte de la dirección a editar (o en blanco).
watch(
  () => props.modelValue,
  (abierto) => {
    if (!abierto) return
    errores.value = {}
    errorGeneral.value = ''
    const d = props.direccion
    form.value = d
      ? {
          nombre: d.nombre,
          direccion: d.direccion,
          referencia: d.referencia ?? '',
          ubigeo_region: d.ubigeo_region ?? '',
          ubigeo_provincia: d.ubigeo_provincia ?? '',
          ubigeo_distrito: d.ubigeo_distrito ?? '',
        }
      : vacio()
  },
)

function cerrar() {
  emit('update:modelValue', false)
}

async function guardar() {
  errores.value = {}
  errorGeneral.value = ''
  const f = form.value
  if (!f.nombre.trim()) errores.value.nombre = 'Escribe un nombre para reconocerla.'
  if (!f.direccion.trim()) errores.value.direccion = 'Escribe la dirección.'
  if (Object.keys(errores.value).length) return

  // Los nombres de región/provincia/distrito salen del ubigeo elegido, como en el cliente.
  const region = regiones.find((r) => r.id === f.ubigeo_region)
  const provincia = provincias.value.find((p) => p.id === f.ubigeo_provincia)
  const distrito = distritos.value.find((d) => d.id === f.ubigeo_distrito)
  const datos = {
    nombre: f.nombre.trim(),
    direccion: f.direccion.trim(),
    referencia: f.referencia.trim() || null,
    ubigeo_region: f.ubigeo_region || null,
    ubigeo_provincia: f.ubigeo_provincia || null,
    ubigeo_distrito: f.ubigeo_distrito || null,
    region: region?.name ?? null,
    provincia: provincia?.name ?? null,
    distrito: distrito?.name ?? null,
  }

  guardando.value = true
  try {
    const guardada = props.direccion
      ? await direccionClienteService.actualizar(props.direccion.id, { ...datos, activo: props.direccion.activo })
      : await direccionClienteService.crear(props.clienteId, datos)
    emit('guardada', guardada)
    cerrar()
  } catch (e) {
    errores.value = erroresDeValidacion(e)
    if (!Object.keys(errores.value).length) errorGeneral.value = 'No se pudo guardar la dirección. Inténtalo de nuevo.'
  } finally {
    guardando.value = false
  }
}
</script>
