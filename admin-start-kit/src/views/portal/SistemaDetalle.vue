<!-- frontend/src/views/portal/SistemaDetalle.vue -->
<template>
  <div class="bg-black text-white">
    <!-- Breadcrumb -->
    <div class="border-bottom border-secondary py-2 bg-dark-2">
      <div class="container">
        <nav class="small">
          <router-link to="/" class="text-white-50 text-decoration-none">Inicio</router-link>
          <span class="text-muted mx-1">›</span>
          <router-link to="/sistemas" class="text-white-50 text-decoration-none">Sistemas Empresariales</router-link>
          <span class="text-muted mx-1">›</span>
          <span class="text-white">{{ sistema.nombre }}</span>
        </nav>
      </div>
    </div>

    <!-- Hero -->
    <section class="hero-sys py-5 position-relative overflow-hidden">
      <div class="container position-relative z-1">
        <div class="row align-items-center g-4">
          <div class="col-lg-8">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="icon-box bg-dark rounded-3 p-3" style="font-size: 38px; width: 80px; height: 80px;">
                {{ sistema.icono }}
              </div>
              <div>
                <span class="badge bg-danger rounded-pill mb-1" v-if="sistema.badge">{{ sistema.badge }}</span>
                <h1 class="display-4 fw-bold mb-0">{{ sistema.nombre }}</h1>
              </div>
            </div>
            <p class="text-white-50 mb-3 "  style="max-width: 900px; font-size: 18px; line-height: 1.6;">
              {{ sistema.descripcion_larga || sistema.descripcion }}
            </p>
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="text-warning">
                <i v-for="n in 5" :key="n" class="bi"
                   :class="n <= Math.floor(sistema.rating) ? 'bi-star-fill' : (n - 0.5 <= sistema.rating ? 'bi-star-half' : 'bi-star')"></i>
              </span>
              <span class="text-muted small">{{ sistema.rating }} de 5 · {{ sistema.reviews }} empresas activas</span>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <span v-for="modulo in sistema.modulos" :key="modulo" class="badge bg-secondary bg-opacity-25 text-white-50 rounded-pill px-3 py-2">
                {{ modulo }}
              </span>
            </div>
          </div>
          <div class="col-lg-4 text-center">
            <div class="d-flex flex-column gap-3">
              <div class="bg-dark-2 p-3 rounded-3 border border-secondary">
                <div class="display-1 fw-bold text-danger">
                  {{ precioActual }}<small class="fs-6 text-muted">/mes</small>
                </div>
                <div class="text-muted small">{{ textoFacturacion }}</div>
              </div>
              <button class="btn btn-danger btn-lg" @click="contratar">Contratar ahora</button>
              <button class="btn btn-outline-secondary" @click="demo">📅 Agendar demo gratis</button>
            </div>
          </div>
        </div>
      </div>
      <!-- Fondo decorativo -->
      <div class="hero-bg position-absolute top-0 start-0 w-100 h-100 z-0" style="background: radial-gradient(ellipse at 80% 30%, rgba(232,0,29,0.15) 0%, transparent 60%);"></div>
    </section>

    <!-- Contenido principal -->
    <div class="container py-4">
      <div class="row g-4">
        <!-- Columna izquierda (8) -->
        <div class="col-lg-8">
          <!-- Galería -->
          <div class="row g-2 mb-4">
            <div class="col-3 col-md-2 d-flex flex-column gap-2 order-md-1">
              <div v-for="(thumb, idx) in galeria" :key="idx"
                   class="thumb-sys bg-dark rounded-3 border d-flex align-items-center justify-content-center"
                   :class="{ 'border-danger': currentThumb === idx }"
                   style="aspect-ratio: 1/1; font-size: 28px; cursor: pointer;"
                   @click="currentThumb = idx">
                {{ thumb.emoji }}
              </div>
            </div>
            <div class="col-9 col-md-10">
              <div class="gallery-main bg-dark-2 rounded-4 border border-secondary position-relative d-flex align-items-center justify-content-center" style="min-height: 350px;">
                <span class="display-1" style="animation: float 5s ease-in-out infinite;">{{ galeria[currentThumb].emoji }}</span>
                <button class="play-btn position-absolute top-50 start-50 translate-middle bg-danger border-0 rounded-circle d-flex align-items-center justify-content-center" style="width: 64px; height: 64px; box-shadow: 0 0 30px rgba(232,0,29,0.4);" @click="playDemo">
                  <i class="bi bi-play-fill fs-2 text-white"></i>
                </button>
                <span class="badge bg-danger rounded-pill position-absolute top-0 start-0 m-3">🎥 Ver demo en video</span>
              </div>
            </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs border-bottom-0 gap-3" id="sistemaTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#caracteristicas" type="button" role="tab">Características</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#especificaciones" type="button" role="tab">Especificaciones técnicas</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#testimonios" type="button" role="tab">Testimonios ({{ sistema.testimonios?.length || 0 }})</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#faq" type="button" role="tab">Preguntas frecuentes</button>
            </li>
          </ul>

          <div class="tab-content bg-dark-2 p-4 rounded-4 border border-secondary mt-3">
            <!-- Características -->
            <div class="tab-pane fade show active" id="caracteristicas" role="tabpanel">
              <p class="text-white-50 mb-4" style="line-height: 1.8;">
                <strong class="text-white">{{ sistema.nombre }}</strong> está diseñado para que controles tu negocio sin complicaciones. Desde la caja registradora hasta el control de tus proveedores, todo en una sola plataforma accesible desde tu celular, tablet o computadora.
              </p>
              <div class="row g-3">
                <div v-for="(mod, idx) in sistema.modulos_detalle" :key="idx" class="col-md-6">
                  <div class="module-feature bg-dark rounded-3 border border-secondary p-3 h-100">
                    <div class="fs-1 mb-2">{{ mod.icono }}</div>
                    <h6 class="fw-bold">{{ mod.titulo }}</h6>
                    <ul class="list-unstyled small text-white-50 ps-3 mb-0">
                      <li v-for="item in mod.items" :key="item" class="mb-1"><i class="bi bi-check-circle-fill text-success me-1"></i> {{ item }}</li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>

            <!-- Especificaciones -->
            <div class="tab-pane fade" id="especificaciones" role="tabpanel">
              <div class="row g-2">
                <div v-for="(spec, idx) in sistema.especificaciones" :key="idx" class="col-md-6">
                  <div class="bg-dark p-3 rounded-3 border border-secondary h-100">
                    <div class="text-muted small text-uppercase">{{ spec.key }}</div>
                    <div class="fw-semibold">{{ spec.val }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Testimonios -->
            <div class="tab-pane fade" id="testimonios" role="tabpanel">
              <div class="row g-3">
                <div v-for="(testi, idx) in sistema.testimonios" :key="idx" class="col-md-4">
                  <div class="testimonial-card bg-dark p-3 rounded-3 border border-secondary h-100">
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <div class="avatar rounded-circle bg-danger d-flex align-items-center justify-content-center text-white fw-bold" style="width: 40px; height: 40px;">{{ testi.nombre[0] }}</div>
                      <div>
                        <div class="fw-semibold small">{{ testi.nombre }}</div>
                        <div class="text-warning small">{{ '★'.repeat(testi.rating) }}</div>
                      </div>
                    </div>
                    <div class="small text-danger text-uppercase fw-bold mb-2">{{ testi.negocio }}</div>
                    <p class="text-white-50 small mb-0" style="line-height: 1.6;">{{ testi.comentario }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- FAQ -->
            <div class="tab-pane fade" id="faq" role="tabpanel">
              <div class="accordion accordion-dark" id="faqAccordion">
                <div v-for="(faq, idx) in sistema.faqs" :key="idx" class="accordion-item bg-dark border-secondary mb-2 rounded-3 overflow-hidden">
                  <h2 class="accordion-header">
                    <button class="accordion-button bg-dark text-white collapsed" type="button" data-bs-toggle="collapse" :data-bs-target="'#faq' + idx">
                      {{ faq.pregunta }}
                    </button>
                  </h2>
                  <div :id="'faq' + idx" class="accordion-collapse collapse" :class="{ show: idx === 0 }" data-bs-parent="#faqAccordion">
                    <div class="accordion-body bg-dark text-white-50" style="line-height: 1.7;">
                      {{ faq.respuesta }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Columna derecha (4) sticky -->
        <div class="col-lg-4">
          <div class="sticky-top" style="top: 20px;">
            <div class="d-flex flex-column gap-3">
              <!-- Plan -->
              <div class="card-dark bg-dark-2 border border-secondary rounded-4 p-3">
                <div class="price-toggle d-flex bg-dark rounded-pill p-1 mb-3">
                  <button class="btn btn-sm rounded-pill flex-grow-1" :class="plan === 'mensual' ? 'btn-danger' : 'text-white-50'" @click="plan = 'mensual'">Mensual</button>
                  <button class="btn btn-sm rounded-pill flex-grow-1" :class="plan === 'anual' ? 'btn-danger' : 'text-white-50'" @click="plan = 'anual'">Anual <span class="text-success small">Ahorra 20%</span></button>
                </div>
                <div class="d-flex align-items-baseline gap-2 mb-1">
                  <span class="display-4 fw-bold">{{ precioActual }}<small class="fs-6 text-muted">/mes</small></span>
                </div>
                <div class="text-muted small mb-3">{{ textoFacturacion }}</div>

                <div class="d-flex flex-column gap-2 mb-3">
                  <div v-for="feat in sistema.features" :key="feat" class="fs-6 text-white-50">
                    <i class="bi bi-check-circle-fill text-success me-2"></i> {{ feat }}
                  </div>
                </div>

                <button class="btn btn-danger w-100 mb-2" @click="contratar">⚡ Contratar plan {{ plan }}</button>
                <button class="btn btn-outline-secondary w-100" @click="demo">📅 Agendar demo gratis</button>
              </div>

              <!-- Garantías -->
              <div class="card-dark bg-dark-2 border border-secondary rounded-4 p-3 d-flex flex-column gap-2">
                <div v-for="gar in garantias" :key="gar" class="d-flex align-items-center gap-2 text-muted fs-6">
                  <i class="bi bi-check-circle-fill text-success"></i> {{ gar }}
                </div>
              </div>

              <!-- Sistemas relacionados -->
              <div class="card-dark bg-dark-2 border border-secondary rounded-4 p-3">
                <h6 class="fw-bold mb-2">TAMBIÉN TE PUEDE INTERESAR</h6>
                <div class="row g-2">
                  <div v-for="rel in sistema.relacionados" :key="rel.id" class=" col-6">
                    <div class="related-sys bg-dark p-2 rounded-3 border border-secondary text-center cursor-pointer" @click="irSistema(rel.slug)">
                      <div class="fs-2 mb-1">{{ rel.icono }}</div>
                      <div class="small fw-semibold">{{ rel.nombre }}</div>
                      <div class="small text-muted">desde S/ {{ rel.precio }}/mes</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
      <div class="toast align-items-center text-white bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="3000" ref="toast">
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
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Toast } from 'bootstrap'

const route = useRoute()
const router = useRouter()
const plan = ref<'mensual' | 'anual'>('mensual')
const currentThumb = ref(0)
const toastRef = ref<HTMLElement | null>(null)
let toastInstance: Toast | null = null

// Datos mock del sistema (simulando respuesta de API)
const sistema = ref({
  id: 1,
  nombre: 'SISTEMA PARA MINIMARKET',
  icono: '🛒',
  badge: '🔥 Más popular',
  descripcion: 'Controla ventas, caja, inventario y proveedores desde un solo lugar. Diseñado para minimarkets, bodegas y tiendas de abarrotes que necesitan agilidad y orden.',
  descripcion_larga: 'El Sistema para Minimarket está diseñado para que controles tu negocio sin complicaciones. Desde la caja registradora hasta el control de tus proveedores, todo en una sola plataforma accesible desde tu celular, tablet o computadora.',
  rating: 4.9,
  reviews: 48,
  precioMensual: 99,
  precioAnual: 79,
  modulos: ['📊 Ventas POS', '📦 Inventario', '💰 Caja', '📈 Reportes', '🧾 Facturación SUNAT', '🚚 Proveedores'],
  modulos_detalle: [
    {
      icono: '🛒',
      titulo: 'Punto de Venta (POS)',
      items: ['Venta rápida con lector de código de barras', 'Múltiples cajas y usuarios simultáneos', 'Boletas y facturas electrónicas SUNAT', 'Pagos con tarjeta, Yape, Plin y efectivo']
    },
    {
      icono: '📦',
      titulo: 'Control de Inventario',
      items: ['Stock en tiempo real por sucursal', 'Alertas de stock mínimo', 'Control de fechas de vencimiento', 'Transferencias entre almacenes']
    },
    {
      icono: '💰',
      titulo: 'Gestión de Caja',
      items: ['Apertura y cierre de caja con arqueo', 'Registro de ingresos y egresos', 'Historial de movimientos por usuario', 'Reporte de diferencias automático']
    },
    {
      icono: '📈',
      titulo: 'Reportes y Analítica',
      items: ['Ventas por día, semana, mes o producto', 'Productos más y menos vendidos', 'Rentabilidad por categoría', 'Exportación a Excel y PDF']
    },
    {
      icono: '🚚',
      titulo: 'Proveedores y Compras',
      items: ['Registro de proveedores y contactos', 'Órdenes de compra y recepción', 'Historial de precios de compra', 'Cuentas por pagar']
    },
    {
      icono: '📱',
      titulo: 'App Móvil',
      items: ['Consulta ventas desde tu celular', 'Notificaciones de stock bajo', 'Toma de inventario con cámara', 'Disponible para Android e iOS']
    }
  ],
  especificaciones: [
    { key: 'Requisitos', val: 'PC, tablet o celular con internet' },
    { key: 'Usuarios incluidos', val: 'Hasta 3 (plan básico) — ilimitados en plan Pro' },
    { key: 'Sucursales', val: '1 incluida — adicionales desde S/ 39/mes' },
    { key: 'Almacenamiento', val: 'En la nube · respaldo automático diario' },
    { key: 'Facturación electrónica', val: 'Integración directa con SUNAT' },
    { key: 'Soporte', val: 'Chat y WhatsApp en horario de oficina' },
    { key: 'Capacitación', val: 'Incluida — 2 sesiones online de 1 hora' },
    { key: 'Tiempo de implementación', val: '24 a 48 horas hábiles' }
  ],
  features: [
    'Punto de venta (POS) ilimitado',
    'Control de inventario en tiempo real',
    'Facturación electrónica SUNAT',
    'Reportes y analítica avanzada',
    'Hasta 3 usuarios',
    'Soporte por WhatsApp y chat',
    'Capacitación incluida'
  ],
  testimonios: [
    { nombre: 'Minimarket "Don José"', negocio: 'Tarapoto, San Martín', rating: 5, comentario: 'Desde que usamos el sistema ya no perdemos tiempo cuadrando caja. Las boletas electrónicas se emiten solas y el control de stock nos avisa cuando algo se está acabando.' },
    { nombre: 'Bodega "La Económica"', negocio: 'Moyobamba, San Martín', rating: 5, comentario: 'La implementación fue rápida, en menos de 2 días ya estábamos vendiendo con el sistema. El soporte responde rápido por WhatsApp cuando tenemos dudas.' },
    { nombre: 'Abarrotes "San Martín"', negocio: 'Rioja, San Martín', rating: 4, comentario: 'Los reportes me ayudan a saber qué productos se venden más y así negociar mejor con mis proveedores. La app móvil es muy útil para revisar todo desde casa.' }
  ],
  faqs: [
    { pregunta: '¿Necesito comprar equipos especiales para usar el sistema?', respuesta: 'No es necesario. El sistema funciona en cualquier computadora, tablet o celular con conexión a internet. Si deseas, puedes complementar con una impresora de tickets y lector de código de barras, pero no son obligatorios para empezar.' },
    { pregunta: '¿Cómo funciona la facturación electrónica con SUNAT?', respuesta: 'El sistema está integrado directamente con SUNAT. Cada venta genera automáticamente la boleta o factura electrónica, la envía para su validación y queda almacenada para tus declaraciones mensuales.' },
    { pregunta: '¿Puedo cambiar de plan mensual a anual después?', respuesta: 'Sí, puedes cambiar de plan en cualquier momento desde tu panel de "Mis sistemas". Si pasas a un plan anual, se prorratea el tiempo restante de tu suscripción actual.' },
    { pregunta: '¿Qué pasa con mi información si cancelo el servicio?', respuesta: 'Tu información permanece disponible para exportación durante 90 días después de la cancelación. Puedes descargar tus reportes, inventario y datos de clientes en formato Excel antes de que se eliminen definitivamente.' },
    { pregunta: '¿Ofrecen capacitación para mi equipo de trabajo?', respuesta: 'Sí, todos los planes incluyen 2 sesiones de capacitación online de 1 hora cada una para tu equipo. Además, contamos con manuales y videos tutoriales disponibles las 24 horas.' }
  ],
  relacionados: [
    { id: 2, nombre: 'Botica', icono: '💊', precio: 119, slug: 'botica' },
    { id: 4, nombre: 'Restaurante', icono: '🍽️', precio: 139, slug: 'restaurante' }
  ]
})

// Galería (emojis)
const galeria = ref([
  { emoji: '📊' },
  { emoji: '🛒' },
  { emoji: '📦' },
  { emoji: '📈' },
  { emoji: '📱' }
])

const garantias = [
  '14 días de prueba sin compromiso',
  'Soporte técnico incluido',
  'Cambio de plan en cualquier momento',
  'Facturación electrónica mensual'
]

// Computed
const precioActual = computed(() => {
  return plan.value === 'anual' ? sistema.value.precioAnual : sistema.value.precioMensual
})

const textoFacturacion = computed(() => {
  if (plan.value === 'anual') {
    return `Facturado S/${sistema.value.precioAnual * 12} al año`
  }
  return 'Facturado mensualmente'
})

// Métodos
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

const contratar = () => {
  mostrarToast(`🛒 Iniciando contratación — Plan ${plan.value}...`)
}

const demo = () => {
  mostrarToast('📅 Agendando demo gratuita...')
}

const playDemo = () => {
  mostrarToast('▶️ Reproduciendo video demo del sistema...')
}

const irSistema = (slug: string) => {
  router.push(`/sistemas/${slug}`)
}

// Cargar datos reales desde API (simulado)
onMounted(() => {
  const slug = route.params.slug as string
  if (slug) {
    // Aquí llamarías a sistemaService.obtenerPorSlug(slug)
    console.log('Cargando sistema:', slug)
  }
  // Inicializar tabs de Bootstrap
  import('bootstrap').then(({ Tab }) => {
    const triggerTabList = [].slice.call(document.querySelectorAll('#sistemaTabs button'))
    triggerTabList.forEach((triggerEl: any) => {
      new Tab(triggerEl)
    })
  })
})
</script>

<style scoped>
.bg-dark-2 { background-color: #0f0f0f; }
.hero-sys {
  background: linear-gradient(135deg, #0d0005 0%, #1a0008 50%, #0d0005 100%);
  border-bottom: 1px solid rgba(255,255,255,0.07);
}
.hero-bg { pointer-events: none; }
.icon-box { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); }
.gallery-main { background: var(--negro-3, #1a1a1a); }
.thumb-sys {
  background: var(--negro-3, #1a1a1a);
  border: 2px solid rgba(255,255,255,0.07);
  transition: all 0.25s;
}
.thumb-sys:hover { border-color: rgba(232,0,29,0.4); }
.thumb-sys.border-danger { border-color: #dc3545 !important; box-shadow: 0 0 0 3px rgba(232,0,29,0.22); }
.play-btn { transition: all 0.25s; }
.play-btn:hover { transform: translate(-50%, -50%) scale(1.1); background: #b02a37; }
.nav-tabs .nav-link {
  color: #888;
  border: none;
  background: transparent;
  border-bottom: 2px solid transparent;
  border-radius: 0;
  padding: 0.5rem 1rem;
}
.nav-tabs .nav-link:hover { color: #fff; }
.nav-tabs .nav-link.active {
  color: #fff;
  background: transparent;
  border-bottom-color: #dc3545;
}
.tab-content { background: #111; border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; }
.module-feature { transition: border-color 0.2s; }
.module-feature:hover { border-color: rgba(232,0,29,0.25); }
.accordion-dark .accordion-item { background: #111; border: 1px solid rgba(255,255,255,0.07); }
.accordion-dark .accordion-button {
  background: #111;
  color: #fff;
  box-shadow: none;
}
.accordion-dark .accordion-button:not(.collapsed) {
  background: rgba(232,0,29,0.06);
  color: #fff;
}
.accordion-dark .accordion-button::after { filter: invert(1); }
.accordion-dark .accordion-body { color: #bbb; background: #111; }
.price-toggle .btn { transition: all 0.25s; }
.related-sys { transition: all 0.25s; cursor: pointer; }
.related-sys:hover { border-color: rgba(232,0,29,0.3); transform: translateY(-3px); }
.card-dark { background: #111; border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; }
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-10px); }
}
</style>