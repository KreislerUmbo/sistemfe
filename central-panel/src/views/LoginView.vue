<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import httpClient from '@/services/httpClient';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();

const email = ref('');
const password = ref('');
const loading = ref(false);
const errorMessage = ref<string | null>(null);

async function onSubmit() {
  loading.value = true;
  errorMessage.value = null;

  try {
    const { data } = await httpClient.post('central/auth/login', {
      email: email.value,
      password: password.value,
    });

    auth.saveSession(data.access_token, data.user);
    router.push({ name: 'dashboard' });
  } catch (error: any) {
    errorMessage.value = error.response?.data?.message ?? 'No se pudo iniciar sesión.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="card shadow-sm" style="width: 24rem">
      <div class="card-body p-4">
        <h1 class="h4 mb-1">Panel Central</h1>
        <p class="text-muted mb-4">SistemaFE — gestión de tenants</p>

        <form @submit.prevent="onSubmit">
          <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input
              id="email"
              v-model="email"
              type="email"
              class="form-control"
              required
              autocomplete="username"
            />
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Contraseña</label>
            <input
              id="password"
              v-model="password"
              type="password"
              class="form-control"
              required
              autocomplete="current-password"
            />
          </div>

          <div v-if="errorMessage" class="alert alert-danger py-2" role="alert">
            {{ errorMessage }}
          </div>

          <button type="submit" class="btn btn-primary w-100" :disabled="loading">
            {{ loading ? 'Ingresando…' : 'Ingresar' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
