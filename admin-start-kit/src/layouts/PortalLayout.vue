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
import NavPortal from '@/components/portal/NavbarPortal.vue'
import FooterPortal from '@/components/portal/FooterPortal.vue'
import CartDrawer from '@/components/portal/CartDrawer.vue'
</script>