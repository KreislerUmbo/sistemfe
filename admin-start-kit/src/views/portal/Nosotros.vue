<template>
  <div class="bg-black text-white">
    <!-- Breadcrumb -->
    <div class="border-bottom border-danger py-2 bg-dark-2">
      <div class="container">
        <nav class="small">
          <router-link to="/" class="text-white-50 text-decoration-none">Inicio</router-link>
          <span class="text-muted mx-1">›</span>
          <span class="text-white">Nosotros</span>
        </nav>
      </div>
    </div>

    <!-- Hero -->
    <section class="hero-nos py-5 text-center position-relative overflow-hidden">
      <div class="container position-relative z-1">
        <span class="badge hero-tag rounded-pill px-3 py-2 mb-3 bg-danger bg-opacity-10 border border-danger text-danger">🏢 NUESTRA HISTORIA</span>
        <h1 class="display-2 fw-bold mb-3">
          TECNOLOGÍA QUE<br><span class="text-danger">IMPULSA TU NEGOCIO</span>
        </h1>
        <p class="text-white-50 mx-auto mb-4" style="max-width:600px; font-size:15px; line-height:1.7;">
          Desde 2018 ayudamos a personas y empresas de San Martín a crecer con tecnología accesible: productos de calidad, sistemas de gestión a medida y soporte técnico siempre cercano.
        </p>
        <div class="row g-4 justify-content-center mt-2">
          <div class="col-6 col-md-3">
            <div class="stat-num">8<span class="text-danger">+</span></div>
            <div class="text-muted small">Años de experiencia</div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-num">120<span class="text-danger">+</span></div>
            <div class="text-muted small">Empresas con sistema activo</div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-num">15<span class="text-danger">K+</span></div>
            <div class="text-muted small">Productos vendidos</div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-num">99<span class="text-danger">%</span></div>
            <div class="text-muted small">Clientes satisfechos</div>
          </div>
        </div>
      </div>
      <div class="hero-bg position-absolute top-0 start-0 w-100 h-100 z-0" style="background: radial-gradient(ellipse at 50% 100%, rgba(232,0,29,0.12) 0%, transparent 65%);"></div>
    </section>

    <!-- Historia -->
    <section class="py-5">
      <div class="container">
        <div class="row g-5 align-items-center">
          <div class="col-lg-5">
            <div class="d-flex align-items-center gap-2 mb-3">
              <span class="section-line"></span>
              <span class="text-danger small fw-bold text-uppercase">Nuestra historia</span>
            </div>
            <h2 class="display-4 fw-bold mb-3">DE UNA TIENDA<br>A UN ECOSISTEMA</h2>
            <p class="text-white-50" style="font-size:14px; line-height:1.8;">
              TechStore nació como una pequeña tienda de cómputo en Tarapoto. Con el tiempo, nuestros propios clientes nos pidieron ayuda para organizar sus negocios — así nacieron nuestros sistemas de gestión, hoy usados por minimarkets, boticas, hoteles y restaurantes en toda la región.
            </p>
          </div>
          <div class="col-lg-7">
            <div class="timeline ps-4 position-relative">
              <div v-for="(item, idx) in timeline" :key="idx" class="timeline-item position-relative pb-4">
                <div class="timeline-dot position-absolute bg-danger rounded-circle" style="left: -20px; top: 2px; width: 16px; height: 16px; border: 3px solid #0a0a0a;"></div>
                <div class="timeline-year text-danger display-6 fw-bold">{{ item.year }}</div>
                <div class="timeline-text text-white-50">{{ item.text }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Misión y Visión -->
    <section class="py-5 bg-dark-2 border-top border-bottom border-danger">
      <div class="container">
        <div class="row g-4">
          <div class="col-md-6">
            <div class="card bg-dark-3 border-secondary p-4 h-100 rounded-4">
              <div class="valor-icon mb-3">🎯</div>
              <h3 class="fw-bold">MISIÓN</h3>
              <p class="text-white-50" style="font-size:14px; line-height:1.7;">Brindar soluciones tecnológicas accesibles y confiables que ayuden a personas y empresas de nuestra región a crecer, simplificando la gestión de sus negocios con productos y sistemas de calidad.</p>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card bg-dark-3 border-secondary p-4 h-100 rounded-4">
              <div class="valor-icon mb-3">🚀</div>
              <h3 class="fw-bold">VISIÓN</h3>
              <p class="text-white-50" style="font-size:14px; line-height:1.7;">Ser la empresa tecnológica de referencia en San Martín, reconocida por la calidad de nuestros productos, la innovación de nuestros sistemas y el trato cercano con cada uno de nuestros clientes.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Valores -->
    <section class="py-5">
      <div class="container">
        <div class="text-center mb-4">
          <div class="d-flex align-items-center gap-2 justify-content-center mb-2">
            <span class="section-line"></span>
            <span class="text-danger small fw-bold text-uppercase">Lo que nos define</span>
          </div>
          <h2 class="display-4 fw-bold">NUESTROS VALORES</h2>
        </div>
        <div class="row g-3">
          <div v-for="valor in valores" :key="valor.titulo" class="col-md-3">
            <div class="valor-card bg-dark-3 border-secondary p-4 rounded-4 h-100 text-center">
              <div class="valor-icon mx-auto mb-3">{{ valor.icono }}</div>
              <h5 class="fw-bold">{{ valor.titulo }}</h5>
              <p class="text-muted small mb-0">{{ valor.descripcion }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Equipo -->
    <section class="py-5 bg-dark-2 border-top border-bottom border-danger">
      <div class="container">
        <div class="text-center mb-4">
          <div class="d-flex align-items-center gap-2 justify-content-center mb-2">
            <span class="section-line"></span>
            <span class="text-danger small fw-bold text-uppercase">Las personas detrás</span>
          </div>
          <h2 class="display-4 fw-bold">NUESTRO EQUIPO</h2>
        </div>
        <div class="row g-3">
          <div v-for="miembro in equipo" :key="miembro.nombre" class="col-md-6 col-lg-3">
            <div class="team-card bg-dark-3 border-secondary rounded-4 overflow-hidden h-100">
              <div class="team-avatar d-flex align-items-center justify-content-center" style="height: 160px; font-size: 64px;" :style="{ background: miembro.gradiente }">
                {{ miembro.avatar }}
              </div>
              <div class="p-3 text-center">
                <h6 class="fw-bold mb-0">{{ miembro.nombre }}</h6>
                <div class="text-muted small mb-2">{{ miembro.cargo }}</div>
                <div class="d-flex gap-2 justify-content-center">
                  <a href="#" class="team-social-btn" @click.prevent="toast('🔗 Abriendo LinkedIn...')"><i class="bi bi-linkedin"></i></a>
                  <a href="#" class="team-social-btn" @click.prevent="toast('🔗 Abriendo correo...')"><i class="bi bi-envelope"></i></a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Testimonios -->
    <section class="py-5">
      <div class="container">
        <div class="text-center mb-4">
          <div class="d-flex align-items-center gap-2 justify-content-center mb-2">
            <span class="section-line"></span>
            <span class="text-danger small fw-bold text-uppercase">Lo que dicen de nosotros</span>
          </div>
          <h2 class="display-4 fw-bold">CLIENTES QUE CONFÍAN EN NOSOTROS</h2>
        </div>
        <div class="row g-3">
          <div v-for="testi in testimonios" :key="testi.nombre" class="col-md-4">
            <div class="testi-card bg-dark-3 border-secondary p-4 rounded-4 h-100">
              <div class="d-flex align-items-center gap-2 mb-2">
                <div class="testi-avatar rounded-circle bg-danger d-flex align-items-center justify-content-center text-white fw-bold" style="width: 40px; height: 40px;">{{ testi.nombre[0] }}</div>
                <div>
                  <div class="fw-semibold small">{{ testi.nombre }}</div>
                  <div class="text-warning small">{{ '★'.repeat(testi.rating) }}</div>
                </div>
              </div>
              <p class="testi-text text-white-50 small mb-0" style="line-height: 1.7;">{{ testi.comentario }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Ubicación -->
    <section class="py-5 bg-dark-2 border-top border-bottom border-danger">
      <div class="container">
        <div class="row g-4 align-items-center">
          <div class="col-lg-6">
            <div class="d-flex align-items-center gap-2 mb-3">
              <span class="section-line"></span>
              <span class="text-danger small fw-bold text-uppercase">Visítanos</span>
            </div>
            <h2 class="display-4 fw-bold mb-3">NUESTRA TIENDA</h2>
            <div class="d-flex flex-column gap-3">
              <div class="d-flex gap-3">
                <i class="bi bi-geo-alt fs-5 text-danger mt-1"></i>
                <div><span class="fw-bold">Jr. Comercio 123, Tarapoto</span><br><span class="text-muted small">San Martín, Perú</span></div>
              </div>
              <div class="d-flex gap-3">
                <i class="bi bi-clock fs-5 text-danger mt-1"></i>
                <div><span class="fw-bold">Lun – Sáb: 8:00am – 7:00pm</span><br><span class="text-muted small">Domingo: 9:00am – 2:00pm</span></div>
              </div>
              <div class="d-flex gap-3">
                <i class="bi bi-whatsapp fs-5 text-danger mt-1"></i>
                <div><span class="fw-bold">+51 987 654 321</span><br><span class="text-muted small">Atención por WhatsApp</span></div>
              </div>
            </div>
            <button class="btn btn-danger mt-4" @click="toast('🗺️ Abriendo Google Maps...')"><i class="bi bi-map me-2"></i>Cómo llegar</button>
          </div>
          <div class="col-lg-6">
            <div class="map-box bg-dark-3 border-secondary rounded-4 d-flex align-items-center justify-content-center" style="height: 280px; font-size: 48px;">🗺️</div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA Final -->
    <section class="py-5">
      <div class="container">
        <div class="cta-box bg-gradient-dark rounded-4 p-5 text-center border border-danger border-opacity-25">
          <h2 class="display-4 fw-bold mb-3">¿LISTO PARA TRABAJAR CON NOSOTROS?</h2>
          <p class="text-white-50 mx-auto mb-4" style="max-width:500px;">Explora nuestro catálogo de productos o conoce los sistemas que pueden transformar tu negocio.</p>
          <div class="d-flex gap-3 justify-content-center flex-wrap">
            <button class="btn btn-danger btn-lg" @click="toast('🛒 Abriendo catálogo...')">Ver catálogo</button>
            <button class="btn btn-outline-light btn-lg" @click="toast('🏢 Abriendo sistemas...')">Ver sistemas empresariales</button>
          </div>
        </div>
      </div>
    </section>

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
import { Toast } from 'bootstrap'

const toastRef = ref<HTMLElement | null>(null)
let toastInstance: Toast | null = null

const timeline = [
  { year: '2018', text: 'Abrimos nuestra primera tienda física en Tarapoto, vendiendo laptops, accesorios y brindando soporte técnico básico.' },
  { year: '2020', text: 'Lanzamos nuestro primer sistema de gestión para minimarkets, en respuesta a la necesidad de digitalización durante la pandemia.' },
  { year: '2022', text: 'Ampliamos nuestra línea de sistemas a boticas, hoteles y restaurantes, alcanzando 80 empresas activas en la región San Martín.' },
  { year: '2025', text: 'Lanzamos nuestro portal de ventas online, integrando productos, sistemas empresariales y servicio técnico en un solo lugar.' }
]

const valores = [
  { icono: '🤝', titulo: 'Cercanía', descripcion: 'Atendemos como vecinos, no como un call center. Conocemos a nuestros clientes por su nombre.' },
  { icono: '✅', titulo: 'Confianza', descripcion: 'Productos originales, garantías reales y precios transparentes, sin letras pequeñas.' },
  { icono: '💡', titulo: 'Innovación', descripcion: 'Mejoramos nuestros sistemas constantemente escuchando lo que cada negocio realmente necesita.' },
  { icono: '⚡', titulo: 'Rapidez', descripcion: 'Soporte técnico que responde rápido, porque sabemos que tu negocio no puede esperar.' }
]

const equipo = [
  { nombre: 'Umbo Ramírez', cargo: 'Fundador y Gerente General', avatar: '👨‍💼', gradiente: 'linear-gradient(135deg,#1a0005,#2d0010)' },
  { nombre: 'María Torres', cargo: 'Jefa de Desarrollo de Sistemas', avatar: '👩‍💻', gradiente: 'linear-gradient(135deg,#001a0d,#002a18)' },
  { nombre: 'Jorge Pinedo', cargo: 'Jefe de Soporte Técnico', avatar: '🧑‍🔧', gradiente: 'linear-gradient(135deg,#05051a,#0a0a28)' },
  { nombre: 'Lucía Salas', cargo: 'Atención al Cliente y Ventas', avatar: '👩‍💼', gradiente: 'linear-gradient(135deg,#1a0a00,#251200)' }
]

const testimonios = [
  { nombre: 'Carlos Ramírez', rating: 5, comentario: 'Compré mi laptop y desde entonces son mi tienda de cabecera. El soporte técnico siempre responde rápido y con confianza.' },
  { nombre: 'Minimarket "Don José"', rating: 5, comentario: 'El sistema de gestión cambió completamente cómo manejamos la tienda. Ya no perdemos tiempo cuadrando caja manualmente.' },
  { nombre: 'Abarrotes "San Martín"', rating: 4, comentario: 'Nos atendieron como si fuéramos parte de la familia. Explican todo con paciencia y nunca nos sentimos presionados a comprar.' }
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
</script>

<style scoped>
.bg-dark-2 { background-color: #0f0f0f; }
.bg-dark-3 { background-color: #1a1a1a; }
.hero-nos { background: linear-gradient(160deg,#050505 0%,#0d0010 50%,#050505 100%); }
.hero-bg { pointer-events: none; }
.section-line { width: 28px; height: 3px; background: #dc3545; border-radius: 2px; }
.stat-num { font-size: 52px; font-weight: bold; line-height: 1; color: #fff; }
.timeline::before { content: ''; position: absolute; left: 9px; top: 8px; bottom: 8px; width: 2px; background: rgba(255,255,255,0.07); }
.timeline-dot { box-shadow: 0 0 0 2px #dc3545; }
.timeline-year { font-size: 24px; }
.timeline-text { font-size: 13px; }
.valor-icon { font-size: 32px; width: 56px; height: 56px; background: rgba(220,53,69,0.1); border: 1px solid rgba(220,53,69,0.2); border-radius: 14px; display: flex; align-items: center; justify-content: center; }
.valor-card { transition: all 0.25s; }
.valor-card:hover { border-color: rgba(220,53,69,0.3); transform: translateY(-4px); }
.team-card { transition: all 0.25s; }
.team-card:hover { border-color: rgba(220,53,69,0.25); transform: translateY(-4px); }
.team-social-btn { width: 30px; height: 30px; border-radius: 50%; background: #222; border: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; justify-content: center; color: #bbb; transition: all 0.2s; }
.team-social-btn:hover { border-color: #dc3545; color: #dc3545; }
.testi-avatar { font-size: 16px; }
.map-box { font-size: 48px; }
.bg-gradient-dark { background: linear-gradient(135deg,#0d0005,#1a0008); }
</style>