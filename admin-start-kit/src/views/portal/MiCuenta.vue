<!-- frontend/src/views/portal/MiCuenta.vue -->
<template>
  <div class="container py-4">
    <div class="row">
      <!-- Menú lateral -->
      <div class="col-md-3 mb-4">
        <div class="card bg-dark text-white border-secondary">
          <div class="card-body">
            <div class="text-center mb-3">
              <div class="avatar-circle bg-danger mx-auto mb-2">
                {{ iniciales }}
              </div>
              <h5 class="mb-0">{{ clientAuth.client?.full_name || clientAuth.client?.name }}</h5>
              <small class="text-muted">{{ clientAuth.client?.email }}</small>
            </div>
            <hr class="border-secondary">

            <div class="p-2">
              <div class="sb-label px-2 pt-2 pb-1">General</div>
              <ul class="nav nav-account flex-column gap-1">
                <li>
                  <router-link to="/micuenta/panel" class="nav-link text-white" active-class="active bg-danger" >
                    <i class="bi bi-grid-3x3-gap-fill me-2"></i> Panel
                  </router-link>
                </li>
                <li class="nav-item">
                  <router-link to="/micuenta/pedidos" class="nav-link text-white" active-class="active bg-danger"
                    :class="{ active: $route.path === '/micuenta/pedidos' }">
                    <i class="bi bi-receipt me-2"></i> Mis pedidos
                  </router-link>
                </li>
                <li class="nav-item">
                  <router-link to="/micuenta/comprobantes" class="nav-link text-white" active-class="active bg-danger"
                    :class="{ active: $route.path === '/micuenta/comprobantes' }">
                    <i class="bi bi-receipt me-2"></i> Mis Comprobantes
                  </router-link>
                </li>
                <li class="nav-item">
                  <router-link to="/micuenta/favoritos" class="nav-link text-white" active-class="active bg-danger"
                    :class="{ active: $route.path === '/micuenta/favoritos' }">
                    <i class="fa-regular fa-heart me-2"></i> Favoritos
                  </router-link>
                </li>
              </ul>


              <div class="sb-label px-2 pt-3 pb-1">Cuenta</div>
              <ul class="nav nav-account flex-column gap-1">
                <li>
                  <router-link to="/micuenta/perfil" class="nav-link text-white" active-class="active bg-danger"
                    :class="{ active: $route.path === '/micuenta/perfil' }">
                    <i class="bi bi-person-circle me-2"></i> Datos Personales
                  </router-link>
                </li>
                <li class="nav-item">
                  <router-link to="/micuenta/datos" class="nav-link text-white" active-class="active bg-danger">
                    <i class="fa-solid fa-location-dot me-2"></i> Mis direcciones
                  </router-link>
                </li>
                <li class="nav-item">
                  <router-link to="/micuenta/seguridad" class="nav-link text-white" active-class="active bg-danger">
                    <i class="fa-solid fa-lock me-2"></i> Seguridad
                  </router-link>
                </li>

              </ul>
            </div>
            <hr class="border-secondary">
            <div class="p-2">
              <button @click="cerrarSesion" class="nav-link text-white text-start w-100">
                <i class="bi bi-box-arrow-right me-2"></i> Cerrar sesión
              </button>
            </div>

          </div>
        </div>
      </div>
      <!-- Contenido dinámico -->
      <div class="col-md-9">
        <router-view />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useClientAuthStore } from '@/stores/clientAuth'
import type { Client } from '@/stores/clientAuth'
import { useRouter } from 'vue-router'

const clientAuth = useClientAuthStore()

const router = useRouter()

const client = computed<Client | null>(() => clientAuth.client)

const iniciales = computed(() => {
  const nombre = client.value?.name || ''
  const apellido = client.value?.surname || ''
  return (nombre.charAt(0) + apellido.charAt(0)).toUpperCase()
})

const cerrarSesion = () => {
  clientAuth.logout()
  router.push('/')
}
</script>

<style scoped>
.avatar-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  font-weight: bold;
}

.sb-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--gris);
}
</style>