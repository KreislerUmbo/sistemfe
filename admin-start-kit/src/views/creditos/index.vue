<template>
  <DefaultLayout>
    <div class="creditos-contenedor mx-auto">
      <div class="d-flex align-items-center gap-2 mb-3">
        <h5 class="fw-bold mb-0 flex-grow-1">Créditos</h5>
        <router-link v-if="puede('creditos.crear')" :to="{ name: 'creditos.nuevo' }" class="btn btn-primary boton-alto d-inline-flex align-items-center">
          <i class="fas fa-plus me-md-1"></i><span class="d-none d-md-inline">Nuevo crédito</span>
        </router-link>
      </div>

      <!-- Filtros -->
      <div class="d-flex flex-column flex-xl-row gap-2 mb-3">
        <div class="position-relative buscador">
          <i class="fas fa-search text-muted icono-buscar"></i>
          <input v-model="buscarTexto" type="search" class="form-control boton-alto campo-buscar" placeholder="Cliente, DNI o N.º de crédito"
            aria-label="Buscar créditos" maxlength="100" />
        </div>
        <div class="chips d-flex gap-2 overflow-auto pb-1" role="tablist" aria-label="Estado">
          <button v-for="v in VISTAS" :key="v.id" type="button" role="tab" :aria-selected="filtros.vista === v.id"
            class="btn btn-sm rounded-pill text-nowrap boton-chip" :class="filtros.vista === v.id ? 'btn-dark' : 'btn-outline-secondary'"
            @click="cambiar({ vista: v.id })">
            {{ v.texto }}
          </button>
        </div>
      </div>

      <div v-if="error" class="alert alert-danger">
        {{ error.mensaje }}
        <button type="button" class="btn btn-sm btn-danger ms-2" @click="cargar">Reintentar</button>
      </div>

      <!-- Celular y tablet: tarjetas -->
      <div class="d-xl-none" :class="{ 'opacity-50': cargando && filas.length }">
        <div class="row g-2">
          <div v-for="f in filas" :key="f.id" class="col-12 col-md-6">
            <router-link :to="{ name: 'creditos.detalle', params: { id: f.id } }" class="card border shadow-none h-100 mb-0 text-reset text-decoration-none tarjeta">
              <div class="card-body p-3 cifra">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div class="min-w-0">
                    <div class="fw-semibold text-truncate">{{ f.cliente.nombre }}</div>
                    <small class="text-muted">{{ f.numero_credito ?? 'Borrador' }} · {{ textoFrecuencia(f.frecuencia_unidad, f.frecuencia_intervalo) }}</small>
                  </div>
                  <span class="badge rounded-pill" :class="insignia(f).clase"><i :class="insignia(f).icono" class="me-1"></i>{{ insignia(f).texto }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-end mt-2">
                  <div>
                    <small class="text-muted d-block">{{ f.situacion ? 'Saldo por pagar' : 'Total del crédito' }}</small>
                    <span class="fw-bold fs-5">{{ formatoSoles(f.situacion?.saldo_por_pagar ?? f.monto_total) }}</span>
                  </div>
                  <small class="text-end" :class="f.situacion?.dias_atraso ? 'text-danger fw-semibold' : 'text-muted'">{{ lineaProxima(f) }}</small>
                </div>
                <div v-if="f.situacion?.cuotas_total" class="progress mt-2" style="height: 4px" role="progressbar"
                  :aria-valuenow="f.situacion.cuotas_pagadas" :aria-valuemax="f.situacion.cuotas_total"
                  :aria-label="`${f.situacion.cuotas_pagadas} de ${f.situacion.cuotas_total} pagos`">
                  <div class="progress-bar bg-success" :style="{ width: `${porcentaje(f)}%` }"></div>
                </div>
              </div>
            </router-link>
          </div>
        </div>
      </div>

      <!-- Escritorio: tabla -->
      <div class="card border-0 shadow-sm d-none d-xl-block" :class="{ 'opacity-50': cargando && filas.length }">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 cifra">
            <thead class="table-light">
              <tr>
                <th v-for="c in COLUMNAS" :key="c.texto" :class="c.clase" :aria-sort="ariaOrden(c.orden)">
                  <button v-if="c.orden" type="button" class="btn btn-link p-0 fw-semibold text-reset text-decoration-none text-nowrap" @click="ordenar(c.orden)">
                    {{ c.texto }} <i class="fas small" :class="iconoOrden(c.orden)"></i>
                  </button>
                  <template v-else>{{ c.texto }}</template>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in filas" :key="f.id" class="fila" tabindex="0" @click="abrir(f)" @keydown.enter="abrir(f)">
                <td class="fw-semibold text-nowrap">{{ f.numero_credito ?? '—' }}</td>
                <td>
                  <div class="fw-semibold">{{ f.cliente.nombre }}</div>
                  <small class="text-muted">{{ f.cliente.documento }}</small>
                </td>
                <td class="text-nowrap">{{ formatoFecha(f.fecha_desembolso) }}</td>
                <td class="text-end text-nowrap">{{ formatoSoles(f.monto_capital) }}</td>
                <td class="text-end text-nowrap fw-semibold">{{ f.situacion ? formatoSoles(f.situacion.saldo_por_pagar) : '—' }}</td>
                <td class="text-nowrap">
                  <template v-if="f.situacion?.proxima">{{ fechaCorta(f.situacion.proxima.fecha_vencimiento) }} · {{ formatoSoles(f.situacion.proxima.pendiente) }}</template>
                  <template v-else>—</template>
                </td>
                <td class="text-nowrap" :class="{ 'text-danger fw-semibold': f.situacion?.dias_atraso }">
                  {{ f.situacion?.dias_atraso ? textoDias(f.situacion.dias_atraso) : '—' }}
                </td>
                <td><span class="badge rounded-pill" :class="insignia(f).clase"><i :class="insignia(f).icono" class="me-1"></i>{{ insignia(f).texto }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="cargando && !filas.length" class="text-center text-muted py-5">
        <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
      </div>
      <div v-else-if="!cargando && !error && !filas.length" class="text-center text-muted py-5">
        <i class="fas fa-hand-holding-usd fs-2 d-block mb-2 opacity-50"></i>
        {{ filtros.buscar || filtros.vista !== 'todos' ? 'Ningún crédito coincide con los filtros.' : 'Todavía no hay créditos.' }}
      </div>

      <!-- Paginación -->
      <div v-if="meta && meta.last_page > 1" class="d-flex align-items-center justify-content-between gap-2 mt-3">
        <button type="button" class="btn btn-light boton-alto" :disabled="pagina <= 1 || cargando" @click="irAPagina(pagina - 1)">
          <i class="fas fa-chevron-left"></i><span class="d-none d-sm-inline ms-1">Anterior</span>
        </button>
        <small class="text-muted text-center">Página {{ meta.current_page }} de {{ meta.last_page }} · {{ meta.total }} créditos</small>
        <button type="button" class="btn btn-light boton-alto" :disabled="pagina >= meta.last_page || cargando" @click="irAPagina(pagina + 1)">
          <span class="d-none d-sm-inline me-1">Siguiente</span><i class="fas fa-chevron-right"></i>
        </button>
      </div>
    </div>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — listado (04-frontend pantalla 4). Saldo, atraso y próximo pago vienen
// calculados por el backend para cada fila; el cobrador solo ve su cartera (lo filtra el
// backend). Los filtros viven en la URL para que "volver" desde el detalle los conserve.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import { creditoService } from '@/services/admin/creditoService'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { fechaCorta, PRESENTACION_CREDITO, textoDias, textoFrecuencia } from '@/helpers/creditos/estados'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import { alternarOrden, aQuery, desdeQuery, VISTAS, type FiltrosListado, type OrdenListado } from '@/helpers/creditos/listado'
import type { FilaCredito, Paginado } from '@/types/creditos'

const route = useRoute()
const router = useRouter()
const { puede } = usePermisosCredito()

const COLUMNAS: { texto: string; orden?: OrdenListado; clase?: string }[] = [
  { texto: 'N.º', orden: 'numero' },
  { texto: 'Cliente', orden: 'cliente' },
  { texto: 'Desembolso', orden: 'desembolso' },
  { texto: 'Prestado', orden: 'monto', clase: 'text-end' },
  { texto: 'Saldo por pagar', clase: 'text-end' },
  { texto: 'Próximo pago' },
  { texto: 'Atraso' },
  { texto: 'Estado' },
]

const estadoUrl = computed(() => desdeQuery(route.query))
const filtros = computed(() => estadoUrl.value.filtros)
const pagina = computed(() => estadoUrl.value.pagina)

const filas = ref<FilaCredito[]>([])
const meta = ref<Paginado<FilaCredito>['meta'] | null>(null)
const cargando = ref(false)
const error = ref<ErrorCredito | null>(null)
const buscarTexto = ref(filtros.value.buscar)
let ultimaSolicitud = 0
let temporizador: ReturnType<typeof setTimeout> | null = null

function cambiar(cambios: Partial<FiltrosListado>, nuevaPagina = 1) {
  router.replace({ query: aQuery({ ...filtros.value, ...cambios }, nuevaPagina) })
}

const ordenar = (columna: OrdenListado) => router.replace({ query: aQuery(alternarOrden(filtros.value, columna), 1) })
const irAPagina = (n: number) => cambiar({}, n)
const abrir = (f: FilaCredito) => router.push({ name: 'creditos.detalle', params: { id: f.id } })

async function cargar() {
  const numero = ++ultimaSolicitud
  cargando.value = true
  error.value = null
  try {
    const respuesta = await creditoService.listar(filtros.value, pagina.value)
    if (numero !== ultimaSolicitud) return
    filas.value = respuesta.data
    meta.value = respuesta.meta
  } catch (e) {
    if (numero !== ultimaSolicitud) return
    error.value = interpretarErrorCredito(e)
  } finally {
    if (numero === ultimaSolicitud) cargando.value = false
  }
}

// Solo mientras esta vista está activa (al ir al detalle la query cambia y no hay que recargar).
watch(() => route.query, () => {
  if (route.name === 'creditos.index') cargar()
}, { immediate: true, deep: true })

// La búsqueda espera a que el usuario deje de escribir.
watch(buscarTexto, (texto) => {
  if (temporizador) clearTimeout(temporizador)
  temporizador = setTimeout(() => {
    if (texto.trim() !== filtros.value.buscar) cambiar({ buscar: texto })
  }, 300)
})
watch(() => filtros.value.buscar, (valor) => {
  if (valor !== buscarTexto.value.trim()) buscarTexto.value = valor
})
onBeforeUnmount(() => {
  if (temporizador) clearTimeout(temporizador)
})

function insignia(f: FilaCredito) {
  const vencidas = f.situacion?.cuotas_vencidas ?? 0
  if (f.estado === 'activo' && vencidas > 0) {
    return { texto: `${vencidas} atrasada${vencidas > 1 ? 's' : ''}`, clase: 'bg-warning-subtle text-warning-emphasis', icono: 'fas fa-exclamation-triangle' }
  }
  return PRESENTACION_CREDITO[f.estado]
}

function lineaProxima(f: FilaCredito): string {
  const s = f.situacion
  if (!s) return formatoFecha(f.fecha_desembolso)
  if (s.dias_atraso) return `${textoDias(s.dias_atraso)} de atraso`
  return s.proxima ? `${fechaCorta(s.proxima.fecha_vencimiento)} · ${formatoSoles(s.proxima.pendiente)}` : 'Sin cuotas pendientes'
}

const porcentaje = (f: FilaCredito) => Math.round(((f.situacion?.cuotas_pagadas ?? 0) / (f.situacion?.cuotas_total || 1)) * 100)

function ariaOrden(columna?: OrdenListado) {
  if (!columna || filtros.value.orden !== columna) return undefined
  return filtros.value.direccion === 'asc' ? 'ascending' : 'descending'
}

function iconoOrden(columna: OrdenListado) {
  if (filtros.value.orden !== columna) return 'fa-sort opacity-25'
  return filtros.value.direccion === 'asc' ? 'fa-sort-up' : 'fa-sort-down'
}
</script>

<style scoped>
.creditos-contenedor {
  max-width: 1440px;
}
.boton-alto {
  min-height: 44px;
}
.boton-chip {
  min-height: 40px;
  padding-inline: 0.9rem;
}
.buscador {
  flex: 1 1 auto;
}
@media (min-width: 1200px) {
  .buscador {
    max-width: 380px;
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
.chips {
  scrollbar-width: none;
}
.cifra {
  font-variant-numeric: tabular-nums;
}
.min-w-0 {
  min-width: 0;
}
.fila {
  cursor: pointer;
}
.tarjeta:hover,
.tarjeta:focus-visible {
  border-color: var(--bs-primary) !important;
}
</style>
