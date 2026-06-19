<template>
  <div class="container py-5">
    <div class="row">
      <div class="col-md-3">
        <!-- Menú lateral de cuenta -->
        <div class="list-group mb-4">
          <router-link to="/mi-cuenta/perfil" class="list-group-item list-group-item-action bg-dark text-white border-secondary">Perfil</router-link>
          <router-link to="/mi-cuenta/pedidos" class="list-group-item list-group-item-action bg-dark text-white border-secondary">Mis pedidos</router-link>
          <router-link to="/mi-cuenta/direcciones" class="list-group-item list-group-item-action bg-dark text-white border-secondary">Direcciones</router-link>
          <button @click="cerrarSesion" class="list-group-item list-group-item-action bg-dark text-danger border-secondary">Cerrar sesión</button>
        </div>
      </div>
      <div class="col-md-9">
        <div class="card bg-dark text-white border-secondary">
          <div class="card-body">
            <h4 class="card-title">Mi cuenta</h4>
            <p class="card-text">Información del usuario...</p>
            <!-- Aquí cargas datos del usuario desde el store de auth -->
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const authStore = useAuthStore()
const router = useRouter()

const cerrarSesion = () => {
  //authStore.logout()
  router.push('/')
}
</script>