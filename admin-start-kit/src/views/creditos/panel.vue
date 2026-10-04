<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Panel" :subtitulo="datos ? `Situación al ${fechaLarga(datos.corte).toLowerCase()}` : ''" icono="fas fa-chart-line" :volver="false">
      <router-link v-if="puede('creditos.cobrar')" :to="{ name: 'creditos.cobranza' }" class="btn btn-primary btn-sm">
        <i class="fas fa-route me-1"></i>Cobranza del día
      </router-link>
      <router-link v-if="puede('creditos.cobrar')" :to="{ name: 'creditos.agenda' }" class="btn btn-outline-primary btn-sm">
        <i class="far fa-calendar-alt me-1"></i>Agenda
      </router-link>
      <router-link v-if="puede('creditos.reportes')" :to="{ name: 'creditos.reportes' }" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-file-alt me-1"></i>Reportes
      </router-link>
      <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cargando" title="Actualizar" aria-label="Actualizar" @click="cargar">
        <i class="fas fa-sync-alt" :class="{ 'fa-spin': cargando }"></i>
      </button>
    </EncabezadoCredito>

    <div v-if="error" class="alert alert-danger py-2 small">
      {{ error.mensaje }}
      <button type="button" class="btn btn-sm btn-danger ms-2" @click="cargar">Reintentar</button>
    </div>

    <div v-if="cargando && !datos" class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
    </div>

    <template v-if="datos">
      <!-- Indicadores -->
      <div class="row g-3 mb-3 cifra">
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Cartera vigente</small>
              <div class="fs-4 fw-bold mt-1">{{ formatoSoles(datos.cartera.saldo_capital) }}</div>
              <small class="text-muted">Saldo de capital · {{ datos.cartera.creditos }} crédito(s), {{ datos.cartera.clientes }} cliente(s)</small>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Cartera en riesgo</small>
              <div class="fs-4 fw-bold mt-1" :class="{ 'text-danger': datos.riesgo.creditos > 0 }">{{ datos.riesgo.porcentaje }} %</div>
              <small class="text-muted">{{ formatoSoles(datos.riesgo.saldo_capital) }} en {{ datos.riesgo.creditos }} crédito(s) atrasados más allá de la gracia</small>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Cobranza de hoy</small>
              <div class="fs-4 fw-bold mt-1 text-success">{{ formatoSoles(datos.cobranza_hoy.cobrado) }}</div>
              <small class="text-muted d-block mb-2">de {{ formatoSoles(datos.cobranza_hoy.por_cobrar) }} por cobrar ({{ datos.cobranza_hoy.porcentaje }} %)</small>
              <div class="progress" style="height: 6px" role="progressbar" aria-label="Avance de la cobranza de hoy"
                :aria-valuenow="Number(datos.cobranza_hoy.porcentaje)" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-success" :style="{ width: `${Math.min(100, Number(datos.cobranza_hoy.porcentaje))}%` }"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-body">
              <small class="text-muted text-uppercase fw-semibold">Conciliación de hoy</small>
              <div class="mt-1">
                <span v-if="datos.conciliacion.cuadra" class="badge bg-success-subtle text-success fs-6"><i class="fas fa-check me-1"></i>Cuadra</span>
                <span v-else class="badge bg-danger-subtle text-danger fs-6"><i class="fas fa-exclamation-triangle me-1"></i>Diferencia {{ formatoSoles(datos.conciliacion.diferencia) }}</span>
              </div>
              <small class="text-muted d-block mt-2">Cobros {{ formatoSoles(datos.conciliacion.creditos) }} · Caja {{ formatoSoles(datos.conciliacion.caja) }}</small>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- Cobrado por día -->
        <div class="col-12 col-xl-7">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
              <h6 class="card-title mb-0">Cobrado en los últimos 14 días</h6>
              <button type="button" class="btn btn-link btn-sm p-0" @click="verTabla = !verTabla">
                {{ verTabla ? 'Ver gráfico' : 'Ver tabla' }}
              </button>
            </div>
            <div class="card-body">
              <ApexChart v-if="!verTabla" :chart="grafico" />
              <div v-else class="table-responsive">
                <table class="table table-sm mb-0 cifra">
                  <thead class="table-light"><tr class="small text-secondary"><th>Día</th><th class="text-end">Cobrado</th></tr></thead>
                  <tbody>
                    <tr v-for="d in datos.cobrado_dias" :key="d.fecha"><td>{{ formatoFecha(d.fecha) }}</td><td class="text-end">{{ formatoSoles(d.monto) }}</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Más atrasados -->
        <div class="col-12 col-xl-5">
          <div class="card border-0 shadow-sm h-100 mb-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
              <h6 class="card-title mb-0">Los más atrasados</h6>
              <router-link v-if="puede('creditos.reportes')" :to="{ name: 'creditos.reportes', query: { reporte: 'morosidad' } }" class="small">Ver morosidad</router-link>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 cifra">
                  <thead class="table-light">
                    <tr class="small text-secondary text-uppercase">
                      <th class="ps-3">Cliente</th>
                      <th class="text-end">Atraso</th>
                      <th class="text-end pe-3">Saldo</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="!datos.mas_atrasados.length">
                      <td colspan="3" class="text-center py-4 text-muted fst-italic">Ningún crédito atrasado.</td>
                    </tr>
                    <tr v-for="f in datos.mas_atrasados" :key="f.credito_id">
                      <td class="ps-3">
                        <router-link :to="{ name: 'creditos.detalle', params: { id: f.credito_id } }" class="fw-semibold text-reset">{{ f.cliente }}</router-link>
                        <small class="text-muted d-block">{{ f.numero_credito }}</small>
                      </td>
                      <td class="text-end text-nowrap"><span class="badge bg-danger-subtle text-danger">{{ f.dias_atraso }} día{{ f.dias_atraso === 1 ? '' : 's' }}</span></td>
                      <td class="text-end pe-3 text-nowrap">
                        {{ formatoSoles(f.saldo_capital) }}
                        <small v-if="Number(f.mora) > 0" class="d-block text-danger">Mora {{ formatoSoles(f.mora) }}</small>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Panel de inicio del giro créditos (04d). Todo llega calculado del backend
// (mismo alcance de cartera que la Cobranza del día); aquí solo se muestra.
import { computed, onMounted, ref } from 'vue'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import ApexChart from '@/components/ApexChart.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import { creditoService } from '@/services/admin/creditoService'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { fechaLarga } from '@/helpers/creditos/cobranza'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import { useLayoutStore } from '@/stores/layout'
import type { ApexChartType } from '@/types'
import type { PanelCreditos } from '@/types/creditos'

const { puede } = usePermisosCredito()
const layout = useLayoutStore()

const datos = ref<PanelCreditos | null>(null)
const cargando = ref(false)
const error = ref<ErrorCredito | null>(null)
const verTabla = ref(false)

async function cargar() {
  cargando.value = true
  error.value = null
  try {
    datos.value = await creditoService.panel()
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

// Una sola serie: sin leyenda (el título la nombra); verde validado para fondo claro y oscuro.
const grafico = computed<ApexChartType>(() => {
  const oscuro = layout.layout.theme === 'dark'
  const tinta = oscuro ? '#9ca3af' : '#6b7280'
  const dias = datos.value?.cobrado_dias ?? []
  return {
    height: 260,
    type: 'bar',
    series: [{ name: 'Cobrado', data: dias.map((d) => Number(d.monto)) }],
    options: {
      chart: { toolbar: { show: false }, fontFamily: 'inherit', foreColor: tinta },
      colors: ['#16a34a'],
      plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
      dataLabels: { enabled: false },
      legend: { show: false },
      stroke: { show: true, width: 2, colors: ['transparent'] },
      grid: { borderColor: oscuro ? '#2f3542' : '#eef0f3', strokeDashArray: 3, yaxis: { lines: { show: true } }, xaxis: { lines: { show: false } } },
      xaxis: {
        categories: dias.map((d) => formatoFecha(d.fecha).slice(0, 5)),
        axisBorder: { show: false },
        axisTicks: { show: false },
      },
      yaxis: { labels: { formatter: (v: number) => formatoSoles(v).replace('.00', '') } },
      tooltip: {
        theme: oscuro ? 'dark' : 'light',
        x: { formatter: (_v: number, o?: { dataPointIndex: number }) => (o ? formatoFecha(dias[o.dataPointIndex]?.fecha) : '') },
        y: { formatter: (v: number) => formatoSoles(v) },
      },
    },
  }
})
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
