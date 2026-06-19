<!-- frontend/src/components/Portal/FooterPortal.vue -->
<template>
  <footer class="bg-black text-white-50 pt-5 pb-4 border-top border-danger">
    <div class="container">
      <div class="row gy-4">
        <!-- Col 1: Logo y descripción -->
        <div class="col-md-4">
          <router-link to="/" class="navbar-brand fw-bold fs-3 text-white">TECH<span class="text-danger">TORE</span></router-link>
          <p class="mt-3 small">Tu tienda de tecnología y software de gestión. Innovación y confianza desde 2024.</p>
          <div class="d-flex gap-3 mt-3">
            <a href="#" class="text-white-50 hover-red"><i class="bi bi-facebook fs-5"></i></a>
            <a href="#" class="text-white-50 hover-red"><i class="bi bi-twitter fs-5"></i></a>
            <a href="#" class="text-white-50 hover-red"><i class="bi bi-instagram fs-5"></i></a>
          </div>
        </div>
        <!-- Col 2: Enlaces -->
        <div class="col-md-2">
          <h6 class="text-white fw-semibold">Enlaces</h6>
          <ul class="list-unstyled small">
            <li><router-link to="/catalogo" class="text-white-50 text-decoration-none hover-red">Productos</router-link></li>
            <li><router-link to="/sistemas" class="text-white-50 text-decoration-none hover-red">Sistemas Mypes</router-link></li>
            <li><router-link to="/servicio-tecnico" class="text-white-50 text-decoration-none hover-red">Servicio Técnico</router-link></li>
            <li><a href="#" class="text-white-50 text-decoration-none hover-red">Ofertas</a></li>
          </ul>
        </div>
        <!-- Col 3: Ayuda -->
        <div class="col-md-2">
          <h6 class="text-white fw-semibold">Ayuda</h6>
          <ul class="list-unstyled small">
            <li><a href="#" class="text-white-50 text-decoration-none hover-red">FAQ</a></li>
            <li><a href="#" class="text-white-50 text-decoration-none hover-red">Términos</a></li>
            <li><a href="#" class="text-white-50 text-decoration-none hover-red">Privacidad</a></li>
            <li><a href="#" class="text-white-50 text-decoration-none hover-red">Garantía</a></li>
          </ul>
        </div>
        <!-- Col 4: Contacto -->
        <div class="col-md-4">
          <h6 class="text-white fw-semibold">Contacto</h6>
          <ul class="list-unstyled small">
            <li><i class="bi bi-telephone me-2"></i> +51 950 917 606</li>
            <li><i class="bi bi-envelope me-2"></i> umbosac@gmail.com</li>
            <li><i class="bi bi-geo-alt me-2"></i> Tarapoto, Perú</li>
          </ul>
        </div>
      </div>
      <hr class="border-secondary mt-4">
      <div class="text-center small">
        &copy; {{ new Date().getFullYear() }} UmboSystem. Todos los derechos reservados.
      </div>
    </div>
  </footer>
</template>

<style scoped>
.hover-red:hover {
  color: #dc3545 !important;
}
</style>