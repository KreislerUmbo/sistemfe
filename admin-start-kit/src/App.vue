<template>
  <RouterView />
</template>

<script setup lang="ts">
import { RouterView } from "vue-router";
import { useLayoutStore } from "@/stores/layout";
import { useAuthStore } from "@/stores/auth";
import { iniciarVigilanciaSesion } from "@/helpers/sessionExpiryWatcher";
// import configureFakeBackend from "@/helpers/fake-backend";
import { onMounted } from "vue";

onMounted(() => {
  useLayoutStore().init();
  // Cubre el caso de sesión ya abierta antes de este mount (F5) — login.vue
  // arranca la vigilancia aparte para una sesión nueva.
  if (useAuthStore().isAuthenticated()) {
    iniciarVigilanciaSesion();
  }
});

// configureFakeBackend();
</script>
