<template>
  <DefaultLayout>
    <EncabezadoCredito :titulo="verTodos ? 'Cobranza del día' : 'Mi cobranza del día'" :subtitulo="datos ? fechaLarga(datos.fecha) : ''"
      icono="fas fa-route" :volver="false">
      <span v-if="caja" class="badge" :class="caja.abierta ? 'bg-success' : 'bg-danger'">
        <i class="fas fa-cash-register me-1"></i>{{ caja.abierta ? 'Caja abierta' : 'Caja cerrada' }}
      </span>
      <router-link v-if="puede('cash.open_session')" :to="{ name: 'cash.session' }" class="btn btn-outline-primary btn-sm">
        <i class="fas fa-cash-register me-1"></i>{{ caja?.abierta ? 'Mi caja' : 'Abrir caja' }}
      </router-link>
      <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cargando" title="Actualizar" @click="cargar">
        <i class="fas fa-sync-alt" :class="{ 'fa-spin': cargando }"></i>
      </button>
    </EncabezadoCredito>

    <!-- Resumen -->
    <div class="row g-3 mb-3 cifra">
      <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center py-3 mb-0">
          <div class="fs-4 fw-bold">{{ datos ? formatoSoles(datos.resumen.por_cobrar) : '—' }}</div>
          <small class="text-muted">Por cobrar</small>
        </div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center py-3 mb-0">
          <div class="fs-4 fw-bold text-success">{{ datos ? formatoSoles(datos.resumen.cobrado) : '—' }}</div>
          <small class="text-muted">Cobrado hoy</small>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm py-3 px-3 mb-0 h-100 justify-content-center">
          <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Clientes al día</span>
            <span class="fw-semibold">{{ datos?.resumen.clientes_al_dia ?? 0 }} / {{ datos?.resumen.clientes_total ?? 0 }}</span>
          </div>
          <div class="progress" style="height: 6px" role="progressbar" aria-label="Clientes al día"
            :aria-valuenow="datos?.resumen.clientes_al_dia ?? 0" :aria-valuemax="datos?.resumen.clientes_total ?? 0">
            <div class="progress-bar bg-success" :style="{ width: `${avance}%` }"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12 col-md-5">
            <div class="input-group input-group-sm">
              <input v-model="buscar" type="search" class="form-control" placeholder="Buscar por cliente, dirección o N.º..." aria-label="Buscar en la cobranza" />
              <span class="input-group-text"><i class="fas fa-search"></i></span>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <select v-model="filtro" class="form-select form-select-sm" aria-label="Filtrar">
              <option v-for="f in FILTROS" :key="f.id" :value="f.id">{{ f.texto }} ({{ conteo[f.id] }})</option>
            </select>
          </div>
          <div v-if="datos?.cobradores.length || cobradorId !== null" class="col-6 col-md-4">
            <select v-model="cobradorId" class="form-select form-select-sm" aria-label="Cobrador">
              <option :value="null">Todos los cobradores</option>
              <option :value="0">Sin cobrador</option>
              <option v-for="c in datos?.cobradores ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2 small">
      {{ error.mensaje }}
      <button type="button" class="btn btn-sm btn-danger ms-2" @click="cargar">Reintentar</button>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 cifra">
            <thead class="table-light">
              <tr class="small text-secondary text-uppercase">
                <th class="ps-3">Cliente</th>
                <th v-if="verTodos" class="d-none d-lg-table-cell">Cobrador</th>
                <th>Estado</th>
                <th class="text-end">Monto</th>
                <th class="text-center pe-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="cargando && !datos">
                <td colspan="5" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
              </tr>
              <tr v-else-if="!pendientesVisibles.length && !cobradosVisibles.length">
                <td colspan="5" class="text-center py-5 text-muted fst-italic">
                  {{ buscar ? 'Nadie coincide con la búsqueda.' : filtro === 'cobrados' ? 'Todavía no hay cobros hoy.' : 'No hay cobros pendientes para hoy.' }}
                </td>
              </tr>

              <tr v-for="p in pendientesVisibles" :key="`p${p.credito_id}`">
                <td class="ps-3">
                  <router-link :to="{ name: 'creditos.detalle', params: { id: p.credito_id } }" class="fw-semibold text-reset">{{ p.cliente.nombre }}</router-link>
                  <small class="text-muted d-block">{{ direccion(p) || p.numero_credito }}</small>
                </td>
                <td v-if="verTodos" class="small d-none d-lg-table-cell">{{ p.cobrador?.nombre ?? 'Sin cobrador' }}</td>
                <td class="text-nowrap">
                  <span class="badge" :class="p.estado === 'vencido' ? 'bg-danger' : 'bg-warning text-dark'">{{ textoEstado(p).texto }}</span>
                  <small v-if="abonos.get(p.credito_id)" class="d-block text-muted">Abonó {{ formatoSoles(abonos.get(p.credito_id)) }}</small>
                </td>
                <td class="text-end fw-semibold text-nowrap">{{ formatoSoles(p.exigible_hoy) }}</td>
                <td class="text-center pe-3 text-nowrap">
                  <a v-if="enlaceLlamada(p.cliente.telefono)" :href="enlaceLlamada(p.cliente.telefono)!" class="btn btn-sm btn-outline-secondary me-1"
                    :title="`Llamar ${p.cliente.telefono}`" :aria-label="`Llamar a ${p.cliente.nombre}`"><i class="fas fa-phone"></i></a>
                  <a v-if="enlaceWhatsapp(p.cliente.telefono)" :href="enlaceWhatsapp(p.cliente.telefono)!" target="_blank" rel="noopener"
                    class="btn btn-sm btn-outline-success me-1" title="WhatsApp" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                  <a v-if="enlaceMapa(p.cliente)" :href="enlaceMapa(p.cliente)!" target="_blank" rel="noopener"
                    class="btn btn-sm btn-outline-secondary me-1" title="Abrir mapa" aria-label="Abrir mapa"><i class="fas fa-map-marker-alt"></i></a>
                  <button v-if="puedeCobrar" type="button" class="btn btn-sm btn-success" @click="cobrar(p.credito_id)">
                    <i class="fas fa-money-bill-wave me-1"></i>Cobrar
                  </button>
                </td>
              </tr>

              <tr v-for="c in cobradosVisibles" :key="`c${c.credito_id}`">
                <td class="ps-3">
                  <router-link :to="{ name: 'creditos.detalle', params: { id: c.credito_id } }" class="fw-semibold text-reset">{{ c.cliente.nombre }}</router-link>
                  <small class="text-muted d-block">Cobrado {{ c.ultimo_pago }}{{ c.metodos.length ? ` · ${c.metodos.join(', ')}` : '' }}</small>
                </td>
                <td v-if="verTodos" class="small d-none d-lg-table-cell">{{ c.cobrador?.nombre ?? 'Sin cobrador' }}</td>
                <td><span class="badge bg-success">Cobrado</span></td>
                <td class="text-end fw-semibold text-nowrap">{{ formatoSoles(c.monto_aplicado) }}</td>
                <td class="text-center pe-3">
                  <router-link :to="{ name: 'creditos.detalle', params: { id: c.credito_id } }" class="btn btn-sm btn-outline-primary text-nowrap">
                    <i class="fas fa-arrow-right me-1"></i>Abrir
                  </router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Cobranza del día (04-frontend pantalla 5, mockup 4). El backend decide
// qué ve cada uno (el cobrador, solo su cartera), ordena vencidos → hoy y suma el resumen.
// "Promesas" y "Registrar visita" llegan con las gestiones de cobranza (Fase 8).
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import { creditoService } from '@/services/admin/creditoService'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { coincide, enlaceLlamada, enlaceMapa, enlaceWhatsapp, fechaLarga, textoEstado } from '@/helpers/creditos/cobranza'
import { formatoSoles } from '@/helpers/creditos/formato'
import type { CobranzaDelDia, EstadoCaja, PendienteCobranza } from '@/types/creditos'

type Filtro = 'pendientes' | 'vencidos' | 'hoy' | 'cobrados'
const FILTROS: { id: Filtro; texto: string }[] = [
  { id: 'pendientes', texto: 'Pendientes' },
  { id: 'vencidos', texto: 'Vencidos' },
  { id: 'hoy', texto: 'Vencen hoy' },
  { id: 'cobrados', texto: 'Cobrados' },
]

const router = useRouter()
const { puede } = usePermisosCredito()

const datos = ref<CobranzaDelDia | null>(null)
const caja = ref<EstadoCaja | null>(null)
const cargando = ref(false)
const error = ref<ErrorCredito | null>(null)
const filtro = ref<Filtro>('pendientes')
const buscar = ref('')
const cobradorId = ref<number | null>(null)

const verTodos = computed(() => puede('creditos.ver_todos'))
const puedeCobrar = computed(() => puede('creditos.cobrar'))

const pendientes = computed(() => (datos.value?.data ?? []).filter((p) => coincide(p, buscar.value)))
const cobrados = computed(() => (datos.value?.cobrados ?? []).filter((c) => coincide(c, buscar.value)))
const conteo = computed<Record<Filtro, number>>(() => ({
  pendientes: pendientes.value.length,
  vencidos: pendientes.value.filter((p) => p.estado === 'vencido').length,
  hoy: pendientes.value.filter((p) => p.estado === 'hoy').length,
  cobrados: cobrados.value.length,
}))
const pendientesVisibles = computed(() => {
  if (filtro.value === 'cobrados') return []
  if (filtro.value === 'pendientes') return pendientes.value
  return pendientes.value.filter((p) => p.estado === (filtro.value === 'vencidos' ? 'vencido' : 'hoy'))
})
const cobradosVisibles = computed(() => (filtro.value === 'cobrados' ? cobrados.value : []))
/** Lo abonado hoy a un crédito que todavía debe (monto ya sumado por el backend). */
const abonos = computed(() => new Map((datos.value?.cobrados ?? []).map((c) => [c.credito_id, c.monto_aplicado])))
const avance = computed(() => {
  const r = datos.value?.resumen
  return r && r.clientes_total ? Math.round((r.clientes_al_dia / r.clientes_total) * 100) : 0
})

async function cargar() {
  cargando.value = true
  error.value = null
  try {
    const [respuesta, estadoCaja] = await Promise.all([
      creditoService.cobranzaDelDia(cobradorId.value),
      creditoService.estadoCaja().catch(() => null),
    ])
    datos.value = respuesta
    caja.value = estadoCaja
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
watch(cobradorId, cargar)

function cobrar(creditoId: number) {
  router.push({ name: 'creditos.cobrar', params: { id: creditoId }, query: { volver: 'cobranza' } })
}

function direccion(p: PendienteCobranza): string {
  return [p.cliente.direccion_cobro, p.cliente.referencia].filter(Boolean).join(' · ')
}

</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
