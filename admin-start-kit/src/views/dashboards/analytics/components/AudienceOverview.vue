<template>
  <b-col md="6" lg="8">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Audience Overview</b-card-title>
          </div>
          <div class="col-auto">
            <b-dropdown variant="light" menu-class="dropdown-menu-end">
              <template #button-content>
                <i class="icofont-calendar fs-5 me-1"></i> This Year<i
                  class="las la-angle-down ms-1"
                ></i>
              </template>
              <b-dropdown-item>Today</b-dropdown-item>
              <b-dropdown-item>Last Week</b-dropdown-item>
              <b-dropdown-item>Last Month</b-dropdown-item>
              <b-dropdown-item>This Year</b-dropdown-item>
            </b-dropdown>
          </div>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <ApexChart
          :chart="audienceOverviewChart"
          class="apex-charts"
          id="audience_overview"
        />
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import { audienceOverviewChart } from "@/views/dashboards/analytics/components/data";
</script>
