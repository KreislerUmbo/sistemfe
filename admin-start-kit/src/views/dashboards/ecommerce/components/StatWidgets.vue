<template>
  <b-col md="12" lg="4">
    <template v-for="(item, idx) in statData" :key="idx">
      <StatWidgetCard :item="item" />
    </template>
  </b-col>
</template>
<script setup lang="ts">
import { statData } from "@/views/dashboards/ecommerce/components/data";
import StatWidgetCard from "@/views/dashboards/ecommerce/components/StatWidgetCard.vue";
</script>
