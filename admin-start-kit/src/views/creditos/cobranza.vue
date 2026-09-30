<template>
  <DefaultLayout>
    <div class="creditos-contenedor mx-auto">
      <!-- Resumen (mockup 4) -->
      <div class="card bg-dark text-white border-0 mb-3">
        <div class="card-body p-3 p-md-4 cifra">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0">
              <div class="small opacity-75">{{ datos ? fechaLarga(datos.fecha) : '' }}</div>
              <h5 class="fw-bold mb-0 text-white">{{ verTodos ? 'Cobranza de hoy' : 'Mi cobranza de hoy' }}</h5>
            </div>
            <span v-if="caja" class="badge rounded-pill px-2 py-1 flex-shrink-0" :class="caja.abierta ? 'bg-success' : 'bg-danger'">
              {{ caja.abierta ? 'Caja abierta' : 'Caja cerrada' }}
            </span>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-6">
              <div class="small opacity-75">Por cobrar</div>
              <div class="fw-bold cifra-grande text-nowrap">{{ datos ? formatoSoles(datos.resumen.por_cobrar) : '—' }}</div>
            </div>
            <div class="col-6">
              <div class="small opacity-75">Cobrado</div>
              <div class="fw-bold cifra-grande text-nowrap text-success">{{ datos ? formatoSoles(datos.resumen.cobrado) : '—' }}</div>
            </div>
          </div>
          <div class="progress bg-secondary bg-opacity-50 mt-2" style="height: 8px" role="progressbar" aria-label="Clientes al día"
            :aria-valuenow="datos?.resumen.clientes_al_dia ?? 0" :aria-valuemax="datos?.resumen.clientes_total ?? 0">
            <div class="progress-bar bg-success" :style="{ width: `${avance}%` }"></div>
          </div>
          <div v-if="datos" class="small opacity-75 mt-1">{{ datos.resumen.clientes_al_dia }} de {{ datos.resumen.clientes_total }} clientes al día</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="d-flex flex-column flex-xl-row gap-2 mb-3">
        <div class="chips d-flex gap-2 overflow-auto pb-1 order-xl-2 flex-xl-grow-1" role="tablist" aria-label="Filtrar">
          <button v-for="f in FILTROS" :key="f.id" type="button" role="tab" :aria-selected="filtro === f.id"
            class="btn btn-sm rounded-pill text-nowrap boton-chip" :class="filtro === f.id ? 'btn-dark' : 'btn-outline-secondary'" @click="filtro = f.id">
            {{ f.texto }}<span class="ms-1 opacity-75">{{ conteo[f.id] }}</span>
          </button>
        </div>
        <div class="d-flex gap-2 order-xl-1">
          <div class="position-relative flex-grow-1 buscador">
            <i class="fas fa-search text-muted icono-buscar"></i>
            <input ref="campoBuscar" v-model="buscar" type="search" class="form-control boton-alto campo-buscar" placeholder="Cliente, dirección o N.º"
              aria-label="Buscar en la cobranza" />
          </div>
          <button type="button" class="btn btn-light boton-icono" aria-label="Actualizar" :disabled="cargando" @click="cargar">
            <i class="fas fa-sync-alt" :class="{ 'fa-spin': cargando }"></i>
          </button>
          <select v-if="datos?.cobradores.length || cobradorId !== null" v-model="cobradorId" class="form-select boton-alto selector-cobrador" aria-label="Cobrador">
            <option :value="null">Todos los cobradores</option>
            <option :value="0">Sin cobrador</option>
            <option v-for="c in datos?.cobradores ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
          </select>
        </div>
      </div>

      <div v-if="error" class="alert alert-danger">
        {{ error.mensaje }}
        <button type="button" class="btn btn-sm btn-danger ms-2" @click="cargar">Reintentar</button>
      </div>
      <div v-else-if="cargando && !datos" class="text-center text-muted py-5">
        <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
      </div>

      <template v-else-if="datos">
        <!-- Celular y tablet: tarjetas -->
        <div class="d-xl-none">
          <div class="row g-2">
            <div v-for="p in pendientesVisibles" :key="`p${p.credito_id}`" class="col-12 col-md-6">
              <div class="card border h-100 mb-0" :class="{ 'borde-vencido': p.estado === 'vencido' }">
                <div class="card-body p-3 cifra d-flex flex-column gap-2">
                  <div class="d-flex justify-content-between gap-2">
                    <div class="min-w-0">
                      <div class="fw-bold">{{ p.cliente.nombre }}</div>
                      <small class="text-muted d-block">{{ direccion(p) }}</small>
                    </div>
                    <div class="text-end flex-shrink-0">
                      <div class="fw-bold fs-5">{{ formatoSoles(p.exigible_hoy) }}</div>
                      <small class="fw-semibold" :class="textoEstado(p).clase">{{ textoEstado(p).texto }}</small>
                      <small v-if="abonos.get(p.credito_id)" class="d-block text-muted">Abonó {{ formatoSoles(abonos.get(p.credito_id)) }}</small>
                    </div>
                  </div>
                  <small v-if="verTodos && p.cobrador" class="text-muted"><i class="fas fa-user me-1"></i>{{ p.cobrador.nombre }}</small>
                  <div class="d-flex gap-2">
                    <a v-if="enlaceLlamada(p.cliente.telefono)" :href="enlaceLlamada(p.cliente.telefono)!" class="btn btn-light boton-icono" :aria-label="`Llamar a ${p.cliente.nombre}`">
                      <i class="fas fa-phone"></i>
                    </a>
                    <a v-if="enlaceMapa(p.cliente)" :href="enlaceMapa(p.cliente)!" target="_blank" rel="noopener" class="btn btn-light boton-icono" aria-label="Abrir mapa">
                      <i class="fas fa-map-marker-alt"></i>
                    </a>
                    <a v-if="enlaceWhatsapp(p.cliente.telefono)" :href="enlaceWhatsapp(p.cliente.telefono)!" target="_blank" rel="noopener" class="btn btn-light boton-icono" aria-label="WhatsApp">
                      <i class="fab fa-whatsapp"></i>
                    </a>
                    <button v-if="puedeCobrar" type="button" class="btn btn-success flex-grow-1 boton-alto fw-bold" @click="cobrar(p.credito_id)">Cobrar</button>
                  </div>
                </div>
              </div>
            </div>
            <div v-for="c in cobradosVisibles" :key="`c${c.credito_id}`" class="col-12 col-md-6">
              <router-link :to="{ name: 'creditos.detalle', params: { id: c.credito_id } }" class="card border h-100 mb-0 tarjeta-cobrada text-reset text-decoration-none">
                <div class="card-body p-3 cifra d-flex justify-content-between align-items-center gap-2">
                  <div class="min-w-0">
                    <div class="fw-bold text-muted">{{ c.cliente.nombre }}</div>
                    <small class="text-muted">Cobrado {{ c.ultimo_pago }}{{ c.metodos.length ? ` · ${c.metodos.join(', ')}` : '' }}</small>
                  </div>
                  <div class="text-end">
                    <div class="fw-bold fs-5 text-muted">{{ formatoSoles(c.monto_aplicado) }}</div>
                    <small class="fw-semibold text-success"><i class="fas fa-check me-1"></i>Cobrado</small>
                  </div>
                </div>
              </router-link>
            </div>
          </div>
        </div>

        <!-- Escritorio: tabla con acciones en la fila -->
        <div class="card border-0 shadow-sm d-none d-xl-block">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 cifra">
              <thead class="table-light">
                <tr>
                  <th>Cliente</th><th>Dirección</th><th v-if="verTodos">Cobrador</th><th>Estado</th>
                  <th class="text-end">Monto</th><th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in pendientesVisibles" :key="`p${p.credito_id}`">
                  <td>
                    <router-link :to="{ name: 'creditos.detalle', params: { id: p.credito_id } }" class="fw-semibold text-reset">{{ p.cliente.nombre }}</router-link>
                    <small class="text-muted d-block">{{ p.numero_credito }}</small>
                  </td>
                  <td class="small">{{ direccion(p) || '—' }}</td>
                  <td v-if="verTodos" class="small">{{ p.cobrador?.nombre ?? 'Sin cobrador' }}</td>
                  <td>
                    <span class="fw-semibold" :class="textoEstado(p).clase">{{ textoEstado(p).texto }}</span>
                    <small v-if="abonos.get(p.credito_id)" class="d-block text-muted">Abonó {{ formatoSoles(abonos.get(p.credito_id)) }}</small>
                  </td>
                  <td class="text-end fw-bold text-nowrap">{{ formatoSoles(p.exigible_hoy) }}</td>
                  <td class="text-end text-nowrap">
                    <a v-if="enlaceLlamada(p.cliente.telefono)" :href="enlaceLlamada(p.cliente.telefono)!" class="btn btn-sm btn-light me-1" :aria-label="`Llamar a ${p.cliente.nombre}`" :title="p.cliente.telefono ?? ''">
                      <i class="fas fa-phone"></i>
                    </a>
                    <a v-if="enlaceWhatsapp(p.cliente.telefono)" :href="enlaceWhatsapp(p.cliente.telefono)!" target="_blank" rel="noopener" class="btn btn-sm btn-light me-1" aria-label="WhatsApp">
                      <i class="fab fa-whatsapp"></i>
                    </a>
                    <a v-if="enlaceMapa(p.cliente)" :href="enlaceMapa(p.cliente)!" target="_blank" rel="noopener" class="btn btn-sm btn-light me-1" aria-label="Abrir mapa">
                      <i class="fas fa-map-marker-alt"></i>
                    </a>
                    <button v-if="puedeCobrar" type="button" class="btn btn-sm btn-success px-3 fw-semibold" @click="cobrar(p.credito_id)">Cobrar</button>
                  </td>
                </tr>
                <tr v-for="c in cobradosVisibles" :key="`c${c.credito_id}`" class="text-muted">
                  <td>
                    <router-link :to="{ name: 'creditos.detalle', params: { id: c.credito_id } }" class="fw-semibold text-reset">{{ c.cliente.nombre }}</router-link>
                    <small class="d-block">{{ c.numero_credito }}</small>
                  </td>
                  <td class="small">Cobrado {{ c.ultimo_pago }}{{ c.metodos.length ? ` · ${c.metodos.join(', ')}` : '' }}</td>
                  <td v-if="verTodos" class="small">{{ c.cobrador?.nombre ?? 'Sin cobrador' }}</td>
                  <td><span class="fw-semibold text-success"><i class="fas fa-check me-1"></i>Cobrado</span></td>
                  <td class="text-end fw-bold text-nowrap">{{ formatoSoles(c.monto_aplicado) }}</td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="!pendientesVisibles.length && !cobradosVisibles.length" class="text-center text-muted py-5">
          <i class="fas fa-check-circle fs-2 d-block mb-2 opacity-50"></i>
          {{ buscar ? 'Nadie coincide con la búsqueda.' : filtro === 'cobrados' ? 'Todavía no hay cobros hoy.' : 'No hay cobros pendientes para hoy.' }}
        </div>
      </template>

      <!-- Celular: acciones al alcance del pulgar -->
      <div class="d-md-none">
        <BarraAccionMovil>
          <button type="button" class="btn btn-outline-dark flex-grow-1 fw-bold" @click="enfocarBusqueda">
            <i class="fas fa-search me-1"></i>Buscar cliente
          </button>
          <router-link v-if="puede('cash.open_session')" :to="{ name: 'cash.session' }" class="btn btn-dark flex-grow-1 fw-bold d-inline-flex align-items-center justify-content-center">
            {{ caja?.abierta ? 'Cerrar mi caja' : 'Abrir caja' }}
          </router-link>
        </BarraAccionMovil>
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
import BarraAccionMovil from '@/components/Creditos/BarraAccionMovil.vue'
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
const campoBuscar = ref<HTMLInputElement | null>(null)

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

function enfocarBusqueda() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
  campoBuscar.value?.focus()
}
</script>

<style scoped>
.creditos-contenedor {
  max-width: 1440px;
}
.cifra {
  font-variant-numeric: tabular-nums;
}
.cifra-grande {
  font-size: 1.35rem;
}
@media (min-width: 768px) {
  .cifra-grande {
    font-size: 1.6rem;
  }
}
.min-w-0 {
  min-width: 0;
}
.boton-alto {
  min-height: 44px;
}
.boton-icono {
  width: 44px;
  height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.boton-chip {
  min-height: 40px;
  padding-inline: 0.9rem;
}
.chips {
  scrollbar-width: none;
}
.buscador {
  min-width: 0;
}
@media (min-width: 1200px) {
  .buscador {
    width: 320px;
    flex-grow: 0 !important;
  }
}
.campo-buscar {
  padding-left: 2.6rem;
}
.icono-buscar {
  position: absolute;
  left: 1rem;
  top: 50%;
  transform: translateY(-50%);
}
.selector-cobrador {
  max-width: 220px;
}
.borde-vencido {
  border-left: 4px solid var(--bs-danger) !important;
}
.tarjeta-cobrada {
  background: var(--bs-success-bg-subtle);
}
</style>
