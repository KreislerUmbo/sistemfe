<template>
  <div class="bg-black text-white">
    <!-- Breadcrumb -->
    <div class="border-bottom border-danger py-2 bg-dark-2">
      <div class="container">
        <nav class="small">
          <router-link to="/" class="text-white-50 text-decoration-none">Inicio</router-link>
          <span class="text-muted mx-1">›</span>
          <span class="text-white">Sistemas</span>
        </nav>
      </div>
    </div>

    <!-- Hero Section -->
    <section class="hero-section py-5 text-center position-relative overflow-hidden">
      <div class="container position-relative z-1">
        <div
          class="hero-tag d-inline-flex align-items-center gap-2 bg-danger bg-opacity-10 border border-danger rounded-pill px-3 py-1 mb-4">
          <span>🏢</span> Soluciones Tecnológicas Empresariales
        </div>
        <h1 class="display-3 fw-bold mb-3">
          SISTEMAS PARA
          <span class="text-danger d-block">TU NEGOCIO</span>
        </h1>
        <p class="text-white-50 mx-auto" style="max-width: 560px;">
          Software de gestión a medida para cada tipo de empresa. Fácil de usar, rápido de implementar y con soporte
          técnico incluido.
        </p>
        <div class="row justify-content-center mt-4 g-4">
          <div class="col-auto">
            <div class="text-center">
              <div class="display-4 fw-bold text-white">120<span class="text-danger">+</span></div>
              <div class="small text-muted">Empresas activas</div>
            </div>
          </div>
          <div class="col-auto">
            <div class="text-center">
              <div class="display-4 fw-bold text-white">6<span class="text-danger">+</span></div>
              <div class="small text-muted">Tipos de sistemas</div>
            </div>
          </div>
          <div class="col-auto">
            <div class="text-center">
              <div class="display-4 fw-bold text-white">99<span class="text-danger">%</span></div>
              <div class="small text-muted">Satisfacción clientes</div>
            </div>
          </div>
          <div class="col-auto">
            <div class="text-center">
              <div class="display-4 fw-bold text-white">24<span class="text-danger">/7</span></div>
              <div class="small text-muted">Soporte técnico</div>
            </div>
          </div>
        </div>
      </div>
      <div class="hero-bg position-absolute top-0 start-0 w-100 h-100 z-0"
        style="background: radial-gradient(ellipse at 50% 100%, rgba(232,0,29,0.12) 0%, transparent 65%);"></div>
    </section>

    <!-- Filtros -->
    <div class="container mt-3">
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <button v-for="cat in filtros" :key="cat.valor" class="btn btn-sm rounded-pill px-3 py-2"
          :class="filtroActivo === cat.valor ? 'btn-danger' : 'btn-outline-secondary'"
          @click="filtroActivo = cat.valor">
          <span class="me-1">{{ cat.icono }}</span> {{ cat.nombre }}
        </button>
      </div>
    </div>

    <!-- Grid de sistemas -->
    <div class="container py-5">
      <div class="row g-4">
        <div v-for="sistema in sistemasFiltrados" :key="sistema.id" class="col-md-6 col-lg-4">
          <div class="card h-100 bg-dark-2 border-secondary position-relative overflow-hidden"
            :class="[sistema.clase, { 'border-danger': sistema.destacado }]">
            <!-- Badge destacado -->
            <div v-if="sistema.badge" class="position-absolute top-0 end-0 m-3 z-1">
              <span class="badge" :class="sistema.badgeClase">{{ sistema.badge }}</span>
            </div>
            <!-- Header -->
            <div class="sys-card-header card-header bg-transparent border-secondary pt-4 pb-2"
              onclick="verDetalle('Minimarket')" :class="sistema.headerClass">
              <div class="d-flex justify-content-between align-items-start">
                <div class="bg-dark rounded-3 p-3" style="width: 64px; height: 64px;">
                  <span class="fs-2">{{ sistema.icono }}</span>
                </div>
              </div>
              <h3 class="card-title h2 mt-3 fw-bold text-white">{{ sistema.nombre }}</h3>
              <p class="card-text text-white-50 small">{{ sistema.descripcion }}</p>
              <div class="d-flex align-items-center gap-2 mt-2">
                <div class="text-warning">{{ '★'.repeat(Math.floor(sistema.rating)) }}<span class="text-white-50">{{
                  '☆'.repeat(5 - Math.floor(sistema.rating)) }}</span></div>
                <span class="small text-muted">{{ sistema.rating }} · {{ sistema.reviews }} clientes</span>
              </div>
              <div class="d-flex flex-wrap gap-1 mt-2">
                <span v-for="mod in sistema.modulos" :key="mod"
                  class="badge bg-secondary bg-opacity-25 text-white-50">{{ mod }}</span>
              </div>
            </div>
            <!-- Body -->
            <div class="card-body d-flex flex-column">
              <!-- Toggle mensual/anual -->
              <div class="btn-group w-100 mb-3" role="group">
                <button type="button" class="btn btn-sm"
                  :class="sistema.plan === 'mensual' ? 'btn-danger' : 'btn-outline-secondary'"
                  @click="sistema.plan = 'mensual'">Mensual</button>
                <button type="button" class="btn btn-sm"
                  :class="sistema.plan === 'anual' ? 'btn-danger' : 'btn-outline-secondary'"
                  @click="sistema.plan = 'anual'">Anual <span class="small text-success">Ahorra 20%</span></button>
              </div>
              <!-- Precio -->
              <div class="mb-3">
                <div class="d-flex align-items-baseline gap-2">
                  <span class="display-6 fw-bold text-white">S/ {{ precioActual(sistema) }}<small
                      class="fs-6 text-muted">/mes</small></span>
                  <span v-if="sistema.plan === 'anual'" class="text-decoration-line-through text-muted small">S/ {{
                    sistema.precioMensual }}/mes</span>
                </div>
                <div class="small text-muted">{{ sistema.plan === 'anual' ? `Facturado S/ ${sistema.precioAnualTotal} al
                  año` : 'Facturado mensualmente' }}</div>
              </div>
              <!-- Features -->
              <ul class="list-unstyled mb-3">
                <li v-for="feature in sistema.caracteristicas" :key="feature"
                  class="mb-2 d-flex align-items-center gap-2 text-white">
                  <i class="bi bi-check-circle-fill text-success small"></i> {{ feature }}
                </li>
              </ul>
              <!-- Botones -->
              <div class="d-flex flex-column gap-2">
                <!-- Botón principal ocupa todo el ancho -->
                <button class="btn btn-danger btn-sys-primary w-100" @click="contratar(sistema)">
                  Contratar ahora
                </button>
                <!-- Fila con dos botones mitad-mitad -->
                <div class="d-flex gap-2">
                  <button class="btn btn-outline-secondary flex-fill" @click="verDetalle((sistema.id))">
                    Ver Detalle
                  </button>
                  <button class="btn btn-outline-secondary flex-fill" @click="demo(sistema)">
                    Solcitar Demo
                  </button>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- CTA Final -->
    <div class="container pb-5">
      <div class="bg-gradient-dark rounded-4 p-5 text-center position-relative overflow-hidden">
        <div class="position-relative z-1">
          <div class="badge bg-danger mb-3">¿No encuentras tu rubro?</div>
          <h2 class="display-5 fw-bold">DESARROLLAMOS TU<br>SISTEMA A MEDIDA</h2>
          <p class="text-white-50 mx-auto" style="max-width: 500px;">Cuéntanos cómo funciona tu negocio y creamos el
            software perfecto para ti. Cotización sin costo.</p>
          <div class="d-flex flex-wrap justify-content-center gap-3 mt-4">
            <button class="btn btn-danger px-4 py-2" @click="solicitarCotizacion">📞 Solicitar cotización →</button>
            <button class="btn btn-outline-light px-4 py-2" @click="whatsapp">💬 Chatear por WhatsApp</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast (opcional) -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
      <div class="toast align-items-center text-white bg-dark border-0" role="alert" aria-live="assertive"
        aria-atomic="true" data-bs-autohide="true" data-bs-delay="3000" ref="toast">
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
import { ref, computed } from 'vue'
import { Toast } from 'bootstrap'
import { useRouter } from 'vue-router'

const router = useRouter()

// Datos mock de sistemas (reemplazar con API)
const sistemas = ref([
  {
    id: 1,
    nombre: 'MINIMARKET',
    icono: '🛒',
    clase: 'minimarket',
    destacado: true,
    badge: '🔥 Más popular',
    badgeClase: 'bg-danger',
    headerClass: 'bg-gradient-minimarket',
    descripcion: 'Control total de ventas, caja, inventario y proveedores para tu tienda.',
    rating: 4.9,
    reviews: 48,
    modulos: ['Ventas POS', 'Inventario', 'Caja', 'Reportes', 'Facturación'],
    precioMensual: 99,
    precioAnual: 79,
    plan: 'mensual',
    caracteristicas: [
      'Punto de venta (POS) táctil',
      'Control de inventario en tiempo real',
      'Facturación electrónica SUNAT',
      'Reportes de ventas diarios',
      'Gestión de proveedores',
      'Soporte técnico incluido'
    ]
  },
  {
    id: 2,
    nombre: 'BOTICA / FARMACIA',
    icono: '💊',
    clase: 'botica',
    destacado: false,
    badge: '✨ Actualizado',
    badgeClase: 'bg-success',
    headerClass: 'bg-gradient-botica',
    descripcion: 'Gestión de medicamentos, vencimientos, recetas y stock con alerta automática.',
    rating: 4.8,
    reviews: 32,
    modulos: ['Medicamentos', 'Vencimientos', 'Recetas', 'Alertas'],
    precioMensual: 119,
    precioAnual: 95,
    plan: 'mensual',
    caracteristicas: [
      'Catálogo de medicamentos DIGEMID',
      'Control de vencimientos con alertas',
      'Gestión de recetas médicas',
      'Facturación electrónica SUNAT',
      'Reportes de rotación de stock',
      'Soporte técnico 24/7'
    ]
  },
  {
    id: 3,
    nombre: 'HOTEL / HOSPEDAJE',
    icono: '🏨',
    clase: 'hotel',
    destacado: false,
    badge: '⚡ Pro',
    badgeClase: 'bg-primary',
    headerClass: 'bg-gradient-hotel',
    descripcion: 'Reservas, check-in/out, housekeeping, facturación y gestión de huéspedes.',
    rating: 4.9,
    reviews: 21,
    modulos: ['Reservas', 'Check-in/out', 'Housekeeping', 'Huéspedes'],
    precioMensual: 179,
    precioAnual: 143,
    plan: 'mensual',
    caracteristicas: [
      'Gestión de reservas online',
      'Check-in / Check-out digital',
      'Control de habitaciones en mapa',
      'Facturación y boletas electrónicas',
      'Módulo de housekeeping',
      'Reportes de ocupación y revenue'
    ]
  },
  {
    id: 4,
    nombre: 'RESTAURANTE',
    icono: '🍽️',
    clase: 'restaurante',
    destacado: false,
    badge: '🔥 Top ventas',
    badgeClase: 'bg-warning text-dark',
    headerClass: 'bg-gradient-restaurante',
    descripcion: 'Comandas digitales, mesas, cocina, caja y delivery integrado para tu local.',
    rating: 4.8,
    reviews: 37,
    modulos: ['Comandas', 'Mesas', 'Cocina', 'Delivery'],
    precioMensual: 139,
    precioAnual: 111,
    plan: 'mensual',
    caracteristicas: [
      'Comandas desde tablet / celular',
      'Gestión visual de mesas',
      'Pantalla de cocina (KDS)',
      'Delivery y recojo en tienda',
      'Facturación electrónica SUNAT',
      'Reportes de platos más vendidos'
    ]
  },
  {
    id: 5,
    nombre: 'PRÉSTAMOS',
    icono: '💰',
    clase: 'prestamos',
    destacado: false,
    badge: '⚡ Pro',
    badgeClase: 'bg-primary',
    headerClass: 'bg-gradient-prestamos',
    descripcion: 'Control de créditos, cuotas, clientes morosos y cobranzas automatizadas.',
    rating: 4.7,
    reviews: 14,
    modulos: ['Créditos', 'Cuotas', 'Cobranzas', 'Mora'],
    precioMensual: 159,
    precioAnual: 127,
    plan: 'mensual',
    caracteristicas: [
      'Registro y aprobación de préstamos',
      'Calendario de cuotas automático',
      'Alertas de mora y cobranzas',
      'Cálculo de intereses y moras',
      'Historial crediticio de clientes',
      'Reportes financieros detallados'
    ]
  },
  {
    id: 6,
    nombre: 'TALLER / SERVICIO',
    icono: '🔧',
    clase: 'taller',
    destacado: false,
    badge: '✨ Nuevo',
    badgeClase: 'bg-success',
    headerClass: 'bg-gradient-taller',
    descripcion: 'Órdenes de trabajo, técnicos, repuestos, seguimiento y entrega de equipos.',
    rating: 4.9,
    reviews: 18,
    modulos: ['O/T', 'Técnicos', 'Repuestos', 'Seguimiento'],
    precioMensual: 89,
    precioAnual: 71,
    plan: 'mensual',
    caracteristicas: [
      'Órdenes de trabajo digitales',
      'Asignación de técnicos',
      'Control de repuestos e insumos',
      'Seguimiento por WhatsApp al cliente',
      'Garantías y devoluciones',
      'Facturación electrónica SUNAT'
    ]
  }
])

// Filtros
const filtros = [
  { valor: 'all', nombre: 'Todos', icono: '⊞' },
  { valor: 'comercio', nombre: 'Comercio', icono: '🛒' },
  { valor: 'salud', nombre: 'Salud', icono: '💊' },
  { valor: 'hoteleria', nombre: 'Hotelería', icono: '🏨' },
  { valor: 'gastronomia', nombre: 'Gastronomía', icono: '🍽️' },
  { valor: 'financiero', nombre: 'Financiero', icono: '💰' },
  { valor: 'servicio', nombre: 'Servicio', icono: '🔧' }
]

const filtroActivo = ref('all')

// Mapeo categorías
const categoriaMap: Record<number, string> = {
  1: 'comercio',
  2: 'salud',
  3: 'hoteleria',
  4: 'gastronomia',
  5: 'financiero',
  6: 'servicio'
}

const sistemasFiltrados = computed(() => {
  if (filtroActivo.value === 'all') return sistemas.value
  return sistemas.value.filter((_, idx) => categoriaMap[idx + 1] === filtroActivo.value)
})

// Precio según plan
const precioActual = (sistema: any) => {
  if (sistema.plan === 'anual') return sistema.precioAnual
  return sistema.precioMensual
}

// Toast
const toastRef = ref<HTMLElement | null>(null)
let toastInstance: Toast | null = null

const mostrarToast = (mensaje: string) => {
  if (toastInstance) toastInstance.dispose()
  const toastEl = toastRef.value
  if (toastEl) {
    const msgSpan = document.getElementById('toastMsg')
    if (msgSpan) msgSpan.innerText = mensaje
    toastInstance = new Toast(toastEl, { autohide: true, delay: 3000 })
    toastInstance.show()
  }
}

// Acciones
const contratar = (sistema: any) => {
  mostrarToast(`${sistema.nombre} seleccionado — redirigiendo...`)
  // Lógica para redirigir a checkout o carrito
}

const demo = (sistema: any) => {
  mostrarToast(`📅 Demo de ${sistema.nombre} agendada`)
  // Lógica para agendar demo
}

/* const verDetalle = (id: number) => {
  router.push({ name: 'sistemas.detalle', params: { id } })
} */
const verDetalle = (id: number) => {
  router.push({ name: 'sistemas.detalle', params: { id } })
}

const solicitarCotizacion = () => {
  mostrarToast('📞 Te contactaremos pronto — gracias!')
}

const whatsapp = () => {
  window.open('https://wa.me/51987654321?text=Hola%2C%20quiero%20informaci%C3%B3n%20sobre%20sus%20sistemas', '_blank')
}
</script>

<style scoped>
.bg-dark-2 {
  background-color: #2d2d2d;
}

.bg-gradient-minimarket {
  background: linear-gradient(135deg, #0d0005, #3f0012);
}

.bg-gradient-botica {
  background: linear-gradient(135deg, #001a0d, #004225);
}

.bg-gradient-hotel {
  background: linear-gradient(135deg, #05051a, #12124a);
}

.bg-gradient-restaurante {
  background: linear-gradient(135deg, #1a0a00, #a95c14);
}

.bg-gradient-prestamos {
  background: linear-gradient(135deg, #0d0500, #4a0077);
}

.bg-gradient-taller {
  background: linear-gradient(135deg, #0a0a0a, #57aec9);
}

.bg-gradient-dark {
  background: linear-gradient(135deg, #0d0005, #2d000d);
}

.hero-bg {
  pointer-events: none;
}

.sys-card-header {
  padding: 24px 24px 18px;
  position: relative;
  overflow: hidden;
  cursor: pointer;
}
</style>