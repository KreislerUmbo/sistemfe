<template>
  <footer>
    <!-- Newsletter Strip -->
    <section class="newsletter-strip py-4">
      <div class="container position-relative z-1">
        <div class="row align-items-center g-3">
          <div class="col-md-6">
            <div class="font-display fs-3 mb-1">📩 SUSCRÍBETE A NUESTRAS OFERTAS</div>
            <p class="text-muted small mb-0">Recibe promociones exclusivas, lanzamientos y descuentos antes que nadie.</p>
          </div>
          <div class="col-md-6">
            <div class="input-group nl-input-group ms-md-auto">
              <input type="email" class="form-control bg-dark text-white border-secondary" placeholder="tu@correo.com">
              <button class="btn btn-danger" @click="suscribirse">
                <i class="bi bi-send me-1"></i>Suscribirme
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>
<!-- <hr class="my-4 border-secondary">
    Trust Strip 
    <section class="trust-strip py-3 ">
      <div class="container">
        <div class="row g-3 text-center text-md-start">
          <div class="col-6 col-md-3">
            <div class="d-flex align-items-center gap-2 justify-content-center justify-content-md-start text-white-50 small">
              <i class="bi bi-truck fs-5 text-success"></i>
              <span>Envío gratis en Tarapoto</span>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="d-flex align-items-center gap-2 justify-content-center justify-content-md-start text-white-50 small">
              <i class="bi bi-shield-check fs-5 text-success"></i>
              <span>Pago 100% seguro</span>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="d-flex align-items-center gap-2 justify-content-center justify-content-md-start text-white-50 small">
              <i class="bi bi-arrow-repeat fs-5 text-success"></i>
              <span>Devolución 30 días</span>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="d-flex align-items-center gap-2 justify-content-center justify-content-md-start text-white-50 small">
              <i class="bi bi-headset fs-5 text-success"></i>
              <span>Soporte 24/7</span>
            </div>
          </div>
        </div>
      </div>
-->
    <!-- Footer Main -->
    <div class="footer-main py-5 ">
      <div class="container">
        <div class="row g-4">
          <!-- Marca -->
          <div class="col-md-6 col-lg-3">
            <router-link to="/" class="logo text-decoration-none">
              <span class="fw-bold fs-3 text-white">UMBO<span class="text-danger">SYSTEM</span></span>
            </router-link>
            <p class="text-muted small mt-3" style="max-width: 280px;">
              Tecnología y sistemas de gestión para personas y empresas de San Martín desde 2018. Productos originales, garantía real y soporte cercano.
            </p>
            <div class="d-flex gap-2 mt-3">
              <a href="#" class="social-foot d-flex align-items-center justify-content-center rounded-2 bg-dark border-secondary text-white-50" style="width: 38px; height: 38px;" @click.prevent="social('Facebook')">
                <i class="bi bi-facebook"></i>
              </a>
              <a href="#" class="social-foot d-flex align-items-center justify-content-center rounded-2 bg-dark border-secondary text-white-50" style="width: 38px; height: 38px;" @click.prevent="social('Instagram')">
                <i class="bi bi-instagram"></i>
              </a>
              <a href="#" class="social-foot d-flex align-items-center justify-content-center rounded-2 bg-dark border-secondary text-white-50" style="width: 38px; height: 38px;" @click.prevent="social('TikTok')">
                <i class="bi bi-tiktok"></i>
              </a>
              <a href="#" class="social-foot d-flex align-items-center justify-content-center rounded-2 bg-dark border-secondary text-white-50" style="width: 38px; height: 38px;" @click.prevent="social('WhatsApp')">
                <i class="bi bi-whatsapp"></i>
              </a>
            </div>
          </div>

          <!-- Tienda -->
          <div class="col-6 col-md-3 col-lg-2">
            <h6 class="footer-col-title text-uppercase fw-bold small text-white">Tienda</h6>
            <ul class="list-unstyled">
              <li><router-link to="/tienda" class="footer-link text-decoration-none text-white-50 small" @click="navegacion('Catálogo')">Catálogo de productos</router-link></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Ofertas')">Ofertas y descuentos</a></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Laptops')">Laptops</a></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Antivirus')">Antivirus y software</a></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Accesorios')">Accesorios</a></li>
            </ul>
          </div>

          <!-- Empresas -->
          <div class="col-6 col-md-3 col-lg-2">
            <h6 class="footer-col-title text-uppercase fw-bold small text-white">Empresas</h6>
            <ul class="list-unstyled">
              <li><router-link to="/sistemas" class="footer-link text-decoration-none text-white-50 small" @click="navegacion('Sistemas empresariales')">Sistemas empresariales</router-link></li>
              <li><router-link to="/sistemas?tipo=minimarket" class="footer-link text-decoration-none text-white-50 small" @click="navegacion('Minimarket')">Minimarket</router-link></li>
              <li><router-link to="/sistemas?tipo=botica" class="footer-link text-decoration-none text-white-50 small" @click="navegacion('Botica')">Botica</router-link></li>
              <li><router-link to="/sistemas?tipo=restaurante" class="footer-link text-decoration-none text-white-50 small" @click="navegacion('Restaurante')">Restaurante</router-link></li>
              <li><router-link to="/servicio-tecnico" class="footer-link text-decoration-none text-white-50 small" @click="navegacion('Servicio técnico')">Servicio técnico</router-link></li>
            </ul>
          </div>

          <!-- Ayuda -->
          <div class="col-6 col-md-3 col-lg-2">
            <h6 class="footer-col-title text-uppercase fw-bold small text-white">Ayuda</h6>
            <ul class="list-unstyled">
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Seguimiento')">Rastrear mi pedido</a></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Devoluciones')">Devoluciones</a></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('FAQ')">Preguntas frecuentes</a></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Contacto')">Contáctanos</a></li>
              <li><a href="#" class="footer-link text-decoration-none text-white-50 small" @click.prevent="navegacion('Nosotros')">Nosotros</a></li>
            </ul>
          </div>

          <!-- Contacto -->
          <div class="col-6 col-md-3 col-lg-3">
            <h6 class="footer-col-title text-uppercase fw-bold small text-white">Contacto</h6>
            <ul class="list-unstyled">
              <li class="d-flex gap-2 text-white-50 small mb-2">
                <i class="bi bi-geo-alt text-danger"></i>
                <span>Jr. Comercio 123, Tarapoto, San Martín</span>
              </li>
              <li class="d-flex gap-2 text-white-50 small mb-2">
                <i class="bi bi-whatsapp text-danger"></i>
                <span>+51 950 917 606</span>
              </li>
              <li class="d-flex gap-2 text-white-50 small mb-2">
                <i class="bi bi-envelope text-danger"></i>
                <span>contacto@umbosystem.pe</span>
              </li>
              <li class="d-flex gap-2 text-white-50 small mb-2">
                <i class="bi bi-clock text-danger"></i>
                <span>Lun–Sáb: 8am–7pm · Dom: 9am–1pm</span>
              </li>
            </ul>
          </div>
        </div>

        <hr class="my-4 border-secondary">

        <!-- Apps + Pagos -->
        <div class="row g-3 align-items-center">
          <div class="col-md-6">
            <div class="text-muted small mb-2">Descarga nuestra app (Proximamente)</div>
            <div class="d-flex gap-2 flex-wrap">
              <div class="app-badge d-flex align-items-center gap-2 bg-dark border-info rounded-3 px-3 py-2" style="cursor: pointer;" @click="app('iOS')">
                <i class="bi bi-apple fs-4 text-success"></i>
                <div>
                  <div class="small text-muted" style="font-size: 9px;">Descarga en</div>
                  <div class="fw-bold small text-white">App Store</div>
                </div>
              </div>
              <div class="app-badge d-flex align-items-center gap-2 bg-dark border-secondary rounded-3 px-3 py-2" style="cursor: pointer;" @click="app('Android')">
                <i class="bi bi-google-play fs-4 text-success"></i>
                <div>
                  <div class="small text-muted" style="font-size: 9px;">Disponible en</div>
                  <div class="fw-bold small text-white">Google Play</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small mb-2 text-md-end">Métodos de pago aceptados</div>
            <div class="d-flex gap-2 flex-wrap justify-content-md-end">
              <span class="pay-badge bg-dark border-secondary rounded-2 px-3 py-1 small fw-bold text-white-50">VISA</span>
              <span class="pay-badge bg-dark border-secondary rounded-2 px-3 py-1 small fw-bold text-white-50">MASTERCARD</span>
              <span class="pay-badge bg-dark border-secondary rounded-2 px-3 py-1 small fw-bold text-white-50">AMEX</span>
              <span class="pay-badge bg-dark border-secondary rounded-2 px-3 py-1 small fw-bold" style="color: #7B2D8B;">YAPE</span>
              <span class="pay-badge bg-dark border-secondary rounded-2 px-3 py-1 small fw-bold" style="color: #00B4D8;">PLIN</span>
              <span class="pay-badge bg-dark border-secondary rounded-2 px-3 py-1 small fw-bold text-white-50">BCP</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Bottom Bar -->
    <div class="footer-bottom py-3 bg-black border-top border-danger">
      <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="text-muted small">© 2026 UmboSystem SAC. Todos los derechos reservados.</span>
        <div class="d-flex gap-3 flex-wrap">
          <a href="#" class="legal-link text-decoration-none text-muted small" @click.prevent="legal('Términos')">Términos y condiciones</a>
          <a href="#" class="legal-link text-decoration-none text-muted small" @click.prevent="legal('Privacidad')">Política de privacidad</a>
          <a href="#" class="legal-link text-decoration-none text-muted small" @click.prevent="legal('Cookies')">Cookies</a>
          <a href="#" class="legal-link text-decoration-none text-muted small" @click.prevent="legal('Libro reclamaciones')">Libro de reclamaciones</a>
        </div>
      </div>
    </div>

    <!-- Toast (para notificaciones) -->
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
  </footer>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Toast } from 'bootstrap'

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

const suscribirse = () => {
  mostrarToast('✅ ¡Suscripción exitosa! Revisa tu correo.')
}

const social = (red: string) => {
  mostrarToast(`📱 Abriendo ${red}...`)
}

const navegacion = (seccion: string) => {
  mostrarToast(`🔗 Navegando a ${seccion}...`)
}

const app = (plataforma: string) => {
  mostrarToast(`📱 Abriendo ${plataforma}...`)
}

const legal = (pagina: string) => {
  mostrarToast(`📄 Abriendo ${pagina}...`)
}
</script>

<style scoped>
.bg-dark-2 {
  background-color: #0f0f0f;
}
.newsletter-strip {
  background: linear-gradient(135deg, #1a0005 0%, #2d0010 50%, #1a0008 100%);
  border-top: 1px solid rgba(232, 0, 29, 0.2);
  position: relative;
  overflow: hidden;
}
.newsletter-strip::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 80% 50%, rgba(232, 0, 29, 0.15) 0%, transparent 65%);
}
.nl-input-group .form-control {
  border-radius: 10px 0 0 10px;
}
.nl-input-group .btn {
  border-radius: 0 10px 10px 0;
}
.font-display {
  font-family: 'Bebas Neue', sans-serif;
  letter-spacing: 1px;
}
.social-foot {
  transition: all 0.2s;
}
.social-foot:hover {
  border-color: #dc3545 !important;
  color: #dc3545 !important;
  transform: translateY(-2px);
}
.footer-link {
  transition: all 0.2s;
}
.footer-link:hover {
  color: #dc3545 !important;
  padding-left: 4px;
}
.app-badge {
  transition: all 0.2s;
}
.app-badge:hover {
  border-color: rgba(255, 255, 255, 0.25);
}
.pay-badge {
  transition: all 0.2s;
}
.pay-badge:hover {
  border-color: rgba(255, 255, 255, 0.2);
}
.legal-link {
  transition: color 0.2s;
}
.legal-link:hover {
  color: #ffffff !important;
}
</style>