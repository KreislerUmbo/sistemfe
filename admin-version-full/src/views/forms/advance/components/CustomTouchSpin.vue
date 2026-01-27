<template>
  <b-col md="6" lg="6">
    <UIComponentCard title="Custom Touch Spin">
      <div class="input-group qty-icons w-50">
        <b-button variant="primary" @click="count && count--">-</b-button>
        <input
          type="number"
          class="form-control"
          min="0"
          name="quantity"
          :value="count"
          style="pointer-events: none"
        />
        <b-button variant="primary" @click="count++">+</b-button>
      </div>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import { ref } from "vue";
const count = ref<number>(0);
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
