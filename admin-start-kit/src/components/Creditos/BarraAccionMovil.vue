<template>
  <div class="barra-accion">
    <div class="barra-accion__contenido d-flex gap-2 justify-content-end">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (04-frontend, verificación 4): en celular la acción principal va fija
// abajo, al alcance del pulgar (la barra superior de Rizz ocupa 100 px fijos). Desde
// 768 px vuelve a ser una fila normal al pie del formulario.
</script>

<style scoped>
.barra-accion {
  margin-top: 1rem;
}

@media (max-width: 767.98px) {
  .barra-accion {
    position: sticky;
    bottom: 0;
    z-index: 1020;
    margin: 1rem -1rem 0;
    padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom));
    background: var(--bs-body-bg);
    border-top: 1px solid var(--bs-border-color);
    box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.06);
  }

  /* En celular los botones se reparten el ancho, con el tamaño normal del sistema. */
  .barra-accion__contenido :deep(.btn) {
    flex: 1 1 0;
    white-space: nowrap;
    padding-inline: 0.5rem;
    font-size: 0.8125rem;
  }
}
</style>
