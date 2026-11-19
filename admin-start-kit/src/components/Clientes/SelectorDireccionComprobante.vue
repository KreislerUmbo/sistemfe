<template>
  <!-- T-107: la dirección que imprime el comprobante, elegida de la lista del cliente: la fiscal (por
       defecto, sin preguntar) o una de sus otras direcciones; si no está, «+ Agregar dirección» la crea
       y la deja elegida. Siempre visible (decisión del usuario, 19-nov-2026: un solo campo de dirección,
       en vez de un texto editable más el selector). `modelValue` = client_address_id (null = la fiscal). -->
  <div class="selector-direccion-comprobante">
    <label class="form-label mb-1 small fw-semibold text-secondary" :for="idSelect">
      <i class="fas fa-map-marker-alt me-1"></i>Dirección del comprobante
    </label>
    <select :id="idSelect" class="form-select form-select-sm" :value="modelValue ?? ''" :disabled="!clienteId" @change="elegir">
      <option value="">{{ clienteId ? 'Fiscal' + (direccionFiscal ? ' — ' + direccionFiscal : '') : 'Elige un cliente' }}</option>
      <option v-for="d in opciones" :key="d.id" :value="d.id">
        {{ d.nombre }} — {{ d.direccion }}{{ d.activo ? '' : ' (desactivada)' }}
      </option>
    </select>
    <template v-if="clienteId">
      <a href="#" class="small d-inline-block mt-1" @click.prevent="modalAbierto = true">
        <i class="fas fa-plus me-1"></i>Agregar dirección
      </a>
      <DireccionClienteModal v-model="modalAbierto" :cliente-id="clienteId" @guardada="alGuardar" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import DireccionClienteModal from '@/components/Clientes/DireccionClienteModal.vue'
import { direccionClienteService, type DireccionCliente } from '@/services/admin/clienteService'

const props = defineProps<{
  clienteId?: number | null
  modelValue: number | null
  /** Texto de la dirección fiscal (clients.address), solo para mostrarla en la primera opción. */
  direccionFiscal?: string | null
}>()
const emit = defineEmits<{ (e: 'update:modelValue', id: number | null): void }>()

const idSelect = `direccion-comprobante-${Math.random().toString(36).slice(2, 8)}`
const direcciones = ref<DireccionCliente[]>([])
const modalAbierto = ref(false)

// Activas, más la ya elegida aunque se haya desactivado después (venta guardada que se edita).
const opciones = computed(() => direcciones.value.filter((d) => d.activo || d.id === props.modelValue))

async function cargar(id: number | null | undefined) {
  direcciones.value = []
  if (!id) return
  try {
    const lista = await direccionClienteService.listar(id, true)
    // Si el cliente cambió mientras llegaba la respuesta, se descarta.
    if (props.clienteId === id) direcciones.value = lista
  } catch {
    direcciones.value = [] // solo la fiscal: la emisión sigue igual
  }
}

// Al cambiar de cliente vuelve a la fiscal. Sin cliente previo (primera carga, p. ej. al editar
// una venta) se respeta el valor que mandó el padre.
watch(
  () => props.clienteId,
  (nuevo, anterior) => {
    if (anterior && props.modelValue !== null) emit('update:modelValue', null)
    cargar(nuevo)
  },
  { immediate: true },
)

function elegir(e: Event) {
  const v = (e.target as HTMLSelectElement).value
  emit('update:modelValue', v === '' ? null : Number(v))
}

function alGuardar(d: DireccionCliente) {
  direcciones.value = [...direcciones.value, d]
  emit('update:modelValue', d.id)
}
</script>
