<template>
  <div class="bg-black text-white min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
          <div class="card bg-dark-2 border-secondary rounded-4">
            <div class="card-body p-4 p-md-5">
              <div class="text-center mb-4">
                <router-link to="/" class="navbar-brand fw-bold fs-3 text-white">
                  TECH<span class="text-danger">STORE</span>
                </router-link>
                <h2 class="h4 mt-3">Iniciar sesión</h2>
                <p class="text-muted small">Accede a tu cuenta para gestionar tus pedidos</p>
              </div>

              <form @submit.prevent="handleLogin">
                <div class="mb-3">
                  <label class="form-label small fw-bold">Correo electrónico</label>
                  <input type="email" class="form-control bg-dark text-white border-secondary" v-model="form.email" required placeholder="cliente@ejemplo.com">
                </div>
                <div class="mb-3">
                  <label class="form-label small fw-bold">Contraseña</label>
                  <input type="password" class="form-control bg-dark text-white border-secondary" v-model="form.password" required placeholder="••••••">
                </div>
                <div class="mb-3 d-flex justify-content-between align-items-center">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" v-model="remember">
                    <label class="form-check-label text-white-50 small" for="remember">Recordarme</label>
                  </div>
                  <a href="#" class="text-danger small text-decoration-none">¿Olvidaste tu contraseña?</a>
                </div>
                <button class="btn btn-danger w-100 py-2 fw-bold" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                  Ingresar
                </button>
              </form>

              <hr class="border-secondary my-4">

              <p class="text-center text-muted small mb-0">
                ¿No tienes cuenta? 
                <router-link :to="{ path: '/registro', query: { redirect: redirectTo } }" class="text-danger text-decoration-none">
                  Regístrate aquí
                </router-link>
              </p>
            </div>
          </div>

          <div class="text-center mt-3">
            <router-link to="/" class="text-white-50 small text-decoration-none">
              ← Volver a la tienda
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useClientAuthStore } from '@/stores/clientAuth'

const router = useRouter()
const route = useRoute()
const clientAuth = useClientAuthStore()

const form = ref({
  email: '',
  password: ''
})
const remember = ref(false)
const loading = ref(false)

const redirectTo = computed(() => route.query.redirect || '/')

const handleLogin = async () => {
  loading.value = true
  try {
    await clientAuth.login(form.value)
    // Redirigir a la página que estaba o al checkout
    router.push(redirectTo.value as string)
  } catch (error: any) {
    let msg = 'Error al iniciar sesión'
    if (error.response?.data?.message) msg = error.response.data.message
    else if (error.response?.data?.errors) {
      const errs = error.response.data.errors
      msg = Object.values(errs).flat().join('\n')
    }
    alert(msg)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (clientAuth.isAuthenticated) {
    router.push(redirectTo.value as string)
  }
})
</script>

<style scoped>
.bg-dark-2 { background-color: #111; }
</style>