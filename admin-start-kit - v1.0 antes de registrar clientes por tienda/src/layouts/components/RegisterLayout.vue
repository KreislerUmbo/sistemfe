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
</style>
