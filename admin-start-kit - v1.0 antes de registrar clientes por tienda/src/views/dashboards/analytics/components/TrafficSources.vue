<template>
  <b-col md="6" lg="4">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Traffic Sources</b-card-title>
          </div>
          <div class="col-auto">
            <b-dropdown variant="light" menu-class="dropdown-menu-end">
              <template #button-content>
                <i class="icofont-search-user fs-5 me-1"></i> Direct<i
                  class="las la-angle-down ms-1"
                ></i>
              </template>
              <b-dropdown-item>Email</b-dropdown-item>
              <b-dropdown-item>Referral</b-dropdown-item>
              <b-dropdown-item>Direct</b-dropdown-item>
              <b-dropdown-item>Organic</b-dropdown-item>
              <b-dropdown-item>Campaign</b-dropdown-item>
            </b-dropdown>
          </div>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <div class>
          <ApexChart
            :chart="trafficSourcesChart"
            class="apex-charts d-block w-90 mx-auto"
            id="traffic_sources"
          />
          <hr class="hr-dashed border-secondary w-25 mt-0 mx-auto" />
        </div>
        <div class="text-center">
          <h4>Direct Visitors</h4>
          <p class="text-muted mt-2">
            This is a simple hero unit, a simple jumbotron-style component
          </p>
          <b-button type="button" variant="outline-primary" class="px-3 mt-2"
            >More details</b-button
          >
        </div>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import { trafficSourcesChart } from "@/views/dashboards/analytics/components/data";
</script>
