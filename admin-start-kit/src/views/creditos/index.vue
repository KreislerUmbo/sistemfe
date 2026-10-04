<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Créditos" :subtitulo="meta ? `${meta.total} registro(s) encontrado(s)` : ''" :volver="false">
      <router-link v-if="puede('creditos.crear')" :to="{ name: 'creditos.nuevo' }" class="btn btn-primary fw-semibold shadow-sm">
        <i class="fas fa-plus me-2"></i>Nuevo crédito
      </router-link>
    </EncabezadoCredito>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12 col-md-6">
            <div class="input-group input-group-sm">
              <input v-model="buscarTexto" type="search" class="form-control" placeholder="Buscar por cliente, DNI o N.º de crédito..."
                aria-label="Buscar créditos" maxlength="100" />
              <span class="input-group-text"><i class="fas fa-search"></i></span>
            </div>
          </div>
          <div class="col-8 col-md-4">
            <select class="form-select form-select-sm" :value="filtros.vista" aria-label="Estado"
              @change="cambiar({ vista: ($event.target as HTMLSelectElement).value as VistaListado })">
              <option v-for="v in VISTAS" :key="v.id" :value="v.id">{{ v.id === 'todos' ? 'Todos los estados' : v.texto }}</option>
            </select>
          </div>
          <div class="col-4 col-md-2">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100" title="Limpiar filtros" @click="limpiar">
              <i class="fas fa-eraser"></i>
            </button>
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
        <div class="table-responsive" :class="{ 'opacity-50': cargando && filas.length }">
          <table class="table table-hover align-middle mb-0 cifra">
            <thead class="table-light">
              <tr class="small text-secondary text-uppercase">
                <th v-for="c in COLUMNAS" :key="c.texto" :class="c.clase" :aria-sort="ariaOrden(c.orden)">
                  <a v-if="c.orden" href="#" class="text-reset text-decoration-none text-nowrap" @click.prevent="ordenar(c.orden)">
                    {{ c.texto }} <i class="fas small" :class="iconoOrden(c.orden)"></i>
                  </a>
                  <template v-else>{{ c.texto }}</template>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="cargando && !filas.length">
                <td :colspan="COLUMNAS.length" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
              </tr>
              <tr v-else-if="!filas.length">
                <td :colspan="COLUMNAS.length" class="text-center py-5 text-muted fst-italic">
                  {{ filtros.buscar || filtros.vista !== 'todos' ? 'Ningún crédito coincide con los filtros.' : 'Sin créditos registrados.' }}
                </td>
              </tr>
              <tr v-for="f in filas" :key="f.id">
                <td class="ps-3 fw-semibold text-nowrap">{{ f.numero_credito ?? '—' }}</td>
                <td>
                  {{ f.cliente.nombre }}
                  <small class="text-muted d-block">{{ f.cliente.documento }}</small>
                </td>
                <td class="small text-nowrap d-none d-md-table-cell">{{ formatoFecha(f.fecha_desembolso) }}</td>
                <td class="text-end text-nowrap d-none d-md-table-cell">{{ formatoSoles(f.monto_capital) }}</td>
                <td class="text-end text-nowrap fw-semibold">{{ f.situacion ? formatoSoles(f.situacion.saldo_por_pagar) : '—' }}</td>
                <td class="small text-nowrap d-none d-lg-table-cell">
                  <template v-if="f.situacion?.proxima">{{ fechaCorta(f.situacion.proxima.fecha_vencimiento) }} · {{ formatoSoles(f.situacion.proxima.pendiente) }}</template>
                  <template v-else>—</template>
                </td>
                <td class="text-center">
                  <span class="badge rounded-pill" :class="insignia(f).clase">{{ insignia(f).texto }}</span>
                  <small v-if="f.situacion?.dias_atraso" class="text-danger d-block">{{ textoDias(f.situacion.dias_atraso) }}</small>
                </td>
                <td class="text-center pe-3">
                  <router-link :to="{ name: 'creditos.detalle', params: { id: f.id } }" class="btn btn-sm btn-outline-primary text-nowrap">
                    <i class="fas fa-arrow-right me-1"></i>Abrir
                  </router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="meta && meta.last_page > 1" class="d-flex align-items-center justify-content-end p-3 border-top flex-wrap gap-2">
          <small class="text-muted">Página {{ meta.current_page }} de {{ meta.last_page }}</small>
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary" :disabled="pagina <= 1 || cargando" @click="irAPagina(pagina - 1)">
              <i class="fas fa-chevron-left"></i> Anterior
            </button>
            <button class="btn btn-outline-secondary" :disabled="pagina >= meta.last_page || cargando" @click="irAPagina(pagina + 1)">
              Siguiente <i class="fas fa-chevron-right"></i>
            </button>
          </div>
        </div>
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
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import { creditoService } from '@/services/admin/creditoService'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { fechaCorta, PRESENTACION_CREDITO, textoDias } from '@/helpers/creditos/estados'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import { alternarOrden, aQuery, desdeQuery, VISTAS, type FiltrosListado, type OrdenListado, type VistaListado } from '@/helpers/creditos/listado'
import type { FilaCredito, Paginado } from '@/types/creditos'

const route = useRoute()
const router = useRouter()
const { puede } = usePermisosCredito()

const COLUMNAS: { texto: string; orden?: OrdenListado; clase?: string }[] = [
  { texto: 'N.º', orden: 'numero', clase: 'ps-3' },
  { texto: 'Cliente', orden: 'cliente' },
  { texto: 'Desembolso', orden: 'desembolso', clase: 'd-none d-md-table-cell' },
  { texto: 'Prestado', orden: 'monto', clase: 'text-end d-none d-md-table-cell' },
  { texto: 'Saldo por pagar', clase: 'text-end' },
  { texto: 'Próximo pago', clase: 'd-none d-lg-table-cell' },
  { texto: 'Estado', clase: 'text-center' },
  { texto: 'Acciones', clase: 'text-center pe-3' },
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
const limpiar = () => {
  buscarTexto.value = ''
  router.replace({ query: {} })
}

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
.cifra {
  font-variant-numeric: tabular-nums;
}
</style>
