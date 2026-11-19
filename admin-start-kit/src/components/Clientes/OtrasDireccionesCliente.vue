<template>
  <!-- T-065: otras direcciones del cliente (sucursales). Quien emite un comprobante elige una de ellas
       en vez de la dirección fiscal (selector en las pantallas de emisión: T-107). Agregar lo puede
       quien emite o edita clientes; editar y desactivar, solo quien edita clientes. Dentro del <form>
       de la ficha: todos los botones son type="button". -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between">
      <span class="fw-semibold text-dark"><i class="fas fa-map-marker-alt me-2 text-primary"></i>Otras direcciones</span>
      <button v-if="puedeAgregar" type="button" class="btn btn-outline-primary btn-sm" @click="abrirNueva">
        <i class="fas fa-plus me-1"></i>Agregar
      </button>
    </div>
    <div class="card-body">
      <div v-if="cargando" class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
      <div v-else-if="errorCarga" class="text-danger small">{{ errorCarga }}</div>
      <p v-else-if="!direcciones.length" class="text-muted small mb-0">
        Sin otras direcciones. La dirección fiscal del cliente se usa en todos sus comprobantes.
      </p>
      <ul v-else class="list-group list-group-flush">
        <li v-for="d in direcciones" :key="d.id" class="list-group-item px-0 d-flex align-items-start justify-content-between gap-2"
          :class="{ 'opacity-50': !d.activo }">
          <div class="small">
            <div class="fw-semibold">
              {{ d.nombre }}
              <span v-if="!d.activo" class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Desactivada</span>
            </div>
            <div>{{ d.direccion }}</div>
            <div v-if="d.distrito || d.provincia" class="text-muted">{{ [d.distrito, d.provincia].filter(Boolean).join(', ') }}</div>
          </div>
          <div v-if="puedeEditar" class="btn-group btn-group-sm flex-shrink-0">
            <button type="button" class="btn btn-outline-secondary" title="Editar" :disabled="cambiando === d.id" @click="abrirEdicion(d)">
              <i class="fas fa-pen"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" :title="d.activo ? 'Desactivar' : 'Reactivar'"
              :disabled="cambiando === d.id" @click="alternar(d)">
              <i class="fas" :class="d.activo ? 'fa-eye-slash' : 'fa-eye'"></i>
            </button>
          </div>
        </li>
      </ul>
    </div>
    <DireccionClienteModal v-model="modalAbierto" :cliente-id="clienteId" :direccion="enEdicion" @guardada="alGuardar" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DireccionClienteModal from '@/components/Clientes/DireccionClienteModal.vue'
import { useToast } from '@/composables/useToast'
import { direccionClienteService, type DireccionCliente } from '@/services/admin/clienteService'

const props = defineProps<{ clienteId: number; puedeAgregar: boolean; puedeEditar: boolean }>()

const toast = useToast()
const cargando = ref(true)
const errorCarga = ref<string | null>(null)
const lista = ref<DireccionCliente[]>([])
const modalAbierto = ref(false)
const enEdicion = ref<DireccionCliente | null>(null)
const cambiando = ref<number | null>(null)

// Activas primero (el servidor ya las ordena así; se reordena al cambiar el estado sin recargar).
const direcciones = computed(() => [...lista.value].sort((a, b) => Number(b.activo) - Number(a.activo)))

async function cargar() {
  cargando.value = true
  errorCarga.value = null
  try {
    lista.value = await direccionClienteService.listar(props.clienteId, true)
  } catch {
    errorCarga.value = 'No se pudieron cargar las direcciones.'
  } finally {
    cargando.value = false
  }
}

function abrirNueva() {
  enEdicion.value = null
  modalAbierto.value = true
}

function abrirEdicion(d: DireccionCliente) {
  enEdicion.value = d
  modalAbierto.value = true
}

function alGuardar() {
  toast.success('Dirección guardada')
  cargar()
}

async function alternar(d: DireccionCliente) {
  cambiando.value = d.id
  try {
    const { id, client_id, ...datos } = d
    void id
    void client_id
    await direccionClienteService.actualizar(d.id, { ...datos, activo: !d.activo })
    toast.success(d.activo ? 'Dirección desactivada' : 'Dirección reactivada')
    await cargar()
  } catch {
    toast.warning('No se pudo cambiar el estado de la dirección.')
  } finally {
    cambiando.value = null
  }
}

onMounted(cargar)
</script>
