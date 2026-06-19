<template>
  <div class="bg-black text-white min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
          <div class="card bg-dark-2 border-secondary rounded-4">
            <div class="card-body p-4 p-md-5">
              <div class="text-center mb-4">
                <router-link to="/" class="navbar-brand fw-bold fs-3 text-white">
                  TECH<span class="text-danger">STORE</span>
                </router-link>
                <h2 class="h4 mt-3">Crear cuenta</h2>
                <p class="text-muted small">Regístrate para ver tus pedidos y más</p>
              </div>

              <form @submit.prevent="handleRegister">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Nombre</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.name" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Apellido</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.surname" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-bold">Correo electrónico</label>
                    <input type="email" class="form-control bg-dark text-white border-secondary" v-model="form.email" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-bold">Teléfono / WhatsApp</label>
                    <input type="tel" class="form-control bg-dark text-white border-secondary" v-model="form.phone" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-bold">Número de documento (DNI/RUC) <span class="text-muted">(opcional)</span></label>
                    <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.n_document">
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-bold">Contraseña</label>
                    <input type="password" class="form-control bg-dark text-white border-secondary" v-model="form.password" required minlength="6">
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-bold">Confirmar contraseña</label>
                    <input type="password" class="form-control bg-dark text-white border-secondary" v-model="form.password_confirmation" required>
                  </div>
                </div>

                <div class="form-check mt-3">
                  <input class="form-check-input" type="checkbox" id="terms" v-model="acceptTerms" required>
                  <label class="form-check-label text-white-50 small" for="terms">
                    Acepto los <a href="#" class="text-danger">Términos y condiciones</a> y la 
                    <a href="#" class="text-danger">Política de privacidad</a>.
                  </label>
                </div>

                <button class="btn btn-danger w-100 mt-4 py-2 fw-bold" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                  Registrarme
                </button>
              </form>

              <hr class="border-secondary my-4">

              <p class="text-center text-muted small mb-0">
                ¿Ya tienes cuenta? 
                <router-link :to="{ path: '/cliente/login', query: { redirect: redirectTo } }" class="text-danger text-decoration-none">
                  Inicia sesión
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
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useClientAuthStore } from '@/stores/clientAuth'

const router = useRouter()
const route = useRoute()
const clientAuth = useClientAuthStore()

const form = ref({
  name: '',
  surname: '',
  email: '',
  phone: '',
  n_document: '',
  password: '',
  password_confirmation: ''
})
const acceptTerms = ref(false)
const loading = ref(false)

const redirectTo = computed(() => route.query.redirect || '/')

const handleRegister = async () => {
  if (form.value.password !== form.value.password_confirmation) {
    alert('Las contraseñas no coinciden')
    return
  }
  if (!acceptTerms.value) {
    alert('Debes aceptar los términos y condiciones')
    return
  }

  loading.value = true
  try {
    await clientAuth.register(form.value)//aqui lo que hago es llamar al metodo register
    router.push(redirectTo.value as string)//redirigir a la pagina que estaba
  } catch (error: any) {
    let msg = 'Error al registrarse'
    if (error.response?.data?.errors) {
      const errs = error.response.data.errors
      msg = Object.values(errs).flat().join('\n')
    } else if (error.response?.data?.message) {
      msg = error.response.data.message
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