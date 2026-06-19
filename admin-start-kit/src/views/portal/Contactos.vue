<template>
  <div class="bg-black text-white">
    <!-- Breadcrumb -->
    <div class="border-bottom border-danger py-2 bg-dark-2">
      <div class="container">
        <nav class="small">
          <router-link to="/" class="text-white-50 text-decoration-none">Inicio</router-link>
          <span class="text-muted mx-1">›</span>
          <span class="text-white">Contacto</span>
        </nav>
      </div>
    </div>

    <!-- Hero -->
    <section class="hero-contact py-5 text-center position-relative overflow-hidden">
      <div class="container position-relative z-1">
        <span class="badge hero-tag rounded-pill px-3 py-2 mb-3 bg-danger bg-opacity-10 border border-danger text-danger">💬 ESTAMOS PARA AYUDARTE</span>
        <h1 class="display-2 fw-bold mb-2">HABLEMOS</h1>
        <p class="text-white-50 mx-auto" style="max-width:560px; font-size:15px;">Elige el canal que prefieras o escríbenos directamente. Nuestro equipo te responderá lo antes posible.</p>
      </div>
      <div class="hero-bg position-absolute top-0 start-0 w-100 h-100 z-0" style="background: radial-gradient(ellipse at 50% 100%, rgba(232,0,29,0.12) 0%, transparent 65%);"></div>
    </section>

    <!-- Canales de contacto -->
    <div class="container py-4">
      <div class="row g-3">
        <div v-for="canal in canales" :key="canal.titulo" class="col-md-3">
          <div class="channel-card bg-dark-3 border-secondary rounded-4 p-4 text-center h-100 cursor-pointer" @click="toast(canal.accion)">
            <div class="channel-icon mx-auto mb-3 d-flex align-items-center justify-content-center rounded-3" :class="canal.iconClass" style="width: 60px; height: 60px; font-size: 26px;">
              <i :class="canal.icono"></i>
            </div>
            <h6 class="fw-bold mb-1">{{ canal.titulo }}</h6>
            <div class="text-muted small mb-1">{{ canal.detalle }}</div>
            <span v-if="canal.badge" class="badge" :class="canal.badgeClass">{{ canal.badge }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Formulario + Info -->
    <div class="container py-4">
      <div class="row g-4">
        <!-- Formulario -->
        <div class="col-lg-7">
          <div class="card bg-dark-3 border-secondary rounded-4">
            <div class="card-header bg-transparent border-secondary font-display fs-5 text-white">📧 ENVÍANOS UN MENSAJE</div>
            <div class="card-body">
              <label class="form-label d-block mb-2">Motivo de tu consulta</label>
              <div class="row g-2 mb-3">
                <div v-for="motivo in motivos" :key="motivo.label" class="col-4 col-md-2">
                  <div class="motivo-pill rounded-3 border border-secondary bg-dark-3 text-center p-2 cursor-pointer" :class="{ active: motivoSeleccionado === motivo.label }" @click="motivoSeleccionado = motivo.label">
                    <i :class="motivo.icono" class="d-block mb-1"></i>{{ motivo.label }}
                  </div>
                </div>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label small fw-bold text-uppercase text-muted">Nombre completo *</label>
                  <input type="text" class="form-control bg-dark-3 border-secondary text-white" placeholder="Carlos Ramírez">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-bold text-uppercase text-muted">Correo electrónico *</label>
                  <input type="email" class="form-control bg-dark-3 border-secondary text-white" placeholder="carlos@correo.com">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-bold text-uppercase text-muted">Teléfono / WhatsApp</label>
                  <input type="tel" class="form-control bg-dark-3 border-secondary text-white" placeholder="987 654 321">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-bold text-uppercase text-muted">Asunto *</label>
                  <input type="text" class="form-control bg-dark-3 border-secondary text-white" placeholder="¿En qué te podemos ayudar?">
                </div>
                <div class="col-12">
                  <label class="form-label small fw-bold text-uppercase text-muted">Mensaje *</label>
                  <textarea class="form-control bg-dark-3 border-secondary text-white" rows="5" placeholder="Cuéntanos los detalles de tu consulta..."></textarea>
                </div>
              </div>

              <button class="btn btn-danger btn-lg w-100 rounded-3" @click="enviarMensaje">
                <i class="bi bi-send me-2"></i>Enviar mensaje
              </button>
            </div>
          </div>
        </div>

        <!-- Info -->
        <div class="col-lg-5">
          <div class="d-flex flex-column gap-3">
            <!-- Mapa -->
            <div class="map-box bg-dark-3 border-secondary rounded-4 d-flex align-items-center justify-content-center position-relative" style="height: 200px; font-size: 48px;">
              🗺️
              <span class="position-absolute top-50 start-50 translate-middle" style="font-size: 36px; animation: bounce 2s infinite;">📍</span>
            </div>

            <!-- Horario -->
            <div class="card bg-dark-3 border-secondary rounded-4 p-3">
              <h5 class="fw-bold text-white"><i class="bi bi-clock me-2"></i>HORARIO DE ATENCIÓN EN OFICINA</h5>
              <div v-for="horario in horarios" :key="horario.dia" class="d-flex justify-content-between py-2 text-muted border-bottom border-secondary" :class="{ 'text-success fw-bold': horario.hoy }">
                <span class="text-muted">{{ horario.dia }}</span>
                <span class="text-muted">{{ horario.hora }}</span>
              </div>
            </div>

            <!-- Dirección -->
            <div class="card bg-dark-3 border-secondary rounded-4 p-3">
              <h5 class="fw-bold text-white"><i class="bi bi-geo-alt me-2"></i>NUESTRA TIENDA</h5>
              <div class="text-muted small mb-1">Jr. Comercio 123, Tarapoto</div>
              <div class="text-muted small mb-3">San Martín, Perú</div>
              <button class="btn btn-outline-light w-100 rounded-3" @click="toast('🗺️ Abriendo Google Maps...')"><i class="bi bi-map me-2"></i>Cómo llegar</button>
            </div>

            <!-- Redes -->
            <div class="card bg-dark-3 border-secondary rounded-4 p-3">
              <h5 class="fw-bold text-white"><i class="bi bi-share me-2"></i>SÍGUENOS</h5>
              <div class="d-flex gap-2">
                <a href="#" class="social-btn" @click.prevent="toast('📘 Abriendo Facebook...')"><i class="bi bi-facebook"></i></a>
                <a href="#" class="social-btn" @click.prevent="toast('📷 Abriendo Instagram...')"><i class="bi bi-instagram"></i></a>
                <a href="#" class="social-btn" @click.prevent="toast('🎵 Abriendo TikTok...')"><i class="bi bi-tiktok"></i></a>
                <a href="#" class="social-btn" @click.prevent="toast('💬 Abriendo WhatsApp...')"><i class="bi bi-whatsapp"></i></a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- FAQ -->
    <div class="container py-4">
      <div class="text-center mb-4">
        <h2 class="fw-bold">PREGUNTAS RÁPIDAS</h2>
        <p class="text-muted small">Antes de escribirnos, revisa si tu duda ya tiene respuesta</p>
      </div>
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="accordion accordion-dark" id="faqContacto">
            <div v-for="(faq, idx) in faqs" :key="idx" class="accordion-item bg-dark-3 border-secondary rounded-3 mb-2 overflow-hidden">
              <h2 class="accordion-header">
                <button class="accordion-button bg-dark-3 text-white collapsed" type="button" data-bs-toggle="collapse" :data-bs-target="'#fq' + idx">
                  {{ faq.pregunta }}
                </button>
              </h2>
              <div :id="'fq' + idx" class="accordion-collapse collapse" :class="{ show: idx === 0 }" data-bs-parent="#faqContacto">
                <div class="accordion-body bg-dark-3 text-white-50">{{ faq.respuesta }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal éxito -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark-3 text-white border-success">
          <div class="modal-body text-center p-4">
            <div class="success-ring mx-auto mb-3 d-flex align-items-center justify-content-center bg-success bg-opacity-10 rounded-circle" style="width: 80px; height: 80px;">
              <i class="bi bi-check-lg fs-1 text-success"></i>
            </div>
            <h3 class="fw-bold">¡MENSAJE ENVIADO!</h3>
            <p class="text-muted small">Gracias por escribirnos. Te responderemos a tu correo en menos de 24 horas.</p>
            <div class="badge bg-success bg-opacity-10 border border-success text-success px-3 py-2 mb-3">Ticket #MSG-2025-0918</div>
            <button class="btn btn-outline-light w-100 rounded-3" data-bs-dismiss="modal">Cerrar</button>
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
import { ref, onMounted } from 'vue'
import { Modal, Toast } from 'bootstrap'

const toastRef = ref<HTMLElement | null>(null)
let toastInstance: Toast | null = null
const motivoSeleccionado = ref('General')

const canales = [
  { titulo: 'WhatsApp', icono: 'bi bi-whatsapp', iconClass: 'bg-success bg-opacity-10 border border-success', detalle: '+51 987 654 321', badge: '● En línea', badgeClass: 'bg-success bg-opacity-10 text-success', accion: '💬 Abriendo WhatsApp...' },
  { titulo: 'Llámanos', icono: 'bi bi-telephone', iconClass: 'bg-primary bg-opacity-10 border border-primary', detalle: '(042) 123-456', badge: 'Lun–Sáb 8am–7pm', badgeClass: 'bg-secondary bg-opacity-10 text-muted', accion: '📞 Llamando a TechStore...' },
  { titulo: 'Escríbenos', icono: 'bi bi-envelope', iconClass: 'bg-danger bg-opacity-10 border border-danger', detalle: 'contacto@techstore.pe', badge: 'Respuesta en 24h', badgeClass: 'bg-secondary bg-opacity-10 text-muted', accion: '📧 Abriendo cliente de correo...' },
  { titulo: 'Visítanos', icono: 'bi bi-shop', iconClass: 'bg-warning bg-opacity-10 border border-warning', detalle: 'Jr. Comercio 123', badge: 'Tarapoto', badgeClass: 'bg-secondary bg-opacity-10 text-muted', accion: '🗺️ Abriendo Google Maps...' }
]

const motivos = [
  { label: 'General', icono: 'bi bi-question-circle' },
  { label: 'Compra', icono: 'bi bi-cart' },
  { label: 'Soporte', icono: 'bi bi-tools' },
  { label: 'Sistemas', icono: 'bi bi-building' },
  { label: 'Reclamo', icono: 'bi bi-arrow-counterclockwise' },
  { label: 'Trabajo', icono: 'bi bi-briefcase' }
]

const horarios = [
  { dia: 'Lunes – Viernes', hora: '8:00am – 7:00pm', hoy: false },
  { dia: 'Sábado', hora: '8:00am – 7:00pm', hoy: true },
  { dia: 'Domingo', hora: '9:00am – 2:00pm', hoy: false }
]

const faqs = [
  { pregunta: '¿Cuánto tardan en responder por WhatsApp?', respuesta: 'Normalmente respondemos en menos de 15 minutos durante nuestro horario de atención. Fuera de horario, te contestamos al siguiente día hábil a primera hora.' },
  { pregunta: '¿Puedo hacer seguimiento de un reclamo?', respuesta: 'Sí, al enviar tu reclamo recibirás un número de ticket por correo que puedes usar para consultar el estado en cualquier momento contactándonos por WhatsApp.' },
  { pregunta: '¿Atienden consultas sobre sistemas empresariales aquí?', respuesta: 'Sí, pero si ya tienes un sistema específico en mente te recomendamos usar el formulario de "Solicitar demo / cotización" en la sección de Sistemas Empresariales para una atención más especializada.' }
]

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

const toast = mostrarToast

const enviarMensaje = () => {
  const modalEl = document.getElementById('successModal')
  if (modalEl) {
    const modal = new Modal(modalEl)
    modal.show()
  }
}

onMounted(() => {
  // Inicializar acordeón
  import('bootstrap').then(() => {})
})
</script>

<style scoped>
.bg-dark-2 { background-color: #0f0f0f; }
.bg-dark-3 { background-color: #1a1a1a; }
.hero-contact { background: linear-gradient(160deg,#050505 0%,#0d0010 50%,#050505 100%); }
.hero-bg { pointer-events: none; }
.cursor-pointer { cursor: pointer; }
.channel-card { transition: all 0.25s; }
.channel-card:hover { border-color: rgba(220,53,69,0.3); transform: translateY(-4px); box-shadow: 0 16px 40px rgba(0,0,0,0.4); }
.motivo-pill { transition: all 0.2s; }
.motivo-pill:hover { border-color: rgba(255,255,255,0.2); }
.motivo-pill.active { border-color: #dc3545; background: rgba(220,53,69,0.08); color: #fff; box-shadow: 0 0 0 3px rgba(220,53,69,0.22); }
.map-box { font-size: 48px; }
@keyframes bounce { 0%,100% { transform: translate(-50%, -100%); } 50% { transform: translate(-50%, -115%); } }
.social-btn { width: 44px; height: 44px; border-radius: 12px; background: #222; border: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; justify-content: center; color: #bbb; font-size: 18px; transition: all 0.2s; }
.social-btn:hover { border-color: #dc3545; color: #dc3545; transform: translateY(-2px); }
.accordion-dark .accordion-item { border-color: rgba(255,255,255,0.07); }
.accordion-dark .accordion-button { background: #1a1a1a; color: #fff; box-shadow: none; }
.accordion-dark .accordion-button:not(.collapsed) { background: rgba(220,53,69,0.06); color: #fff; }
.accordion-dark .accordion-button::after { filter: invert(1); }
.accordion-dark .accordion-body { background: #1a1a1a; color: #bbb; }
.success-ring { animation: ring-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes ring-pop { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>