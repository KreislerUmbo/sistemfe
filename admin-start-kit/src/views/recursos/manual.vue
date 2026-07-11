<!-- frontend/src/views/manuales/Galeria.vue -->
<template>
  <DefaultLayout>
    <b-container fluid>
      <b-row class="mb-4">
        <b-col>
          <h2><i class="las la-video"></i> Manuales y Capacitación</h2>
          <p class="text-muted">Recursos multimedia para aprender a usar los sistemas</p>
        </b-col>
      </b-row>

      <!-- Buscador y filtros -->
      <b-row class="mb-3 align-items-center">
        <b-col md="5">
          <b-input-group>
            <b-form-input v-model="search" placeholder="Buscar manuales, videos, documentos..." @input="buscar" />
            <b-input-group-append>
              <b-button variant="dark" @click="buscar"><i class="las la-search"></i></b-button>
            </b-input-group-append>
          </b-input-group>
        </b-col>
        <b-col md="3">
          <b-form-select v-model="filtroTipo" @change="buscar">
            <option value="">Todos los tipos</option>
            <option value="video">🎬 Videos</option>
            <option value="documento">📄 Documentos</option>
            <option value="imagen">🖼️ Imágenes</option>
            <option value="link">🔗 Enlaces</option>
          </b-form-select>
        </b-col>
        <b-col md="2">
          <b-form-select v-model="filtroCategoria" @change="buscar">
            <option value="">Todas las categorías</option>
            <option v-for="cat in categorias" :key="cat">{{ cat }}</option>
          </b-form-select>
        </b-col>
        <b-col md="2" class="text-end">
          <b-badge variant="info">{{ totalRecursos }} recursos</b-badge>
        </b-col>
      </b-row>

      <!-- Grid de recursos -->
      <b-row v-if="!cargando">
        <b-col v-for="recurso in recursos" :key="recurso.id" md="6" lg="4" xl="3" class="mb-4">
          <b-card class="h-100 shadow-hover" @click="verDetalle(recurso)">
            <div class="position-relative">
              <b-img :src="urlStorage(recurso.miniatura) || '/img/default-thumb.png'" fluid style="height: 160px; width: 100%; object-fit: cover; border-radius: 8px;" />
              <span class="position-absolute top-0 end-0 m-2 badge" :class="tipoBadge(recurso.tipo)">
                <i :class="tipoIcono(recurso.tipo)"></i> {{ recurso.tipo }}
              </span>
              <span v-if="recurso.destacado" class="position-absolute top-0 start-0 m-2 badge bg-warning text-dark">
                ⭐ Destacado
              </span>
            </div>
            <b-card-body>
              <h6 class="card-title fw-bold">{{ truncate(recurso.titulo, 50) }}</h6>
              <p class="card-text text-muted small">{{ truncate(recurso.descripcion, 80) }}</p>
              <div class="d-flex justify-content-between align-items-center">
                <span class="badge bg-secondary">{{ recurso.categoria || 'General' }}</span>
                <span class="text-muted small">{{ formatDate(recurso.created_at) }}</span>
              </div>
            </b-card-body>
          </b-card>
        </b-col>
        <b-col v-if="recursos.length === 0" class="text-center py-5">
          <i class="las la-folder-open display-1 text-muted"></i>
          <p class="mt-3">No se encontraron recursos con esos filtros.</p>
        </b-col>
      </b-row>

      <!-- Paginación -->
      <b-row v-if="totalPages > 1">
        <b-col>
          <b-pagination v-model="currentPage" :total-rows="totalRecursos" :per-page="perPage"
            prev-text="Anterior" next-text="Siguiente" @change="cargarRecursos" />
        </b-col>
      </b-row>
    </b-container>

    <!-- Modal de detalle -->
    <b-modal v-model="showModal" size="lg" :title="recursoSeleccionado?.titulo" hide-footer>
      <div v-if="recursoSeleccionado">
        <!-- Video -->
        <div v-if="recursoSeleccionado.tipo === 'video' && recursoSeleccionado.url">
          <div class="ratio ratio-16x9">
            <iframe :src="embedUrl(recursoSeleccionado.url)" allowfullscreen></iframe>
          </div>
        </div>
        <!-- Imagen -->
        <div v-else-if="recursoSeleccionado.tipo === 'imagen' && recursoSeleccionado.archivo">
          <b-img :src="urlStorage(recursoSeleccionado.archivo)" fluid />
        </div>
        <!-- Documento / Link -->
        <div v-else>
          <p v-html="recursoSeleccionado.descripcion"></p>
          <a v-if="recursoSeleccionado.url" :href="recursoSeleccionado.url" target="_blank" class="btn btn-danger">🔗 Abrir enlace</a>
          <a v-if="recursoSeleccionado.archivo" :href="urlStorage(recursoSeleccionado.archivo)" download class="btn btn-danger">⬇️ Descargar</a>
        </div>
        <hr />
        <div class="d-flex gap-2">
          <span class="badge bg-secondary">{{ recursoSeleccionado.categoria || 'General' }}</span>
          <span class="badge" :class="tipoBadge(recursoSeleccionado.tipo)">{{ recursoSeleccionado.tipo }}</span>
          <span class="text-muted small">{{ formatDate(recursoSeleccionado.created_at) }}</span>
        </div>
      </div>
    </b-modal>
  </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import { ref, onMounted } from 'vue'
import { recursoService } from '@/services/admin/recursoService'

const recursos = ref([])
const categorias = ref<string[]>([])
const search = ref('')
const filtroTipo = ref('')
const filtroCategoria = ref('')
const currentPage = ref(1)
const totalRecursos = ref(0)
const totalPages = ref(0)
const perPage = ref(12)
const cargando = ref(true)
const showModal = ref(false)
const recursoSeleccionado = ref<any>(null)

const urlStorage = (path: string) => {
  if (!path) return ''
  if (path.startsWith('http')) return path
  return import.meta.env.VITE_STORAGE_URL + '/' + path
}

const truncate = (text: string, len: number) => text?.length > len ? text.slice(0, len) + '…' : text
const formatDate = (date: string) => new Date(date).toLocaleDateString('es-PE')
const embedUrl = (url: string) => {
  if (url.includes('youtube.com/watch')) {
    const videoId = url.split('v=')[1]?.split('&')[0]
    return `https://www.youtube.com/embed/${videoId}`
  }
  if (url.includes('youtu.be')) {
    const videoId = url.split('/').pop()
    return `https://www.youtube.com/embed/${videoId}`
  }
  return url
}

const tipoBadge = (tipo: string) => {
  const map: Record<string, string> = { video: 'bg-danger', documento: 'bg-primary', imagen: 'bg-success', link: 'bg-info' }
  return map[tipo] || 'bg-secondary'
}
const tipoIcono = (tipo: string) => {
  const map: Record<string, string> = { video: 'bi-play-circle', documento: 'bi-file-earmark-pdf', imagen: 'bi-image', link: 'bi-link-45deg' }
  return map[tipo] || 'bi-file'
}

const cargarRecursos = async () => {//aqui lo que hago es llamar al metodo listar que esta en el recursoService
  cargando.value = true
  try {
    const params = {
      page: currentPage.value,
      search: search.value,
      tipo: filtroTipo.value,
      categoria: filtroCategoria.value,
      per_page: perPage.value
    }
    const res = await recursoService.listarPublicos(params)
    recursos.value = res.data || []
    totalRecursos.value = res.total || 0
    totalPages.value = res.last_page || 1
    // Extraer categorías para filtro
    const cats = new Set(recursos.value.map(r => r.categoria).filter(Boolean))
    categorias.value = Array.from(cats)
  } catch (e) { console.error(e) }
  finally { cargando.value = false }
}

const buscar = () => {
  currentPage.value = 1
  cargarRecursos()
}

const verDetalle = (recurso: any) => {
  recursoSeleccionado.value = recurso
  showModal.value = true
}

onMounted(cargarRecursos)
</script>

<style scoped>
.shadow-hover {
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
}
.shadow-hover:hover {
  transform: translateY(-5px);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}
</style>