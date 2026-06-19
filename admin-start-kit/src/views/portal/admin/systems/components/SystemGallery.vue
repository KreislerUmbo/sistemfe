<template>
  <b-card>
    <template #header>
      <h5 class="mb-0"><i class="fas fa-images me-2"></i>Galería de Imágenes</h5>
    </template>

    <!-- Dropzone para subir imágenes -->
    <b-form-file
      v-model="files"
      multiple
      accept="image/*"
      placeholder="Arrastra o selecciona imágenes..."
      drop-placeholder="Suelta las imágenes aquí..."
      @change="handleFileUpload"
      class="mb-3"
    />

    <!-- Grilla de miniaturas -->
    <div v-if="localGallery.length > 0" class="d-flex flex-wrap gap-2">
      <div
        v-for="(img, index) in localGallery"
        :key="index"
        class="gallery-item position-relative"
        :class="{ 'is-cover': img.is_primary }"
      >
        <!-- Imagen -->
        <img
          :src="img.url"
          alt="Imagen del sistema"
          class="img-thumbnail"
          style="width: 120px; height: 120px; object-fit: cover;"
        />

        <!-- Botón eliminar -->
        <button
          class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1"
          @click="removeImage(index)"
          title="Eliminar imagen"
        >
          <i class="fas fa-times"></i>
        </button>

        <!-- Etiqueta de portada -->
        <span
          v-if="img.is_primary"
          class="badge bg-success position-absolute bottom-0 start-0 m-1"
        >
          Portada
        </span>

        <!-- Botón para marcar como portada (si no lo es) -->
        <button
          v-else
          class="btn btn-primary btn-sm position-absolute bottom-0 start-0 m-1"
          @click="setPrimary(index)"
          title="Marcar como portada"
        >
          <i class="fas fa-star"></i>
        </button>

        <!-- Indicador de archivo local (para saber si se subirá) -->
        <span
          v-if="img.file"
          class="badge bg-info position-absolute top-0 start-0 m-1"
          style="font-size: 9px;"
        >
          Nuevo
        </span>
      </div>
    </div>

    <!-- Mensaje cuando no hay imágenes -->
    <p v-else class="text-muted text-center py-3 mb-0">
      <i class="fas fa-cloud-upload-alt me-2"></i>
      No hay imágenes. Sube una o varias haciendo clic en el área de arriba.
    </p>
  </b-card>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  gallery: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['update:gallery']);

// Copia local de la galería (para no mutar el prop directamente)
const localGallery = ref([...props.gallery]);

// Archivos seleccionados (para el input file)
const files = ref(null);

// Sincronizar cambios al padre
watch(localGallery, (newVal) => {
  emit('update:gallery', newVal);
}, { deep: true });

// Si el padre cambia el prop, actualizar la copia local
watch(() => props.gallery, (newVal) => {
  // Evitar un bucle infinito comparando si realmente cambió
  if (JSON.stringify(newVal) !== JSON.stringify(localGallery.value)) {
    localGallery.value = [...newVal];
  }
}, { deep: true });

// Manejar subida de archivos
function handleFileUpload() {
  if (!files.value || files.value.length === 0) return;

  // Convertir FileList a array
  const fileArray = Array.from(files.value);

  fileArray.forEach(file => {
    // Crear URL de previsualización
    const reader = new FileReader();
    reader.onload = (e) => {
      const newImage = {
        file: file,           // Guardamos el archivo para subirlo después
        url: e.target.result, // URL para previsualización
        is_primary: localGallery.value.length === 0 // El primero es portada
      };
      localGallery.value.push(newImage);
    };
    reader.readAsDataURL(file);
  });

  // Limpiar el input para poder seleccionar los mismos archivos nuevamente
  files.value = null;
}

// Eliminar imagen (por índice)
function removeImage(index) {
  const removed = localGallery.value[index];
  // Si era la portada, asignar la primera como portada (si queda alguna)
  if (removed.is_primary && localGallery.value.length > 1) {
    // Buscar el primer elemento que no sea el eliminado
    const remaining = localGallery.value.filter((_, i) => i !== index);
    if (remaining.length > 0) {
      remaining[0].is_primary = true;
    }
  }
  localGallery.value.splice(index, 1);
}

// Establecer imagen como portada
function setPrimary(index) {
  localGallery.value.forEach((img, i) => {
    img.is_primary = i === index;
  });
}

// Exponer método para obtener archivos para subir (usado en el padre)
function getFilesToUpload() {
  return localGallery.value
    .filter(img => img.file)          // solo las que tienen archivo
    .map(img => img.file);
}

// Exponer método para obtener datos de la galería (para guardar en DB)
function getGalleryData() {
  return localGallery.value.map(img => ({
    url: img.url,                     // puede ser URL existente o la que se subirá
    is_primary: img.is_primary,
    file: img.file || null
  }));
}

// Exponer funciones al padre mediante defineExpose
defineExpose({
  getFilesToUpload,
  getGalleryData
});
</script>

<style scoped>
.gallery-item {
  border-radius: 8px;
  overflow: hidden;
  border: 2px solid transparent;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.gallery-item.is-cover {
  border-color: #28a745;
  box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.3);
}
.gallery-item:hover {
  border-color: #6c757d;
}
.gallery-item .btn {
  border-radius: 50%;
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}
</style>