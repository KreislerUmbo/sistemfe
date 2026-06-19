<template>
  <nav v-if="lastPage > 1">
    <ul class="pagination justify-content-center">
      <li class="page-item" :class="{ disabled: currentPage === 1 }">
        <button class="page-link bg-dark text-white border-secondary" @click="$emit('page-changed', currentPage - 1)">Anterior</button>
      </li>
      <li v-for="page in totalPages" :key="page" class="page-item" :class="{ active: page === currentPage }">
        <button class="page-link bg-dark text-white border-secondary" @click="$emit('page-changed', page)">{{ page }}</button>
      </li>
      <li class="page-item" :class="{ disabled: currentPage === lastPage }">
        <button class="page-link bg-dark text-white border-secondary" @click="$emit('page-changed', currentPage + 1)">Siguiente</button>
      </li>
    </ul>
  </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue'
const props = defineProps<{ currentPage: number; lastPage: number }>()
const emit = defineEmits<{ (e: 'page-changed', page: number): void }>()
const totalPages = computed(() => Math.min(props.lastPage, 10)) // opcional
</script>