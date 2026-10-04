<template>
  <Teleport to="body">
    <b-modal v-model="abierto" :title="titulo" :size="tamano" centered scrollable no-close-on-backdrop
      :body-class="'p-3 p-md-4'" @shown="enfocar">
      <div ref="cuerpo" @keydown.enter="alPresionarEnter">
        <slot />
        <div v-if="error" class="alert mt-3 mb-0" :class="error.tipo === 'red' ? 'alert-warning' : 'alert-danger'" role="alert">
          {{ error.mensaje }}
        </div>
      </div>
      <template #footer>
        <div class="d-flex gap-2 w-100 justify-content-end flex-wrap">
          <button type="button" class="btn btn-light flex-grow-1 flex-md-grow-0" :disabled="procesando" @click="abierto = false">Cancelar</button>
          <button type="button" class="btn flex-grow-1 flex-md-grow-0 px-md-4" :class="`btn-${variante}`"
            :disabled="procesando || deshabilitado" @click="emit('confirmar')">
            <span v-if="procesando" class="spinner-border spinner-border-sm me-1"></span>
            {{ error?.reintentable ? 'Reintentar' : textoConfirmar }}
          </button>
        </div>
      </template>
    </b-modal>
  </Teleport>
</template>

<script setup lang="ts">
// Módulo Créditos (04-frontend "Teclado"): Enter confirma el diálogo activo, Esc lo
// cierra, foco inicial en el primer campo. El botón se bloquea mientras procesa y, si
// falló la red, dice "Reintentar" (misma clave de idempotencia: no duplica).
import { ref } from 'vue'
import type { ErrorCredito } from '@/composables/creditos/errorCredito'

withDefaults(defineProps<{
  titulo: string
  textoConfirmar?: string
  variante?: string
  procesando?: boolean
  deshabilitado?: boolean
  tamano?: 'sm' | 'md' | 'lg' | 'xl'
  error?: ErrorCredito | null
}>(), { textoConfirmar: 'Confirmar', variante: 'primary', procesando: false, deshabilitado: false, tamano: 'md', error: null })

const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ confirmar: [] }>()
const cuerpo = ref<HTMLElement | null>(null)

function enfocar() {
  cuerpo.value?.querySelector<HTMLElement>('input:not([readonly]):not([type=hidden]), select, textarea')?.focus()
}

function alPresionarEnter(e: KeyboardEvent) {
  // En un textarea, Enter es salto de línea.
  if ((e.target as HTMLElement).tagName === 'TEXTAREA') return
  e.preventDefault()
  emit('confirmar')
}
</script>
