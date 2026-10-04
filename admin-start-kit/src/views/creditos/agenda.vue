<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Agenda de cobranza" :subtitulo="datos ? datos.periodo.texto : ''" icono="far fa-calendar-alt" :volver="false">
      <button type="button" class="btn btn-outline-danger btn-sm" :disabled="!datos || exportando" @click="exportarPdf">
        <i class="fas fa-file-pdf me-1"></i>Hoja de ruta
      </button>
      <button type="button" class="btn btn-outline-success btn-sm" :disabled="!datos || exportando" @click="exportarExcel">
        <i class="fas fa-file-excel me-1"></i>Excel
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cargando" title="Actualizar" aria-label="Actualizar" @click="cargar">
        <i class="fas fa-sync-alt" :class="{ 'fa-spin': cargando }"></i>
      </button>
    </EncabezadoCredito>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Rango de días">
            <button v-for="r in RANGOS_AGENDA" :key="r.id" type="button" class="btn"
              :class="rango === r.id ? 'btn-primary' : 'btn-outline-primary'" @click="elegirRango(r.id)">{{ r.texto }}</button>
          </div>
          <div class="form-check form-switch mb-0 ms-md-2">
            <input id="agenda-atrasados" v-model="incluirAtrasados" class="form-check-input" type="checkbox" />
            <label class="form-check-label small" for="agenda-atrasados">Incluir cuotas ya atrasadas</label>
          </div>
        </div>
        <div class="row g-2">
          <template v-if="rango === 'rango'">
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1" for="agenda-desde">Desde</label>
              <CampoFecha id="agenda-desde" v-model="desde" etiqueta="la fecha inicial" />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1" for="agenda-hasta">Hasta</label>
              <CampoFecha id="agenda-hasta" v-model="hasta" :min="desde" etiqueta="la fecha final" />
            </div>
          </template>
          <div v-if="(datos?.opciones.cobradores.length ?? 0) > 1 || cobradorId !== null" class="col-6 col-md-3">
            <label class="form-label small mb-1" for="agenda-cobrador">Cobrador</label>
            <select id="agenda-cobrador" v-model="cobradorId" class="form-select form-select-sm">
              <option :value="null">Todos</option>
              <option v-for="c in datos?.opciones.cobradores ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
          </div>
          <div v-if="(datos?.opciones.distritos.length ?? 0) > 0 || distrito" class="col-6 col-md-3">
            <label class="form-label small mb-1" for="agenda-distrito">Distrito</label>
            <select id="agenda-distrito" v-model="distrito" class="form-select form-select-sm">
              <option value="">Todos</option>
              <option v-for="d in datos?.opciones.distritos ?? []" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
        </div>
        <small v-if="rango === 'rango'" class="text-muted d-block mt-2">Hasta {{ MAXIMO_DIAS }} días por consulta.</small>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2 small">
      {{ error.mensaje }}
      <button type="button" class="btn btn-sm btn-danger ms-2" @click="cargar">Reintentar</button>
    </div>

    <!-- Resumen -->
    <div class="row g-3 mb-3 cifra">
      <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3 mb-0">
          <div class="fs-4 fw-bold">{{ datos?.totales.clientes ?? '—' }}</div>
          <small class="text-muted">Clientes a visitar</small>
        </div>
      </div>
      <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3 mb-0">
          <div class="fs-4 fw-bold">{{ datos?.totales.cuotas ?? '—' }}</div>
          <small class="text-muted">Cuotas</small>
        </div>
      </div>
      <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3 mb-0">
          <div class="fs-4 fw-bold">{{ datos ? formatoSoles(datos.totales.monto) : '—' }}</div>
          <small class="text-muted">Por cobrar</small>
        </div>
      </div>
    </div>

    <div v-if="cargando && !datos" class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
    </div>
    <div v-else-if="datos && !datos.dias.length" class="card border-0 shadow-sm">
      <div class="card-body text-center py-5 text-muted fst-italic">No hay cuotas que venzan en estas fechas.</div>
    </div>

    <!-- Día → cobrador -->
    <div v-for="dia in datos?.dias ?? []" :key="dia.dia ?? 'atrasados'" class="card border-0 shadow-sm mb-3">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2" :class="dia.dia === null ? 'bg-danger-subtle' : 'bg-transparent'">
        <h6 class="card-title mb-0">
          <i class="me-2" :class="dia.dia === null ? 'fas fa-exclamation-circle text-danger' : 'far fa-calendar text-primary'"></i>{{ tituloDiaAgenda(dia.dia, datos!.hoy) }}
        </h6>
        <small class="text-muted cifra">{{ dia.totales.clientes }} cliente(s) · {{ dia.totales.cuotas }} cuota(s) · <span class="fw-semibold text-body">{{ formatoSoles(dia.totales.monto) }}</span></small>
      </div>
      <div class="card-body p-0">
        <template v-for="grupo in dia.cobradores" :key="grupo.cobrador">
          <div v-if="dia.cobradores.length > 1 || verTodos" class="px-3 py-2 border-bottom bg-light d-flex justify-content-between small">
            <span class="fw-semibold"><i class="fas fa-user me-1 text-muted"></i>{{ grupo.cobrador }}</span>
            <span class="text-muted cifra">{{ grupo.totales.clientes }} cliente(s) · {{ formatoSoles(grupo.totales.monto) }}</span>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 cifra tabla-agenda">
              <colgroup>
                <col />
                <col style="width: 130px" />
                <col style="width: 150px" />
                <col style="width: 130px" />
                <col style="width: 230px" />
              </colgroup>
              <tbody>
                <tr v-for="f in grupo.filas" :key="`${f.credito_id}-${f.numero_cuota}`">
                  <td class="ps-3">
                    <router-link :to="{ name: 'creditos.detalle', params: { id: f.credito_id } }" class="fw-semibold text-reset">{{ f.cliente.nombre }}</router-link>
                    <small class="text-muted d-block">{{ direccion(f) || f.numero_credito }}</small>
                  </td>
                  <td class="small text-nowrap d-none d-md-table-cell">
                    Cuota {{ f.numero_cuota }}/{{ f.cuotas_total }}
                    <span class="d-block text-muted">{{ f.numero_credito }}</span>
                  </td>
                  <td class="small text-nowrap">
                    <span v-if="cuotaVencida(f, datos!.hoy)" class="badge bg-danger-subtle text-danger">Venció {{ formatoFecha(f.fecha_vencimiento) }}</span>
                    <template v-else>
                      <span class="text-muted">{{ formatoFecha(f.fecha_vencimiento) }}</span>
                      <span v-if="f.con_atraso" class="d-block text-danger" title="El crédito tiene otras cuotas vencidas">Crédito con atraso</span>
                    </template>
                  </td>
                  <td class="text-end text-nowrap">
                    <span class="fw-semibold">{{ formatoSoles(f.pendiente) }}</span>
                    <small v-if="Number(f.mora) > 0" class="d-block text-danger">+ mora {{ formatoSoles(f.mora) }}</small>
                  </td>
                  <td class="text-end pe-3 text-nowrap">
                    <a v-if="enlaceLlamada(f.cliente.telefono)" :href="enlaceLlamada(f.cliente.telefono)!" class="btn btn-sm btn-outline-secondary me-1"
                      :title="`Llamar ${f.cliente.telefono}`" :aria-label="`Llamar a ${f.cliente.nombre}`"><i class="fas fa-phone"></i></a>
                    <a v-if="enlaceWhatsapp(f.cliente.telefono)" :href="enlaceWhatsappConTexto(f.cliente.telefono, mensajeRecordatorio(f, datos!.hoy))" target="_blank" rel="noopener"
                      class="btn btn-sm btn-outline-success me-1" title="Enviar recordatorio por WhatsApp" :aria-label="`Recordatorio por WhatsApp a ${f.cliente.nombre}`"><i class="fab fa-whatsapp"></i></a>
                    <a v-if="enlaceMapa(f.cliente)" :href="enlaceMapa(f.cliente)!" target="_blank" rel="noopener"
                      class="btn btn-sm btn-outline-secondary me-1" title="Abrir mapa" aria-label="Abrir mapa"><i class="fas fa-map-marker-alt"></i></a>
                    <router-link v-if="f.dia === null || f.dia === datos!.hoy" :to="{ name: 'creditos.cobrar', params: { id: f.credito_id }, query: { volver: 'agenda' } }"
                      class="btn btn-sm btn-success"><i class="fas fa-money-bill-wave me-1"></i>Cobrar</router-link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </div>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Agenda de cobranza (04d, reporte 8): quiénes vencen mañana, pasado mañana o
// en un rango, agrupados por día y cobrador, para organizar la ruta con anticipación. El
// backend decide el alcance (el cobrador ve solo su cartera) y suma los totales.
import { onMounted, ref, watch } from 'vue'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import CampoFecha from '@/components/CampoFecha.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import { creditoService } from '@/services/admin/creditoService'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { enlaceLlamada, enlaceMapa, enlaceWhatsapp } from '@/helpers/creditos/cobranza'
import { enlaceWhatsappConTexto } from '@/helpers/creditos/documentos'
import { formatoFecha, formatoSoles, hoyEnLima } from '@/helpers/creditos/formato'
import {
  cuotaVencida, descargarBlob, fechasAgenda, mensajeRecordatorio, nombreArchivoReporte, RANGOS_AGENDA, tituloDiaAgenda, type RangoAgenda,
} from '@/helpers/creditos/reportes'
import type { AgendaCobranza, FilaAgenda, FiltrosReporte } from '@/types/creditos'

const MAXIMO_DIAS = 31

const { puede } = usePermisosCredito()
const toast = useToast()
const verTodos = puede('creditos.ver_todos')

const hoy = hoyEnLima()
const rango = ref<RangoAgenda>('manana')
const desde = ref(fechasAgenda('manana', hoy)!.desde)
const hasta = ref(fechasAgenda('manana', hoy)!.hasta)
const incluirAtrasados = ref(false)
const cobradorId = ref<number | null>(null)
const distrito = ref('')

const datos = ref<AgendaCobranza | null>(null)
const cargando = ref(false)
const exportando = ref(false)
const error = ref<ErrorCredito | null>(null)

function filtros(): FiltrosReporte {
  return { desde: desde.value, hasta: hasta.value, incluir_atrasados: incluirAtrasados.value, cobrador_id: cobradorId.value, distrito: distrito.value }
}

let consulta = 0
async function cargar() {
  if (!desde.value || !hasta.value) return
  const mia = ++consulta
  cargando.value = true
  error.value = null
  try {
    const respuesta = await creditoService.reporte('agenda', filtros())
    if (mia === consulta) datos.value = respuesta
  } catch (e) {
    if (mia === consulta) error.value = interpretarErrorCredito(e)
  } finally {
    if (mia === consulta) cargando.value = false
  }
}

function elegirRango(nuevo: RangoAgenda) {
  rango.value = nuevo
  const fechas = fechasAgenda(nuevo, hoy)
  if (fechas) {
    desde.value = fechas.desde
    hasta.value = fechas.hasta
  }
}

onMounted(cargar)
watch([desde, hasta, incluirAtrasados, cobradorId, distrito], cargar)

function direccion(f: FilaAgenda): string {
  return [f.cliente.direccion_cobro, f.cliente.referencia, f.cliente.distrito].filter(Boolean).join(' · ')
}

async function exportarPdf() {
  // La pestaña se abre antes de la petición: si se abre después, el navegador la bloquea.
  const pestana = window.open('', '_blank')
  exportando.value = true
  try {
    const { url } = await creditoService.reportePdfUrl('agenda', filtros())
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
    descargarBlob(await creditoService.reporteExcel('agenda', filtros()), nombreArchivoReporte('agenda', filtros(), hoy))
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
@media (min-width: 768px) {
  .tabla-agenda {
    table-layout: fixed;
  }
}
</style>
