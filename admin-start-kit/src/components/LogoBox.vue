<template>
  <router-link to="/" class="logo">
    <span>
      <img :src="logoSm" alt="logo-small" class="logo-sm" />{{ " " }}
    </span>
    <span class="">
      <img :src="logoLight" alt="logo-large" class="logo-lg logo-light" />
      <img :src="logoDark" alt="logo-large" class="logo-lg logo-dark" />
    </span>
  </router-link>
</template>
<script setup lang="ts">
import logoSm from "@/assets/images/logo-sm.png";
import logoLight from "@/assets/images/logo-light.png";
import logoDark from "@/assets/images/logo-dark.png";
</script>
