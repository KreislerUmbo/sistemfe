<!-- frontend/src/views/portal/HomePortal.vue -->
<template>
  <div>
     <HeroPortal />   <!-- ← slider de ofertas -->
    <SeccionSistemasEmpresariales />
    <SeccionCategorias />
    <SeccionServicios />
  </div>
</template>

<script setup>
import HeroPortal from '@/components/portal/HeroPortal.vue'
import SeccionSistemasEmpresariales from '@/components/Portal/SeccionSistemasEmpresariales.vue'
import SeccionCategorias from '@/components/portal/SeccionCategorias.vue'
import SeccionServicios from '@/components/portal/SeccionServicios.vue'
</script>