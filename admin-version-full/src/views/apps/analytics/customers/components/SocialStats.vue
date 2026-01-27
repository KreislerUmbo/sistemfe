<template>
  <b-row class="justify-content-center">
    <b-col md="6" lg="4" v-for="(item, idx) in socialStatData" :key="idx">
      <SocialStatCard :item="item" />
    </b-col>
  </b-row>
</template>
<script setup lang="ts">
import { socialStatData } from "@/views/apps/analytics/customers/components/data";
import SocialStatCard from "@/views/apps/analytics/customers/components/SocialStatCard.vue";
</script>
