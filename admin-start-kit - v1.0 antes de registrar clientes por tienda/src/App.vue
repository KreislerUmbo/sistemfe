<template>
  <RouterView />
</template>

<script setup lang="ts">
import { RouterView } from "vue-router";
import { useLayoutStore } from "@/stores/layout";
// import configureFakeBackend from "@/helpers/fake-backend";
import { onMounted } from "vue";

onMounted(() => {
  useLayoutStore().init();
});

// configureFakeBackend();
</script>
