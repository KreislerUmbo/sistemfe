<template>
  <b-card>
    <template #header>
      <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Características (panel de precio)</h5>
    </template>

    <p class="text-muted small">
      Estos ítems aparecen como lista de check ✓ en el panel de precio del detalle del sistema.
      Arrastra <i class="fas fa-grip-vertical"></i> para reordenar.
    </p>

    <!-- Lista de características -->
    <div v-for="(feat, idx) in localFeatures" :key="idx" class="feature-row">
      <div class="d-flex align-items-center mb-2">
        <!-- Drag handle (visual) -->
        <i class="fas fa-grip-vertical text-muted me-2" style="cursor:grab;"></i>

        <!-- Input de texto -->
        <b-form-input v-model="localFeatures[idx]" placeholder="Ej: Punto de venta (POS) ilimitado"
          @input="updateParent" />

        <!-- Botón eliminar -->
        <b-button variant="outline-danger" size="sm" class="ms-2" @click="removeFeature(idx)"
          title="Eliminar característica">
          <i class="fas fa-trash"></i>
        </b-button>
      </div>
    </div>

    <!-- Botón agregar -->
    <b-button variant="outline-success" size="sm" @click="addFeature">
      <i class="fas fa-plus me-1"></i>Agregar característica
    </b-button>
  </b-card>
</template>

<script setup>
import { ref, watch } from 'vue';

// Props: recibimos el array de características desde el padre
const props = defineProps({
  features: {
    type: Array,
    required: true,
    default: () => []
  }
});

// Emit: notificamos al padre cuando el array cambia
const emit = defineEmits(['update:features']);

// Copia local para trabajar sin mutar directamente el prop
const localFeatures = ref([...props.features]);

// Sincronizar cambios hacia el padre
function updateParent() {
  emit('update:features', [...localFeatures.value]);
}

// Agregar una nueva característica (vacía)
function addFeature() {
  // Verificar si la última está vacía para no crear múltiples vacías
/*   if (localFeatures.value.length > 0 && localFeatures.value[localFeatures.value.length - 1] === '') {
    alert('No puedes agregar varias características vacías');
    return;
  } */
  localFeatures.value.push('');
  updateParent();
}

// Eliminar una característica por índice
function removeFeature(index) {
  localFeatures.value.splice(index, 1);
  updateParent();
}

// Si el padre actualiza el prop, reflejamos el cambio
watch(() => props.features, (newVal) => {
  localFeatures.value = [...newVal];
}, { deep: true });

// Exponer el array al padre (por si necesita acceder directamente)
defineExpose({
  localFeatures,
  updateParent
});
</script>

<style scoped>
.feature-row {
  transition: all 0.2s;
}

.feature-row:hover {
  background-color: rgba(0, 0, 0, 0.02);
  border-radius: 4px;
}
</style>