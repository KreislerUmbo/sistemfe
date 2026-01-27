<template>
  <b-col md="12" lg="3">
    <UIComponentCard title="Top Country">
      <template v-for="(item, idx) in topCountriesData" :key="idx">
        <div
          class="d-flex align-items-center"
          :class="
            topCountriesData.length - 1 === idx
              ? 'pt-3'
              : 'border-dashed-bottom py-3'
          "
        >
          <img
            :src="item.countryFlag"
            class="thumb-sm align-self-center rounded-circle"
            alt="..."
          />
          <div class="flex-grow-1 ms-2">
            <h5 class="m-0">{{ item.count }}</h5>
            <p class="text-muted mb-0">
              {{ item.name }} . Last Month
              <span class="text-success"
                >{{ item.change }}% <i class="mdi mdi-arrow-up"></i
              ></span>
            </p>
          </div>
        </div>
      </template>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import UIComponentCard from "@/components/UIComponentCard.vue";
import { topCountriesData } from "@/views/apps/analytics/reports/components/data";
</script>
