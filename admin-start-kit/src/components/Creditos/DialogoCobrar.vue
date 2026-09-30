<template>
  <Teleport to="body">
    <b-modal v-model="abierto" :title="titulo" size="xl" centered scrollable hide-footer no-close-on-backdrop body-class="p-3 p-md-4"
      @hidden="alOcultar">
      <CobrarPanel v-if="abierto || cobrado" :key="version" :detalle="detalle" :metodos-pago="metodosPago" :caja="caja"
        :puede-fecha-anterior="puedeFechaAnterior" :puede-liquidar="puedeLiquidar"
        @hecho="cobrado = true" @cerrar="abierto = false" @liquidar="irALiquidar" />
    </b-modal>
  </Teleport>
</template>

<script setup lang="ts">
// Módulo Créditos (04-frontend pantalla 3, escritorio): Cobrar como modal ancho de 2
// columnas sobre el Detalle. Al cerrarse tras un cobro, avisa al Detalle para recargar.
import { computed, ref, watch } from 'vue'
import CobrarPanel from './CobrarPanel.vue'
import { creditoService } from '@/services/admin/creditoService'
import type { DetalleCredito, EstadoCaja, MetodoPago } from '@/types/creditos'

const props = defineProps<{ detalle: DetalleCredito; metodosPago: MetodoPago[]; puedeFechaAnterior: boolean; puedeLiquidar: boolean }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: []; liquidar: [] }>()

const caja = ref<EstadoCaja | null | undefined>(undefined)
const cobrado = ref(false)
const version = ref(0)
let liquidarAlCerrar = false

const titulo = computed(() => `Cobrar · ${[props.detalle.credito.cliente?.nombre, props.detalle.credito.numero_credito].filter(Boolean).join(' · ')}`)

watch(abierto, async (valor) => {
  if (!valor) return
  version.value++
  cobrado.value = false
  caja.value = undefined
  caja.value = await creditoService.estadoCaja().catch(() => null)
})

function irALiquidar() {
  liquidarAlCerrar = true
  abierto.value = false
}

function alOcultar() {
  if (cobrado.value) emit('hecho')
  cobrado.value = false
  if (liquidarAlCerrar) {
    liquidarAlCerrar = false
    emit('liquidar')
  }
}
</script>
