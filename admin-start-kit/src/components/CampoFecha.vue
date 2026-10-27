<template>
  <div class="input-group input-group-sm campo-fecha" :class="{ 'is-invalid': invalido }">
    <input ref="entrada" :id="id" type="text" class="form-control" :class="{ 'is-invalid': invalido }" :disabled="disabled" />
    <button type="button" class="btn btn-outline-secondary" :disabled="disabled" tabindex="-1" :aria-label="`Abrir calendario${etiqueta ? ` de ${etiqueta}` : ''}`"
      @click="instancia?.toggle()">
      <i class="far fa-calendar-alt"></i>
    </button>
  </div>
</template>

<script setup lang="ts">
// Campo de fecha con el calendario de la plantilla Rizz (flatpickr + tema _flatpicker.scss).
// v-model en "YYYY-MM-DD" (o '' sin fecha); se muestra dd/mm/aaaa. Mismo calendario en el
// celular (disableMobile), en español y con lunes como primer día. Reemplaza al FlatPicker.vue
// del template, que buscaba el input por id al montar y no se enteraba de cambios externos.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import flatpickr from 'flatpickr'
import { Spanish } from 'flatpickr/dist/l10n/es.js'
import type { Instance } from 'flatpickr/dist/types/instance'
import { soloFechaISO } from '@/helpers/fecha'

const props = withDefaults(defineProps<{
  id?: string
  min?: string
  max?: string
  placeholder?: string
  invalido?: boolean
  disabled?: boolean
  /** Para el aria-label del botón del calendario. */
  etiqueta?: string
  /** Dentro de un modal: el calendario se dibuja junto al campo (si no, va al body y el modal le roba el foco). */
  estatico?: boolean
}>(), { id: undefined, min: undefined, max: undefined, placeholder: 'dd/mm/aaaa', invalido: false, disabled: false, etiqueta: undefined, estatico: false })

const modelo = defineModel<string | null>({ default: '' })
const entrada = ref<HTMLInputElement | null>(null)
const instancia = ref<Instance | null>(null)

onMounted(() => {
  if (!entrada.value) return
  instancia.value = flatpickr(entrada.value, {
    locale: Spanish,
    dateFormat: 'Y-m-d',
    altInput: true,
    altFormat: 'd/m/Y',
    altInputClass: 'form-control',
    allowInput: true,
    disableMobile: true,
    static: props.estatico,
    defaultDate: soloFechaISO(modelo.value) || undefined,
    minDate: props.min || undefined,
    maxDate: props.max || undefined,
    onChange: (_fechas, texto) => {
      if (texto !== modelo.value) modelo.value = texto
    },
    // Al escribir a mano y salir del campo, flatpickr aplica la fecha; si queda vacío, se limpia.
    onClose: (fechas) => {
      if (!fechas.length && modelo.value) modelo.value = ''
    },
  }) as Instance
  sincronizarAlterno()
  normalizarModelo()
})

/**
 * T-026: si el formulario trae la fecha con hora y zona ("2026-08-20T00:00:00.000000Z"),
 * flatpickr la leía como un instante UTC y mostraba el día anterior. El campo toma el día
 * escrito y deja el modelo en "YYYY-MM-DD", el mismo formato que al elegir en el calendario.
 */
function normalizarModelo(): boolean {
  const fecha = soloFechaISO(modelo.value)
  if (modelo.value && fecha !== modelo.value) {
    modelo.value = fecha
    return true
  }
  return false
}

onBeforeUnmount(() => instancia.value?.destroy())

/** El input visible (alterno) lo crea flatpickr: copia placeholder, estado inválido y deshabilitado. */
function sincronizarAlterno() {
  const alterno = instancia.value?.altInput
  if (!alterno) return
  alterno.placeholder = props.placeholder
  alterno.disabled = props.disabled
  alterno.classList.toggle('is-invalid', props.invalido)
}

watch(modelo, (valor) => {
  // Con hora: se corrige el modelo y este mismo watch vuelve a correr con la fecha sola.
  if (normalizarModelo()) return
  const actual = instancia.value?.input.value ?? ''
  if ((valor || '') !== actual) instancia.value?.setDate(valor || '', false)
})
watch(() => props.min, (valor) => instancia.value?.set('minDate', valor || undefined))
watch(() => props.max, (valor) => instancia.value?.set('maxDate', valor || undefined))
watch(() => [props.placeholder, props.invalido, props.disabled], sincronizarAlterno)
</script>

<style scoped>
.campo-fecha :deep(.form-control[readonly]) {
  background-color: var(--bs-body-bg);
}
</style>
