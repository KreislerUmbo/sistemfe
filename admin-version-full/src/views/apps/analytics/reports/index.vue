<template>
  <DefaultLayout>
    <b-row>
      <TopCountry />
      <Metrics />
    </b-row>

    <b-row>
      <VisitsDetails />
      <SocialMedia />
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import TopCountry from "@/views/apps/analytics/reports/components/TopCountry.vue";
import Metrics from "@/views/apps/analytics/reports/components/Metrics.vue";
import VisitsDetails from "@/views/apps/analytics/reports/components/VisitsDetails.vue";
import SocialMedia from "@/views/apps/analytics/reports/components/SocialMedia.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
