<template>
  <div class="bg-black text-white">
    <!-- Breadcrumb -->
    <div class="border-bottom border-danger py-2 bg-dark-2">
      <div class="container">
        <nav class="small">
          <router-link to="/" class="text-white-50 text-decoration-none">Inicio</router-link>
          <span class="text-muted mx-1">›</span>
          <span class="text-white">Servicio Técnico</span>
        </nav>
      </div>
    </div>

    <!-- Hero -->
    <section class="hero-servicio py-5 position-relative overflow-hidden">
      <div class="container position-relative z-1">
        <div class="row align-items-center g-4">
          <div class="col-lg-7">
            <span class="badge bg-danger rounded-pill mb-3">🔧 Reparación express</span>
            <h1 class="display-3 fw-bold mb-3">
              SERVICIO TÉCNICO<br>
              <span class="text-danger">ESPECIALIZADO</span>
            </h1>
            <p class="text-white-50 mb-4" style="font-size: 16px; max-width: 500px;">
              Diagnóstico gratuito, repuestos originales y garantía en todos nuestros servicios. 
              Reparamos laptops, PCs, impresoras y más.
            </p>
            <div class="d-flex flex-wrap gap-3">
              <button class="btn btn-danger btn-lg" @click="scrollToForm">📋 Solicitar servicio</button>
              <button class="btn btn-outline-secondary btn-lg" @click="whatsapp">💬 Consultar por WhatsApp</button>
            </div>
            <div class="d-flex align-items-center gap-4 mt-4">
              <div><span class="fw-bold text-danger">24h</span> <span class="text-muted small">de respuesta</span></div>
              <div><span class="fw-bold text-danger">30 días</span> <span class="text-muted small">de garantía</span></div>
              <div><span class="fw-bold text-danger">100%</span> <span class="text-muted small">diagnóstico gratis</span></div>
            </div>
          </div>
          <div class="col-lg-5 text-center">
            <div class="bg-dark-2 p-4 rounded-4 border border-secondary">
              <span class="display-1">🔧</span>
              <p class="text-muted mt-2">Más de 500 equipos reparados</p>
              <div class="d-flex justify-content-center gap-3">
                <div class="text-center">
                  <div class="display-6 fw-bold text-danger">98%</div>
                  <div class="small text-muted">Satisfacción</div>
                </div>
                <div class="text-center">
                  <div class="display-6 fw-bold text-danger">4.9</div>
                  <div class="small text-muted">★ Calificación</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="hero-bg position-absolute top-0 start-0 w-100 h-100 z-0" style="background: radial-gradient(ellipse at 80% 30%, rgba(232,0,29,0.12) 0%, transparent 60%);"></div>
    </section>

    <!-- Servicios -->
    <section class="py-5">
      <div class="container">
        <div class="text-center mb-5">
          <span class="badge bg-danger rounded-pill mb-2">Lo que hacemos</span>
          <h2 class="display-5 fw-bold">SERVICIOS DE <span class="text-danger">REPARACIÓN</span></h2>
          <p class="text-white-50 mx-auto" style="max-width: 500px;">Diagnosticamos y reparamos todo tipo de equipos tecnológicos con rapidez y calidad.</p>
        </div>
        <div class="row g-4">
          <div v-for="servicio in servicios" :key="servicio.id" class="col-md-6 col-lg-3">
            <div class="card h-100 bg-dark-2 border-secondary text-center p-3 rounded-4 transition-all hover-card">
              <div class="display-1 mb-2">{{ servicio.icono }}</div>
              <h5 class="fw-bold text-white">{{ servicio.nombre }}</h5>
              <p class="text-white-50 small">{{ servicio.descripcion }}</p>
              <span class="badge bg-danger bg-opacity-25 text-danger mt-auto">Desde S/ {{ servicio.precio }}</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Tarifas / Paquetes -->
    <section class="py-5 bg-dark-2 border-top border-bottom border-secondary">
      <div class="container">
        <div class="text-center mb-5">
          <span class="badge bg-danger rounded-pill mb-2">Planes y precios</span>
          <h2 class="display-5 fw-bold">PAQUETES DE <span class="text-danger">REPARACIÓN</span></h2>
        </div>
        <div class="row g-4">
          <div v-for="paquete in paquetes" :key="paquete.id" class="col-md-4">
            <div class="card h-100 bg-dark border-secondary rounded-4 p-4 text-center transition-all" :class="{ 'border-danger shadow-lg': paquete.destacado }">
              <div class="display-4 mb-2">{{ paquete.icono }}</div>
              <h4 class="fw-bold text-white">{{ paquete.nombre }}</h4>
              <div class="display-5 fw-bold text-danger">S/ {{ paquete.precio }}</div>
              <p class="text-muted small">{{ paquete.periodo }}</p>
              <ul class="list-unstyled text-start mt-3">
                <li v-for="item in paquete.caracteristicas" :key="item" class="mb-2 text-white">
                  <i class="bi bi-check-circle-fill text-success me-2"></i> {{ item }}
                </li>
              </ul>
              <button class="btn btn-outline-danger mt-auto" @click="seleccionarPaquete(paquete)">Seleccionar</button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Formulario de solicitud -->
    <section class="py-5" id="form-solicitud">
      <div class="container">
        <div class="row g-4">
          <div class="col-lg-6">
            <span class="badge bg-danger rounded-pill mb-2">Solicita tu servicio</span>
            <h2 class="display-5 fw-bold">¿NECESITAS AYUDA <span class="text-danger">CON TU EQUIPO?</span></h2>
            <p class="text-white-50">Completa el formulario y te contactaremos en menos de 24 horas para coordinar el diagnóstico gratuito.</p>
            <div class="d-flex flex-column gap-3 mt-4">
              <div class="d-flex align-items-center gap-3">
                <div class="bg-danger bg-opacity-10 p-3 rounded-3"><i class="bi bi-clock-history fs-3 text-danger"></i></div>
                <div><strong>Diagnóstico en 24h</strong><br><span class="text-muted small">Evaluamos tu equipo y te damos un presupuesto sin costo</span></div>
              </div>
              <div class="d-flex align-items-center gap-3">
                <div class="bg-danger bg-opacity-10 p-3 rounded-3"><i class="bi bi-shield-check fs-3 text-danger"></i></div>
                <div><strong>Garantía 30 días</strong><br><span class="text-muted small">Todos nuestros trabajos tienen garantía por escrito</span></div>
              </div>
              <div class="d-flex align-items-center gap-3">
                <div class="bg-danger bg-opacity-10 p-3 rounded-3"><i class="bi bi-truck fs-3 text-danger"></i></div>
                <div><strong>Recojo y entrega</strong><br><span class="text-muted small">Servicio de recojo a domicilio en Tarapoto</span></div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card bg-dark-2 border-secondary rounded-4 p-4">
              <h5 class="fw-bold mb-3">Solicitar servicio técnico</h5>
              <form @submit.prevent="enviarSolicitud">
                <div class="mb-3">
                  <label class="form-label small fw-bold">Tipo de servicio *</label>
                  <select class="form-select bg-dark text-white border-secondary" v-model="form.tipo">
                    <option value="">Seleccionar</option>
                    <option v-for="s in servicios" :key="s.id" :value="s.nombre">{{ s.nombre }}</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label small fw-bold">Nombre completo *</label>
                  <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.nombre" placeholder="Carlos Ramírez" required>
                </div>
                <div class="mb-3">
                  <label class="form-label small fw-bold">Correo electrónico *</label>
                  <input type="email" class="form-control bg-dark text-white border-secondary" v-model="form.email" placeholder="carlos@correo.com" required>
                </div>
                <div class="mb-3">
                  <label class="form-label small fw-bold">Teléfono / WhatsApp *</label>
                  <input type="tel" class="form-control bg-dark text-white border-secondary" v-model="form.telefono" placeholder="987 654 321" required>
                </div>
                <div class="mb-3">
                  <label class="form-label small fw-bold">Descripción del problema *</label>
                  <textarea class="form-control bg-dark text-white border-secondary" rows="3" v-model="form.descripcion" placeholder="Cuéntanos qué le pasa a tu equipo..." required></textarea>
                </div>
                <div class="mb-3 form-check">
                  <input type="checkbox" class="form-check-input" id="terms" v-model="form.aceptaTerminos" required>
                  <label class="form-check-label text-white-50 small" for="terms">Acepto los términos y condiciones del servicio</label>
                </div>
                <button type="submit" class="btn btn-danger w-100 py-2 fw-bold" :disabled="cargando">
                  <span v-if="cargando" class="spinner-border spinner-border-sm me-2"></span>
                  Enviar solicitud
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Testimonios -->
    <section class="py-5 bg-dark-2 border-top border-secondary">
      <div class="container">
        <div class="text-center mb-5">
          <span class="badge bg-danger rounded-pill mb-2">Lo que dicen</span>
          <h2 class="display-5 fw-bold">TESTIMONIOS <span class="text-danger">DE CLIENTES</span></h2>
        </div>
        <div class="row g-4">
          <div v-for="testi in testimonios" :key="testi.id" class="col-md-4">
            <div class="card bg-dark border-secondary rounded-4 p-4 h-100">
              <div class="d-flex align-items-center gap-3 mb-3">
                <div class="rounded-circle bg-danger d-flex align-items-center justify-content-center text-white fw-bold" style="width: 48px; height: 48px;">{{ testi.nombre[0] }}</div>
                <div>
                  <div class="fw-bold">{{ testi.nombre }}</div>
                  <div class="text-warning small">{{ '★'.repeat(testi.rating) }}</div>
                </div>
              </div>
              <p class="text-white-50 small" style="line-height: 1.7;">{{ testi.comentario }}</p>
              <div class="text-muted small mt-auto">{{ testi.equipo }}</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Garantías -->
    <section class="py-5">
      <div class="container">
        <div class="row g-4">
          <div class="col-md-3">
            <div class="text-center p-4 border border-secondary rounded-4 bg-dark-2 h-100">
              <i class="bi bi-shield-check display-4 text-danger"></i>
              <h6 class="fw-bold mt-3">Garantía 30 días</h6>
              <p class="text-white-50 small">Todos nuestros trabajos tienen garantía contra fallas.</p>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-4 border border-secondary rounded-4 bg-dark-2 h-100">
              <i class="bi bi-tools display-4 text-danger"></i>
              <h6 class="fw-bold mt-3">Repuestos originales</h6>
              <p class="text-white-50 small">Usamos solo repuestos de calidad y originales.</p>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-4 border border-secondary rounded-4 bg-dark-2 h-100">
              <i class="bi bi-clock-history display-4 text-danger"></i>
              <h6 class="fw-bold mt-3">Diagnóstico rápido</h6>
              <p class="text-white-50 small">Evaluamos tu equipo en menos de 24 horas.</p>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-4 border border-secondary rounded-4 bg-dark-2 h-100">
              <i class="bi bi-whatsapp display-4 text-danger"></i>
              <h6 class="fw-bold mt-3">Atención por WhatsApp</h6>
              <p class="text-white-50 small">Soporte post-servicio por chat.</p>
            </div>
          </div>
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
import { ref } from 'vue'
import { Toast } from 'bootstrap'

// Datos mock
const servicios = ref([
  { id: 1, nombre: 'Reparación de Laptops', icono: '💻', descripcion: 'Pantallas rotas, teclados, baterías, placas madre.', precio: 80 },
  { id: 2, nombre: 'Reparación de PCs', icono: '🖥️', descripcion: 'Escritorio, fuentes de poder, tarjetas gráficas.', precio: 70 },
  { id: 3, nombre: 'Impresoras', icono: '🖨️', descripcion: 'Cabezales, rodillos, atascos, tóner.', precio: 60 },
  { id: 4, nombre: 'Celulares y Tablets', icono: '📱', descripcion: 'Cambio de pantalla, batería, carga.', precio: 90 },
])

const paquetes = ref([
  { id: 1, nombre: 'Diagnóstico', icono: '🔍', precio: 0, periodo: 'Gratis', destacado: false, caracteristicas: ['Evaluación completa', 'Presupuesto sin compromiso', 'Informe detallado'] },
  { id: 2, nombre: 'Básico', icono: '🔧', precio: 79, periodo: 'por equipo', destacado: true, caracteristicas: ['Diagnóstico gratuito', 'Mano de obra incluida', 'Garantía 15 días'] },
  { id: 3, nombre: 'Premium', icono: '⚡', precio: 149, periodo: 'por equipo', destacado: false, caracteristicas: ['Diagnóstico gratuito', 'Mano de obra + repuestos', 'Garantía 30 días', 'Recojo y entrega'] },
])

const testimonios = ref([
  { id: 1, nombre: 'Carlos R.', comentario: 'Mi laptop no encendía y me la dejaron como nueva en 2 días. Muy recomendados.', equipo: 'Laptop HP Pavilion', rating: 5 },
  { id: 2, nombre: 'María L.', comentario: 'Excelente servicio, me explicaron todo el proceso y el precio fue justo.', equipo: 'PC de escritorio', rating: 5 },
  { id: 3, nombre: 'Jorge P.', comentario: 'La impresora de mi negocio ya funciona perfecto. Rápidos y confiables.', equipo: 'Impresora Epson', rating: 4 },
])

const form = ref({
  tipo: '',
  nombre: '',
  email: '',
  telefono: '',
  descripcion: '',
  aceptaTerminos: false
})

const cargando = ref(false)
const toastRef = ref<HTMLElement | null>(null)
let toastInstance: Toast | null = null

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

const scrollToForm = () => {
  document.getElementById('form-solicitud')?.scrollIntoView({ behavior: 'smooth' })
}

const whatsapp = () => {
  window.open('https://wa.me/51950917606?text=Hola%2C%20necesito%20soporte%20técnico', '_blank')
}

const seleccionarPaquete = (paquete: any) => {
  mostrarToast(`✅ Paquete "${paquete.nombre}" seleccionado — te contactaremos pronto`)
}

const enviarSolicitud = () => {
  cargando.value = true
  setTimeout(() => {
    cargando.value = false
    mostrarToast('✅ Solicitud enviada — te responderemos en menos de 24 horas')
    form.value = { tipo: '', nombre: '', email: '', telefono: '', descripcion: '', aceptaTerminos: false }
  }, 1500)
}
</script>

<style scoped>
.bg-dark-2 { background-color: #0f0f0f; }
.hero-servicio {
  background: linear-gradient(135deg, #0d0005 0%, #1a0008 50%, #0d0005 100%);
  border-bottom: 1px solid rgba(255,255,255,0.07);
}
.hero-bg { pointer-events: none; }
.transition-all { transition: all 0.2s ease; }
.hover-card:hover { border-color: #dc3545 !important; transform: translateY(-3px); }
</style>