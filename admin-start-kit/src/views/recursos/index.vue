<!-- frontend/src/views/manuales/Index.vue -->
<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card-header>
          <b-card-title>📚 Manuales y Capacitación</b-card-title>
          <b-row class="align-items-center justify-content-between mt-3">
            <b-col lg="1" sm="1">
              <label for="search">Buscar:</label>
            </b-col>
            <b-col lg="6" sm="2" class="text-center">
              <b-form-input type="text" id="search" v-model="search"
                placeholder="Buscar por título, categoría..." @keyup.enter="listar" />
            </b-col>
            <b-col lg="3" sm="3">
              <b-button type="button" @click="listar" variant="success">
                <i class="fas fa-search"></i>
              </b-button>
              <b-button type="button" @click="reset" variant="dark" class="mx-2">
                <i class="fas fa-sync"></i>
              </b-button>
            </b-col>
            <b-col lg="2" sm="2">
              <b-button type="button" variant="success" to="/manuales/register">
                <i class="far fa-plus-square ml-2"></i> Nuevo Recurso
              </b-button>
            </b-col>
          </b-row>
          <b-row class="align-items-center justify-content-between mt-1">
            <b-col sm="2">
              <label for="tipo">Tipo:</label>
            </b-col>
            <b-col lg="2" md="1">
              <b-form-select id="tipo" v-model="filtroTipo">
                <option value="">Todos</option>
                <option value="video">🎬 Video</option>
                <option value="documento">📄 Documento</option>
                <option value="imagen">🖼️ Imagen</option>
                <option value="link">🔗 Enlace</option>
              </b-form-select>
            </b-col>
            <b-col lg="2" md="1">
              <b-form-select id="estado" v-model="filtroEstado">
                <option value="">Estado</option>
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
              </b-form-select>
            </b-col>
            <b-col sm="2">
              <b-form-checkbox v-model="filtroDestacado" switch>
                Destacados
              </b-form-checkbox>
            </b-col>
          </b-row>
        </b-card-header>

        <b-card-body class="pt-0 mt-2">
          <b-table-simple responsive striped class="mb-0 table-centered">
            <b-thead class="table-light">
              <b-tr>
                <b-th>#</b-th>
                <b-th>Miniatura</b-th>
                <b-th>Título</b-th>
                <b-th>Tipo</b-th>
                <b-th>Categoría</b-th>
                <b-th>Destacado</b-th>
                <b-th>Estado</b-th>
                <b-th class="text-end">Acciones</b-th>
              </b-tr>
            </b-thead>
            <b-tbody>
              <b-tr v-for="(recurso, index) in recursos" :key="recurso.id">
                <b-td>{{ index + 1 }}</b-td>
                <b-td>
                  <img v-if="recurso.miniatura" :src="urlStorage(recurso.miniatura)" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px;" alt="Miniatura">
                  <span v-else class="text-muted">Sin imagen</span>
                </b-td>
                <b-td>
                  <strong>{{ recurso.titulo }}</strong>
                  <div class="text-muted small">{{ truncate(recurso.descripcion, 60) }}</div>
                </b-td>
                <b-td>
                  <b-badge :variant="tipoBadge(recurso.tipo)">
                    {{ tipoIcono(recurso.tipo) }} {{ recurso.tipo }}
                  </b-badge>
                </b-td>
                <b-td>{{ recurso.categoria || 'General' }}</b-td>
                <b-td>
                  <b-badge v-if="recurso.destacado" variant="warning">⭐ Destacado</b-badge>
                  <span v-else class="text-muted">—</span>
                </b-td>
                <b-td>
                  <b-badge :variant="recurso.estado ? 'success' : 'danger'">
                    {{ recurso.estado ? 'Activo' : 'Inactivo' }}
                  </b-badge>
                </b-td>
                <b-td class="text-end">
                  <button type="button" class="btn btn-link p-0 border-0" @click="verDetalle(recurso.id)">
                    <i class="las la-eye text-secondary fs-22"></i>
                  </button>
                  <button type="button" class="btn btn-link p-0 border-0" @click="editar(recurso.id)">
                    <i class="las la-pen text-secondary fs-22"></i>
                  </button>
                  <button type="button" class="btn btn-link p-0 border-0" @click="eliminar(recurso)">
                    <i class="las la-trash-alt text-secondary fs-22"></i>
                  </button>
                </b-td>
              </b-tr>
            </b-tbody>
          </b-table-simple>
          <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="perPage"
            prev-text="Anterior" next-text="Siguiente" />
        </b-card-body>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import Swal from 'sweetalert2'
import { recursoService, type RecursoManual } from '@/services/admin/recursoService'

const router = useRouter()

const recursos = ref<RecursoManual[]>([])
const search = ref('')
const filtroTipo = ref('')
const filtroEstado = ref('')
const filtroDestacado = ref(false)
const currentPage = ref(1)
const totalRows = ref(0)
const perPage = ref(15)

const urlStorage = (path: string) => {
  if (!path) return ''
  if (path.startsWith('http')) return path
  return import.meta.env.VITE_STORAGE_URL + '/' + path
}

const truncate = (text: string, len: number) => {
  if (!text) return ''
  return text.length > len ? text.slice(0, len) + '…' : text
}

const tipoIcono = (tipo: string) => {
  const map: Record<string, string> = {
    video: '🎬',
    documento: '📄',
    imagen: '🖼️',
    link: '🔗'
  }
  return map[tipo] || '📎'
}

const tipoBadge = (tipo: string) => {
  const map: Record<string, string> = {
    video: 'danger',
    documento: 'primary',
    imagen: 'success',
    link: 'info'
  }
  return map[tipo] || 'secondary'
}

const listar = async () => {
  try {
    const params = {
      page: currentPage.value,
      search: search.value,
      tipo: filtroTipo.value,
      estado: filtroEstado.value,
      destacado: filtroDestacado.value ? 1 : undefined
    }
    const res = await recursoService.listar(params)
    recursos.value = res.data || []
    totalRows.value = res.total || 0
  } catch (error) {
    console.error(error)
  }
}

const reset = () => {
  search.value = ''
  filtroTipo.value = ''
  filtroEstado.value = ''
  filtroDestacado.value = false
  currentPage.value = 1
  listar()
}

const verDetalle = (id: number) => {
  router.push(`/recursos/${id}`)
}

const editar = (id: number) => {
  router.push(`/recursos/register/${id}`)
}

const eliminar = (recurso: RecursoManual) => {
  Swal.fire({
    title: 'Confirmar eliminación',
    text: `¿Estás seguro de eliminar "${recurso.titulo}"?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#3085d6',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Sí, eliminar'
  }).then(async (result) => {
    if (result.isConfirmed) {
      try {
        await recursoService.eliminar(recurso.id!)
        await listar()
        Swal.fire('¡Eliminado!', 'El recurso ha sido eliminado.', 'success')
      } catch (error) {
        Swal.fire('Error', 'No se pudo eliminar el recurso.', 'error')
      }
    }
  })
}

watch(currentPage, () => listar())

onMounted(() => {
  listar()
})
</script>