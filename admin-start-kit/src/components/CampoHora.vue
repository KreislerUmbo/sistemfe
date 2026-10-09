<template>
  <div class="input-group input-group-sm campo-hora" :class="{ 'is-invalid': invalido }">
    <input ref="entrada" :id="id" type="text" class="form-control" :class="{ 'is-invalid': invalido }" :disabled="disabled" />
    <button type="button" class="btn btn-outline-secondary" :disabled="disabled" tabindex="-1" :aria-label="`Elegir hora${etiqueta ? ` de ${etiqueta}` : ''}`"
      @click="instancia?.toggle()">
      <i class="far fa-clock"></i>
    </button>
  </div>
</template>

<script setup lang="ts">
// Campo de hora con el selector de la plantilla Rizz (flatpickr sin calendario, mismo tema que
// CampoFecha.vue). v-model en "HH:mm" 24 h (o '' sin hora) — el backend valida H:i sin segundos;
// si llega "HH:mm:ss" desde la base se recorta al mostrar.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import flatpickr from 'flatpickr'
import { Spanish } from 'flatpickr/dist/l10n/es.js'
import type { Instance } from 'flatpickr/dist/types/instance'

const props = withDefaults(defineProps<{
  id?: string
  placeholder?: string
  invalido?: boolean
  disabled?: boolean
  /** Para el aria-label del botón. */
  etiqueta?: string
  /** Dentro de un modal: el selector se dibuja junto al campo (si no, va al body y el modal le roba el foco). */
  estatico?: boolean
}>(), { id: undefined, placeholder: 'hh:mm', invalido: false, disabled: false, etiqueta: undefined, estatico: false })

const modelo = defineModel<string | null>({ default: '' })
/** `cambio`: una sola vez al cerrar el selector y solo si la hora cambió — para guardar al momento
 *  (las flechas del selector actualizan el v-model en cada clic). */
const emit = defineEmits<{ (e: 'cambio', valor: string): void }>()
let alAbrir = ''
const entrada = ref<HTMLInputElement | null>(null)
const instancia = ref<Instance | null>(null)

const recortar = (valor: string | null | undefined) => (valor ? valor.slice(0, 5) : '')

onMounted(() => {
  if (!entrada.value) return
  instancia.value = flatpickr(entrada.value, {
    locale: Spanish,
    enableTime: true,
    noCalendar: true,
    dateFormat: 'H:i',
    time_24hr: true,
    allowInput: true,
    disableMobile: true,
    static: props.estatico,
    defaultDate: recortar(modelo.value) || undefined,
    onChange: (_fechas, texto) => {
      if (texto !== recortar(modelo.value)) modelo.value = texto
    },
    // Al escribir a mano y salir del campo, flatpickr aplica la hora; si queda vacío, se limpia.
    onOpen: () => { alAbrir = recortar(modelo.value) },
    onClose: (fechas) => {
      if (!fechas.length && modelo.value) modelo.value = ''
      const final = recortar(modelo.value)
      if (final !== alAbrir) emit('cambio', final)
    },
  }) as Instance
  sincronizar()
})

onBeforeUnmount(() => instancia.value?.destroy())

function sincronizar() {
  if (!entrada.value) return
  entrada.value.placeholder = props.placeholder
}

watch(modelo, (valor) => {
  const actual = instancia.value?.input.value ?? ''
  if (recortar(valor) !== actual) instancia.value?.setDate(recortar(valor) || '', false)
})
watch(() => props.placeholder, sincronizar)
</script>

<style scoped>
.campo-hora :deep(.form-control[readonly]) {
  background-color: var(--bs-body-bg);
}
</style>
