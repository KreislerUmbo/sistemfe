<template>
  <div class="input-group input-group-sm campo-fecha" :class="{ 'is-invalid': invalido || !!aviso, 'has-validation': !!aviso }">
    <input ref="entrada" :id="id" type="text" class="form-control" :class="{ 'is-invalid': invalido }" :disabled="disabled" />
    <button type="button" class="btn btn-outline-secondary" :disabled="disabled" tabindex="-1" :aria-label="`Abrir calendario${etiqueta ? ` de ${etiqueta}` : ''}`"
      @click="instancia?.toggle()">
      <i class="far fa-calendar-alt"></i>
    </button>
    <!-- T-117: una fecha escrita que no existe no se cambia por otra: se avisa y queda la anterior. -->
    <div v-if="aviso" class="invalid-feedback d-block w-100" role="alert">{{ aviso }}</div>
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
import { parsearFechaEscrita, soloFechaISO } from '@/helpers/fecha'

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

// T-117: flatpickr arma la fecha escrita con new Date(año, mes, día) y JavaScript pasa el día que sobra al
// mes siguiente: «31/11/2026» quedaba como 01/12/2026 sin avisar. Ahora una fecha escrita que no existe
// deja la fecha anterior (o el campo vacío si no había) y un aviso debajo hasta que se escriba o elija otra.
const FORMATO_ESCRITO = 'd/m/Y'
const aviso = ref('')
// true mientras lo último escrito a mano fue rechazado: el onChange que sigue (flatpickr vuelve a poner la
// fecha anterior, o vacío) no debe borrar el aviso. El aviso se pone acá y no en onChange porque un clic
// fuera con el calendario abierto aplica lo escrito sin disparar onChange.
let ultimaRechazada = false

/** Lo escrito a mano (formato d/m/Y) se valida acá; lo demás (modelo, min, max en Y-m-d) lo lee flatpickr como siempre. */
function leerFecha(texto: string, formato: string): Date | undefined {
  if (formato !== FORMATO_ESCRITO) return flatpickr.parseDate(texto, formato)
  const iso = parsearFechaEscrita(texto)
  ultimaRechazada = !iso
  if (iso) {
    const [anio, mes, dia] = iso.split('-').map(Number) as [number, number, number]
    return new Date(anio, mes - 1, dia)
  }
  aviso.value = textoAviso(texto.trim())
  const anterior = instancia.value?.selectedDates[0]
  return anterior ? new Date(anterior.getTime()) : undefined
}

function textoAviso(texto: string): string {
  return /^\d{1,2}[/.-]\d{1,2}[/.-]\d{4}$/.test(texto)
    ? `${texto} no existe.`
    : `«${texto}» no es una fecha: escríbela como dd/mm/aaaa.`
}

onMounted(() => {
  if (!entrada.value) return
  instancia.value = flatpickr(entrada.value, {
    locale: Spanish,
    dateFormat: 'Y-m-d',
    altInput: true,
    altFormat: FORMATO_ESCRITO,
    // Los tipos piden Date, pero flatpickr descarta un undefined (utils/dates.js): campo vacío.
    parseDate: leerFecha as (fecha: string, formato: string) => Date,
    altInputClass: 'form-control',
    allowInput: true,
    disableMobile: true,
    static: props.estatico,
    defaultDate: soloFechaISO(modelo.value) || undefined,
    minDate: props.min || undefined,
    maxDate: props.max || undefined,
    onChange: (_fechas, texto) => {
      // Tras una fecha rechazada flatpickr vuelve a poner la anterior (o vacío): el aviso se queda.
      if (!ultimaRechazada) aviso.value = ''
      ultimaRechazada = false
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
  alterno.classList.toggle('is-invalid', props.invalido || !!aviso.value)
}

watch(modelo, (valor) => {
  // Una fecha nueva (elegida o puesta desde afuera) quita el aviso de una fecha rechazada.
  aviso.value = ''
  // Con hora: se corrige el modelo y este mismo watch vuelve a correr con la fecha sola.
  if (normalizarModelo()) return
  const actual = instancia.value?.input.value ?? ''
  if ((valor || '') !== actual) instancia.value?.setDate(valor || '', false)
})
watch(() => props.min, (valor) => instancia.value?.set('minDate', valor || undefined))
watch(() => props.max, (valor) => instancia.value?.set('maxDate', valor || undefined))
watch(() => [props.placeholder, props.invalido, props.disabled, aviso.value], sincronizarAlterno)
</script>

<style scoped>
.campo-fecha :deep(.form-control[readonly]) {
  background-color: var(--bs-body-bg);
}
</style>
