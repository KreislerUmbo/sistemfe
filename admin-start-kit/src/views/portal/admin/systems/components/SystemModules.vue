<template>
  <b-card class=" shadow">
    <template #header>
      <h5 class="mb-0"><i class="fas fa-cubes me-2"></i>Módulos del Sistema</h5>
    </template>

    <p class="text-muted small">
           Cada módulo se muestra como un bloque en la pestaña "Características" del detalle. en el portal
    </p>

    <!-- Lista de módulos -->
    <div v-for="(mod, modIdx) in localModules" :key="modIdx" class="mb-3 py-0">
      <b-card no-body >
        <!-- Header con toggle -->
        <b-card-header class="d-flex align-items-center justify-content-between py-2">
          <div class="d-flex align-items-center">
            <!-- Ícono -->
            <b-form-input
              v-model="mod.icon"
              placeholder="Ícono"
              style="max-width:70px;"
              @input="emitChange"
            />
            <!-- Nombre -->
            <b-form-input
              v-model="mod.name"
              placeholder="Nombre del módulo"
              class="mx-2"
              @input="emitChange"
            />
            <!-- Badge -->
            <b-badge variant="secondary" class="me-2">
              {{ mod.items.length }} ítems
            </b-badge>
          </div>

          <!-- Botones -->
          <div>
            <b-button
              v-b-toggle="'module-'+modIdx"
              variant="outline-primary"
              size="sm"
              class="me-2"
            >
              <i class="fas fa-chevron-down"></i>
            </b-button>
            <b-button
              variant="outline-danger"
              size="sm"
              @click="removeModule(modIdx)"
              title="Eliminar módulo"
            >
              <i class="fas fa-trash"></i>
            </b-button>
          </div>
        </b-card-header>

        <!-- Collapse con ítems -->
        <b-collapse :id="'module-'+modIdx" class="card-body">
          <div
            v-for="(item, itemIdx) in mod.items"
            :key="itemIdx"
            class="d-flex align-items-center mb-2"
          >
            <b-form-input
              v-model="mod.items[itemIdx]"
              placeholder="Funcionalidad..."
              @input="emitChange"
            />
            <b-button
              variant="outline-danger"
              size="sm"
              class="ms-2"
              @click="removeModuleItem(modIdx, itemIdx)"
              title="Eliminar ítem"
            >
              <i class="fas fa-times"></i>
            </b-button>
          </div>

          <!-- Botón agregar ítem -->
          <b-button
            variant="outline-secondary"
            size="sm"
            @click="addModuleItem(modIdx)"
          >
            <i class="fas fa-plus me-1"></i>Agregar ítem del módulo
          </b-button>
        </b-collapse>
      </b-card>
    </div>

    <!-- Botón agregar módulo -->
    <b-button variant="success" @click="addModule">
      <i class="fas fa-plus me-1"></i>Agregar nuevo módulo
    </b-button>
  </b-card>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  modules: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['update:modules', 'change']);

// Copia local de módulos
const localModules = ref([...props.modules]);

// Función para emitir cambios al padre
function emitChange() {
  emit('update:modules', localModules.value);
  emit('change', localModules.value);
}

// Agregar módulo
function addModule() {
  localModules.value.push({
    name: '',
    icon: '📋',
    items: []
  });
  emitChange();
}

// Eliminar módulo
function removeModule(index) {
  localModules.value.splice(index, 1);
  emitChange();
}

// Agregar ítem a un módulo
function addModuleItem(modIdx) {
  localModules.value[modIdx].items.push('');
  emitChange();
}

// Eliminar ítem de un módulo
function removeModuleItem(modIdx, itemIdx) {
  localModules.value[modIdx].items.splice(itemIdx, 1);
  emitChange();
}

// Sincronizar con el prop del padre
watch(() => props.modules, (newVal) => {
  // Evitar bucle infinito comparando
  if (JSON.stringify(newVal) !== JSON.stringify(localModules.value)) {
    localModules.value = [...newVal];
  }
}, { deep: true });
</script>