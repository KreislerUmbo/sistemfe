<template>
  <b-card class=" shadow">
    <template #header>
      <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Información General</h5>
    </template>

    <b-row>
      <!-- Ícono -->
      <b-col md="3">
        <b-form-group label="Ícono">
          <div class="icon-picker" @click="openEmojiPicker">
            <span class="display-4">{{ localIcon || '🛒' }}</span>
          </div>
          <small class="text-muted">Haz clic para cambiar</small>
        </b-form-group>
      </b-col>

      <!-- Nombre y slug -->
      <b-col md="9">
        <b-form-group label="Nombre *">
          <b-form-input
            v-model="localName"
            placeholder="Ej: Minimarket"
            @input="emitChange"
          />
        </b-form-group>
        <b-form-group label="Slug (URL)">
          <b-form-input
            v-model="localSlug"
            placeholder="minimarket"
            disabled
          />
          <small class="text-muted">Se genera automáticamente a partir del nombre</small>
        </b-form-group>
      </b-col>
    </b-row>

    <b-row>
      <!-- Categoría -->
      <b-col md="6">
        <b-form-group label="Categoría *">
          <b-form-select
            v-model="localCategoryId"
            :options="categoryOptions"
            @change="emitChange"
          >
            <template #first>
              <b-form-select-option :value="null">Seleccionar categoría...</b-form-select-option>
            </template>
          </b-form-select>
        </b-form-group>
      </b-col>

      <!-- Estado activo -->
      <b-col md="6">
        <b-form-group label="Estado">
          <b-form-checkbox
            v-model="localIsActive"
            switch
            @change="emitChange"
          >
            {{ localIsActive ? 'Activo' : 'Inactivo' }}
          </b-form-checkbox>
          <small class="text-muted d-block">Visible en el portal público</small>
        </b-form-group>
      </b-col>
    </b-row>

    <!-- Descripción corta -->
    <b-form-group label="Descripción corta *">
      <b-form-input
        v-model="localDescriptionShort"
        maxlength="120"
        placeholder="Breve descripción para la tarjeta del catálogo"
        @input="emitChange"
      />
      <small class="text-muted">{{ localDescriptionShort.length }}/120 caracteres</small>
    </b-form-group>

    <!-- Descripción larga -->
    <b-form-group label="Descripción larga">
      <b-form-textarea
        v-model="localDescriptionLong"
        rows="3"
        placeholder="Descripción detallada para la página interna"
        @input="emitChange"
      />
    </b-form-group>

    <!-- Destacado -->
    <b-form-group label="Destacado">
      <b-form-checkbox
        v-model="localIsFeatured"
        switch
        @change="emitChange"
      >
        {{ localIsFeatured ? 'Sí' : 'No' }}
      </b-form-checkbox>
      <small class="text-muted d-block">Aparece con borde rojo en el catálogo</small>
    </b-form-group>
  </b-card>
</template>

<script setup>
import { ref, watch, onMounted, computed } from 'vue';

const props = defineProps({
  icon: { type: String, default: '🛒' },
  name: { type: String, default: '' },
  slug: { type: String, default: '' },
  categoryId: { type: [Number, String], default: null },
  isActive: { type: Boolean, default: true },
  isFeatured: { type: Boolean, default: false },
  descriptionShort: { type: String, default: '' },
  descriptionLong: { type: String, default: '' },
  categories: { type: Array, default: () => [] }
});

const emit = defineEmits([
  'update:icon',
  'update:name',
  'update:slug',
  'update:categoryId',
  'update:isActive',
  'update:isFeatured',
  'update:descriptionShort',
  'update:descriptionLong',
  'change' // evento genérico para notificar cambios
]);

// Datos locales
const localIcon = ref(props.icon);
const localName = ref(props.name);
const localSlug = ref(props.slug);
const localCategoryId = ref(props.categoryId);
const localIsActive = ref(props.isActive);
const localIsFeatured = ref(props.isFeatured);
const localDescriptionShort = ref(props.descriptionShort);
const localDescriptionLong = ref(props.descriptionLong);

// Opciones para el select de categorías
const categoryOptions = computed(() => {
  return props.categories.map(cat => ({
    value: cat.id,
    text: cat.name
  }));
});

// Generar slug a partir del nombre
function generateSlug(name) {
  if (!name) return '';
  return name
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

// Emitir cambios al padre
function emitChange() {
  // Actualizar slug si el nombre cambió y el slug está vacío o es el antiguo
  if (localName.value && !localSlug.value) {
    localSlug.value = generateSlug(localName.value);
  }

  // Emitir cada propiedad individual
  emit('update:icon', localIcon.value);
  emit('update:name', localName.value);
  emit('update:slug', localSlug.value);
  emit('update:categoryId', localCategoryId.value);
  emit('update:isActive', localIsActive.value);
  emit('update:isFeatured', localIsFeatured.value);
  emit('update:descriptionShort', localDescriptionShort.value);
  emit('update:descriptionLong', localDescriptionLong.value);

  // Evento genérico
  emit('change', {
    icon: localIcon.value,
    name: localName.value,
    slug: localSlug.value,
    categoryId: localCategoryId.value,
    isActive: localIsActive.value,
    isFeatured: localIsFeatured.value,
    descriptionShort: localDescriptionShort.value,
    descriptionLong: localDescriptionLong.value
  });
}

// Abrir selector de emojis (simple, puedes mejorarlo)
function openEmojiPicker() {
  const emojis = ['🛒', '💊', '🏨', '🍽️', '💰', '🔧', '📊', '📦', '📱', '⚡', '🔥', '✨'];
  const selected = prompt('Elige un emoji (puedes copiar y pegar):', localIcon.value);
  if (selected && emojis.includes(selected)) {
    localIcon.value = selected;
    emitChange();
  } else if (selected) {
    localIcon.value = selected;
    emitChange();
  }
}

// Sincronizar con props del padre
watch(() => props.icon, (val) => { localIcon.value = val; });
watch(() => props.name, (val) => { localName.value = val; });
watch(() => props.slug, (val) => { localSlug.value = val; });
watch(() => props.categoryId, (val) => { localCategoryId.value = val; });
watch(() => props.isActive, (val) => { localIsActive.value = val; });
watch(() => props.isFeatured, (val) => { localIsFeatured.value = val; });
watch(() => props.descriptionShort, (val) => { localDescriptionShort.value = val; });
watch(() => props.descriptionLong, (val) => { localDescriptionLong.value = val; });
</script>

<style scoped>
.icon-picker {
  width: 80px;
  height: 80px;
  border-radius: 8px;
  border: 2px dashed #dee2e6;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: border-color 0.2s;
  background: #f8f9fa;
}
.icon-picker:hover {
  border-color: #28a745;
}
.icon-picker .display-4 {
  font-size: 2.5rem;
  line-height: 1;
}
</style>