<template>
  <div class="container-fluid">

    <div class="mb-4">
      <div class="security-label">MI CUENTA</div>
      <h1 class="security-title">SEGURIDAD</h1>
    </div>

    <div class="card security-card">

      <div class="card-header security-header">
        <i class="bi bi-shield-lock me-2"></i>
        CONFIGURACIÓN DE SEGURIDAD
      </div>

      <div class="card-body">

        <form @submit.prevent="cambiarPassword">

          <div class="row">

            <div class="col-md-4">
              <label class="form-label">
                Contraseña Actual
              </label>

              <input
                v-model="form.current_password"
                type="password"
                class="form-control security-input"
                required
              >
            </div>

            <div class="col-md-4">
              <label class="form-label">
                Nueva Contraseña
              </label>

              <input
                v-model="form.password"
                type="password"
                class="form-control security-input"
                required
              >
            </div>

            <div class="col-md-4">
              <label class="form-label">
                Confirmar Contraseña
              </label>

              <input
                v-model="form.password_confirmation"
                type="password"
                class="form-control security-input"
                required
              >
            </div>

          </div>

          <div class="mt-4">

            <button
              type="submit"
              class="btn btn-danger"
              :disabled="saving"
            >

              <span
                v-if="saving"
                class="spinner-border spinner-border-sm me-2"
              ></span>

              Actualizar Contraseña

            </button>

          </div>

        </form>

      </div>

    </div>

  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import Swal from 'sweetalert2'
import publicHttpClient from '@/helpers/publicHttpClient'

const saving = ref(false)

const form = ref({
  current_password: '',
  password: '',
  password_confirmation: ''
})

const cambiarPassword = async () => {

  saving.value = true

  try {

    const response = await publicHttpClient.put(
      '/portal/profile/password',
      form.value
    )

    Swal.fire({
      icon: 'success',
      title: 'Contraseña actualizada',
      text: response.data.message
    })

    form.value = {
      current_password: '',
      password: '',
      password_confirmation: ''
    }

  } catch (error: any) {

    console.error(error)

    Swal.fire({
      icon: 'error',
      title: 'Error',
      text: error.response?.data?.message || 'No se pudo actualizar'
    })

  } finally {

    saving.value = false

  }

}
</script>

<style scoped>

.security-label{
  color:#ff2748;
  font-size:.9rem;
  font-weight:700;
  letter-spacing:3px;
}

.security-title{
  color:#fff;
  font-weight:800;
  margin-top:5px;
}

.security-card{
  background:#121212;
  border:1px solid #2b2b2b;
  border-radius:20px;
}

.security-header{
  background:#0d0d0d;
  color:white;
  font-weight:700;
  border-bottom:1px solid #2b2b2b;
}

.security-input{
  background:#1b1b1b;
  border:1px solid #333;
  color:white;
}

.security-input:focus{
  background:#1b1b1b;
  color:white;
  border-color:#dc3545;
  box-shadow:none;
}

.form-label{
  color:#d6d6d6;
  font-size:.9rem;
}

</style>