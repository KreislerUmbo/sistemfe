<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <StatWidgets />
    </b-row>
    <b-row class="justify-content-center">
      <AudienceOverview />
      <NewVisitors />
    </b-row>
    <b-row>
      <BrowserTrafficReports />
      <TotalVisits />
    </b-row>
    <b-row class="justify-content-center">
      <TrafficSources />
      <OrganicTraffic />
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import StatWidgets from "@/views/dashboards/analytics/components/StatWidgets.vue";
import AudienceOverview from "@/views/dashboards/analytics/components/AudienceOverview.vue";
import BrowserTrafficReports from "@/views/dashboards/analytics/components/BrowserTrafficReports.vue";
import NewVisitors from "@/views/dashboards/analytics/components/NewVisitors.vue";
import OrganicTraffic from "@/views/dashboards/analytics/components/OrganicTraffic.vue";
import TotalVisits from "@/views/dashboards/analytics/components/TotalVisits.vue";
import TrafficSources from "@/views/dashboards/analytics/components/TrafficSources.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
