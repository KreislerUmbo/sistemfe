<template>
  <DefaultLayout>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
      <div>
        <h5 class="fw-bold mb-0 text-dark"><i class="iconoir-home-simple me-2 text-primary"></i>Inicio</h5>
        <small v-if="datos" class="text-muted">Hoy, {{ fechaTexto(datos.hoy) }}</small>
      </div>
      <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cargando" title="Actualizar" aria-label="Actualizar" @click="cargar">
        <i class="fas fa-sync-alt" :class="{ 'fa-spin': cargando }"></i>
      </button>
    </div>

    <div v-if="error" class="alert alert-danger py-2 small">
      No se pudo cargar el inicio.
      <button type="button" class="btn btn-sm btn-danger ms-2" @click="cargar">Reintentar</button>
    </div>
    <div v-if="cargando && !datos" class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
    </div>
    <div v-else-if="datos && sinBloques" class="card border-0 shadow-sm">
      <div class="card-body text-center py-5 text-muted">
        <i class="iconoir-home-simple fs-2 d-block mb-2"></i>
        Bienvenido. Usa el menú de la izquierda para empezar.
      </div>
    </div>

    <template v-if="b">
      <!-- Indicadores -->
      <div v-if="b.ventas || b.por_cobrar || b.sunat" class="row g-3 mb-3 cifra">
        <div v-if="b.ventas" class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Ventas de hoy</small>
              <template v-if="b.ventas.hoy.length">
                <div v-for="v in b.ventas.hoy" :key="v.moneda" class="mt-1">
                  <div class="fs-4 fw-bold">{{ formatoMoneda(v.neto, v.moneda) }}</div>
                  <small class="text-muted d-block">
                    {{ v.comprobantes }} comprobante(s) · ticket {{ formatoMoneda(v.ticket_promedio, v.moneda) }}
                  </small>
                  <small v-if="v.notas_credito_cantidad" class="text-danger d-block">− {{ formatoMoneda(v.notas_credito, v.moneda) }} en notas de crédito</small>
                </div>
              </template>
              <div v-else class="fs-4 fw-bold mt-1 text-muted">{{ formatoMoneda(0) }}</div>
            </div>
          </div>
        </div>
        <div v-if="b.ventas" class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Ventas del mes</small>
              <template v-if="b.ventas.mes.length">
                <div v-for="v in b.ventas.mes" :key="v.moneda" class="mt-1">
                  <div class="d-flex align-items-baseline gap-2 flex-wrap">
                    <span class="fs-4 fw-bold">{{ formatoMoneda(v.neto, v.moneda) }}</span>
                    <small v-if="textoVariacion(v.variacion)" class="fw-semibold" :class="textoVariacion(v.variacion)!.clase"
                      :title="`Mes anterior al mismo día: ${formatoMoneda(v.neto_mes_anterior, v.moneda)}`">{{ textoVariacion(v.variacion)!.texto }}</small>
                  </div>
                  <small class="text-muted d-block">
                    Bruto {{ formatoMoneda(v.bruto, v.moneda) }}<template v-if="v.internos"> · {{ formatoMoneda(v.interno, v.moneda) }} en notas de venta</template>
                  </small>
                </div>
              </template>
              <div v-else class="fs-4 fw-bold mt-1 text-muted">{{ formatoMoneda(0) }}</div>
            </div>
          </div>
        </div>
        <div v-if="b.por_cobrar" class="col-12 col-sm-6 col-xl-3">
          <router-link :to="{ name: 'credit_receivables.index' }" class="card border-0 shadow-sm h-100 mb-0 text-reset tarjeta-enlace">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Por cobrar</small>
              <template v-if="b.por_cobrar.por_moneda.length">
                <div v-for="c in b.por_cobrar.por_moneda" :key="c.moneda" class="mt-1">
                  <div class="fs-4 fw-bold">{{ formatoMoneda(c.saldo, c.moneda) }}</div>
                  <small class="text-muted d-block">{{ c.ventas }} venta(s) de {{ c.clientes }} cliente(s)</small>
                  <small v-if="c.vencidas" class="text-danger d-block">{{ formatoMoneda(c.saldo_vencido, c.moneda) }} vencido en {{ c.vencidas }} venta(s)</small>
                </div>
              </template>
              <div v-else class="mt-1"><span class="fs-4 fw-bold text-muted">{{ formatoMoneda(0) }}</span><small class="text-muted d-block">Nada pendiente de cobro.</small></div>
            </div>
          </router-link>
        </div>
        <div v-if="b.sunat" class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Pendientes SUNAT</small>
              <div class="fs-4 fw-bold mt-1" :class="totalSunat ? 'text-warning' : 'text-success'">
                <i class="fas me-1" :class="totalSunat ? 'fa-exclamation-triangle' : 'fa-check'"></i>{{ totalSunat || 'Al día' }}
              </div>
              <small class="text-muted d-block">Comprobantes: {{ b.sunat.por_enviar }} por enviar · {{ b.sunat.con_error }} rechazado(s) o con error</small>
              <small v-if="b.sunat.notas && (b.sunat.notas.pendientes || b.sunat.notas.rechazadas)" class="d-block">
                <router-link :to="{ name: 'nota.list' }" class="text-danger">Notas: {{ b.sunat.notas.pendientes }} pendiente(s), {{ b.sunat.notas.rechazadas }} rechazada(s)</router-link>
              </small>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <!-- Ventas por día -->
        <div v-if="b.ventas_dias" class="col-12 col-xl-8">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
              <h6 class="card-title mb-0">Ventas de los últimos 30 días <small class="text-muted fw-normal">(bruto, por fecha de emisión)</small></h6>
              <div class="d-flex gap-2 align-items-center">
                <div v-if="b.ventas_dias.monedas.length > 1" class="btn-group btn-group-sm" role="group" aria-label="Moneda">
                  <button v-for="m in b.ventas_dias.monedas" :key="m" type="button" class="btn"
                    :class="monedaGrafico === m ? 'btn-primary' : 'btn-outline-primary'" @click="monedaGrafico = m">{{ m }}</button>
                </div>
                <button type="button" class="btn btn-link btn-sm p-0" @click="verTabla = !verTabla">{{ verTabla ? 'Ver gráfico' : 'Ver tabla' }}</button>
              </div>
            </div>
            <div class="card-body">
              <div v-if="!b.ventas_dias.monedas.length" class="text-center text-muted fst-italic py-5">Sin ventas en los últimos 30 días.</div>
              <ApexChart v-else-if="!verTabla" :chart="grafico" />
              <div v-else class="table-responsive tabla-alta">
                <table class="table table-sm mb-0 cifra">
                  <thead class="table-light"><tr class="small text-secondary"><th>Día</th><th v-for="m in b.ventas_dias.monedas" :key="m" class="text-end">{{ m }}</th></tr></thead>
                  <tbody>
                    <tr v-for="d in [...b.ventas_dias.dias].reverse()" :key="d.fecha">
                      <td>{{ formatoFecha(d.fecha) }}</td>
                      <td v-for="m in b.ventas_dias.monedas" :key="m" class="text-end">{{ formatoMoneda(d.montos[m], m) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Comprobantes por atender -->
        <div v-if="b.sunat" class="col-12 col-xl-4">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
              <h6 class="card-title mb-0">Comprobantes por atender</h6>
              <router-link :to="{ name: 'sale.list' }" class="small">Ir a ventas</router-link>
            </div>
            <div class="card-body p-0">
              <div v-if="!b.sunat.lista.length" class="text-center text-muted fst-italic py-5 px-3">Todos los comprobantes fueron aceptados por SUNAT.</div>
              <ul v-else class="list-group list-group-flush">
                <li v-for="s in b.sunat.lista" :key="s.id" class="list-group-item d-flex justify-content-between align-items-start gap-2 cifra">
                  <div class="min-w-0 flex-grow-1">
                    <div class="fw-semibold text-truncate">{{ s.cliente ?? 'Sin cliente' }}</div>
                    <small class="text-muted">
                      {{ s.comprobante ?? `Venta #${s.id}` }}<template v-if="s.adelanto"> · adelanto</template> · {{ formatoFecha(s.fecha) }}
                    </small>
                  </div>
                  <div class="text-end text-nowrap">
                    <div class="small fw-semibold">{{ formatoMoneda(s.total, s.moneda) }}</div>
                    <span class="badge" :class="s.estado === 'error' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning'"
                      :title="s.error ?? ''">{{ s.estado === 'error' ? 'Con error' : 'Por enviar' }}</span>
                  </div>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Retail: productos -->
      <div v-if="b.productos" class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-header bg-transparent"><h6 class="card-title mb-0">Más vendidos del mes <small class="text-muted fw-normal">(por cantidad)</small></h6></div>
            <div class="card-body p-0">
              <div v-if="!b.productos.top.length" class="text-center text-muted fst-italic py-4">Sin ventas de productos este mes.</div>
              <table v-else class="table align-middle mb-0 cifra">
                <tbody>
                  <tr v-for="(p, i) in b.productos.top" :key="p.id">
                    <td class="ps-3 text-muted" style="width: 2rem">{{ i + 1 }}</td>
                    <td class="text-truncate" style="max-width: 0">{{ p.producto }}</td>
                    <td class="text-end text-nowrap">{{ p.cantidad }} und.</td>
                    <td class="text-end pe-3 text-nowrap text-muted small">{{ p.ventas }} venta(s)</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
              <h6 class="card-title mb-0">Sin stock <span v-if="b.productos.sin_stock" class="badge bg-danger-subtle text-danger ms-1">{{ b.productos.sin_stock }}</span></h6>
              <router-link :to="{ name: 'product.index' }" class="small">Ver productos</router-link>
            </div>
            <div class="card-body p-0">
              <div v-if="!b.productos.sin_stock" class="text-center text-muted fst-italic py-4">Todos los productos con control de stock tienen existencias.</div>
              <ul v-else class="list-group list-group-flush">
                <li v-for="p in b.productos.sin_stock_lista" :key="p.id" class="list-group-item d-flex justify-content-between gap-2 cifra">
                  <span class="text-truncate">{{ p.producto }}</span>
                  <span class="text-danger text-nowrap">{{ p.stock }}</span>
                </li>
                <li v-if="b.productos.sin_stock > b.productos.sin_stock_lista.length" class="list-group-item small text-muted">
                  y {{ b.productos.sin_stock - b.productos.sin_stock_lista.length }} más
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Agencia: cotizaciones y próximos días -->
      <div v-if="b.cotizaciones || b.proximos" class="row g-3 mb-3">
        <div v-if="b.cotizaciones" class="col-12 col-lg-4">
          <router-link :to="{ name: 'agencia.cotizador.index' }" class="card border-0 shadow-sm h-100 mb-0 text-reset tarjeta-enlace">
            <div class="card-body cifra">
              <small class="text-muted text-uppercase fw-semibold">Cotizaciones del mes</small>
              <div class="fs-4 fw-bold mt-1">{{ b.cotizaciones.creadas }}</div>
              <small class="text-muted d-block">{{ b.cotizaciones.reservadas }} reservada(s) · {{ b.cotizaciones.conversion }} % de conversión</small>
              <small class="text-muted d-block">{{ b.cotizaciones.enviadas }} enviada(s) esperando respuesta · {{ b.cotizaciones.borrador }} en borrador</small>
            </div>
          </router-link>
        </div>
        <div v-if="b.proximos" class="col-12" :class="b.cotizaciones ? 'col-lg-8' : ''">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
              <h6 class="card-title mb-0">Próximos 7 días</h6>
              <router-link v-if="b.proximos.sin_asignar" :to="{ name: 'agencia.reporteOperativo.index' }" class="badge bg-danger-subtle text-danger text-decoration-none">
                <i class="fas fa-exclamation-circle me-1"></i>{{ b.proximos.sin_asignar }} servicio(s) sin guía o proveedor
              </router-link>
            </div>
            <div class="card-body p-0">
              <div class="row g-0">
                <div class="col-12 col-md-6 border-end-md">
                  <div class="px-3 pt-3 pb-1 small text-muted text-uppercase fw-semibold">Viajes que empiezan ({{ b.proximos.viajes_total }})</div>
                  <div v-if="!b.proximos.viajes.length" class="px-3 pb-3 text-muted fst-italic small">Ningún viaje en los próximos días.</div>
                  <ul v-else class="list-group list-group-flush">
                    <li v-for="v in b.proximos.viajes" :key="v.id" class="list-group-item">
                      <router-link :to="{ name: 'agencia.reservas.detalle', params: { id: v.id } }" class="fw-semibold text-reset">{{ v.cliente ?? 'Sin cliente' }}</router-link>
                      <small class="text-muted d-block">{{ v.codigo ? `${v.codigo} · ` : '' }}{{ formatoFecha(v.desde) }} · {{ v.pasajeros }} pasajero(s)</small>
                    </li>
                  </ul>
                </div>
                <div class="col-12 col-md-6">
                  <div class="px-3 pt-3 pb-1 small text-muted text-uppercase fw-semibold">Salidas operativas</div>
                  <div v-if="!b.proximos.salidas.length" class="px-3 pb-3 text-muted fst-italic small">Sin salidas programadas.</div>
                  <ul v-else class="list-group list-group-flush">
                    <li v-for="s in b.proximos.salidas" :key="s.id" class="list-group-item">
                      <router-link :to="{ name: 'agencia.salidas.detalle', params: { id: s.id } }" class="fw-semibold text-reset">{{ s.tour ?? 'Salida' }}</router-link>
                      <small class="text-muted d-block">
                        {{ formatoFecha(s.fecha) }}{{ s.hora ? ` ${s.hora}` : '' }} · {{ s.pasajeros }}{{ s.cupo ? `/${s.cupo}` : '' }} pasajero(s)
                        · <span :class="{ 'text-danger': !s.guia }">{{ s.guia ?? 'sin guía' }}</span>
                      </small>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Caja -->
      <div v-if="cajas" class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
          <h6 class="card-title mb-0">Cajas <small class="text-muted fw-normal">{{ cajas.summary.with_open_session }} de {{ cajas.summary.total_active_registers }} abiertas</small></h6>
          <router-link :to="{ name: 'cash.dashboard' }" class="small">Historial y reportes</router-link>
        </div>
        <div class="card-body p-0">
          <ul class="list-group list-group-flush">
            <li v-for="c in cajas.registers" :key="c.cash_register_id" class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap">
              <span><i class="fas fa-cash-register me-2 text-muted"></i>{{ c.cash_register_name }}<small v-if="c.branch" class="text-muted"> · {{ c.branch.name }}</small></span>
              <span v-if="c.has_open_session" class="small">
                Abierta por {{ c.opened_by_user?.name ?? '—' }}
                <span v-if="c.is_stale" class="badge bg-warning-subtle text-warning ms-1">Abierta hace {{ Math.floor(c.elapsed_hours ?? 0) }} h</span>
              </span>
              <span v-else class="badge bg-secondary-subtle text-secondary">Cerrada</span>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Inicio con datos reales del tenant (retail y agencia de viajes; créditos tiene su Panel y la
// ruta lo redirige). El backend arma cada bloque según el giro y los permisos y suma los montos
// por moneda: aquí solo se muestran.
import { computed, onMounted, ref, watch } from 'vue'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import ApexChart from '@/components/ApexChart.vue'
import { dashboardService } from '@/services/admin/dashboardService'
import { useAuthStore } from '@/stores/auth'
import { useLayoutStore } from '@/stores/layout'
import { fechaTexto, formatoMoneda, textoVariacion } from '@/helpers/dashboard'
import { formatoFecha } from '@/helpers/creditos/formato'
import type { ApexChartType } from '@/types'
import type { CashDashboardResponse } from '@/types/cash-session'
import type { Dashboard } from '@/types/dashboard'

const auth = useAuthStore()
const layout = useLayoutStore()

const datos = ref<Dashboard | null>(null)
const cajas = ref<CashDashboardResponse | null>(null)
const cargando = ref(false)
const error = ref(false)
const verTabla = ref(false)
const monedaGrafico = ref('PEN')

const b = computed(() => datos.value?.bloques ?? null)
const sinBloques = computed(() => !b.value || (!Object.keys(b.value).length && !cajas.value))
// Comprobantes y notas por atender: "Al día" solo si no queda nada de ninguno.
const totalSunat = computed(() => {
  const s = b.value?.sunat
  if (!s) return 0
  return s.por_enviar + s.con_error + (s.notas ? s.notas.pendientes + s.notas.rechazadas : 0)
})

async function cargar() {
  cargando.value = true
  error.value = false
  try {
    const [inicio, caja] = await Promise.all([
      dashboardService.inicio(),
      auth.isPermitedRoute('cash.view_all') ? dashboardService.cajas().catch(() => null) : Promise.resolve(null),
    ])
    datos.value = inicio
    cajas.value = caja
  } catch {
    error.value = true
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
// Soles por defecto; si solo hay ventas en otra moneda, esa.
watch(() => b.value?.ventas_dias?.monedas, (monedas) => {
  if (monedas?.length && !monedas.includes(monedaGrafico.value)) monedaGrafico.value = monedas[0]
})

// Una serie (la moneda elegida): monedas distintas no comparten eje. Verde validado para
// fondo claro y oscuro (mismo que el Panel de créditos).
const grafico = computed<ApexChartType>(() => {
  const oscuro = layout.layout.theme === 'dark'
  const tinta = oscuro ? '#9ca3af' : '#6b7280'
  const dias = b.value?.ventas_dias?.dias ?? []
  const moneda = monedaGrafico.value
  return {
    height: 280,
    type: 'bar',
    series: [{ name: `Ventas ${moneda}`, data: dias.map((d) => Number(d.montos[moneda] ?? 0)) }],
    options: {
      chart: { toolbar: { show: false }, fontFamily: 'inherit', foreColor: tinta },
      colors: ['#16a34a'],
      plotOptions: { bar: { columnWidth: '60%', borderRadius: 3, borderRadiusApplication: 'end' } },
      dataLabels: { enabled: false },
      legend: { show: false },
      grid: { borderColor: oscuro ? '#2f3542' : '#eef0f3', strokeDashArray: 3, xaxis: { lines: { show: false } } },
      xaxis: {
        categories: dias.map((d) => formatoFecha(d.fecha).slice(0, 5)),
        tickAmount: 10,
        labels: { rotate: 0, hideOverlappingLabels: true },
        axisBorder: { show: false },
        axisTicks: { show: false },
      },
      yaxis: { labels: { formatter: (v: number) => formatoMoneda(v, moneda).replace('.00', '') } },
      tooltip: {
        theme: oscuro ? 'dark' : 'light',
        x: { formatter: (_v: number, o?: { dataPointIndex: number }) => (o ? formatoFecha(dias[o.dataPointIndex]?.fecha) : '') },
        y: { formatter: (v: number) => formatoMoneda(v, moneda) },
      },
    },
  }
})
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
/* Deja que el texto largo se trunque dentro de un flex en vez de empujar el monto fuera. */
.min-w-0 {
  min-width: 0;
}
.tarjeta-enlace {
  text-decoration: none;
  transition: box-shadow 0.15s ease;
}
.tarjeta-enlace:hover {
  box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.08) !important;
}
.tabla-alta {
  max-height: 300px;
  overflow-y: auto;
}
@media (min-width: 768px) {
  .border-end-md {
    border-right: 1px solid var(--bs-border-color);
  }
}
</style>
