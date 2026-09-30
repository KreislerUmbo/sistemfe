<template>
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div class="min-w-0">
      <h5 class="fw-bold mb-0 text-dark">
        <i :class="icono" class="me-2 text-primary"></i>{{ titulo }}
        <slot name="insignia" />
      </h5>
      <small v-if="subtitulo" class="text-muted">{{ subtitulo }}</small>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <slot />
      <button v-if="volver" type="button" class="btn btn-outline-secondary" @click="alVolver">
        <i class="fas fa-arrow-left me-2"></i>Volver
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos: encabezado de página con el mismo patrón que Agencia de Viajes y Ventas
// (título con ícono, subtítulo y "Volver" a la derecha).
import { useRouter, type RouteLocationRaw } from 'vue-router'

const props = withDefaults(defineProps<{
  titulo: string
  subtitulo?: string
  icono?: string
  /** Dónde ir si no hay historial; false oculta el botón. */
  volver?: RouteLocationRaw | false
}>(), { subtitulo: '', icono: 'fas fa-hand-holding-usd', volver: () => ({ name: 'creditos.index' }) })

const router = useRouter()

function alVolver() {
  if (window.history.length > 1) router.back()
  else if (props.volver) router.push(props.volver)
}
</script>

<style scoped>
.min-w-0 {
  min-width: 0;
}
</style>
