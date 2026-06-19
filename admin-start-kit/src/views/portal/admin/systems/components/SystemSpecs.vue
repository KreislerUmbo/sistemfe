<template>
  <b-card>
    <template #header>
      <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>Especificaciones Técnicas</h5>
    </template>
    <p class="text-muted small">Información técnica que se muestra en la pestaña "Especificaciones" del detalle.</p>
    
    <div v-for="(spec, index) in specs" :key="index" class="d-flex align-items-center mb-2">
      <b-form-input
        v-model="specs[index].spec_key"
        placeholder="Clave (ej: Requisitos)"
        class="me-2"
        style="flex:0 0 35%;"
        @input="$emit('update:specs', specs)"
      />
      <b-form-input
        v-model="specs[index].spec_value"
        placeholder="Valor (ej: PC, tablet o celular)"
        @input="$emit('update:specs', specs)"
      />
      <b-button
        variant="outline-danger"
        size="sm"
        class="ms-2"
        @click="removeSpec(index)"
        title="Eliminar especificación"
      >
        <i class="fas fa-trash"></i>
      </b-button>
    </div>
    
    <b-button variant="outline-success" size="sm" @click="addSpec">
      <i class="fas fa-plus me-1"></i>Agregar especificación
    </b-button>
  </b-card>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  specs: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['update:specs']);

// Copia local
const localSpecs = ref([...props.specs]);

watch(localSpecs, (newVal) => {
  emit('update:specs', newVal);
}, { deep: true });

watch(() => props.specs, (newVal) => {
  localSpecs.value = [...newVal];
});

function addSpec() {
  localSpecs.value.push({ spec_key: '', spec_value: '' });
}

function removeSpec(index) {
  localSpecs.value.splice(index, 1);
}
</script>