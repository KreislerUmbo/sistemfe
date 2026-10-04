<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Reportes de créditos" :subtitulo="subtitulo" icono="fas fa-file-alt" :volver="false">
      <button type="button" class="btn btn-outline-danger btn-sm" :disabled="!datosActual || exportando" @click="exportarPdf">
        <i class="fas fa-file-pdf me-1"></i>PDF
      </button>
      <button type="button" class="btn btn-outline-success btn-sm" :disabled="!datosActual || exportando" @click="exportarExcel">
        <i class="fas fa-file-excel me-1"></i>Excel
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cargando" title="Actualizar" aria-label="Actualizar" @click="cargar">
        <i class="fas fa-sync-alt" :class="{ 'fa-spin': cargando }"></i>
      </button>
    </EncabezadoCredito>

    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
      <li v-for="p in pestanas" :key="p.id" class="nav-item" role="presentation">
        <button type="button" class="nav-link text-nowrap" :class="{ active: reporte === p.id }" role="tab" :aria-selected="reporte === p.id" @click="reporte = p.id">
          <i :class="p.icono" class="me-1"></i>{{ p.texto }}
        </button>
      </li>
    </ul>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <template v-if="conPeriodo">
            <div class="col-12 col-lg-auto">
              <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Período">
                <button v-for="r in RANGOS_PERIODO" :key="r.id" type="button" class="btn"
                  :class="rangoPeriodo === r.id ? 'btn-primary' : 'btn-outline-primary'" @click="elegirPeriodo(r.id)">{{ r.texto }}</button>
              </div>
            </div>
            <template v-if="rangoPeriodo === 'rango'">
              <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1" for="rep-desde">Desde</label>
                <CampoFecha id="rep-desde" v-model="desde" :max="hoy" etiqueta="la fecha inicial" />
              </div>
              <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1" for="rep-hasta">Hasta</label>
                <CampoFecha id="rep-hasta" v-model="hasta" :min="desde" :max="hoy" etiqueta="la fecha final" />
              </div>
            </template>
          </template>

          <template v-if="reporte === 'cartera'">
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1" for="rep-estado">Estado</label>
              <select id="rep-estado" v-model="estado" class="form-select form-select-sm">
                <option value="">Activos y castigados</option>
                <option value="activo">Activos</option>
                <option value="castigado">Castigados</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1" for="rep-rango">Atraso</label>
              <select id="rep-rango" v-model="rangoAtraso" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option v-for="(texto, id) in TEXTOS_RANGO" :key="id" :value="id">{{ texto }}</option>
              </select>
            </div>
            <div v-if="(cartera?.opciones.asesores.length ?? 0) > 1 || asesorId !== null" class="col-6 col-md-3">
              <label class="form-label small mb-1" for="rep-asesor">Asesor</label>
              <select id="rep-asesor" v-model="asesorId" class="form-select form-select-sm">
                <option :value="null">Todos</option>
                <option v-for="a in cartera?.opciones.asesores ?? []" :key="a.id" :value="a.id">{{ a.nombre }}</option>
              </select>
            </div>
          </template>

          <div v-if="reporte === 'ingresos'" class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="rep-agrupacion">Agrupar por</label>
            <select id="rep-agrupacion" v-model="agrupacion" class="form-select form-select-sm">
              <option value="dia">Día</option>
              <option value="semana">Semana</option>
              <option value="mes">Mes</option>
            </select>
          </div>

          <div v-if="reporte === 'control'" class="col-12 col-md-4 col-lg-3">
            <label class="form-label small mb-1" for="rep-accion">Acción</label>
            <select id="rep-accion" v-model="accion" class="form-select form-select-sm">
              <option value="">Todas</option>
              <option v-for="a in control?.acciones ?? []" :key="a.valor" :value="a.valor">{{ a.texto }}</option>
            </select>
          </div>

          <div v-if="!conPeriodo" class="col-12 col-md">
            <small class="text-muted"><i class="far fa-clock me-1"></i>Foto de la cartera a hoy, {{ formatoFecha(hoy) }}.</small>
          </div>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2 small">
      {{ error.mensaje }}
      <button type="button" class="btn btn-sm btn-danger ms-2" @click="cargar">Reintentar</button>
    </div>

    <div class="mb-3">
      <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" :aria-expanded="verAyuda" @click="verAyuda = !verAyuda">
        <i class="far fa-question-circle me-1"></i>¿Cómo se calcula?
      </button>
      <div v-if="verAyuda" class="alert alert-info small mt-2 mb-0">
        <ul class="mb-0 ps-3">
          <li v-for="(linea, i) in AYUDA[reporte]" :key="i">{{ linea }}</li>
        </ul>
      </div>
    </div>

    <div v-if="cargando && !datosActual" class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
    </div>

    <!-- Cartera -->
    <template v-if="reporte === 'cartera' && cartera">
      <div class="row g-3 mb-3 cifra">
        <div v-for="k in [
          { t: 'Créditos', v: String(cartera.totales.creditos) },
          { t: 'Capital prestado', v: formatoSoles(cartera.totales.capital_prestado) },
          { t: 'Saldo de capital', v: formatoSoles(cartera.totales.saldo_capital) },
          { t: 'Mora a hoy', v: formatoSoles(cartera.totales.mora) },
        ]" :key="k.t" class="col-6 col-lg-3">
          <div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold">{{ k.v }}</div><small class="text-muted">{{ k.t }}</small></div>
        </div>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 cifra">
              <thead class="table-light">
                <tr class="small text-secondary text-uppercase">
                  <th class="ps-3">Cliente</th>
                  <th class="d-none d-lg-table-cell">Asesor</th>
                  <th class="text-end d-none d-md-table-cell">Prestado</th>
                  <th class="text-end">Saldo capital</th>
                  <th class="text-end d-none d-md-table-cell">Interés</th>
                  <th class="text-end">Mora</th>
                  <th class="text-center d-none d-md-table-cell">Cuotas</th>
                  <th class="pe-3">Atraso</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!cartera.filas.length"><td colspan="8" class="text-center py-4 text-muted fst-italic">Sin créditos con estos filtros.</td></tr>
                <tr v-for="f in cartera.filas" :key="f.credito_id">
                  <td class="ps-3">
                    <router-link :to="{ name: 'creditos.detalle', params: { id: f.credito_id } }" class="fw-semibold text-reset">{{ f.cliente }}</router-link>
                    <small class="text-muted d-block">{{ f.numero_credito }}<span v-if="f.estado === 'castigado'" class="badge bg-dark ms-1">Castigado</span></small>
                  </td>
                  <td class="small d-none d-lg-table-cell">{{ f.asesor ?? 'Sin asesor' }}</td>
                  <td class="text-end d-none d-md-table-cell">{{ formatoSoles(f.capital_prestado) }}</td>
                  <td class="text-end fw-semibold">{{ formatoSoles(f.saldo_capital) }}</td>
                  <td class="text-end d-none d-md-table-cell">{{ formatoSoles(f.saldo_interes) }}</td>
                  <td class="text-end" :class="{ 'text-danger': Number(f.mora) > 0 }">{{ formatoSoles(f.mora) }}</td>
                  <td class="text-center small d-none d-md-table-cell">{{ f.cuotas_pagadas }}/{{ f.cuotas_total }}</td>
                  <td class="pe-3 text-nowrap"><span class="badge" :class="claseRango(f.rango_atraso)">{{ TEXTOS_RANGO[f.rango_atraso] }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- Morosidad -->
    <template v-if="reporte === 'morosidad' && morosidad">
      <div class="row g-3 mb-3 cifra">
        <div class="col-6 col-lg-4"><div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold">{{ formatoSoles(morosidad.total.saldo_total) }}</div><small class="text-muted">Saldo de capital total</small></div></div>
        <div class="col-6 col-lg-4"><div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold text-danger">{{ formatoSoles(morosidad.total.saldo_riesgo) }}</div><small class="text-muted">Saldo en riesgo</small></div></div>
        <div class="col-12 col-lg-4"><div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold text-danger">{{ morosidad.total.porcentaje_riesgo }} %</div><small class="text-muted">Cartera en riesgo</small></div></div>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent"><h6 class="card-title mb-0">Saldo de capital por días de atraso</h6></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0 cifra">
              <thead class="table-light">
                <tr class="small text-secondary text-uppercase">
                  <th class="ps-3">Asesor</th>
                  <th v-for="r in morosidad.total.rangos" :key="r.rango" class="text-end">{{ TEXTOS_RANGO[r.rango] }}</th>
                  <th class="text-end pe-3">En riesgo</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="g in morosidad.por_asesor" :key="g.asesor">
                  <td class="ps-3 fw-semibold">{{ g.asesor }}</td>
                  <td v-for="r in g.rangos" :key="r.rango" class="text-end">
                    {{ formatoSoles(r.saldo) }}<small class="d-block text-muted">{{ r.creditos }} créd.</small>
                  </td>
                  <td class="text-end pe-3 fw-semibold text-danger">{{ g.porcentaje_riesgo }} %</td>
                </tr>
              </tbody>
              <tfoot class="table-light fw-semibold">
                <tr>
                  <td class="ps-3">Total</td>
                  <td v-for="r in morosidad.total.rangos" :key="r.rango" class="text-end">
                    {{ formatoSoles(r.saldo) }}<small class="d-block text-muted fw-normal">{{ r.creditos }} créd.</small>
                  </td>
                  <td class="text-end pe-3 text-danger">{{ morosidad.total.porcentaje_riesgo }} %</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- Ingresos y desembolsos -->
    <template v-if="reporte === 'ingresos' && ingresos">
      <div class="row g-3 mb-3 cifra">
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold">{{ formatoSoles(ingresos.totales.desembolsado) }}</div><small class="text-muted">Desembolsado · {{ ingresos.totales.creditos_entregados }} crédito(s)</small></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold text-success">{{ formatoSoles(ingresos.totales.cobrado) }}</div><small class="text-muted">Cobrado</small></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold">{{ formatoSoles(ingresos.totales.interes) }}</div><small class="text-muted">Interés cobrado</small></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm text-center py-3 mb-0"><div class="fs-5 fw-bold">{{ formatoSoles(ingresos.totales.mora) }}</div><small class="text-muted">Mora cobrada</small></div></div>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 cifra small">
              <thead class="table-light">
                <tr class="text-secondary text-uppercase">
                  <th class="ps-3">Período</th>
                  <th class="text-end">Desembolsado</th>
                  <th class="text-end">Capital</th>
                  <th class="text-end">Interés</th>
                  <th class="text-end">Mora</th>
                  <th class="text-end">Cargos</th>
                  <th class="text-end">Cobrado</th>
                  <th class="text-end">Mora condonada</th>
                  <th class="text-end">Interés descontado</th>
                  <th class="text-end pe-3">Saldo a favor devuelto</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!ingresos.filas.length"><td colspan="10" class="text-center py-4 text-muted fst-italic">Sin movimientos en el período.</td></tr>
                <tr v-for="f in ingresos.filas" :key="f.periodo">
                  <td class="ps-3 text-nowrap">{{ etiquetaPeriodo(f.periodo, ingresos.agrupacion) }}</td>
                  <td class="text-end">{{ formatoSoles(f.desembolsado) }}</td>
                  <td class="text-end">{{ formatoSoles(f.capital) }}</td>
                  <td class="text-end">{{ formatoSoles(f.interes) }}</td>
                  <td class="text-end">{{ formatoSoles(f.mora) }}</td>
                  <td class="text-end">{{ formatoSoles(f.cargo) }}</td>
                  <td class="text-end fw-semibold">{{ formatoSoles(f.cobrado) }}</td>
                  <td class="text-end text-muted">{{ formatoSoles(f.mora_condonada) }}</td>
                  <td class="text-end text-muted">{{ formatoSoles(f.interes_descontado) }}</td>
                  <td class="text-end pe-3 text-muted">{{ formatoSoles(f.saldo_favor_devuelto) }}</td>
                </tr>
              </tbody>
              <tfoot v-if="ingresos.filas.length" class="table-light fw-semibold">
                <tr>
                  <td class="ps-3">Total</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.desembolsado) }}</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.capital) }}</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.interes) }}</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.mora) }}</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.cargo) }}</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.cobrado) }}</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.mora_condonada) }}</td>
                  <td class="text-end">{{ formatoSoles(ingresos.totales.interes_descontado) }}</td>
                  <td class="text-end pe-3">{{ formatoSoles(ingresos.totales.saldo_favor_devuelto) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- Por asesor -->
    <div v-if="reporte === 'asesores' && asesores" class="card border-0 shadow-sm">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 cifra">
            <thead class="table-light">
              <tr class="small text-secondary text-uppercase">
                <th class="ps-3">Asesor</th>
                <th class="text-end">Colocados</th>
                <th class="text-end">Capital colocado</th>
                <th class="text-end">Cobrado</th>
                <th class="text-end">Cartera hoy</th>
                <th class="text-end pe-3">En riesgo</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!asesores.filas.length"><td colspan="6" class="text-center py-4 text-muted fst-italic">Sin datos en el período.</td></tr>
              <tr v-for="f in asesores.filas" :key="f.asesor_id ?? 0">
                <td class="ps-3 fw-semibold">{{ f.asesor }}</td>
                <td class="text-end">{{ f.colocados }}</td>
                <td class="text-end">{{ formatoSoles(f.capital_colocado) }}</td>
                <td class="text-end">{{ formatoSoles(f.cobrado) }}</td>
                <td class="text-end">{{ formatoSoles(f.cartera_saldo) }}<small class="d-block text-muted">{{ f.cartera_creditos }} crédito(s)</small></td>
                <td class="text-end pe-3" :class="{ 'text-danger fw-semibold': Number(f.porcentaje_riesgo) > 0 }">{{ f.porcentaje_riesgo }} %</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Castigados y recuperos -->
    <template v-if="reporte === 'castigados' && castigados">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent"><h6 class="card-title mb-0">Castigos del período</h6></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0 cifra">
              <thead class="table-light">
                <tr class="small text-secondary text-uppercase">
                  <th class="ps-3">Cliente</th><th>Fecha</th><th>Tipo</th><th class="text-end">Saldo hoy</th><th class="pe-3">Motivo</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!castigados.castigos.length"><td colspan="5" class="text-center py-4 text-muted fst-italic">Sin castigos en el período.</td></tr>
                <tr v-for="c in castigados.castigos" :key="`${c.credito_id}-${c.fecha_castigo}`">
                  <td class="ps-3">
                    <router-link :to="{ name: 'creditos.detalle', params: { id: c.credito_id } }" class="fw-semibold text-reset">{{ c.cliente ?? '—' }}</router-link>
                    <small class="text-muted d-block">{{ c.numero_credito }}</small>
                  </td>
                  <td class="text-nowrap">{{ formatoFecha(c.fecha_castigo) }}</td>
                  <td>
                    {{ c.tipo === 'manual' ? 'Manual' : 'Automático' }}
                    <span v-if="c.revertido" class="badge bg-secondary-subtle text-secondary ms-1">Revertido</span>
                  </td>
                  <td class="text-end">{{ formatoSoles(c.saldo_hoy) }}</td>
                  <td class="pe-3 small text-muted">{{ c.motivo ?? '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
          <h6 class="card-title mb-0">Recuperos (pagos de créditos castigados)</h6>
          <span class="fw-semibold text-success cifra">{{ formatoSoles(castigados.total_recuperado) }}</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0 cifra">
              <thead class="table-light">
                <tr class="small text-secondary text-uppercase"><th class="ps-3">Cliente</th><th>Fecha</th><th>Recibo</th><th class="text-end pe-3">Monto</th></tr>
              </thead>
              <tbody>
                <tr v-if="!castigados.recuperos.length"><td colspan="4" class="text-center py-4 text-muted fst-italic">Sin recuperos en el período.</td></tr>
                <tr v-for="r in castigados.recuperos" :key="r.pago_id">
                  <td class="ps-3">
                    <router-link :to="{ name: 'creditos.detalle', params: { id: r.credito_id } }" class="fw-semibold text-reset">{{ r.cliente }}</router-link>
                    <small class="text-muted d-block">{{ r.numero_credito }}</small>
                  </td>
                  <td class="text-nowrap">{{ formatoFecha(r.fecha) }}</td>
                  <td>{{ r.numero_recibo ?? '—' }}</td>
                  <td class="text-end pe-3">{{ formatoSoles(r.monto) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- Control y auditoría -->
    <template v-if="reporte === 'control' && control">
      <div v-for="a in control.alertas" :key="a.usuario ?? ''" class="alert alert-warning py-2 small">
        <i class="fas fa-exclamation-triangle me-1"></i><strong>{{ a.usuario ?? 'Usuario' }}</strong> anuló {{ a.anulaciones }} pagos en el período
        (el umbral configurado es {{ control.umbral_anulaciones }}).
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 cifra">
              <thead class="table-light">
                <tr class="small text-secondary text-uppercase">
                  <th class="ps-3">Fecha</th><th>Acción</th><th>Usuario</th><th>Crédito</th><th class="text-end">Monto</th><th class="pe-3">Motivo</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!control.eventos.length"><td colspan="6" class="text-center py-4 text-muted fst-italic">Sin eventos en el período.</td></tr>
                <tr v-for="(e, i) in control.eventos" :key="i">
                  <td class="ps-3 text-nowrap small">{{ formatoFecha(e.fecha) }} {{ e.fecha.slice(11, 16) }}</td>
                  <td>{{ e.descripcion }}</td>
                  <td class="small">{{ e.usuario ?? '—' }}</td>
                  <td class="small">
                    <router-link v-if="e.credito_id" :to="{ name: 'creditos.detalle', params: { id: e.credito_id } }">{{ e.numero_credito }}</router-link>
                    <span v-else class="text-muted">—</span>
                  </td>
                  <td class="text-end text-nowrap">{{ e.monto !== null ? formatoSoles(e.monto) : '' }}</td>
                  <td class="pe-3 small text-muted">{{ e.motivo ?? '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Reportes (04d): una pestaña por reporte, solo las que el usuario puede ver
// (los de dinero de toda la empresa piden creditos.ver_todos). Todo llega sumado del backend;
// el PDF y el Excel salen con los mismos filtros que la pantalla.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import CampoFecha from '@/components/CampoFecha.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import { creditoService } from '@/services/admin/creditoService'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { formatoFecha, formatoSoles, hoyEnLima } from '@/helpers/creditos/formato'
import {
  descargarBlob, etiquetaPeriodo, fechasPeriodo, nombreArchivoReporte, RANGOS_PERIODO, TEXTOS_RANGO, type RangoPeriodo,
} from '@/helpers/creditos/reportes'
import type {
  AgrupacionIngresos, FiltrosReporte, NombreReporte, RangoAtraso, ReporteAsesores, ReporteCartera, ReporteCastigados,
  ReporteControl, ReporteIngresos, ReporteMorosidad, ReportesPorNombre,
} from '@/types/creditos'

type Pestana = Exclude<NombreReporte, 'agenda'>

const TODAS: { id: Pestana; texto: string; icono: string; verTodos: boolean }[] = [
  { id: 'cartera', texto: 'Cartera', icono: 'fas fa-briefcase', verTodos: false },
  { id: 'morosidad', texto: 'Morosidad', icono: 'fas fa-exclamation-circle', verTodos: false },
  { id: 'ingresos', texto: 'Ingresos y desembolsos', icono: 'fas fa-coins', verTodos: true },
  { id: 'asesores', texto: 'Por asesor', icono: 'fas fa-user-tie', verTodos: true },
  { id: 'castigados', texto: 'Castigados', icono: 'fas fa-ban', verTodos: true },
  { id: 'control', texto: 'Control', icono: 'fas fa-shield-alt', verTodos: true },
]
const CON_PERIODO: Pestana[] = ['ingresos', 'asesores', 'castigados', 'control']

const AYUDA: Record<Pestana, string[]> = {
  cartera: [
    'Créditos activos y castigados de tu cartera, con su situación de hoy calculada por el mismo motor que el detalle del crédito.',
    'Saldo de capital e interés: lo que falta pagar de las cuotas vigentes. La mora es la acumulada a hoy (solo si la configuración cobra mora).',
    'El atraso se mide por la cuota impaga más antigua.',
  ],
  morosidad: [
    'Saldo de capital agrupado por días de atraso de cada crédito.',
    'En riesgo: créditos con más días de atraso que los días de gracia configurados. El porcentaje es saldo en riesgo ÷ saldo total.',
  ],
  ingresos: [
    'Cobrado: capital, interés, mora y cargos aplicados de los pagos válidos del período (por fecha de pago). Un pago anulado no suma.',
    'Los saldos de créditos registrados como existentes (migrados) no son ingreso del período, ni su desembolso.',
    'Mora condonada e interés descontado (por liquidar o renovar antes de tiempo) son lo que se dejó de cobrar; el saldo a favor devuelto es dinero que salió de caja.',
  ],
  asesores: [
    'Colocados y capital colocado: créditos desembolsados en el período por clientes del asesor.',
    'Cobrado: pagos del período de sus clientes. Cartera y riesgo: la foto de hoy.',
  ],
  castigados: [
    'Castigos registrados en el período, manuales o automáticos, con el saldo que el crédito tiene hoy.',
    'Recuperos: pagos que entraron a un crédito castigado después del castigo.',
  ],
  control: [
    'Anulaciones y correcciones de pagos, condonaciones, autorizaciones de excepción, castigos manuales y cambios de configuración.',
    'La alerta aparece cuando un usuario anula más pagos que el umbral configurado en el período.',
  ],
}

const route = useRoute()
const router = useRouter()
const { puede } = usePermisosCredito()
const toast = useToast()

const pestanas = computed(() => TODAS.filter((p) => !p.verTodos || puede('creditos.ver_todos')))
const inicial = String(route.query.reporte ?? '')
const reporte = ref<Pestana>(pestanas.value.some((p) => p.id === inicial) ? (inicial as Pestana) : 'cartera')

const hoy = hoyEnLima()
const rangoPeriodo = ref<RangoPeriodo>('mes')
const desde = ref(fechasPeriodo('mes', hoy)!.desde)
const hasta = ref(fechasPeriodo('mes', hoy)!.hasta)
const agrupacion = ref<AgrupacionIngresos>('dia')
const estado = ref<'' | 'activo' | 'castigado'>('')
const rangoAtraso = ref<RangoAtraso | ''>('')
const asesorId = ref<number | null>(null)
const accion = ref('')

const datos = ref<Partial<ReportesPorNombre>>({})
const cargando = ref(false)
const exportando = ref(false)
const error = ref<ErrorCredito | null>(null)
const verAyuda = ref(false)

const conPeriodo = computed(() => CON_PERIODO.includes(reporte.value))
const datosActual = computed(() => datos.value[reporte.value] ?? null)
const cartera = computed(() => datos.value.cartera as ReporteCartera | undefined)
const morosidad = computed(() => datos.value.morosidad as ReporteMorosidad | undefined)
const ingresos = computed(() => datos.value.ingresos as ReporteIngresos | undefined)
const asesores = computed(() => datos.value.asesores as ReporteAsesores | undefined)
const castigados = computed(() => datos.value.castigados as ReporteCastigados | undefined)
const control = computed(() => datos.value.control as ReporteControl | undefined)

const subtitulo = computed(() => {
  const d = datosActual.value
  if (!d) return ''
  return 'periodo' in d ? d.periodo.texto : `Al ${formatoFecha(hoy)}`
})

function filtros(): FiltrosReporte {
  switch (reporte.value) {
    case 'cartera': return { estado: estado.value, rango: rangoAtraso.value, asesor_id: asesorId.value }
    case 'morosidad': return {}
    case 'ingresos': return { desde: desde.value, hasta: hasta.value, agrupacion: agrupacion.value }
    case 'control': return { desde: desde.value, hasta: hasta.value, accion: accion.value }
    default: return { desde: desde.value, hasta: hasta.value }
  }
}

let consulta = 0
async function cargar() {
  if (conPeriodo.value && (!desde.value || !hasta.value)) return
  const nombre = reporte.value
  const mia = ++consulta
  cargando.value = true
  error.value = null
  try {
    const respuesta = await creditoService.reporte(nombre, filtros())
    if (mia === consulta) datos.value = { ...datos.value, [nombre]: respuesta }
  } catch (e) {
    if (mia === consulta) error.value = interpretarErrorCredito(e)
  } finally {
    if (mia === consulta) cargando.value = false
  }
}

function elegirPeriodo(nuevo: RangoPeriodo) {
  rangoPeriodo.value = nuevo
  const fechas = fechasPeriodo(nuevo, hoy)
  if (fechas) {
    desde.value = fechas.desde
    hasta.value = fechas.hasta
  }
}

onMounted(cargar)
watch(reporte, (nuevo) => {
  router.replace({ query: { ...route.query, reporte: nuevo } })
  cargar()
})
watch([desde, hasta, agrupacion, estado, rangoAtraso, asesorId, accion], cargar)

function claseRango(rango: RangoAtraso): string {
  if (rango === 'al_dia') return 'bg-success-subtle text-success'
  if (rango === '1-7' || rango === '8-15') return 'bg-warning-subtle text-warning'
  return 'bg-danger-subtle text-danger'
}

async function exportarPdf() {
  // La pestaña se abre antes de la petición: si se abre después, el navegador la bloquea.
  const pestana = window.open('', '_blank')
  exportando.value = true
  try {
    const { url } = await creditoService.reportePdfUrl(reporte.value, filtros())
    if (pestana) pestana.location.href = url
    else window.open(url, '_blank')
  } catch (e) {
    pestana?.close()
    toast.error(interpretarErrorCredito(e).mensaje)
  } finally {
    exportando.value = false
  }
}

async function exportarExcel() {
  exportando.value = true
  try {
    descargarBlob(await creditoService.reporteExcel(reporte.value, filtros()), nombreArchivoReporte(reporte.value, filtros(), hoy))
  } catch (e) {
    toast.error(interpretarErrorCredito(e).mensaje)
  } finally {
    exportando.value = false
  }
}
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
