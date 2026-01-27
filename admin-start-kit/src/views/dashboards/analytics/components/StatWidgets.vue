<template>
  <b-col md="6" lg="4" v-for="(item, idx) in statData" :key="idx">
    <StatWidgetCard :item="item" />
  </b-col>
</template>
<script setup lang="ts">
import { statData } from "@/views/dashboards/analytics/components/data";
import StatWidgetCard from "@/views/dashboards/analytics/components/StatWidgetCard.vue";
</script>
