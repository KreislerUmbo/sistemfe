<template>
  <DefaultLayout>
    <div class="creditos-contenedor mx-auto">
      <div class="d-flex align-items-center gap-3 mb-3">
        <button type="button" class="btn btn-light btn-volver" aria-label="Volver" @click="alDetalle">
          <i class="fas fa-chevron-left"></i>
        </button>
        <div class="flex-grow-1 min-w-0">
          <h5 class="fw-bold mb-0">Cobrar</h5>
          <small class="text-muted text-truncate d-block">{{ subtitulo }}</small>
        </div>
      </div>

      <div v-if="cargando" class="text-center py-5 text-muted">
        <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
      </div>
      <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga.mensaje }}</div>
      <div v-else-if="detalle && !cobrable" class="alert alert-info">
        Este crédito está {{ detalle.credito.estado }}: no admite cobros.
        <router-link :to="{ name: 'creditos.detalle', params: { id: detalle.credito.id } }" class="alert-link">Ver crédito</router-link>
      </div>

      <div v-else-if="detalle" class="card border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
          <CobrarPanel :detalle="detalle" :metodos-pago="metodosPago" :caja="caja"
            :puede-fecha-anterior="puede('creditos.pago_fecha_anterior')" :puede-liquidar="puede('creditos.cobrar')"
            @cerrar="alDetalle" @liquidar="liquidarAbierto = true" />
        </div>
      </div>
    </div>

    <DialogoLiquidar v-if="detalle" v-model="liquidarAbierto" :credito-id="detalle.credito.id" :metodos-pago="metodosPago" @hecho="alLiquidar" />
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Cobrar a pantalla completa (04-frontend pantalla 3): la usan el
// celular y la Cobranza del día. En escritorio, el Detalle abre el mismo CobrarPanel
// en un modal (DialogoCobrar).
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import CobrarPanel from '@/components/Creditos/CobrarPanel.vue'
import DialogoLiquidar from '@/components/Creditos/DialogoLiquidar.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import type { DetalleCredito, EstadoCaja, MetodoPago } from '@/types/creditos'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const catalogos = useCreditosCatalogosStore()
const { puede } = usePermisosCredito()

const detalle = ref<DetalleCredito | null>(null)
const metodosPago = ref<MetodoPago[]>([])
const caja = ref<EstadoCaja | null | undefined>(undefined)
const cargando = ref(true)
const errorCarga = ref<ErrorCredito | null>(null)
const liquidarAbierto = ref(false)

const creditoId = computed(() => Number(route.params.id))
const cobrable = computed(() => ['activo', 'castigado'].includes(detalle.value?.credito.estado ?? ''))
const subtitulo = computed(() => {
  const c = detalle.value?.credito
  return c ? [c.cliente?.nombre, c.numero_credito].filter(Boolean).join(' · ') : ''
})

onMounted(async () => {
  try {
    const [d, metodos, estadoCaja] = await Promise.all([
      creditoService.obtener(creditoId.value),
      catalogos.obtenerMetodosPago(),
      creditoService.estadoCaja().catch(() => null),
    ])
    detalle.value = d
    metodosPago.value = metodos
    caja.value = estadoCaja
  } catch (e) {
    errorCarga.value = interpretarErrorCredito(e)
  } finally {
    cargando.value = false
  }
})

function alDetalle() {
  router.replace({ name: 'creditos.detalle', params: { id: creditoId.value } })
}

function alLiquidar() {
  toast.success('Crédito liquidado')
  alDetalle()
}
</script>

<style scoped>
.creditos-contenedor {
  max-width: 1100px;
}
.btn-volver {
  width: 44px;
  height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.min-w-0 {
  min-width: 0;
}
</style>
