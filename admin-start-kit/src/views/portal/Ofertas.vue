<template>
  <div class="bg-black text-white">
    <!-- Breadcrumb -->
    <div class="border-bottom border-secondary py-2 bg-dark-2">
      <div class="container">
        <nav class="small">
          <router-link to="/" class="text-white-50 text-decoration-none">Inicio</router-link>
          <span class="text-muted mx-1">›</span>
          <span class="text-white">Ofertas</span>
        </nav>
      </div>
    </div>

    <!-- Hero de ofertas -->
    <section class="hero-ofertas py-5 position-relative overflow-hidden">
      <div class="container position-relative z-1">
        <div class="row align-items-center g-4">
          <div class="col-lg-7">
            <span class="badge bg-danger rounded-pill mb-3">🔥 Promociones especiales</span>
            <h1 class="display-3 fw-bold mb-3">
              OFERTAS <span class="text-danger">IMPERDIBLES</span>
            </h1>
            <p class="text-white-50 mb-4" style="font-size: 16px; max-width: 500px;">
              Aprovecha los mejores descuentos en tecnología y sistemas empresariales. 
              Ofertas por tiempo limitado.
            </p>
            <div class="d-flex flex-wrap gap-3">
              <button class="btn btn-danger btn-lg" @click="scrollToProductos">🛒 Ver productos</button>
              <button class="btn btn-outline-secondary btn-lg" @click="scrollToSistemas">🏢 Ver sistemas</button>
            </div>
            <div class="d-flex align-items-center gap-4 mt-4">
              <div><span class="fw-bold text-danger">-70%</span> <span class="text-muted small">en productos seleccionados</span></div>
              <div><span class="fw-bold text-danger">20%</span> <span class="text-muted small">en sistemas anuales</span></div>
            </div>
          </div>
          <div class="col-lg-5 text-center">
            <div class="bg-dark-2 p-4 rounded-4 border border-danger position-relative overflow-hidden">
              <div class="position-absolute top-0 end-0 bg-danger text-white px-3 py-1 rounded-bottom-start" style="font-size: 12px; font-weight: 700;">¡LIMITADO!</div>
              <span class="display-1">🔥</span>
              <div class="display-5 fw-bold text-danger">¡APROVECHA!</div>
              <p class="text-muted">Ofertas válidas hasta agotar stock</p>
              <div class="d-flex justify-content-center gap-4">
                <div>
                  <div class="display-6 fw-bold" id="countdown-days">00</div>
                  <div class="small text-muted">Días</div>
                </div>
                <div>
                  <div class="display-6 fw-bold" id="countdown-hours">00</div>
                  <div class="small text-muted">Horas</div>
                </div>
                <div>
                  <div class="display-6 fw-bold" id="countdown-minutes">00</div>
                  <div class="small text-muted">Min</div>
                </div>
                <div>
                  <div class="display-6 fw-bold" id="countdown-seconds">00</div>
                  <div class="small text-muted">Seg</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="hero-bg position-absolute top-0 start-0 w-100 h-100 z-0" style="background: radial-gradient(ellipse at 80% 30%, rgba(232,0,29,0.15) 0%, transparent 60%);"></div>
    </section>

    <!-- Productos en oferta -->
    <section class="py-5" id="productos-ofertas">
      <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <span class="badge bg-danger rounded-pill mb-2">💥 Productos</span>
            <h2 class="display-5 fw-bold">OFERTAS EN <span class="text-danger">PRODUCTOS</span></h2>
          </div>
          <router-link to="/catalogo" class="text-danger text-decoration-none">Ver todos →</router-link>
        </div>
        <div class="row g-4">
          <div v-for="producto in productosOferta" :key="producto.id" class="col-md-4 col-lg-3">
            <div class="card bg-dark border-secondary rounded-4 h-100 overflow-hidden transition-all offer-card">
              <div class="position-relative">
                <img :src="producto.imagen" class="card-img-top" :alt="producto.nombre" style="height: 180px; object-fit: cover;">
                <div class="position-absolute top-0 start-0 m-2">
                  <span class="badge bg-danger rounded-pill">-{{ producto.descuento }}%</span>
                </div>
                <div v-if="producto.stock <= 3" class="position-absolute top-0 end-0 m-2">
                  <span class="badge bg-warning text-dark">¡Últimas unidades!</span>
                </div>
              </div>
              <div class="card-body d-flex flex-column">
                <span class="badge bg-secondary bg-opacity-25 text-white-50 align-self-start mb-2">{{ producto.categoria }}</span>
                <h6 class="fw-bold text-white-50">{{ producto.nombre }}</h6>
                <div class="d-flex align-items-center gap-2">
                  <span class="text-decoration-line-through text-muted">S/ {{ producto.precioOriginal.toFixed(2) }}</span>
                  <span class="text-danger fw-bold fs-5">S/ {{ producto.precioOferta.toFixed(2) }}</span>
                </div>
                <button class="btn btn-danger btn-sm mt-3" @click="agregarAlCarrito(producto)">🛒 Agregar al carrito</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Sistemas en oferta -->
    <section class="py-5 bg-dark-2 border-top border-bottom border-secondary" id="sistemas-ofertas">
      <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <span class="badge bg-danger rounded-pill mb-2">🏢 Sistemas</span>
            <h2 class="display-5 fw-bold">OFERTAS EN <span class="text-danger">SISTEMAS</span></h2>
          </div>
          <router-link to="/sistemas" class="text-danger text-decoration-none">Ver todos →</router-link>
        </div>
        <div class="row g-4">
          <div v-for="sistema in sistemasOferta" :key="sistema.id" class="col-md-6 col-lg-4">
            <div class="card bg-dark border-secondary rounded-4 h-100 overflow-hidden transition-all offer-card">
              <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start">
                  <span class="display-3">{{ sistema.icono }}</span>
                  <span class="badge bg-danger rounded-pill">-{{ sistema.descuento }}%</span>
                </div>
                <h5 class="fs-4 fw-bold mt-2 text-white">{{ sistema.nombre }}</h5>
                <p class="text-white-50 small">{{ sistema.descripcion }}</p>
                <div class="d-flex align-items-center gap-2 mt-2">
                  <span class="text-decoration-line-through text-muted">S/ {{ sistema.precioOriginal }}/mes</span>
                  <span class="text-danger fw-bold fs-4">S/ {{ sistema.precioOferta }}/mes</span>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-2">
                  <span v-for="mod in sistema.modulos" :key="mod" class="badge bg-secondary bg-opacity-25 text-white-50">{{ mod }}</span>
                </div>
                <button class="btn btn-danger mt-3" @click="contratarSistema(sistema)">🎯 Contratar oferta</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA final -->
    <section class="py-5">
      <div class="container">
        <div class="bg-gradient-danger rounded-4 p-5 text-center position-relative overflow-hidden">
          <div class="position-relative z-1">
            <h2 class="display-4 fw-bold">¿NO ENCUENTRAS LO QUE BUSCAS?</h2>
            <p class="text-white-50 mx-auto" style="max-width: 500px;">Tenemos más productos y sistemas en oferta. Consulta por WhatsApp y te ayudamos a encontrar lo mejor para ti.</p>
            <button class="btn btn-light btn-lg mt-3" @click="whatsapp">💬 Consultar por WhatsApp</button>
          </div>
          <div class="position-absolute top-0 start-0 w-100 h-100" style="background: radial-gradient(ellipse at 50% 100%, rgba(232,0,29,0.3) 0%, transparent 65%); pointer-events: none;"></div>
        </div>
      </div>
    </section>

    <!-- Toast -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
      <div class="toast align-items-center text-white bg-dark border-0" role="alert" data-bs-autohide="true" data-bs-delay="3000" ref="toast">
        <div class="d-flex">
          <div class="toast-body">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span id="toastMsg">Mensaje</span>
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useCarritoStore } from '@/stores/carrito'
import { useRouter } from 'vue-router'
import { Toast } from 'bootstrap'

const carritoStore = useCarritoStore()
const router = useRouter()
const toastRef = ref<HTMLElement | null>(null)
let toastInstance: Toast | null = null

// Datos mock de productos en oferta
const productosOferta = ref([
  { id: 1, nombre: 'HP Pavilion 15 Core i5', categoria: 'Laptops', imagen: 'https://gamingpower.pe/cdn/shop/files/s-l1600_2.jpg?v=1761403892&width=1946', precioOriginal: 2499, precioOferta: 1899, descuento: 24, stock: 3 },
  { id: 2, nombre: 'Monitor Aus 27" 4K', categoria: 'Monitores', imagen: 'https://gamingpower.pe/cdn/shop/files/1_dad41f32-0b1d-4a79-bb77-656f6380f9cb.jpg?v=1753547068&width=823', precioOriginal: 1600, precioOferta: 1350, descuento: 15, stock: 5 },
  { id: 3, nombre: 'Teclado Redragon K552', categoria: 'Teclados', imagen: 'https://gamingpower.pe/cdn/shop/files/02_31ba2067-72e5-4840-bd84-6666085882f5.jpg?v=1765460549&width=1946', precioOriginal: 220, precioOferta: 189, descuento: 14, stock: 10 },
  { id: 4, nombre: 'Antivirus Kaspersky 1 año', categoria: 'Antivirus', imagen: 'https://gamingpower.pe/cdn/shop/files/1_430d64b9-9705-4a82-a7b7-ef80e6450203.jpg?v=1766258577&width=823', precioOriginal: 149, precioOferta: 89, descuento: 40, stock: 20 },
])

// Datos mock de sistemas en oferta
const sistemasOferta = ref([
  { id: 1, nombre: 'Sistema para Minimarket', icono: '🛒', descripcion: 'Control total de ventas, caja e inventario', modulos: ['Ventas POS', 'Inventario', 'Caja'], precioOriginal: 99, precioOferta: 79, descuento: 20 },
  { id: 2, nombre: 'Sistema para Botica', icono: '💊', descripcion: 'Gestión de medicamentos y recetas', modulos: ['Medicamentos', 'Recetas', 'Alertas'], precioOriginal: 119, precioOferta: 95, descuento: 20 },
  { id: 3, nombre: 'Sistema para Restaurante', icono: '🍽️', descripcion: 'Comandas digitales y delivery', modulos: ['Comandas', 'Mesas', 'Delivery'], precioOriginal: 139, precioOferta: 111, descuento: 20 },
])

const mostrarToast = (mensaje: string) => {
  if (toastInstance) toastInstance.dispose()
  const el = toastRef.value
  if (el) {
    const span = document.getElementById('toastMsg')
    if (span) span.innerText = mensaje
    toastInstance = new Toast(el, { autohide: true, delay: 3000 })
    toastInstance.show()
  }
}

const agregarAlCarrito = (producto: any) => {
  carritoStore.agregarProducto({
    id: producto.id,
    nombre: producto.nombre,
    precio: producto.precioOferta,
    imagen: producto.imagen,
    cantidad: 1
  })
  mostrarToast(`✅ ${producto.nombre} agregado al carrito`)
}

const contratarSistema = (sistema: any) => {
  mostrarToast(`🏢 Sistema "${sistema.nombre}" seleccionado — redirigiendo...`)
  setTimeout(() => router.push('/sistemas'), 1000)
}

const whatsapp = () => {
  window.open('https://wa.me/51950917606?text=Hola%2C%20quiero%20consultar%20por%20las%20ofertas', '_blank')
}

const scrollToProductos = () => {
  document.getElementById('productos-ofertas')?.scrollIntoView({ behavior: 'smooth' })
}

const scrollToSistemas = () => {
  document.getElementById('sistemas-ofertas')?.scrollIntoView({ behavior: 'smooth' })
}

// Contador regresivo simple
let countdownInterval: ReturnType<typeof setInterval> | null = null

const startCountdown = () => {
  const targetDate = new Date()
  targetDate.setDate(targetDate.getDate() + 3) // 3 días de oferta
  
  const update = () => {
    const now = new Date().getTime()
    const distance = targetDate.getTime() - now
    
    if (distance < 0) {
      if (countdownInterval) clearInterval(countdownInterval)
      return
    }
    
    const days = Math.floor(distance / (1000 * 60 * 60 * 24))
    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))
    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60))
    const seconds = Math.floor((distance % (1000 * 60)) / 1000)
    
    const pad = (n: number) => String(n).padStart(2, '0')
    
    const daysEl = document.getElementById('countdown-days')
    const hoursEl = document.getElementById('countdown-hours')
    const minutesEl = document.getElementById('countdown-minutes')
    const secondsEl = document.getElementById('countdown-seconds')
    
    if (daysEl) daysEl.textContent = pad(days)
    if (hoursEl) hoursEl.textContent = pad(hours)
    if (minutesEl) minutesEl.textContent = pad(minutes)
    if (secondsEl) secondsEl.textContent = pad(seconds)
  }
  
  update()
  countdownInterval = setInterval(update, 1000)
}

onMounted(() => {
  startCountdown()
})
</script>

<style scoped>
.bg-dark-2 { background-color: #0f0f0f; }
.hero-ofertas {
  background: linear-gradient(135deg, #0d0005 0%, #1a0008 50%, #0d0005 100%);
  border-bottom: 1px solid rgba(255,255,255,0.07);
}
.hero-bg { pointer-events: none; }
.transition-all { transition: all 0.2s ease; }
.offer-card:hover { border-color: #dc3545 !important; transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,0.4); }
.bg-gradient-danger {
  background: linear-gradient(135deg, #0d0005 0%, #1a0008 50%, #0d0005 100%);
  border: 1px solid rgba(232,0,29,0.3);
}
</style>