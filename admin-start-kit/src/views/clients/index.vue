<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Clientes" subtitulo="Registro de clientes" icono="fas fa-users" :volver="false">
      <button v-if="puede('register_client')" type="button" class="btn btn-primary" @click="router.push({ name: 'clients.nuevo' })">
        <i class="fas fa-user-plus me-2"></i>Nuevo cliente
      </button>
      <button v-if="contexto.creditos && puede('creditos.cartera.asignar') && puede('creditos.ver_todos')" type="button"
        class="btn btn-outline-primary" @click="traspaso = true">
        <i class="fas fa-exchange-alt me-2"></i>Traspasar cartera
      </button>
      <!-- Venta rápida a consumidor sin identificar (boleta menor a S/ 700) -->
      <button v-if="!contexto.creditos" type="button" class="btn btn-outline-secondary" title="Consumidor final sin datos: boleta menor a S/ 700"
        @click="setClienteSinDatos">
        <i class="fas fa-bolt me-2"></i>Sin datos
      </button>
    </EncabezadoCredito>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-center">
          <div class="col-12" :class="{ 'col-lg-5': contexto.creditos }">
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="fas fa-search"></i></span>
              <input id="buscar-cliente" v-model="search" type="search" class="form-control" placeholder="Buscar por nombre, documento o teléfono…"
                aria-label="Buscar cliente" @keyup.enter="buscar" />
              <button type="button" class="btn btn-outline-secondary" title="Limpiar filtros" @click="reset"><i class="fas fa-sync"></i></button>
            </div>
          </div>
          <template v-if="contexto.creditos">
            <div v-if="asesores.length" class="col-6 col-lg-3">
              <select v-model="filtroAsesor" class="form-select form-select-sm" aria-label="Asesor" @change="buscar">
                <option :value="null">Todos los asesores</option>
                <option :value="0">Sin asesor</option>
                <option v-for="a in asesores" :key="a.id" :value="a.id">{{ a.nombre }}</option>
              </select>
            </div>
            <div class="col-6 col-lg-2">
              <select v-model="filtroSituacion" class="form-select form-select-sm" aria-label="Situación" @change="buscar">
                <option :value="null">Toda situación</option>
                <option v-for="(p, clave) in PRESENTACION_SITUACION" :key="clave" :value="clave">{{ p.texto }}</option>
              </select>
            </div>
            <div class="col-12 col-lg-2">
              <div class="form-check mb-0">
                <input id="filtro-ficha" v-model="filtroFicha" type="checkbox" class="form-check-input" @change="buscar" />
                <label class="form-check-label small" for="filtro-ficha">Ficha incompleta</label>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr class="small text-secondary text-uppercase">
                <th class="ps-3">Cliente</th>
                <th>Teléfono</th>
                <th v-if="contexto.creditos">Asesor</th>
                <th v-if="contexto.creditos">Situación</th>
                <th v-if="contexto.creditos">Ficha</th>
                <th v-if="!contexto.creditos">Tipo</th>
                <th>Ubigeo</th>
                <th v-if="!contexto.creditos">Amazonía</th>
                <th>Estado</th>
                <th>Registro</th>
                <th class="text-end pe-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="cargando"><td :colspan="columnas" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</td></tr>
              <tr v-else-if="clients.length === 0"><td :colspan="columnas" class="text-center text-muted py-4">No hay clientes{{ search ? ' con esa búsqueda' : '' }}.</td></tr>
              <tr v-for="client in clients" v-else :key="client.id" role="button" @click="abrir(client)">
                <td class="ps-3">
                  <div class="fw-semibold">{{ client.full_name }}</div>
                  <small class="text-muted">{{ client.type_document === 'SND' ? 'Sin documento' : `${client.type_document} ${client.n_document}` }}</small>
                </td>
                <td>{{ client.phone || '—' }}</td>
                <template v-if="contexto.creditos">
                  <td class="small">{{ client.credito?.asesor ?? '—' }}</td>
                  <td>
                    <span v-if="client.credito" class="badge" :class="PRESENTACION_SITUACION[client.credito.situacion].clase">
                      {{ PRESENTACION_SITUACION[client.credito.situacion].texto }}
                    </span>
                  </td>
                  <td class="small">
                    <span v-if="!client.credito?.ficha_faltante" class="text-success"><i class="fas fa-check-circle me-1"></i>Completa</span>
                    <span v-else class="text-warning-emphasis"><i class="fas fa-exclamation-triangle me-1"></i>Faltan {{ client.credito.ficha_faltante }}</span>
                  </td>
                </template>
                <td v-else><span class="badge" :class="TIPO[client.type_client]?.clase ?? TIPO['3'].clase">{{ TIPO[client.type_client]?.texto ?? TIPO['3'].texto }}</span></td>
                <td class="small text-muted">{{ [client.distrito, client.region].filter(Boolean).join(', ') || '—' }}</td>
                <td v-if="!contexto.creditos" class="text-center">
                  <span v-if="client.es_amazonia" class="badge bg-success-subtle text-success-emphasis" title="Zona Amazonía — Ley 27037"><i class="fas fa-tree me-1"></i>Sí</span>
                  <span v-else class="text-muted small">—</span>
                </td>
                <td>
                  <span class="badge" :class="client.state == 1 ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'">
                    {{ client.state == 1 ? 'Activo' : 'Inactivo' }}
                  </span>
                </td>
                <td class="small text-muted">{{ formatFechaHora(client.created_at) }}</td>
                <td class="text-end pe-3 text-nowrap" @click.stop>
                  <button type="button" class="btn btn-sm btn-outline-primary me-1" title="Ver / editar" @click="abrir(client)"><i class="fas fa-pen"></i></button>
                  <button v-if="puede('delete_client')" type="button" class="btn btn-sm btn-outline-danger" title="Eliminar" @click="removeClient(client)">
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <small class="text-muted">Mostrar</small>
          <select v-model.number="perPageRows" class="form-select form-select-sm w-auto" aria-label="Filas por página" @change="cambiarPerPage">
            <option :value="15">15</option>
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          <small class="text-muted">de {{ totalPages }}</small>
        </div>
        <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows" size="sm" prev-text="Anterior" next-text="Siguiente" class="mb-0" />
      </div>
    </div>
    <TraspasoCartera v-if="contexto.creditos" v-model="traspaso" @hecho="list" />
  </DefaultLayout>
</template>

<script setup lang="ts">
// Clientes, todos los giros. Alta y edición van a la página del cliente (Fase 4c,
// views/clients/ficha.vue); el modal de antes quedó reemplazado.
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Swal from 'sweetalert2'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import TraspasoCartera from '@/components/Clientes/TraspasoCartera.vue'
import httpClient from '@/helpers/http-client'
import { formatFechaHora } from '@/helpers/fecha'
import { usePermisosCredito } from '@/composables/creditos/usePermisosCredito'
import { clienteService, type ContextoCliente } from '@/services/admin/clienteService'
import { creditoService } from '@/services/admin/creditoService'
import { PRESENTACION_SITUACION, type SituacionCliente } from '@/helpers/creditos/ficha'
import type { UsuarioCartera } from '@/types/creditos'
import type { Client, Clients } from '@/types/clients'

const TIPO: Record<string, { texto: string; clase: string }> = {
  '1': { texto: 'Final', clase: 'bg-success-subtle text-success-emphasis' },
  '2': { texto: 'Empresa', clase: 'bg-info-subtle text-info-emphasis' },
  '3': { texto: 'Cualquiera', clase: 'bg-warning-subtle text-warning-emphasis' },
}

const router = useRouter()
const { puede } = usePermisosCredito()

const search = ref('')
const currentPage = ref(1)
const totalPages = ref(0)
const perPageRows = ref(25)
const clients = ref<Client[]>([])
const cargando = ref(false)
const contexto = ref<ContextoCliente>({ giro: null, creditos: false })
const asesores = ref<UsuarioCartera[]>([])
const filtroAsesor = ref<number | null>(null)
const filtroSituacion = ref<SituacionCliente | null>(null)
const filtroFicha = ref(false)
const traspaso = ref(false)
const columnas = computed(() => (contexto.value.creditos ? 9 : 8))

const abrir = (client: Client) => router.push({ name: 'clients.ficha', params: { id: client.id } })

// ── Cliente sin datos (boleta < S/ 700) ─────────────────────────────
// No se guarda: es el cliente genérico que Ventas usa para consumidor final.
const setClienteSinDatos = () => {
  Swal.fire({
    icon: 'info',
    title: 'Cliente: CLIENTES VARIOS',
    text: 'Se usará para boletas de consumidor final sin datos. El comprobante no debe superar S/ 700.',
    timer: 2500,
    showConfirmButton: false,
  })
}

const removeClient = async (client: Client) => {
  const r = await Swal.fire({
    title: '¿Eliminar cliente?',
    text: `Se eliminará a "${client.full_name}".`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Sí, eliminar',
    cancelButtonText: 'Cancelar',
  })
  if (!r.isConfirmed) return
  try {
    const res = await httpClient.delete(`clients/${client.id}`)
    // 04c: un cliente con créditos no se elimina (code 405).
    if (res.data?.code !== 200) {
      Swal.fire('No se eliminó', res.data?.message ?? 'No se pudo eliminar.', 'warning')
      return
    }
    clients.value = clients.value.filter((c) => c.id !== client.id)
    Swal.fire('Eliminado', `"${client.full_name}" fue eliminado.`, 'success')
  } catch (e: any) {
    Swal.fire('Error', e?.response?.data?.message ?? 'No se pudo eliminar.', 'error')
  }
}

const list = async () => {
  cargando.value = true
  try {
    const filtros = contexto.value.creditos
      ? {
          ...(filtroAsesor.value !== null ? { asesor_id: filtroAsesor.value } : {}),
          ...(filtroSituacion.value ? { situacion: filtroSituacion.value } : {}),
          ...(filtroFicha.value ? { ficha_incompleta: 1 } : {}),
        }
      : {}
    const res = await httpClient.get<Clients>('clients', { params: { page: currentPage.value, search: search.value ?? '', per_page: perPageRows.value, ...filtros } })
    clients.value = res.data.clients.data
    totalPages.value = res.data.total
    perPageRows.value = res.data.paginate
  } catch (error) {
    console.error(error)
  } finally {
    cargando.value = false
  }
}

/** Vuelve a la página 1; si ya estaba ahí, el watch no dispara y se lista directo. */
const buscar = () => {
  if (currentPage.value !== 1) currentPage.value = 1
  else list()
}

const reset = () => {
  search.value = ''
  filtroAsesor.value = null
  filtroSituacion.value = null
  filtroFicha.value = false
  buscar()
}

const cambiarPerPage = () => buscar()

watch(currentPage, () => list())

onMounted(async () => {
  // El contexto decide columnas y filtros: se pide antes de listar.
  try {
    contexto.value = await clienteService.contexto()
  } catch {
    // Sin contexto se lista como en cualquier giro.
  }
  if (contexto.value.creditos && puede('creditos.cartera.asignar')) {
    creditoService.usuariosCartera().then((u) => { asesores.value = u.asesores }).catch(() => undefined)
  }
  await list()
})
</script>
