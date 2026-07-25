<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();

function onLogout() {
  auth.logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <nav class="navbar navbar-expand navbar-dark bg-dark px-3">
    <span class="navbar-brand mb-0 h1">Panel Central</span>
    <ul class="navbar-nav me-auto flex-row gap-3">
      <li class="nav-item">
        <router-link :to="{ name: 'dashboard' }" class="nav-link">Inicio</router-link>
      </li>
      <li class="nav-item">
        <router-link :to="{ name: 'tenants' }" class="nav-link">Tenants</router-link>
      </li>
      <li class="nav-item">
        <router-link :to="{ name: 'plans' }" class="nav-link">Planes</router-link>
      </li>
      <li class="nav-item">
        <router-link :to="{ name: 'audit-logs' }" class="nav-link">Auditoría</router-link>
      </li>
    </ul>
    <div class="d-flex align-items-center gap-3">
      <span class="text-white-50 small">{{ auth.user?.email }}</span>
      <button type="button" class="btn btn-outline-light btn-sm" @click="onLogout">
        Cerrar sesión
      </button>
    </div>
  </nav>
</template>
