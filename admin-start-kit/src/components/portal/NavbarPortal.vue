<template>
  <header class="sticky-top bg-black border-bottom border-danger" style="z-index: 1030;">
    <!-- Fila superior: Logo + buscador + iconos -->
    <div class="px-3 px-md-5 py-2 d-flex flex-wrap align-items-center justify-content-between gap-3">
      <router-link class="navbar-brand fw-bold fs-3 " to="/">
        <span class="fw-bold text-white">UMBO</span> <span class="text-danger">SYSTEM</span>
      </router-link>

      <div class="flex-grow-1 mx-3" style="max-width: 400px;">
        <div class="input-group">
          <span class="input-group-text bg-dark border-secondary text-muted">
            <i class="bi bi-search"></i>
          </span>
          <input type="text" class="form-control bg-dark text-white border-secondary"
            placeholder="Buscar laptops, antivirus..." v-model="searchTerm" @keyup.enter="buscar">
        </div>
      </div>

      <div class="d-flex gap-3 align-items-center">
        <!-- User area -->
        <div v-if="clientAuth.isAuthenticated" class="d-flex gap-3 align-items-center">
          <router-link to="/micuenta/pedidos" class="text-white-50 hover-red nav-action-item position-relative"
            title="Mi cuenta">
            <i class="bi bi-person-circle fs-5"></i>
            <span>{{ clientAuth.client?.name }}</span>
          </router-link>

          <router-link to="/favoritos" class="text-white-50 hover-red nav-action-item position-relative"
            title="Favoritos">
            <i class="bi bi-heart fs-5"></i>
            <span>Favoritos</span>
          </router-link>
          <button class="btn position-relative text-white-50 hover-red" @click="cerrarSesion()">
            <i class="bi bi-box-arrow-right fs-5"></i>
            <span class="text-white-50 hover-red nav-action-item">Salir</span>
          </button>

        </div>
        <router-link v-else to="/micuenta/pedidos" class="btn position-relative text-white-50 hover-red"
          title="Iniciar sesión">
          <i class="bi bi-person-circle fs-5"></i>
          <span class="text-white-50 hover-red nav-action-item">Mi cuenta</span>
        </router-link>
        <!-- dentro del div con clase "d-flex gap-3" -->
        <button class="btn position-relative text-white-50 hover-red" @click="uiStore.openCartDrawer()">
          <i class="bi bi-cart fs-5"></i>
          <span v-if="cartTotalItems > 0" class="position-absolute top-0 badge rounded-pill bg-danger">
            {{ cartTotalItems }}
          </span>
          <span class="text-white-50 hover-red nav-action-item" title="carrito">Mi carrito</span>
        </button>

      </div>
    </div>

    <!-- Fila inferior: Menú responsivo -->
    <div class="px-3 px-md-5 py-2 bg-black">
      <nav class="menu-fuente navbar navbar-expand-lg p-0">
        <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#menuInferior"
          aria-controls="menuInferior" aria-expanded="false">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="menuInferior">
          <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-3 gap-lg-4">
            <li class="nav-item"><router-link class="nav-link text-white-50 hover-red"
                to="/">Inicio</router-link></li>
            <li class="nav-item"><router-link class="nav-link text-white-50 hover-red"
                to="/ofertas">Ofertas</router-link></li>
            <li class="nav-item"><router-link class="nav-link text-white-50 hover-red"
                to="/catalogo">Productos</router-link></li>
            <li class="nav-item"><router-link class="nav-link text-white-50 hover-red" to="/sistemas">Sistema
                Mypes</router-link></li>
            <li class="nav-item"><router-link class="nav-link text-white-50 hover-red" to="/serviciotecnico">Servicio
                Técnico</router-link></li>
            <li class="nav-item"><router-link class="nav-link text-white-50 hover-red"
                to="/nosotros">Nosotros</router-link></li>
            <li class="nav-item"><router-link class="nav-link text-white-50 hover-red"
                to="/contactos">Contactos</router-link></li>
          </ul>
        </div>
      </nav>
    </div>
  </header>
</template>

<script setup>
import { ref, computed } from 'vue'
//para el carriro de compras
import { useCarritoStore } from '@/stores/carrito'
import { useUiStore } from '@/stores/ui'

import { useClientAuthStore } from '@/stores/clientAuth'
const clientAuth = useClientAuthStore()

//import { useFavoritosStore } from '@/stores/favoritos'
import { useRouter } from 'vue-router'

const cartStore = useCarritoStore()
const uiStore = useUiStore()
//const favoritosStore = useFavoritosStore()
const cartTotalItems = computed(() => cartStore.totalItems)
//const favoritosCount = computed(() => favoritosStore.totalItems)

const searchTerm = ref('')
const router = useRouter()

const buscar = () => {
  if (searchTerm.value.trim()) {
    router.push({ path: '/tienda', query: { q: searchTerm.value } })
    searchTerm.value = ''
  }
}

const cerrarSesion = () => {
  clientAuth.logout()
  window.location.href = '/'
}
</script>

<style scoped>
.router-link-active {
  color: var(--bs-danger) !important;
  font-weight: bold;
}

/* .router-link-active {
  color: var(--bs-danger) !important;
  font-weight: bold;
  border-bottom: 2px solid var(--bs-danger);
} */
.hover-red:hover {
  color: #dc3545 !important;
}

.text-white-50 {
  color: rgba(255, 255, 255, 0.7);
}

.bg-black {
  background-color: #000000;
}

.navbar-toggler-icon {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255,255,255,0.7%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
}

.menu-fuente {
  font-size: 14px;

}

/* Nav acciones */
.nav-actions {
  display: flex;
  align-items: center;
  gap: 24px;
}

.nav-action-item {
  display: flex;
  flex-direction: column;
  /* icono arriba, texto abajo */
  align-items: center;
  /* centrado horizontal */
  gap: 2px;
  text-decoration: none;
  font-size: 10px;
}

.nav-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  padding: 6px 12px;
  background: transparent;
  border: none;
  cursor: pointer;
  color: rgba(255, 255, 255, 0.7);
  font-size: 10px;
  font-weight: 500;
  border-radius: 8px;
  transition: all 0.2s;
  text-decoration: none;
}

.nav-btn:hover {
  color: var(--blanco);
  background: rgba(255, 255, 255, 0.06);
}

.nav-btn.carrito {
  position: relative;
}

.carrito-badge {
  position: absolute;
  top: 1px;
  right: 1px;
  background: var(--rojo);
  color: white;
  font-size: 9px;
  font-weight: 700;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 8px var(--rojo-glow);
  animation: pulse-badge 2s infinite;
}

@keyframes pulse-badge {

  0%,
  100% {
    box-shadow: 0 0 8px var(--rojo-glow);
  }

  50% {
    box-shadow: 0 0 16px rgba(232, 0, 29, 0.5);
  }
}
</style>