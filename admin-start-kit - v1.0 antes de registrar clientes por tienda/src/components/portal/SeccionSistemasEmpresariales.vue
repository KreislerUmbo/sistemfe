<!-- frontend/src/components/Portal/SeccionSistemasEmpresariales.vue -->
<template>
  <section class="py-0 py-md-0 bg-dark text-white">
    <section class="hero">
      <div class="slider-track" ref="sliderTrack">

        <!-- Slide 1: Día de la Madre -->
        <div class="slide slide-1">
          <div class="slide-content">
            <div class="slide-tag">🌹 Día de la Madre — hasta el 11 de mayo</div>
            <div class="slide-title">
              EL MEJOR
              <em>REGALO</em>
              PARA MAMÁ
            </div>
            <div class="slide-price">
              <span class="price-old">S/ 2,499</span>
              <span class="price-new">S/ 1,899</span>
              <span class="price-desc">— laptop ultrabook</span>
            </div>
            <div class="slide-chips">
              <div class="chip">✅ Envío gratis</div>
              <div class="chip">💳 12 cuotas sin interés</div>
              <div class="chip">🎁 Incluye bolsa de regalo</div>
            </div>
            <div class="slide-ctas">
              <button class="btn-primary">Ver oferta <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
                  stroke="currentColor" stroke-width="2.5">
                  <path d="M5 12h14M12 5l7 7-7 7" />
                </svg></button>
              <button class="btn-secondary">Ver todo el catálogo</button>
            </div>
          </div>
          <div class="slide-deco">
            <div class="deco-circle">
              <span class="deco-emoji">💻</span>
            </div>
          </div>
          <div class="badge-off">24%<span>OFF</span></div>
        </div>

        <!-- Slide 2: Sistemas empresariales -->
        <div class="slide slide-2">
          <div class="slide-content">
            <div class="slide-tag" style="background:#3264ff;">🏢 Sistemas de Gestión Empresarial</div>
            <div class="slide-title">
              GESTIONA
              <em style="color:#6b8fff;">TU NEGOCIO</em>
              AL MÁXIMO
            </div>
            <div class="slide-chips">
              <div class="chip">🛒 Minimarket</div>
              <div class="chip">💊 Boticas</div>
              <div class="chip">🏨 Hoteles</div>
              <div class="chip">🍽️ Restaurantes</div>
            </div>
            <div class="slide-desc">Software a medida para tu empresa. Planes mensuales y anuales. Soporte técnico
              incluido.</div>
            <div class="slide-ctas">
              <button class="btn-primary" style="background:#3264ff;box-shadow:0 4px 24px rgba(50,100,255,0.4);">Ver
                sistemas <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                  stroke-width="2.5">
                  <path d="M5 12h14M12 5l7 7-7 7" />
                </svg></button>
              <button class="btn-secondary">Solicitar demo</button>
            </div>
          </div>
          <div class="slide-deco">
            <div class="deco-circle">
              <span class="deco-emoji">📊</span>
            </div>
          </div>
        </div>

        <!-- Slide 3: Servicio técnico -->
        <div class="slide slide-3">
          <div class="slide-content">
            <div class="slide-tag" style="background:#ff9500;color:#000;">🔧 Servicio Técnico Certificado</div>
            <div class="slide-title">
              REPARAMOS
              <em style="color:#ffb300;">TU EQUIPO</em>
              RÁPIDO
            </div>
            <div class="slide-chips">
              <div class="chip">⚡ Diagnóstico gratis</div>
              <div class="chip">🛡️ Garantía 3 meses</div>
              <div class="chip">🚀 Entrega en 48h</div>
            </div>
            <div class="slide-desc">Laptops, PCs, impresoras y más. Técnicos certificados con años de experiencia.</div>
            <div class="slide-ctas">
              <button class="btn-primary"
                style="background:#ff9500;color:#000;box-shadow:0 4px 24px rgba(255,149,0,0.4);">Solicitar servicio <svg
                  viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round">
                  <path d="M5 12h14M12 5l7 7-7 7" />
                </svg></button>
              <button class="btn-secondary">Ver precios</button>
            </div>
          </div>
          <div class="slide-deco">
            <div class="deco-circle">
              <span class="deco-emoji">🔧</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de progreso auto -->
      <div class="progress-bar" ref="progressBar"></div>

      <!-- Controles -->
      <div class="slider-arrows">
        <button class="slider-arrow" @click="prevSlide()">‹</button>
        <button class="slider-arrow" @click="nextSlide()">›</button>
      </div>

      <div class="slider-controls">
        <button class="slider-dot active" @click="goToSlide(0)" id="dot-0"></button>
        <button class="slider-dot" @click="goToSlide(1)" id="dot-1"></button>
        <button class="slider-dot" @click="goToSlide(2)" id="dot-2"></button>
      </div>
    </section>
  </section>
</template>

<script setup>
const sistemas = [
  { nombre: 'Minimarket', icono: '🏪', descripcion: 'Control de inventario, cajas, proveedores y más.' },
  { nombre: 'Boticas', icono: '💊', descripcion: 'Gestión de recetas, stock de medicamentos, alertas.' },
  { nombre: 'Hoteles', icono: '🏨', descripcion: 'Reservas, check-in, facturación y housekeeping.' }
]
/*   // Navbar scroll
  const navbar = document.getElementById('navbar');
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 20);
  }); */

// Slider
import { ref, onMounted, onUnmounted } from 'vue'

const sliderTrack = ref(null)
const progressBar = ref(null)
const dots = ref([])
let current = 0
const total = 3
let autoTimer = null

function updateSlider() {
  if (!sliderTrack.value) return
  sliderTrack.value.style.transform = `translateX(-${current * 33.333}%)`
  
  dots.value.forEach((d, i) => {
    d.classList.toggle('active', i === current)
  })

  if (progressBar.value) {
    progressBar.value.style.animation = 'none'
    progressBar.value.offsetHeight // reflow
    progressBar.value.style.animation = 'progress 5s linear forwards'
  }
}

function nextSlide() {
  current = (current + 1) % total
  updateSlider()
  resetAuto()
}

function prevSlide() {
  current = (current - 1 + total) % total
  updateSlider()
  resetAuto()
}

function goToSlide(n) {
  current = n
  updateSlider()
  resetAuto()
}

function resetAuto() {
  clearInterval(autoTimer)
  autoTimer = setInterval(nextSlide, 5000)
}

onMounted(() => {
  updateSlider()
  autoTimer = setInterval(nextSlide, 5000)
})

onUnmounted(() => {
  clearInterval(autoTimer)
})


</script>

<style >
@import url('https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap');

*,
*::before,
*::after {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

:root {
  --rojo: #E8001D;
  --rojo-dark: #B5001A;
  --rojo-glow: rgba(232, 0, 29, 0.25);
  --negro: #0A0A0A;
  --negro-2: #111111;
  --negro-3: #1A1A1A;
  --negro-4: #222222;
  --gris: #888;
  --gris-claro: #ccc;
  --blanco: #FFFFFF;
  --font-display: 'Bebas Neue', sans-serif;
  --font-body: 'Outfit', sans-serif;
}

.hero {
  margin-top: 60px;
  /* altura navbar top (64) + categorías (48) */
  position: relative;
  height: 560px;
  overflow: hidden;
}

.slider-track {
  display: flex;
  width: 300%;
  height: 100%;
  transition: transform 0.7s cubic-bezier(0.77, 0, 0.175, 1);
}

.slide {
  width: 33.333%;
  height: 100%;
  position: relative;
  display: flex;
  align-items: center;
  overflow: hidden;
}

/* Slide 1 – Día de la Madre */
.slide-1 {
  background: linear-gradient(120deg, #0A0A0A 0%, #1a0008 50%, #2d000f 100%);
}

.slide-1::before {
  /*fondo degradado*/
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 70% 50%, rgba(232, 0, 29, 0.3) 0%, transparent 60%);
}

/* Slide 2 – Software empresarial */
.slide-2 {
  background: linear-gradient(120deg, #050510 0%, #0a0a2a 50%, #0d0d1f 100%);
}

.slide-2::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 70% 50%, rgba(50, 100, 255, 0.2) 0%, transparent 60%);
}

/* Slide 3 – Servicio técnico */
.slide-3 {
  background: linear-gradient(120deg, #050505 0%, #1a1000 50%, #1f1500 100%);
}

.slide-3::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 70% 50%, rgba(255, 160, 0, 0.2) 0%, transparent 60%);
}

/* Contenido de slide */
.slide-content {
  position: relative;
  z-index: 2;
  max-width: 1400px;
  margin: 0 auto;
  padding: 0 80px;
  width: 100%;
}

.slide-tag {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--rojo);
  color: white;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 2px;
  text-transform: uppercase;
  padding: 5px 14px;
  border-radius: 4px;
  margin-bottom: 20px;
}

.slide-2 .slide-tag {
  background: #3264ff;
}

.slide-3 .slide-tag {
  background: #ff9500;
  color: #000;
}

.slide-title {
  font-family: var(--font-display);
  font-size: clamp(52px, 6vw, 90px);
  line-height: 0.9;
  letter-spacing: 2px;
  color: var(--blanco);
  margin-bottom: 18px;
  max-width: 580px;
}

.slide-title em {
  font-style: normal;
  color: var(--rojo);
  display: block;
}

.slide-2 .slide-title em {
  color: #6b8fff;
}

.slide-3 .slide-title em {
  color: #ffb300;
}

.slide-desc {
  font-size: 16px;
  color: rgba(255, 255, 255, 0.65);
  max-width: 420px;
  line-height: 1.6;
  margin-bottom: 36px;
  font-weight: 300;
}

.slide-ctas {
  display: flex;
  gap: 14px;
  align-items: center;
}

.btn-primary {
  background: var(--rojo) !important;
  ;
  color: white;
  border: none;
  padding: 14px 32px;
  /*el padding es el espacio entre el texto y el borde*/
  font-family: var(--font-body);
  font-size: 15px;
  font-weight: 600;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.25s;
  display: flex;
  /*esto es para que el boton se ponga en una fila*/
  align-items: center;
  gap: 8px;
  box-shadow: 0 4px 24px var(--rojo-glow);
  /*el box shadow es el sombreado del boton*/
}

.slide-2 .btn-primary {
  background: #3264ff;
  box-shadow: 0 4px 24px rgba(50, 100, 255, 0.35);
}

.slide-3 .btn-primary {
  background: #ff9500;
  color: #000;
  box-shadow: 0 4px 24px rgba(255, 149, 0, 0.35);
}

.btn-primary:hover {
  transform: translateY(-2px);
  filter: brightness(1.1);
}

.btn-secondary {
  background: transparent;
  color: rgba(255, 255, 255, 0.7);
  border: 1px solid rgba(255, 255, 255, 0.2);
  padding: 14px 28px;
  font-family: var(--font-body);
  font-size: 15px;
  font-weight: 500;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.25s;
}

.btn-secondary:hover {
  color: white;
  border-color: rgba(255, 255, 255, 0.5);
  background: rgba(255, 255, 255, 0.05);
}

/* Decoración visual del slide */
.slide-deco {
  position: absolute;
  right: 80px;
  top: 50%;
  transform: translateY(-50%);
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}

.deco-circle {
  width: 380px;
  height: 380px;
  border-radius: 50%;
  border: 1px solid rgba(232, 0, 29, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.slide-2 .deco-circle {
  border-color: rgba(50, 100, 255, 0.15);
}

.slide-3 .deco-circle {
  border-color: rgba(255, 149, 0, 0.15);
}

.deco-circle::before {
  content: '';
  position: absolute;
  inset: 20px;
  border-radius: 50%;
  border: 1px solid rgba(232, 0, 29, 0.1);
}

.slide-2 .deco-circle::before {
  border-color: rgba(50, 100, 255, 0.1);
}

.slide-3 .deco-circle::before {
  border-color: rgba(255, 149, 0, 0.1);
}

.deco-emoji {
  font-size: 140px;
  filter: drop-shadow(0 20px 60px rgba(0, 0, 0, 0.5));
  animation: float 4s ease-in-out infinite;
  user-select: none;
}

@keyframes float { /*@keyframes es una animacion*/

  0%,
  100% {
    transform: translateY(0px);
  }

  50% {
    transform: translateY(-12px);
  }
}

/* Precio promo */
.slide-price {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  margin-bottom: 28px;
}

.price-old {
  font-size: 18px;
  color: var(--gris);
  text-decoration: line-through;
  padding-bottom: 4px;
}

.price-new {
  font-family: var(--font-display);
  font-size: 52px;
  color: var(--rojo) !important;
  line-height: 1;
  letter-spacing: 1px;
}

.slide-2 .price-new {
  color: #6b8fff;
}

.slide-3 .price-new {
  color: #ffb300;
}

.price-desc {
  font-size: 13px;
  color: var(--gris) !important;
  ;
  /* la diferencia entre poner var y la color directamente es que var se puede usar en todo el proyecto y la color directamente solo se usa en este componente */
  padding-bottom: 8px;
}

/* Badge descuento */
.badge-off {
  position: absolute;
  top: 40px;
  right: 340px;
  background: red;
  color: white;
  font-family: var(--font-display);
  font-size: 28px;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  line-height: 1;
  box-shadow: 0 0 30px var(--rojo-glow);
  z-index: 3;
  animation: spin-slow 8s linear infinite;
}

.badge-off span {
  font-family: var(--font-body);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1px;
}

@keyframes spin-slow {
  0% {
    transform: rotate(0deg);
  }

  100% {
    transform: rotate(360deg);
  }
}

/* Chips de features */
.slide-chips {
  display: flex;
  gap: 10px;
  margin-bottom: 28px;
  flex-wrap: wrap;
}

  .chip {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1);
    padding: 6px 14px;
    border-radius: 100px;
    font-size: 12px;
    color: rgba(255,255,255,0.75);
  }
  /* Controles slider */
  .slider-controls {
    position: absolute;
    bottom: 32px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 10;
  }
    .slider-dot {
    width: 28px;
    height: 4px;
    background: rgba(255,255,255,0.2);
    border-radius: 2px;
    cursor: pointer;
    transition: all 0.3s;
    border: none;
  }
    .slider-dot.active {
    width: 48px;
    background: var(--rojo);
    box-shadow: 0 0 10px var(--rojo-glow);
  }
    .slider-arrows {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 100%;
    display: flex;
    justify-content: space-between;
    padding: 0 24px;
    pointer-events: none;
    z-index: 10;
  }
    .slider-arrow {
    width: 48px; height: 48px;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    color: white;
    font-size: 20px;
    cursor: pointer;
    pointer-events: all;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.25s;
    backdrop-filter: blur(10px);
  }
    .slider-arrow:hover {
    background: var(--rojo);
    border-color: var(--rojo);
    box-shadow: 0 0 20px var(--rojo-glow);
  }
  /* Auto-slide progress */
  .progress-bar {
    position: absolute;
    bottom: 0; left: 0;
    height: 3px;
    background: var(--rojo);
    animation: progress 5s linear infinite;
    box-shadow: 0 0 8px var(--rojo-glow);
  }

  @keyframes progress {
    0% { width: 0%; }
    100% { width: 100%; }
  }
</style>