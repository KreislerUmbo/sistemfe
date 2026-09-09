<template>
  <div class="form-header d-flex justify-content-between align-items-center w-100
           border-bottom py-2 px-3 position-sticky z-50" :style="{ top: stickyOffset + 'px' }">
    <!-- Título dinámico -->
    <h4 class="mb-0">{{ title }}</h4>

    <!-- Botones -->
    <div class="d-flex gap-2">
      <!-- Cancelar -->
      <b-button :variant="cancelVariant" @click="$emit('cancel')">
        {{ cancelText }}
      </b-button>

      <!-- Guardar -->
      <b-button :variant="saveVariant" @click="$emit('save')">
        {{ saveText }}
      </b-button>
    </div>
  </div>
</template>

<script>
export default {
  name: "RegisterLayout",
  props: {
    title: { type: String, required: true },
    saveText: { type: String, default: "Guardar" },
    saveVariant: { type: String, default: "primary" },
    cancelText: { type: String, default: "Regresar" },
    cancelVariant: { type: String, default: "warning" },
    stickyOffset: { type: Number, default: 100 },
  }
};
</script>

<style scoped>
.form-header {
  background-color: var(--background-color, rgb(255, 255, 255));
  color: var(--text-color, #141414);
  backdrop-filter: blur(5px);
}

/* Fix (09-sep-2026) — el <h4> de acá adentro quedaba invisible en tema
   oscuro: Bootstrap le pone su propia regla explícita de color
   (h1..h6 { color: var(--bs-heading-color) }), que en tema oscuro esta
   app remapea A PROPÓSITO a un color casi blanco (para leerse bien
   contra el fondo oscuro del resto de la app) — eso GANA por sobre el
   color heredado de .form-header de arriba (una declaración explícita
   siempre le gana a una heredada, sin importar la especificidad). Como
   .form-header SIEMPRE es un fondo claro literal (no themed), el título
   necesita su propio color explícito para no depender de esa variable.
*/
.form-header h4 {
  color: var(--text-color, #141414);
}
</style>
