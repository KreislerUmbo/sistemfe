<script setup lang="ts">
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
</script>

<template>
  <div class="container py-5">
    <h1 class="h3">Bienvenido al panel central</h1>
    <p class="text-muted">
      Sesión iniciada como <strong>{{ auth.user?.name }}</strong> ({{ auth.user?.email }}).
    </p>
    <p class="text-muted">
      Roles: {{ auth.user?.roles?.join(', ') || 'sin roles' }}
    </p>
    <p class="fst-italic">
      Placeholder — el listado de tenants, backups, suscripciones y audit log se agregan en
      sesiones futuras (ver <code>plan-panel-superadmin.md</code>, Fase D).
    </p>
  </div>
</template>
