<template>
  <b-col md="12" lg="7">
    <b-card no-body>
      <b-card-body>
        <b-row class="g-3">
          <b-col md="6" lg="6" v-for="(item, idx) in stateData" :key="idx">
            <StatsWidgetCard :item="item" />
          </b-col>
        </b-row>
        <div
          class="bg-primary-subtle p-2 border-dashed border-primary rounded mt-3"
        >
          <img src="" alt="" class="d-inline-block me-1" height="30" />{{ " " }}
          <span class="text-primary fw-semibold">Karen Savage's</span>
          <span class="text-primary fw-normal">
            best performance from last year</span
          >
        </div>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import { stateData } from "@/views/apps/ecommerce/customers/components/data";
import StatsWidgetCard from "@/views/apps/ecommerce/customers/components/StatsWidgetCard.vue";
</script>
