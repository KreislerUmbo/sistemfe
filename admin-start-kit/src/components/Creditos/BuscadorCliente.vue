<template>
  <div class="buscador-cliente position-relative">
    <label class="form-label fw-semibold small text-uppercase text-muted mb-1" :for="idInput">Cliente</label>
    <div class="input-group input-group-lg">
      <input
        :id="idInput"
        ref="input"
        v-model="texto"
        type="text"
        class="form-control"
        :class="{ 'is-invalid': !!error }"
        :placeholder="seleccionado ? '' : 'Nombre o documento…'"
        :readonly="!!seleccionado"
        autocomplete="off"
        @input="buscar"
        @keydown.down.prevent="mover(1)"
        @keydown.up.prevent="mover(-1)"
        @keydown.enter.prevent="elegirResaltado"
        @keydown.esc="sugerencias = []"
      />
      <button v-if="seleccionado" type="button" class="btn btn-outline-secondary" title="Cambiar cliente" @click="limpiar">
        <i class="fas fa-times"></i>
      </button>
      <button v-else type="button" class="btn btn-outline-primary" title="Registrar cliente nuevo" @click="modalNuevo = true">
        <i class="fas fa-user-plus"></i>
      </button>
    </div>
    <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>

    <ul v-if="sugerencias.length && !seleccionado" class="list-group position-absolute w-100 shadow-sm sugerencias" role="listbox">
      <li
        v-for="(c, i) in sugerencias"
        :key="c.id"
        class="list-group-item list-group-item-action py-2"
        :class="{ active: i === resaltado }"
        role="option"
        @mousedown.prevent="elegir(c)"
      >
        <div class="fw-semibold">{{ c.full_name }}</div>
        <small :class="i === resaltado ? '' : 'text-muted'">{{ c.type_document }} {{ c.n_document }}</small>
      </li>
    </ul>
    <small v-else-if="buscando" class="text-muted d-block mt-1">Buscando…</small>

    <Teleport to="body">
      <b-modal v-model="modalNuevo" title="Registrar cliente" hide-footer centered size="lg">
        <ClientFormQuick :initial-data="null" @saved="onCreado" @cancel="modalNuevo = false" />
      </b-modal>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 1, 04-frontend verificación 2): no había un selector de cliente
// reutilizable; este sigue la misma búsqueda que ventas/cotizaciones (GET clients?search)
// y reusa ClientFormQuick para registrar uno nuevo sin salir del formulario.
import { onMounted, ref, watch } from 'vue'
import httpClient from '@/helpers/http-client'
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue'

export interface ClienteBuscado {
  id: number
  full_name: string
  type_document?: string
  n_document: string
  phone?: string | null
}

const props = defineProps<{ error?: string; autofocus?: boolean }>()
const seleccionado = defineModel<ClienteBuscado | null>({ default: null })

const DEBOUNCE_MS = 250
const RESULTADOS = 8

const idInput = `buscador-cliente-${Math.random().toString(36).slice(2, 9)}`
const input = ref<HTMLInputElement | null>(null)
const texto = ref('')
const sugerencias = ref<ClienteBuscado[]>([])
const resaltado = ref(-1)
const buscando = ref(false)
const modalNuevo = ref(false)
let temporizador: ReturnType<typeof setTimeout> | null = null
let ultima = 0

const etiqueta = (c: ClienteBuscado) => `${c.full_name} · ${c.type_document ?? ''} ${c.n_document}`.replace(/\s+/g, ' ')

watch(seleccionado, (c) => {
  texto.value = c ? etiqueta(c) : ''
}, { immediate: true })

const buscar = () => {
  if (temporizador) clearTimeout(temporizador)
  const consulta = texto.value.trim()
  if (consulta.length < 2) {
    sugerencias.value = []
    return
  }
  temporizador = setTimeout(async () => {
    const numero = ++ultima
    buscando.value = true
    try {
      const { data } = await httpClient.get('clients', { params: { search: consulta, take: RESULTADOS } })
      if (numero === ultima) {
        sugerencias.value = data.clients?.data ?? []
        resaltado.value = sugerencias.value.length ? 0 : -1
      }
    } catch {
      if (numero === ultima) sugerencias.value = []
    } finally {
      if (numero === ultima) buscando.value = false
    }
  }, DEBOUNCE_MS)
}

const mover = (paso: number) => {
  if (!sugerencias.value.length) return
  resaltado.value = (resaltado.value + paso + sugerencias.value.length) % sugerencias.value.length
}

const elegir = (c: ClienteBuscado) => {
  seleccionado.value = c
  sugerencias.value = []
}

const elegirResaltado = () => {
  const c = sugerencias.value[resaltado.value]
  if (c) elegir(c)
}

const limpiar = () => {
  seleccionado.value = null
  sugerencias.value = []
  requestAnimationFrame(() => input.value?.focus())
}

const onCreado = (cliente: ClienteBuscado) => {
  modalNuevo.value = false
  elegir(cliente)
}

onMounted(() => {
  if (props.autofocus && !seleccionado.value) input.value?.focus()
})
</script>

<style scoped>
.sugerencias {
  z-index: 1050;
  max-height: 320px;
  overflow-y: auto;
}
.sugerencias .list-group-item {
  cursor: pointer;
  min-height: 44px;
}
</style>
