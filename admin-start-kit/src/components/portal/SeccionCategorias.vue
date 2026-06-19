<!-- frontend/src/components/Portal/SeccionCategorias.vue -->
<template>
  <section class="py-5 bg-black">
    <div class="section-label">
      <div class="section-label-line"></div>
      <span class="section-label-text">Explorar</span>
    </div>
    <div class="container">
      <h2 class="text-center text-white fw-bold mb-4">CATEGORÍAS <span class="text-danger">POPULARES</span></h2>
      <div class="row g-3">

        <!-- Cargando -->
        <div v-if="cargando" class="text-center text-white-50 py-4">
          <div class="spinner-border text-danger" role="status"></div>
          <p class="mt-2">Cargando categorías...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="text-center text-danger py-4">
          {{ error }}
        </div>

        <!-- Categorías -->
        <div v-else v-for="cat in categoriasDisponibles" :key="cat.id" class="col-6 col-md-4 col-lg-2">
          <div class="card text-center bg-dark border-secondary h-100 category-card" @click="irACategoria(cat.id)" ">
            <div class="card-body d-flex flex-column align-items-center justify-content-center gap-2">
              <img :src="getImageUrl(cat.imagen)":alt="cat.title" class="category-img" />
              <h6 class="card-title text-white mb-0">{{ cat.title }}</h6>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { productoService, type CategoriaPortal } from '@/services/portal/productoService'

const router = useRouter()
const categoriasDisponibles = ref<CategoriaPortal[]>([])
const cargando = ref(true)
const error = ref<string | null>(null)

const cargarCategorias = async () => {
  cargando.value = true
  error.value = null
  try {
    const cats = await productoService.obtenerCategorias()
    // Si el backend devuelve solo id, title, imagen, los usamos directamente
    categoriasDisponibles.value = cats
  } catch (err) {
    console.error(err)
    error.value = 'No se pudieron cargar las categorías'
  } finally {
    cargando.value = false   // ← crucial: apagar el spinner
  }
}
const getImageUrl = (path: string) => {
  if (!path) return '/img/default.png'
  if (path.startsWith('http')) return path
  const storageUrl = import.meta.env.APP_URL || 'http://localhost:8000/storage'
  return `${storageUrl}/${path}`
}
const irACategoria = (id: string) => {
  router.push({ path: '/catalogo', query: { categorie_id: id.toString() } })
}

onMounted(() => {
  cargarCategorias()
})
</script>

<style scoped>

.category-img {
  width: 100px;
  height: 100px;
  object-fit: contain;
  border-radius: 10px;
}

.category-card {
  cursor: pointer;
  transition: all 0.2s;
}

.category-card:hover {
  border-color: #dc3545;
  background-color: #1f1f1f !important;
}

.bg-black {
  background-color: #000000;
}

.section-label {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 24px;
}

.section-label-line {
  width: 32px;
  height: 3px;
  background: var(--rojo);
  border-radius: 2px;
}

.section-label-text {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 3px;
  text-transform: uppercase;
  color: var(--rojo);
}
</style>