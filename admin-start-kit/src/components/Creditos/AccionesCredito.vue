<template>
  <div class="d-flex flex-wrap gap-2">
    <button v-for="a in principales" :key="a.id" type="button" class="btn btn-sm" :class="a.clase" :disabled="a.deshabilitada"
      :title="a.ayuda" @click="emit('accion', a.id)">
      <i :class="a.icono" class="me-1"></i>{{ a.texto }}
    </button>

    <!-- El JS de Bootstrap no se carga en la app (main.ts): el menú usa bootstrap-vue-next. -->
    <b-dropdown v-if="secundarias.length" text="Más" size="sm" variant="outline-secondary" end>
      <b-dropdown-item-button v-for="a in secundarias" :key="a.id" :variant="a.peligro ? 'danger' : undefined" @click="emit('accion', a.id)">
        <i :class="a.icono" class="me-2"></i>{{ a.texto }}
      </b-dropdown-item-button>
    </b-dropdown>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (04-frontend pantalla 2): visibles Cobrar, Liquidar y Reprogramar; el resto
// en "Más". Botones -sm en el encabezado, igual que el detalle de una reserva. Qué se ofrece
// lo decide accionesDisponibles().
import { computed } from 'vue'
import type { AccionCredito } from '@/composables/creditos/accionesCredito'

export type IdAccion = AccionCredito

const props = defineProps<{ acciones: AccionCredito[] }>()
const emit = defineEmits<{ accion: [id: IdAccion] }>()

interface Boton { id: IdAccion; texto: string; icono: string; clase?: string; deshabilitada?: boolean; ayuda?: string; peligro?: boolean }

const CATALOGO: Record<AccionCredito, Omit<Boton, 'id'>> = {
  editar: { texto: 'Editar', icono: 'fas fa-pen', clase: 'btn-outline-primary' },
  activar: { texto: 'Activar', icono: 'fas fa-play', clase: 'btn-primary' },
  cobrar: { texto: 'Cobrar', icono: 'fas fa-money-bill-wave', clase: 'btn-success' },
  liquidar: { texto: 'Liquidar', icono: 'fas fa-check-double', clase: 'btn-outline-primary' },
  reprogramar: { texto: 'Reprogramar', icono: 'far fa-calendar-alt', clase: 'btn-outline-secondary' },
  condonar: { texto: 'Condonar mora', icono: 'fas fa-hand-holding-heart' },
  corregir: { texto: 'Corregir', icono: 'fas fa-wrench' },
  renovar: { texto: 'Renovar', icono: 'fas fa-sync-alt' },
  cambiar_asesor: { texto: 'Cambiar asesor', icono: 'fas fa-user-tie' },
  castigar: { texto: 'Castigar', icono: 'fas fa-gavel', peligro: true },
  revertir_castigo: { texto: 'Revertir castigo', icono: 'fas fa-undo' },
  anular: { texto: 'Anular crédito', icono: 'fas fa-ban', peligro: true },
}

const PRINCIPALES: AccionCredito[] = ['cobrar', 'liquidar', 'reprogramar', 'editar', 'activar']
const boton = (id: AccionCredito): Boton => ({ id, ...CATALOGO[id] })

// "Documentos" es su propio menú (DocumentosCredito), al lado de estas acciones.
const principales = computed<Boton[]>(() => PRINCIPALES.filter((id) => props.acciones.includes(id)).map(boton))

const secundarias = computed<Boton[]>(() => props.acciones.filter((id) => !PRINCIPALES.includes(id)).map(boton))
</script>
