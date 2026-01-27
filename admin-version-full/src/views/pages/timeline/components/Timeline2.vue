<template>
  <b-col md="6" lg="6">
    <UIComponentCard title="Timeline">
      <div class="tracking-list">
        <div
          class="tracking-item"
          v-for="(item, idx) in activities2"
          :key="idx"
        >
          <div class="tracking-icon icon-inner">
            <i class="fas fa-circle"></i>
          </div>
          <div class="tracking-date">
            {{ item.date
            }}<span class="d-block fs-12 text-muted">{{ item.time }}</span>
          </div>
          <p class="mb-0 text-muted">{{ item.description }}</p>
        </div>
      </div>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import { activities2 } from "@/views/pages/timeline/components/data";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
