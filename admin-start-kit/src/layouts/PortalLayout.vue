<!-- frontend/src/layouts/PortalLayout.vue -->
<template>
  <div class="bg-black min-vh-100">
    <NavPortal />
    <main class="flex-grow-1">
      <router-view />
    </main>
    <FooterPortal />
    <!-- Aquí va el drawer del carrito -->
    <CartDrawer />
  </div>
</template>

<script setup>
import NavPortal from '@/components/Portal/NavbarPortal.vue'
import FooterPortal from '@/components/Portal/FooterPortal.vue'
import CartDrawer from '@/components/Portal/CartDrawer.vue'
</script>