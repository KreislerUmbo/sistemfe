<!-- frontend/src/components/Portal/HeroPortal.vue -->
<template>
  <section class="hero-slider position-relative overflow-hidden bg-black">
    <div class="slider-container position-relative" style="height: 500px; min-height: 500px;">
      <!-- Slides -->
      <div
        v-for="(slide, index) in slides"
        :key="index"
        class="slide position-absolute top-0 start-0 w-100 h-100 transition-slide"
        :class="{ 'opacity-100 visible': currentSlide === index, 'opacity-0 invisible': currentSlide !== index }"
      >
        <img :src="slide.image" :alt="slide.title" class="w-100 h-100 object-fit-cover">
        <div class="overlay position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(90deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.3) 100%);"></div>
        <div class="position-absolute top-50 start-0 translate-middle-y w-100">
          <div class="container">
             <div class="slide-tag">🌹 Día de la Madre — hasta el 11 de mayo</div>
            <div class="row justify-content-start">
              <div class="col-lg-6 text-white">
                <h1 class="display-4 fw-bold mb-3">{{ slide.title }}</h1>
                <p class="lead mb-4">{{ slide.description }}</p>
                <router-link to="/tienda" class="btn btn-danger btn-lg rounded-pill px-5">Comprar ahora →</router-link>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Botones anterior / siguiente -->
      <button @click="prevSlide" class="btn-slider prev-btn position-absolute top-50 start-3 translate-middle-y bg-dark bg-opacity-50 border-0 text-white rounded-circle d-flex align-items-center justify-content-center">
        <i class="bi bi-chevron-left fs-3"></i>
      </button>
      <button @click="nextSlide" class="btn-slider next-btn position-absolute top-50 end-3 translate-middle-y bg-dark bg-opacity-50 border-0 text-white rounded-circle d-flex align-items-center justify-content-center">
        <i class="bi bi-chevron-right fs-3"></i>
      </button>

      <!-- Indicadores (dots) -->
      <div class="dots-container position-absolute bottom-0 start-50 translate-middle-x mb-4 d-flex gap-2">
        <button
          v-for="(slide, index) in slides"
          :key="index"
          @click="goToSlide(index)"
          class="dot rounded-circle border-0 p-0"
          :class="{ 'active': currentSlide === index, 'bg-danger': currentSlide === index, 'bg-white bg-opacity-50': currentSlide !== index }"
          style="width: 12px; height: 12px; transition: all 0.3s;"
        ></button>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

// Datos de ejemplo (puedes reemplazar con tu API)
const slides = ref([
  {
    image: 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80',
    title: 'Ofertas por el Día de la Madre',
    description: 'Hasta 30% de descuento en tecnología y accesorios. ¡Regala lo mejor!'
  },
  {
    image: 'https://images.unsplash.com/photo-1593642632823-8f785ba67e45?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80',
    title: 'Antivirus Digital',
    description: 'Protege tu familia con nuestro antivirus premium. 50% off en el primer año.'
  },
  {
    image: 'https://images.unsplash.com/photo-1593642702821-c8da6771f0c6?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80',
    title: 'Discos Duros y Memorias USB',
    description: 'Amplía tu almacenamiento al mejor precio. Envío gratis.'
  }
])

const currentSlide = ref(0)
let intervalId: ReturnType<typeof setInterval> | null = null

const nextSlide = () => {
  currentSlide.value = (currentSlide.value + 1) % slides.value.length
}

const prevSlide = () => {
  currentSlide.value = (currentSlide.value - 1 + slides.value.length) % slides.value.length
}

const goToSlide = (index: number) => {
  currentSlide.value = index
  resetInterval()
}

const resetInterval = () => {
  if (intervalId) clearInterval(intervalId)
  intervalId = setInterval(() => {
    nextSlide()
  }, 5000)
}

onMounted(() => {
  resetInterval()
})

onUnmounted(() => {
  if (intervalId) clearInterval(intervalId)
})
</script>

<style scoped>
.hero-slider {
  background-color: #000;
}
.slide {
  transition: opacity 0.7s ease-in-out, visibility 0.7s;
}
.opacity-0 {
  opacity: 0;
}
.opacity-100 {
  opacity: 1;
}
.invisible {
  visibility: hidden;
}
.visible {
  visibility: visible;
}
.object-fit-cover {
  object-fit: cover;
}
.btn-slider {
  width: 45px;
  height: 45px;
  cursor: pointer;
  transition: background-color 0.2s;
}
.btn-slider:hover {
  background-color: rgba(220, 53, 69, 0.8) !important;
}
.start-3 {
  left: 20px;
}
.end-3 {
  right: 20px;
}
.dot {
  cursor: pointer;
  transition: all 0.2s ease;
}
.dot.active {
  width: 24px !important;
  background-color: #dc3545 !important;
}
.dot:hover:not(.active) {
  background-color: rgba(220, 53, 69, 0.7) !important;
}
@media (max-width: 768px) {
  .slider-container {
    height: 400px !important;
    min-height: 400px;
  }
  .display-4 {
    font-size: 2rem;
  }
  .lead {
    font-size: 1rem;
  }
  .btn-slider {
    width: 35px;
    height: 35px;
  }
  .start-3 {
    left: 10px;
  }
  .end-3 {
    right: 10px;
  }
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
</style>