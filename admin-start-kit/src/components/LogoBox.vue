<template>
  <router-link to="/admin" class="logo">
    <span>
      <img :src="logoSmActual" alt="logo-small" class="logo-sm" />{{ " " }}
    </span>
    <span class="">
      <img :src="logoLgActual" alt="logo-large" class="logo-lg logo-light" />
      <img :src="logoLgActual" alt="logo-large" class="logo-lg logo-dark" />
    </span>
  </router-link>
</template>
<script setup lang="ts">
import { ref, onMounted } from "vue";
import logoSm from "@/assets/images/logo-sm.png";
import logoLight from "@/assets/images/logo-light.png";
import { fetchBranding } from "@/helpers/branding";

// logo-sm (ícono, colapsado) y logo-lg (expandido) conviven en pantalla al
// mismo tiempo cuando el sidebar está expandido — no es un toggle, son dos
// imágenes vecinas. Repetir el mismo logo_vertical cuadrado en ambas se veía
// como el logo duplicado; logo_horizontal (pensado para espacios anchos,
// mismo criterio que el PDF de cotización) es el que corresponde acá. Si el
// tenant no subió uno propio (Configuraciones > Empresa), se mantiene el
// logo genérico de la plantilla.
const logoSmActual = ref(logoSm);
const logoLgActual = ref(logoLight);

onMounted(async () => {
  const branding = await fetchBranding();
  if (branding?.logo_vertical) {
    logoSmActual.value = branding.logo_vertical;
  }
  if (branding?.logo_horizontal) {
    logoLgActual.value = branding.logo_horizontal;
  } else if (branding?.logo_vertical) {
    logoLgActual.value = branding.logo_vertical;
  }
});
</script>
