<template>
  <div>
    <!-- Acciones principales visibles (mockup 2): Cobrar, Liquidar, Reprogramar, Documentos -->
    <div class="acciones" :class="vertical ? 'acciones--vertical' : 'acciones--grilla'">
      <button v-for="a in principales" :key="a.id" type="button" class="btn accion"
        :class="a.id === primaria ? 'btn-primary' : 'btn-light border'" :disabled="a.deshabilitada"
        :title="a.ayuda" @click="emit('accion', a.id)">
        <i :class="a.icono"></i>
        <span>{{ a.texto }}</span>
      </button>
    </div>

    <!-- El JS de Bootstrap no se carga en la app (main.ts): el menú usa bootstrap-vue-next. -->
    <b-dropdown v-if="secundarias.length" text="Más acciones" variant="light" class="w-100 mt-2" toggle-class="border w-100 py-2"
      menu-class="w-100">
      <b-dropdown-item-button v-for="a in secundarias" :key="a.id" :variant="a.peligro ? 'danger' : undefined" @click="emit('accion', a.id)">
        <i :class="a.icono" class="me-2"></i>{{ a.texto }}
      </b-dropdown-item-button>
    </b-dropdown>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (04-frontend pantalla 2): visibles Cobrar, Liquidar, Reprogramar y
// Documentos (deshabilitado hasta 4b); el resto en "Más acciones". Qué se ofrece lo
// decide accionesDisponibles() según estado y permisos.
import { computed } from 'vue'
import type { AccionCredito } from '@/composables/creditos/accionesCredito'

export type IdAccion = AccionCredito | 'documentos'

const props = withDefaults(defineProps<{ acciones: AccionCredito[]; vertical?: boolean }>(), { vertical: false })
const emit = defineEmits<{ accion: [id: IdAccion] }>()

interface Boton { id: IdAccion; texto: string; icono: string; deshabilitada?: boolean; ayuda?: string; peligro?: boolean }

const CATALOGO: Record<AccionCredito, Omit<Boton, 'id'>> = {
  editar: { texto: 'Editar', icono: 'fas fa-pen' },
  activar: { texto: 'Activar', icono: 'fas fa-play' },
  cobrar: { texto: 'Cobrar', icono: 'fas fa-money-bill-wave' },
  liquidar: { texto: 'Liquidar', icono: 'fas fa-check-double' },
  reprogramar: { texto: 'Reprogramar', icono: 'far fa-calendar-alt' },
  condonar: { texto: 'Condonar mora', icono: 'fas fa-hand-holding-heart' },
  corregir: { texto: 'Corregir', icono: 'fas fa-wrench' },
  renovar: { texto: 'Renovar', icono: 'fas fa-sync-alt' },
  castigar: { texto: 'Castigar', icono: 'fas fa-gavel', peligro: true },
  revertir_castigo: { texto: 'Revertir castigo', icono: 'fas fa-undo' },
  anular: { texto: 'Anular crédito', icono: 'fas fa-ban', peligro: true },
}

const PRINCIPALES: AccionCredito[] = ['cobrar', 'liquidar', 'reprogramar', 'editar', 'activar']
const boton = (id: AccionCredito): Boton => ({ id, ...CATALOGO[id] })

const primaria = computed<IdAccion | null>(() => (props.acciones.includes('cobrar') ? 'cobrar' : props.acciones.includes('activar') ? 'activar' : null))

const principales = computed<Boton[]>(() => {
  const lista = PRINCIPALES.filter((id) => props.acciones.includes(id)).map(boton)
  if (props.acciones.includes('cobrar')) {
    lista.push({ id: 'documentos', texto: 'Documentos', icono: 'far fa-file-alt', deshabilitada: true, ayuda: 'Disponible pronto (contrato, recibos y estado de cuenta en PDF)' })
  }
  return lista
})

const secundarias = computed<Boton[]>(() => props.acciones.filter((id) => !PRINCIPALES.includes(id)).map(boton))
</script>

<style scoped>
.acciones {
  display: grid;
  gap: 0.5rem;
}
.acciones--grilla {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
.acciones--vertical {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.accion {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  padding: 0.75rem 0.25rem;
  min-height: 64px;
  font-size: 0.8rem;
  font-weight: 600;
  border-radius: 0.75rem;
}
.accion i {
  font-size: 1.1rem;
}
/* 360 px: 4 columnas no alcanzan para "Reprogramar"/"Documentos"; 2 columnas con ícono al lado. */
@media (max-width: 399.98px) {
  .acciones--grilla {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .acciones--grilla .accion {
    flex-direction: row;
    justify-content: center;
    min-height: 48px;
    font-size: 0.85rem;
  }
}
.fila-toque {
  min-height: 44px;
}
</style>
