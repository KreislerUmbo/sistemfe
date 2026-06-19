<template>
  <div class="bg-black text-white min-vh-100">
    <!-- Breadcrumb -->
    <div class="container py-3" v-if="producto">
      <div class="d-flex align-items-center gap-2 small text-muted">
        <router-link to="/" class="text-white-50 text-decoration-none">Inicio</router-link>
        <span>›</span>
        <router-link to="/catalogo" class="text-white-50 text-decoration-none">Catalogo</router-link>
        <span>›</span>
        <router-link v-if="producto.categoria_id" :to="`/catalogo?categorie_id=${producto.categoria_id}`"
          class="text-white-50 text-decoration-none">
          {{ producto.categoria }}
        </router-link>
        <span v-else class="text-white-50">{{ producto.categoria }}</span>
        <span>›</span>
        <span class="text-white">{{ producto.nombre }}</span>
      </div>
    </div>

    <div class="container py-4">
      <div class="row g-5" v-if="!loading && producto">
        <!-- COLUMNA IZQUIERDA: Imagen + TABS -->
        <div class="col-lg-6">
          <!-- Imagen principal -->
          <div class="position-relative rounded-4 overflow-hidden bg-gradient-dark p-4 text-center mb-4">
            <img :src="producto.imagen" class="img-fluid rounded-3" :alt="producto.nombre"
              style="max-height: 400px; object-fit: contain;">
            <div v-if="producto.stock <= 3" class="position-absolute top-0 end-0 m-3">
              <span class="badge bg-warning text-dark">¡Stock limitado!</span>
            </div>
          </div>

          <!-- TABS: Especificaciones / Descripción / Reseñas -->
          <div class="product-tabs">
            <ul class="nav nav-tabs border-bottom-0 gap-2" id="productTab" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#specs" type="button"
                  role="tab">Especificaciones</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#description" type="button"
                  role="tab">Descripción</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#reviews" type="button" role="tab">Reseñas
                  ({{ reseñas.length }})</button>
              </li>
            </ul>
            <div class="tab-content bg-dark-2 rounded-4 p-4 mt-3 border border-secondary">
              <!-- Especificaciones -->
              <div class="tab-pane fade show active" id="specs" role="tabpanel">
                <div class="row g-3">
                  <div v-for="(spec, idx) in producto.especificaciones" :key="idx" class="col-md-6">
                    <div class="spec-card p-3 rounded-3 bg-black border border-secondary h-100">
                      <div class="text-muted small text-uppercase">{{ spec.key }}</div>
                      <div class="text-white fw-semibold mt-1">{{ spec.val }}</div>
                    </div>
                  </div>
                </div>
              </div>
              <!-- Descripción -->
              <div class="tab-pane fade" id="description" role="tabpanel">
                <div v-html="producto.descripcion || 'Sin descripción disponible.'" class="text-white-50 lh-lg"></div>
              </div>
              <!-- Reseñas (mock por ahora) -->
              <div class="tab-pane fade" id="reviews" role="tabpanel">
                <div
                  class="reviews-summary d-flex flex-wrap gap-4 align-items-center mb-4 p-4 rounded-4 bg-black border border-secondary">
                  <div class="text-center">
                    <div class="display-1 fw-bold text-white">{{ promedioRating }}</div>
                    <div class="text-warning fs-5">{{ estrellasPromedio }}</div>
                    <small class="text-muted">{{ reseñas.length }} reseñas</small>
                  </div>
                  <div class="flex-grow-1">
                    <div v-for="n in [5, 4, 3, 2, 1]" :key="n" class="d-flex align-items-center gap-2 mb-2">
                      <span class="small text-muted" style="width: 20px;">{{ n }}</span>
                      <span class="text-warning small">★</span>
                      <div class="progress flex-grow-1" style="height: 6px;">
                        <div class="progress-bar bg-warning" :style="{ width: ratingPorcentaje(n) + '%' }"></div>
                      </div>
                      <span class="small text-muted" style="width: 40px;">{{ ratingPorcentaje(n) }}%</span>
                    </div>
                  </div>
                </div>
                <div v-for="review in reseñas" :key="review.id"
                  class="review-card mb-3 p-3 rounded-4 bg-black border border-secondary">
                  <div class="d-flex gap-3 mb-2">
                    <div class="avatar rounded-circle bg-danger d-flex align-items-center justify-content-center"
                      style="width: 40px; height: 40px;">{{ review.nombre[0] }}</div>
                    <div>
                      <div class="fw-bold">{{ review.nombre }}</div>
                      <div class="small text-muted">{{ review.fecha }}</div>
                    </div>
                    <div class="ms-auto text-warning">{{ '★'.repeat(review.rating) }}</div>
                  </div>
                  <div class="text-white-50 small mb-2">{{ review.comentario }}</div>
                  <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary">👍 Útil ({{ review.helpful }})</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- COLUMNA DERECHA: Info + compra (sin cambios) -->
        <div class="col-lg-6">
          <!-- ... aquí el contenido que ya tenías (precio, cantidad, botones, garantías, etc.) ... -->
          <div class="mb-3">
            <span class="badge bg-secondary">{{ producto.categoria }}</span>
            <h1 class="display-6 fw-bold mt-2">{{ producto.nombre }}</h1>
            <div class="d-flex align-items-center gap-3 mt-2">
              <div class="text-warning">
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star-half"></i>
                <span class="text-white-50 ms-2">{{ promedioRating }} ({{ reseñas.length }} reseñas)</span>
              </div>
            </div>
          </div>

          <div class="price-block bg-dark-2 p-3 rounded-4 mb-4">
            <div class="d-flex align-items-baseline gap-3">
              <span class="display-5 fw-bold text-danger">S/ {{ producto.precio.toFixed(2) }}</span>
              <span v-if="producto.precioOriginal" class="text-decoration-line-through text-white-50">S/ {{
                producto.precioOriginal.toFixed(2) }}</span>
              <span v-if="producto.descuento" class="badge bg-danger">-{{ producto.descuento }}%</span>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label fw-bold">Cantidad</label>
            <div class="d-flex align-items-center gap-3">
              <div class="input-group" style="width: 150px;">
                <button class="btn btn-outline-secondary" @click="cantidad--" :disabled="cantidad <= 1">-</button>
                <input type="number" class="form-control text-center bg-dark text-white border-secondary"
                  v-model.number="cantidad" min="1" :max="producto.stock">
                <button class="btn btn-outline-secondary" @click="cantidad++"
                  :disabled="cantidad >= producto.stock">+</button>
              </div>
              <span class="text-muted">Stock disponible: <strong>{{ producto.stock }}</strong> unidades</span>
            </div>
          </div>

          <div class="d-grid gap-2">
            <button class="btn btn-danger py-3 fw-bold" @click="comprarAhora">
              ⚡ Comprar ahora
            </button>

            <!-- Si el producto ya está en carrito, mostramos botón "Quitar" -->
            <template v-if="yaEnCarrito">
              <button class="btn btn-outline-danger py-2" @click="quitarDelCarrito">
                🗑️ Quitar del carrito
              </button>
            </template>

            <!-- Siempre mostramos el botón de agregar (con la cantidad seleccionada) -->
            <button class="btn btn-outline-light py-2" @click="agregarAlCarrito"
              :disabled="cantidad > producto.stock || producto.stock === 0">
              🛒 Agregar al carrito (x{{ cantidad }})
            </button>
          </div>

          <hr class="border-secondary my-4">

          <div class="row g-2 text-center small">
            <div class="col-6"><i class="bi bi-shield-shaded me-1"></i> Garantía 1 año</div>
            <div class="col-6"><i class="bi bi-arrow-repeat me-1"></i> Devolución 30 días</div>
            <div class="col-6"><i class="bi bi-lock me-1"></i> Pago seguro</div>
            <div class="col-6"><i class="bi bi-headset me-1"></i> Soporte técnico</div>
          </div>

          <div class="mt-4 p-3 rounded-3 bg-dark-2 border border-success-subtle">
            <div class="d-flex gap-2"><i class="bi bi-truck text-success"></i> <span><strong>Envío gratis</strong> a
                Lima Metropolitana</span></div>
            <div class="d-flex gap-2 mt-1"><i class="bi bi-calendar-check text-success"></i> <span>Entrega estimada:
                <strong>mañana</strong></span></div>
            <div class="d-flex gap-2 mt-1"><i class="bi bi-shop text-success"></i> <span>Retiro en tienda
                disponible</span></div>
          </div>
        </div>
      </div>

      <!-- PRODUCTOS RELACIONADOS (mock) -->
      <div class="row mt-5" v-if="!loading && producto">
        <div class="col-12">
          <h3 class="mb-4">Productos relacionados</h3>
          <div class="row g-4">
            <div v-for="rel in productosRelacionados" :key="rel.id" class="col-md-3 col-6">
              <div class="related-card p-3 rounded-3 bg-dark-2 border border-secondary text-center h-100"
                @click="irProducto(rel.id)">
                <img :src="rel.imagen" class="img-fluid rounded mb-2" style="height: 100px; object-fit: contain;">
                <div class="small fw-bold">{{ rel.nombre }}</div>
                <div class="text-danger fw-bold">S/ {{ rel.precio.toFixed(2) }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Loader y error -->
      <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-danger" role="status"></div>
        <p class="mt-2">Cargando producto...</p>
      </div>
      <div v-if="error" class="alert alert-danger text-center">{{ error }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCarritoStore } from '@/stores/carrito'
import { useUiStore } from '@/stores/ui' // si usas drawer
import Swal from 'sweetalert2' // opcional para notificaciones
import { productoService, type ProductoPortal } from '@/services/portal/productoService'
// Computed para saber si el producto ya está en el carrito
const yaEnCarrito = computed(() => carritoStore.isInCart(producto.value?.id))


const route = useRoute()
const router = useRouter()
const carritoStore = useCarritoStore()
const uiStore = useUiStore()

const producto = ref<ProductoPortal | null>(null)
const loading = ref(true)
const error = ref('')
const cantidad = ref(1)

// Datos mock de reseñas y relacionados (mientras implementas backend)
const reseñas = ref([
  { id: 1, nombre: 'Carlos M.', fecha: '12/04/2025', rating: 5, comentario: 'Excelente producto, llegó rápido y bien empaquetado.', helpful: 14 },
  { id: 2, nombre: 'María L.', fecha: '03/04/2025', rating: 4, comentario: 'Muy buena calidad, aunque un poco caliente bajo carga pesada.', helpful: 9 },
  { id: 3, nombre: 'Roberto S.', fecha: '28/03/2025', rating: 5, comentario: 'Cumple con lo prometido, la recomiendo.', helpful: 6 },
])

const productosRelacionados = ref([
  { id: 2, nombre: 'Lenovo IdeaPad 3', precio: 2299, imagen: 'https://via.placeholder.com/150' },
  { id: 3, nombre: 'Asus VivoBook 15', precio: 1749, imagen: 'https://via.placeholder.com/150' },
  { id: 4, nombre: 'Dell Inspiron 15', precio: 2099, imagen: 'https://via.placeholder.com/150' },
  { id: 5, nombre: 'Acer Aspire 5', precio: 1599, imagen: 'https://via.placeholder.com/150' },
])

// Cálculo de rating promedio (mock)
const promedioRating = computed(() => {
  if (reseñas.value.length === 0) return 0
  const suma = reseñas.value.reduce((acc, r) => acc + r.rating, 0)
  return (suma / reseñas.value.length).toFixed(1)
})

const estrellasPromedio = computed(() => {
  const rating = parseFloat(promedioRating.value)
  return '★'.repeat(Math.floor(rating)) + (rating % 1 ? '½' : '')
})

const ratingPorcentaje = (stars: number) => {
  const total = reseñas.value.length
  if (total === 0) return 0
  const count = reseñas.value.filter(r => r.rating === stars).length
  return (count / total) * 100
}

// Cargar producto real desde API
const cargarProducto = async () => {
  const id = Number(route.params.id)//route.params.id esto lo que hace es obtener el id de la url 
  if (!id || isNaN(id)) { //isNaN es para saber si el id es un numero
    error.value = 'ID de producto inválido'//si el id no es un numero
    loading.value = false
    return
  }
  try {
    const data = await productoService.obtenerPorId(id)
    producto.value = data
    // Ajustar imagen si es relativa
    if (producto.value.imagen && !producto.value.imagen.startsWith('http')) {
      const storageUrl = import.meta.env.VITE_STORAGE_URL || 'http://localhost:8000/storage'
      producto.value.imagen = `${storageUrl}/${producto.value.imagen}`
    }
    // Asegurar especificaciones si vienen vacías
    if (!producto.value.especificaciones || producto.value.especificaciones.length === 0) {
      producto.value.especificaciones = [
        { key: 'Stock', val: producto.value.stock + ' unidades' },
        { key: 'Categoría', val: producto.value.categoria },

      ]
    }
  } catch (err: any) {
    console.error(err)
    error.value = err.response?.status === 404 ? 'Producto no encontrado' : 'Error al cargar el producto'
  } finally {
    loading.value = false
  }
}

const agregarAlCarrito = () => {
  if (!producto.value) return
  carritoStore.agregarProducto({
    id: producto.value.id,
    nombre: producto.value.nombre,
    precio: producto.value.precio,
    imagen: producto.value.imagen,
    cantidad: cantidad.value
  })
  // Notificación opcional
  Swal.fire({
    icon: 'success',
    title: 'Agregado',
    text: `${producto.value.nombre} x${cantidad.value} añadido al carrito`,
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2000
  })
  // Abrir drawer automáticamente? (opcional)
  // uiStore.openCartDrawer()
}


// Función para eliminar completamente el producto del carrito
const quitarDelCarrito = () => {
  if (!producto.value) return
  carritoStore.eliminarProducto(producto.value.id)
  Swal.fire({
    icon: 'info',
    title: 'Eliminado',
    text: `${producto.value.nombre} fue removido del carrito`,
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2000
  })
}



const comprarAhora = () => {
  agregarAlCarrito()
  router.push('/carrito')
}

const irProducto = (id: number) => {
  router.push(`/tienda/productos/${id}`)
}

// Inicializar tabs de Bootstrap después de montar el componente
onMounted(() => {
  cargarProducto()
  // Inicializar los tabs de Bootstrap (necesario si usas Vue)
  import('bootstrap').then(({ Tab }) => {
    const triggerTabList = [].slice.call(document.querySelectorAll('#productTab button'))
    triggerTabList.forEach(triggerEl => {
      new Tab(triggerEl)
    })
  })
})
</script>

<style scoped>
.bg-dark-2 {
  background-color: #1a1a1a;
}

.bg-gradient-dark {
  background: linear-gradient(135deg, #1a0005, #220009);
}

.spec-card,
.related-card {
  transition: all 0.2s;
  cursor: pointer;
}

.spec-card:hover,
.related-card:hover {
  border-color: #dc3545 !important;
  transform: translateY(-3px);
}

.nav-tabs .nav-link {
  background-color: #222;
  color: #ccc;
  border: 1px solid #444;
  border-radius: 30px;
  padding: 8px 20px;
}

.nav-tabs .nav-link.active {
  background-color: #dc3545;
  color: white;
  border-color: #dc3545;
}

.progress-bar {
  border-radius: 3px;
}

.avatar {
  font-weight: bold;
}
</style>